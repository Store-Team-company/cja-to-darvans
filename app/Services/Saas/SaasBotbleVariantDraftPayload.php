<?php

namespace App\Services\Saas;

use App\Models\SaasProduct;

/**
 * Strict, deterministic protocol-v2 staging payload.
 * All supplier variants must be represented or the command fails before any HTTP.
 * "CJ Option" deliberately retains the supplier's complete option label; we do
 * NOT guess which fragments mean Color, Size or Style.
 *
 * Prices are the merchant-confirmed retail price, never supplier cost.
 */
final class SaasBotbleVariantDraftPayload
{
    public const MAX_VARIANTS = 100;
    public const MAX_IMAGES = 20;

    public function make(SaasProduct $product, bool $retailPriceConfirmed): array
    {
        $source = $product->source_variants;
        if (!is_array($source) || !array_is_list($source)
            || count($source) < 1 || count($source) > self::MAX_VARIANTS) {
            throw new SaasBotbleDeliveryException('invalid_variants');
        }
        if (!config('saas.botble_variant_drafts_enabled', false)) {
            throw new SaasBotbleDeliveryException('variant_gate_disabled');
        }
        if (!$retailPriceConfirmed) {
            throw new SaasBotbleDeliveryException('retail_confirmation_required');
        }

        $safe = app(SaasProductHtmlSanitizer::class);
        $price = (string) ($product->sale_price_usd ?: $product->selling_price_usd);
        if (!preg_match('/^[0-9]{1,6}(?:\.[0-9]{1,2})?$/D', $price) || (float) $price <= 0) {
            throw new SaasBotbleDeliveryException('product_not_ready');
        }

        $variants = [];
        $ids = [];
        $skus = [];
        $options = [];

        foreach ($source as $index => $sourceVariant) {
            if (!is_array($sourceVariant)) {
                throw new SaasBotbleDeliveryException('invalid_variants');
            }
            $id = trim((string) ($sourceVariant['id'] ?? ''));
            $sku = trim((string) ($sourceVariant['sku'] ?? ''));
            $option = trim((string) ($sourceVariant['key'] ?? ''));

            if ($id === '' || mb_strlen($id) > 160
                || $sku === '' || mb_strlen($sku) > 180
                || $option === '' || mb_strlen($option) > 100
                || isset($ids[$id]) || isset($skus[$sku]) || isset($options[$option])
                || $sku === $product->sku) {
                throw new SaasBotbleDeliveryException('variant_mapping_required');
            }
            $ids[$id] = true;
            $skus[$sku] = true;
            $options[$option] = true;

            $rawImage = $sourceVariant['image'] ?? null;
            if ($rawImage !== null && $rawImage !== ''
                && (!is_string($rawImage) || !$safe->safeUrl($rawImage))) {
                throw new SaasBotbleDeliveryException('unsafe_variant_image');
            }

            $variant = [
                'source_variant_id' => $id,
                'sku' => $sku,
                'attributes' => ['CJ Option' => $option],
                'price' => $price,
                // CJ product/query does not guarantee available stock; do not
                // invent a quantity or imply anything is in stock.
            ];
            if (filled($rawImage)) {
                $variant['image'] = $rawImage;
            }
            $weight = $sourceVariant['weight_g'] ?? null;
            if ($weight !== null && $weight !== '') {
                if (!preg_match('/^\d{1,8}(?:\.\d{1,3})?$/D', (string) $weight)) {
                    throw new SaasBotbleDeliveryException('invalid_variant_weight');
                }
                $variant['weight_g'] = (string) $weight;
            }
            $variants[] = $variant;
        }

        $gallery = array_values(array_unique(array_filter(array_merge(
            [$product->featured_image_url ?: data_get($product->source_snapshot, 'image_url')],
            $product->gallery_images ?? []
        ))));
        if (count($gallery) > self::MAX_IMAGES) {
            throw new SaasBotbleDeliveryException('too_many_images');
        }
        foreach ($gallery as $url) {
            if (!is_string($url) || !$safe->safeUrl($url)) {
                throw new SaasBotbleDeliveryException('unsafe_variant_image');
            }
        }

        $short = $safe->clean($product->short_description_html);
        $long = $safe->clean($product->long_description_html);
        $payload = [
            'schema_version' => 2,
            'source' => 'cj',
            'source_product_id' => (string) $product->cj_product_id,
            'title' => (string) $product->title,
            'sku' => (string) $product->sku,
            'price' => $price,
            'description' => mb_substr(trim(strip_tags((string) ($long ?: $product->description))), 0, 10000),
            'short_description_html' => $short ?: '',
            'long_description_html' => $long ?: '',
            'seo_title' => (string) ($product->seo_title ?? ''),
            'seo_description' => (string) ($product->seo_description ?? ''),
            'category_name' => (string) ($product->category_name ?? ''),
            'images' => $gallery,
            'variant_count' => count($variants),
            'variants' => $variants,
        ];
        if (filled($product->source_weight_g)) {
            $payload['weight_g'] = (string) $product->source_weight_g;
        }

        return $payload;
    }
}
