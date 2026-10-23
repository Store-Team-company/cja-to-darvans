<?php

namespace App\Services\Saas;

use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SaasCjCatalogBrowser
{
    private const HOST = 'https://developers.cjdropshipping.com';
    private const LIST_PATH = '/api2.0/v1/product/listV2';
    private const DETAIL_PATH = '/api2.0/v1/product/query';
    private const PREVIEW_TTL = 15;

    public function canSearch(User $actor, SaasWorkspace $workspace): bool
    {
        return app(SaasCjProductDraftImporter::class)->canImport($actor, $workspace);
    }

    /** @return array{key:string,count:int} */
    public function search(User $actor, SaasWorkspace $workspace, array $filters): array
    {
        abort_unless($this->canSearch($actor, $workspace), 403);

        // One charged provider call per 60 seconds per workspace. Do not hold
        // a database lock across CJ network I/O or store on a search request.
        $reserved = DB::transaction(function () use ($actor, $workspace): ?array {
            $locked = SaasWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canSearch($actor, $locked), 403);
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
        if (!$reserved) {
            throw new SaasCjApiException('product_cooldown');
        }

        $direct = ($filters['mode'] ?? 'search') === 'direct';
        $params = $direct
            ? [$filters['id_type'] => trim($filters['identifier'])]
            : array_filter([
                'page' => (int) ($filters['page'] ?? 1),
                'size' => (int) ($filters['size'] ?? 20),
                'keyWord' => trim($filters['keyword']),
                'countryCode' => $filters['country_code'] ?? null,
                'startSellPrice' => $filters['min_price'] ?? null,
                'endSellPrice' => $filters['max_price'] ?? null,
                'orderBy' => (int) ($filters['order_by'] ?? 0),
                'sort' => $filters['sort'] ?? 'desc',
                'features' => ['enable_category'],
            ], static fn ($v) => $v !== null && $v !== '');

        try {
            $response = Http::acceptJson()
                ->withHeaders(['CJ-Access-Token' => $reserved['token']])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(5)->timeout(12)
                ->get(self::HOST.($direct ? self::DETAIL_PATH : self::LIST_PATH), $params);
        } catch (ConnectionException) {
            throw new SaasCjApiException('unavailable');
        } catch (Throwable) {
            throw new SaasCjApiException('unavailable');
        }

        if ($response->status() === 429) {
            throw new SaasCjApiException('rate_limited');
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new SaasCjApiException('token_expired');
        }
        if (!$response->ok() || !is_array($body = $response->json())) {
            throw new SaasCjApiException('unavailable');
        }
        if (in_array((int) ($body['code'] ?? 0), [429, 1600200, 1600201], true)) {
            throw new SaasCjApiException('rate_limited');
        }
        if (($body['result'] ?? false) !== true || (int) ($body['code'] ?? 0) !== 200) {
            throw new SaasCjApiException('invalid_response');
        }

        $items = [];
        if ($direct) {
            if (!is_array($body['data'] ?? null)) {
                throw new SaasCjApiException('invalid_response');
            }
            $items = [$body['data']];
        } else {
            $groups = data_get($body, 'data.content');
            if (!is_array($groups) || !array_is_list($groups)) {
                throw new SaasCjApiException('invalid_response');
            }
            foreach ($groups as $group) {
                if (!is_array($group) || !is_array($group['productList'] ?? null)
                    || !array_is_list($group['productList'])) {
                    throw new SaasCjApiException('invalid_response');
                }
                array_push($items, ...$group['productList']);
            }
            if (count($items) > (int) ($filters['size'] ?? 20)) {
                throw new SaasCjApiException('invalid_response');
            }
        }

        $products = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new SaasCjApiException('invalid_response');
            }
            $id = $this->text($item['id'] ?? $item['pid'] ?? null, 160);
            $title = $this->text($item['nameEn'] ?? $item['productNameEn'] ?? null, 250);
            if (!$id || !$title) {
                throw new SaasCjApiException('invalid_response');
            }
            $image = $item['bigImage'] ?? $item['productImage'] ?? null;
            if (!$image && is_array($item['productImageSet'] ?? null)) {
                $image = $item['productImageSet'][0] ?? null;
            }
            $products[$id] = [
                'id' => $id,
                'title' => $title,
                'sku' => $this->text($item['sku'] ?? $item['spu'] ?? $item['productSku'] ?? null, 180),
                'price' => $this->price($item['sellPrice'] ?? null),
                'category' => $this->text($item['threeCategoryName'] ?? $item['categoryName'] ?? null, 200),
                'image' => $this->safeImage($image),
            ];
        }

        // Reject credential rotation/revocation between the HTTP response and
        // writing the temporary, tenant-scoped preview.
        $this->verifyConnection($workspace, $actor, $reserved);
        $key = bin2hex(random_bytes(20));
        Cache::put($this->cacheKey($workspace, $actor, $key), [
            'connection_id' => $reserved['id'],
            'credential_revision' => $reserved['revision'],
            'filters' => $filters,
            'total' => $direct ? count($products) : max(count($products), min(10000000, (int) data_get($body, 'data.totalRecords', count($products)))),
            'products' => array_values($products),
        ], now()->addMinutes(self::PREVIEW_TTL));

        return ['key' => $key, 'count' => count($products)];
    }

    public function preview(User $actor, SaasWorkspace $workspace, ?string $key): ?array
    {
        if (!is_string($key) || !preg_match('/^[a-f0-9]{40}$/D', $key)) {
            return null;
        }
        $value = Cache::get($this->cacheKey($workspace, $actor, $key));
        return is_array($value) ? $value : null;
    }

    /** @return array{created:int,updated:int,skipped:int} */
    public function importSelected(User $actor, SaasWorkspace $workspace, string $key, array $selectedIds): array
    {
        abort_unless($this->canSearch($actor, $workspace), 403);
        $preview = $this->preview($actor, $workspace, $key);
        if (!$preview) {
            throw new SaasCjApiException('preview_expired');
        }
        $available = collect($preview['products'])->keyBy('id');
        $ids = array_values(array_unique($selectedIds));
        if (count($ids) !== count($selectedIds) || !$ids || count($ids) > 50) {
            throw new SaasCjApiException('invalid_selection');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !$available->has($id)) {
                throw new SaasCjApiException('invalid_selection');
            }
        }

        return DB::transaction(function () use ($actor, $workspace, $preview, $available, $ids): array {
            $locked = SaasWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canSearch($actor, $locked), 403);
            $connection = $locked->cjConnections()->lockForUpdate()->first();
            if (!$connection || $connection->id !== $preview['connection_id']
                || (int) $connection->credential_revision !== $preview['credential_revision']
                || $connection->status !== 'connected'
                || !$connection->access_token_expires_at?->gt(now())) {
                throw new SaasCjApiException('connection_changed');
            }

            $entitlements = app(SaasEntitlements::class);
            $pilot = app(SaasCjPilot::class)->allows($locked)
                && app(WorkspacePermissions::class)->roleFor($actor, $locked) === 'owner';
            $max = $entitlements->active($locked)
                ? $entitlements->limit($locked, 'product_limit') : ($pilot ? 50 : 0);
            $total = $locked->products()->count();
            $created = 0;
            $updated = 0;
            $skipped = 0;
            foreach ($ids as $id) {
                $product = $available->get($id);
                $fields = [
                    'title' => $product['title'],
                    'sku' => $product['sku'],
                    'source_snapshot' => [
                        'source' => 'cj-catalog-selected',
                        'currency' => 'USD',
                        'sell_price_usd' => $product['price'],
                        'image_url' => $product['image'],
                        'category' => $product['category'],
                        'fetched_at' => now()->toIso8601String(),
                    ],
                ];
                $existing = $locked->products()->where('cj_product_id', $id)->first();
                if ($existing && $existing->status !== 'draft') {
                    $skipped++;
                } elseif ($existing) {
                    // A fresh CJ snapshot must never erase saved merchant edits.
                    if ($existing->merchant_edited_at) {
                        $existing->source_snapshot = $fields['source_snapshot'];
                    } else {
                        $existing->fill($fields);
                    }
                    $existing->save();
                    $updated++;
                } elseif ($total < $max) {
                    $locked->products()->create(['cj_product_id' => $id, 'status' => 'draft'] + $fields);
                    $total++;
                    $created++;
                } else {
                    $skipped++;
                }
            }
            return compact('created', 'updated', 'skipped');
        });
    }

    private function verifyConnection(SaasWorkspace $workspace, User $actor, array $reserved): void
    {
        $locked = $workspace->fresh();
        abort_unless($locked && $this->canSearch($actor, $locked), 403);
        $connection = $locked->cjConnections()->first();
        if (!$connection || $connection->id !== $reserved['id']
            || (int) $connection->credential_revision !== $reserved['revision']
            || $connection->status !== 'connected'
            || !hash_equals($reserved['token'], (string) $connection->access_token)
            || !$connection->access_token_expires_at?->gt(now())) {
            throw new SaasCjApiException('connection_changed');
        }
    }

    private function cacheKey(SaasWorkspace $workspace, User $actor, string $key): string
    {
        return 'saas:cj:preview:'.hash('sha256', $workspace->id.'|'.$actor->id.'|'.$key);
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= $max
            ? trim($value) : null;
    }

    private function price(mixed $value): ?string
    {
        $price = is_string($value) || is_numeric($value) ? (string) $value : '';
        return preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $price) ? $price : null;
    }

    private function safeImage(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 500
            && str_starts_with($value, 'https://')
            && filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }
}
