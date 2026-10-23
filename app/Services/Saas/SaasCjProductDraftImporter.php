<?php

namespace App\Services\Saas;

use App\Models\SaasCjConnection;
use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SaasCjProductDraftImporter
{
    private const HOST = 'https://developers.cjdropshipping.com';
    private const PATH = '/api2.0/v1/product/listV2';
    private const PAGE_SIZE = 10;
    private const PILOT_CAP = 50;

    public function canImport(User $actor, SaasWorkspace $workspace): bool
    {
        if (!config('saas.enabled') || !config('saas.cj_draft_import_enabled')
            || $workspace->status !== 'active'
            || !app(WorkspacePermissions::class)->allows($actor, $workspace, 'import')) {
            return false;
        }

        $paid = app(SaasEntitlements::class)->allows($workspace, 'import');
        $pilot = app(SaasCjPilot::class)->allows($workspace)
            && app(WorkspacePermissions::class)->roleFor($actor, $workspace) === 'owner'
            && $workspace->products()->count() < self::PILOT_CAP;

        if (!$paid && !$pilot) {
            return false;
        }

        $connection = $workspace->cjConnections()->first();
        return $connection?->status === 'connected'
            && $connection->getRawOriginal('access_token') !== null
            && $connection->access_token_expires_at?->gt(now()->addMinute());
    }

    /** @return array{created:int,updated:int,skipped:int} */
    public function fetchAndStore(User $actor, SaasWorkspace $workspace, string $keyword, int $page): array
    {
        abort_unless($this->canImport($actor, $workspace), 403);

        // Durable row lock and timestamp apply the quota even if the cache
        // store is array-based. No DB transaction remains open over HTTPS.
        $reserved = DB::transaction(function () use ($workspace, $actor): ?array {
            $locked = SaasWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canImport($actor, $locked), 403);
            $connection = $locked->cjConnections()->lockForUpdate()->firstOrFail();
            if ($connection->last_product_fetch_at?->gt(now()->subMinute())) {
                return null;
            }
            $connection->last_product_fetch_at = now();
            $connection->save();

            return [
                'id' => $connection->id,
                'revision' => (int) $connection->credential_revision,
                'token' => $connection->access_token,
            ];
        });
        if ($reserved === null) {
            throw new SaasCjApiException('product_cooldown');
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['CJ-Access-Token' => $reserved['token']])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(5)->timeout(12)
                ->get(self::HOST.self::PATH, [
                    'page' => $page,
                    'size' => self::PAGE_SIZE,
                    'keyWord' => $keyword,
                ]);
        } catch (ConnectionException) {
            throw new SaasCjApiException('unavailable');
        } catch (Throwable) {
            // Provider/HTTP exception detail may include a bearer token.
            throw new SaasCjApiException('unavailable');
        }

        if ($response->status() === 429) {
            throw new SaasCjApiException('rate_limited');
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw new SaasCjApiException('token_expired');
        }
        if (!$response->ok()) {
            throw new SaasCjApiException('unavailable');
        }
        $body = $response->json();
        if (!is_array($body)) {
            throw new SaasCjApiException('invalid_response');
        }
        if ((int) ($body['code'] ?? 0) === 429 ||
            in_array((int) ($body['code'] ?? 0), [1600200, 1600201], true)) {
            throw new SaasCjApiException('rate_limited');
        }
        if (($body['result'] ?? false) !== true || (int) ($body['code'] ?? 0) !== 200) {
            throw new SaasCjApiException('invalid_response');
        }
        $groups = data_get($body, 'data.content');
        if (!is_array($groups) || !array_is_list($groups)) {
            throw new SaasCjApiException('invalid_response');
        }

        $products = [];
        foreach ($groups as $group) {
            if (!is_array($group) || !isset($group['productList'])
                || !is_array($group['productList']) || !array_is_list($group['productList'])) {
                throw new SaasCjApiException('invalid_response');
            }
            foreach ($group['productList'] as $item) {
                if (!is_array($item)
                    || !is_string($item['id'] ?? null) || trim($item['id']) === ''
                    || strlen($item['id']) > 160
                    || !is_string($item['nameEn'] ?? null) || trim($item['nameEn']) === '') {
                    throw new SaasCjApiException('invalid_response');
                }
                $id = trim($item['id']);
                $products[$id] = [
                    'title' => mb_substr(trim($item['nameEn']), 0, 250),
                    'sku' => $this->smallText($item['sku'] ?? $item['spu'] ?? null, 180),
                    'source_snapshot' => [
                        'source' => 'cj-list-v2',
                        'currency' => 'USD',
                        'sell_price_usd' => $this->price($item['sellPrice'] ?? null),
                        'discount_price_usd' => $this->price($item['nowPrice'] ?? null),
                        'image_url' => $this->safeImage($item['bigImage'] ?? null),
                        'category' => $this->smallText($item['threeCategoryName'] ?? null, 200),
                        'source_page' => $page,
                        'fetched_at' => now()->toIso8601String(),
                    ],
                ];
            }
        }
        if (count($products) > self::PAGE_SIZE) {
            throw new SaasCjApiException('invalid_response');
        }

        return DB::transaction(function () use ($workspace, $actor, $reserved, $products): array {
            $locked = SaasWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'active'
                && config('saas.cj_draft_import_enabled')
                && app(WorkspacePermissions::class)->allows($actor, $locked, 'import'), 403);
            $connection = $locked->cjConnections()->lockForUpdate()->first();
            if (!$connection || $connection->id !== $reserved['id']
                || $connection->status !== 'connected'
                || (int) $connection->credential_revision !== $reserved['revision']
                || !$connection->access_token
                || !hash_equals($reserved['token'], $connection->access_token)
                || !$connection->access_token_expires_at?->gt(now())) {
                throw new SaasCjApiException('connection_changed');
            }

            $pilot = app(SaasCjPilot::class)->allows($locked)
                && app(WorkspacePermissions::class)->roleFor($actor, $locked) === 'owner';
            $entitlements = app(SaasEntitlements::class);
            $paid = $entitlements->active($locked);
            if (!$pilot && !$paid) {
                abort(403);
            }
            $max = $paid ? $entitlements->limit($locked, 'product_limit') : self::PILOT_CAP;
            $total = $locked->products()->count();
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($products as $id => $fields) {
                $existing = $locked->products()->where('cj_product_id', $id)->first();
                if ($existing) {
                    if ($existing->status !== 'draft') {
                        ++$skipped; // Never overwrite human-approved/published entries.
                        continue;
                    }
                    $existing->fill($fields);
                    $existing->save();
                    ++$updated;
                } elseif ($total < $max) {
                    $locked->products()->create([
                        'cj_product_id' => $id,
                        'status' => 'draft',
                    ] + $fields);
                    ++$created;
                    ++$total;
                } else {
                    ++$skipped;
                }
            }

            return compact('created', 'updated', 'skipped');
        });
    }

    private function smallText(mixed $value, int $limit): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? mb_substr(trim($value), 0, $limit) : null;
    }

    private function price(mixed $value): ?string
    {
        $price = is_string($value) || is_numeric($value) ? (string) $value : '';
        return preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $price) ? $price : null;
    }

    private function safeImage(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 500
            || !str_starts_with($value, 'https://')
            || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        return $value;
    }
}
