<?php

namespace App\Services\Saas;

use App\Models\SaasProduct;
use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SaasCjProductDetailsFetcher
{
    private const ENDPOINT = 'https://developers.cjdropshipping.com/api2.0/v1/product/query';

    public function __construct(private readonly SaasProductHtmlSanitizer $html)
    {
    }

    /** Refresh a saved draft via the owner's verified CJ key, never the legacy admin token. */
    public function fetch(User $actor, SaasWorkspace $workspace, SaasProduct $product): void
    {
        $this->authorize($actor, $workspace, $product);

        $reservation = DB::transaction(function () use ($actor, $workspace, $product): ?array {
            $locked = SaasWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $locked, $product);
            $connection = $locked->cjConnections()->lockForUpdate()->first();
            if (!$connection || $connection->status !== 'connected'
                || !is_string($connection->access_token)
                || !$connection->access_token_expires_at?->gt(now())) {
                throw new SaasCjApiException('connection_changed');
            }
            if ($connection->last_details_fetch_at?->gt(now()->subMinute())) {
                return null;
            }
            $connection->last_details_fetch_at = now();
            $connection->save();

            return [
                'id' => $connection->id,
                'revision' => (int) $connection->credential_revision,
                'token' => $connection->access_token,
            ];
        });
        if ($reservation === null) {
            throw new SaasCjApiException('product_cooldown');
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['CJ-Access-Token' => $reservation['token']])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(5)->timeout(15)
                ->get(self::ENDPOINT, [
                    'pid' => $product->cj_product_id,
                    'features' => 'enable_description,enable_category',
                ]);
        } catch (Throwable) {
            throw new SaasCjApiException('unavailable');
        }

        if ($response->status() === 429) {
            throw new SaasCjApiException('rate_limited');
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new SaasCjApiException('token_expired');
        }
        if (!$response->ok() || !is_array($response->json())) {
            throw new SaasCjApiException('invalid_response');
        }
        $body = $response->json();
        if ($body['result'] !== true || (int) ($body['code'] ?? 0) !== 200
            || !is_array($body['data'] ?? null)) {
            throw new SaasCjApiException('invalid_response');
        }

        $data = $body['data'];
        $pid = $this->text($data['pid'] ?? $data['id'] ?? null, 160);
        if ($pid === null || !hash_equals((string) $product->cj_product_id, $pid)) {
            // Reject a provider response for the wrong product; no cross-SKU writes.
            throw new SaasCjApiException('invalid_response');
        }
        $title = $this->text($data['productNameEn'] ?? $data['nameEn'] ?? null, 250);
        $sku = $this->text($data['productSku'] ?? $data['sku'] ?? null, 180);
        $category = $this->text($data['categoryName'] ?? null, 200);
        $rawLong = $data['description'] ?? null;
        if ($rawLong !== null && !is_string($rawLong)) {
            throw new SaasCjApiException('invalid_response');
        }
        try {
            $long = $this->html->clean($rawLong);
        } catch (Throwable) {
            throw new SaasCjApiException('invalid_response');
        }
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($long ?? '')) ?? '');
        $short = $plain !== '' ? '<p>'.e(Str::limit($plain, 320, '…')).'</p>' : null;
        $gallery = $this->gallery($data['productImageSet'] ?? $data['productImage'] ?? null);
        $featured = $this->safeImage($data['bigImage'] ?? null)
            ?? ($gallery[0] ?? null);
        if ($featured && !in_array($featured, $gallery, true)) {
            array_unshift($gallery, $featured);
            $gallery = array_slice($gallery, 0, 20);
        }
        $sourcePrice = $this->price($data['sellPrice'] ?? null);
        $variants = $this->variants($data['variants'] ?? []);
        $weight = $this->weight($data['productWeight'] ?? null);
        $metadata = [
            'source' => 'cj-product-query',
            'currency' => 'USD',
            'sell_price_usd' => $sourcePrice,
            'image_url' => $featured,
            'category' => $category,
            'source_sku' => $sku,
            'weight_g' => $weight,
            'variant_count' => count($variants),
            'fetched_at' => now()->toIso8601String(),
        ];

        DB::transaction(function () use (
            $actor, $workspace, $product, $reservation,
            $title, $sku, $category, $long, $short, $gallery, $featured,
            $weight, $variants, $metadata
        ): void {
            $lockedWorkspace = SaasWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $lockedProduct = $lockedWorkspace->products()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $lockedWorkspace, $lockedProduct);
            $connection = $lockedWorkspace->cjConnections()->lockForUpdate()->first();
            if (!$connection || $connection->status !== 'connected'
                || $connection->id !== $reservation['id']
                || (int) $connection->credential_revision !== $reservation['revision']
                || !is_string($connection->access_token)
                || !hash_equals($reservation['token'], $connection->access_token)
                || !$connection->access_token_expires_at?->gt(now())) {
                throw new SaasCjApiException('connection_changed');
            }

            // Source/variant facts always refresh; never replace existing merchant edits.
            $snapshot = array_merge($lockedProduct->source_snapshot ?? [], $metadata);
            $lockedProduct->source_snapshot = $snapshot;
            $lockedProduct->source_weight_g = $weight;
            $lockedProduct->source_variants = $variants;
            $lockedProduct->cj_details_fetched_at = now();
            if (!$lockedProduct->merchant_edited_at) {
                $lockedProduct->title = $title ?? $lockedProduct->title;
                $lockedProduct->sku = $sku ?? $lockedProduct->sku;
                $lockedProduct->category_name = $category ?? $lockedProduct->category_name;
                $lockedProduct->long_description_html = $long;
                $lockedProduct->short_description_html = $short;
                $lockedProduct->featured_image_url = $featured;
                $lockedProduct->gallery_images = $gallery;
                $lockedProduct->slug = Str::slug($lockedProduct->title) ?: null;
            } else {
                foreach ([
                    'long_description_html' => $long,
                    'short_description_html' => $short,
                    'featured_image_url' => $featured,
                    'gallery_images' => $gallery,
                    'category_name' => $category,
                ] as $field => $value) {
                    if (blank($lockedProduct->{$field}) && filled($value)) {
                        $lockedProduct->{$field} = $value;
                    }
                }
            }
            $lockedProduct->save();
        });
    }

    private function authorize(User $actor, SaasWorkspace $workspace, SaasProduct $product): void
    {
        abort_unless($product->workspace_id === $workspace->id && $workspace->status === 'active', 404);
        abort_unless($product->status === 'draft', 403);
        abort_unless(app(WorkspacePermissions::class)->allows($actor, $workspace, 'edit_draft'), 403);
        $active = app(SaasEntitlements::class)->active($workspace);
        $pilot = app(SaasCjPilot::class)->allows($workspace)
            && app(WorkspacePermissions::class)->roleFor($actor, $workspace) === 'owner';
        abort_unless($active || $pilot, 403);
        abort_unless(filled($product->cj_product_id), 422);
    }

    private function text(mixed $value, int $limit): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? Str::limit(trim($value), $limit, '') : null;
    }

    private function price(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $v = trim((string) $value);
        return preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $v) ? $v : null;
    }

    private function weight(mixed $value): ?string
    {
        $v = (string) (is_scalar($value) ? $value : '');
        return preg_match('/^\d{1,8}(?:\.\d{1,3})?$/D', $v) ? $v : null;
    }

    private function safeImage(mixed $value): ?string
    {
        return is_string($value) && app(SaasProductHtmlSanitizer::class)->safeUrl($value)
            ? $value : null;
    }

    private function gallery(mixed $source): array
    {
        if (is_string($source) && str_starts_with(trim($source), '[')) {
            $source = json_decode($source, true);
        }
        if (is_string($source)) {
            $source = [$source];
        }
        if (!is_array($source) || !array_is_list($source)) {
            return [];
        }
        return array_slice(array_values(array_unique(array_filter(
            array_map(fn ($image) => $this->safeImage($image), array_slice($source, 0, 100))
        ))), 0, 20);
    }

    private function variants(mixed $source): array
    {
        if (!is_array($source) || !array_is_list($source)) {
            return [];
        }
        if (count($source) > 100) {
            // Do not silently omit supplier SKU records during enrichment.
            throw new SaasCjApiException('invalid_response');
        }
        $result = [];
        foreach ($source as $item) {
            if (!is_array($item)) {
                throw new SaasCjApiException('invalid_response');
            }
            $id = $this->text($item['vid'] ?? $item['id'] ?? null, 160);
            $sku = $this->text($item['variantSku'] ?? null, 180);
            if (!$id && !$sku) {
                throw new SaasCjApiException('invalid_response');
            }
            $result[] = [
                'id' => $id,
                'sku' => $sku,
                'key' => $this->text($item['variantKey'] ?? $item['variantKeyEn'] ?? null, 200),
                'price_usd' => $this->price($item['variantSellPrice'] ?? null),
                'image' => $this->safeImage($item['variantImage'] ?? null),
                'weight_g' => $this->weight($item['variantWeight'] ?? null),
                'length' => $this->weight($item['variantLength'] ?? null),
                'width' => $this->weight($item['variantWidth'] ?? null),
                'height' => $this->weight($item['variantHeight'] ?? null),
            ];
        }
        return $result;
    }
}
