<?php

namespace Botble\DatapilotConnector\Services;

/**
 * Read-only, schema-only gate for the future native draft import envelope.
 *
 * Never resolves external URLs, creates products, writes database rows, uploads
 * media, or grants approval. Native import remains separately hard-disabled.
 */
final class NativeDraftPreflight
{
    public const MAX_VARIANTS = 100;
    public const MAX_GALLERY = 20;

    /**
     * @return array{ready_for_runtime_audit:bool,errors:list<string>,variation_count:int,gallery_count:int}
     */
    public function inspect(array $data): array
    {
        $errors = [];
        if (($data['schema_version'] ?? null) !== 3) {
            $errors[] = 'requires_v3_regular_and_sale_price_contract';
        }
        if (($data['source'] ?? null) !== 'cj') {
            $errors[] = 'unknown_source';
        }
        foreach (['source_product_id', 'title', 'sku'] as $required) {
            if (!is_string($data[$required] ?? null) || trim($data[$required]) === '') {
                $errors[] = 'missing_'.$required;
            }
        }
        if (($data['currency_code'] ?? null) !== 'USD') {
            $errors[] = 'currency_requires_review';
        }
        $regular = $this->amount($data['regular_price'] ?? null);
        $sale = array_key_exists('sale_price', $data) && $data['sale_price'] !== null
            ? $this->amount($data['sale_price'])
            : null;
        if ($regular === null || $regular <= 0) {
            $errors[] = 'missing_regular_retail_price';
        }
        if (array_key_exists('sale_price', $data) && $data['sale_price'] !== null) {
            if ($sale === null || $sale <= 0 || $regular === null || $sale >= $regular) {
                $errors[] = 'invalid_sale_retail_price';
            }
        }

        $variants = $data['variants'] ?? null;
        if (!is_array($variants) || !array_is_list($variants)
            || count($variants) < 1 || count($variants) > self::MAX_VARIANTS
            || !is_int($data['variant_count'] ?? null)
            || count($variants) !== $data['variant_count']) {
            $errors[] = 'incomplete_variants';
            $variants = [];
        }
        $sourceIds = [];
        $skus = [];
        $options = [];
        foreach ($variants as $index => $variant) {
            if (!is_array($variant)) {
                $errors[] = 'invalid_variant_'.$index;
                continue;
            }
            $sourceId = $variant['source_variant_id'] ?? null;
            $sku = $variant['sku'] ?? null;
            $option = $variant['attributes']['CJ Option'] ?? null;
            if (!is_array($variant['attributes'] ?? null)
                || count($variant['attributes']) !== 1
                || !is_string($sourceId) || trim($sourceId) === ''
                || !is_string($sku) || trim($sku) === ''
                || !is_string($option) || trim($option) === ''
                || isset($sourceIds[$sourceId]) || isset($skus[$sku])
                || isset($options[$option]) || $sku === ($data['sku'] ?? null)) {
                $errors[] = 'invalid_variant_mapping_'.$index;
                continue;
            }
            $sourceIds[$sourceId] = true;
            $skus[$sku] = true;
            $options[$option] = true;
            $variantRegular = $this->amount($variant['regular_price'] ?? null);
            $variantSale = array_key_exists('sale_price', $variant) && $variant['sale_price'] !== null
                ? $this->amount($variant['sale_price'])
                : null;
            if ($variantRegular === null || $variantRegular <= 0
                || (array_key_exists('sale_price', $variant) && $variant['sale_price'] !== null
                    && ($variantSale === null || $variantSale <= 0 || $variantSale >= $variantRegular))) {
                $errors[] = 'invalid_variant_retail_price_'.$index;
            }
            if (!$this->httpsImage($variant['image'] ?? null)) {
                $errors[] = 'missing_or_unsafe_variant_image_'.$index;
            }
            // Never infer stock availability. A native draft must remain
            // unpurchasable until reviewed inventory is separately imported.
            if (array_key_exists('quantity', $variant)
                || array_key_exists('stock_status', $variant)
                || array_key_exists('supplier_price', $variant)
                || array_key_exists('cost_price', $variant)) {
                $errors[] = 'unapproved_inventory_or_supplier_cost_'.$index;
            }
        }

        $images = $data['images'] ?? null;
        if (!is_array($images) || !array_is_list($images)
            || count($images) < 1 || count($images) > self::MAX_GALLERY
            || count(array_filter($images, fn (mixed $url): bool => !$this->httpsImage($url))) > 0
            || count(array_filter($images, 'is_string')) !== count($images)
            || count($images) !== count(array_unique($images))) {
            $errors[] = 'invalid_gallery';
            $images = [];
        }
        if (array_key_exists('publish', $data)
            || array_key_exists('quantity', $data)
            || array_key_exists('stock_status', $data)
            || array_key_exists('supplier_price', $data)
            || array_key_exists('cost_price', $data)) {
            $errors[] = 'unapproved_catalog_fields';
        }

        return [
            'ready_for_runtime_audit' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'variation_count' => count($variants),
            'gallery_count' => count($images),
        ];
    }

    private function amount(mixed $number): ?float
    {
        if ((!is_string($number) && !is_int($number) && !is_float($number))
            || !preg_match('/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?$/D', (string) $number)) {
            return null;
        }

        return (float) $number;
    }

    private function httpsImage(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) > 2048) {
            return false;
        }
        $parts = parse_url($value);
        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null) && $parts['host'] !== ''
            && !isset($parts['user']) && !isset($parts['pass']);
    }
}
