<?php

namespace Botble\DatapilotConnector\Services;

use Botble\DatapilotConnector\Models\ConnectorPairing;
use Botble\DatapilotConnector\Models\IncomingDraft;
use Botble\DatapilotConnector\Models\ProductMapping;
use Botble\DatapilotConnector\Models\VariantMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Approval-only native eCommerce importer. No remote endpoint invokes this.
 * Every catalog write is transactional and maps back to the paired source.
 *
 * Media downloads are deliberately excluded until secure Botble media
 * compatibility / public DNS pinning are tested on the target installation.
 */
class CatalogImportService
{
    public function approve(IncomingDraft $incoming): array
    {
        return DB::transaction(function () use ($incoming): array {
            $draft = IncomingDraft::query()->lockForUpdate()->findOrFail($incoming->getKey());
            $pair = ConnectorPairing::query()->lockForUpdate()->findOrFail($draft->pairing_id);

            if ($pair->status !== 'paired' || !$pair->shared_secret) {
                throw new RuntimeException('Connection revoked; reconnect before approval.');
            }
            if (!in_array($draft->status, ['pending_review', 'failed', 'imported_draft'], true)) {
                throw new RuntimeException('This inbox item cannot be approved.');
            }

            $payload = $draft->payload;
            if (!is_array($payload) || ($payload['source'] ?? null) !== 'cj'
                || (string) ($payload['source_product_id'] ?? '') !== $draft->source_product_id) {
                throw new RuntimeException('Invalid or mismatched source payload.');
            }
            // Signed protocol-v2 payloads remain strictly review-only until
            // native Botble variation + media compatibility tests are approved.
            // This guard is independent of the generic catalog-import flag.
            if (($payload['schema_version'] ?? null) === 2) {
                throw new RuntimeException('Native v2 catalog import is not approved for this Botble version.');
            }
            // Never silently discard images or mark a partially imported catalog item synced.
            if (!empty($payload['images']) || !empty($payload['image'])
                || collect($payload['variants'] ?? [])->contains(fn ($v) => !empty($v['image']))) {
                throw new RuntimeException('Image import is not enabled for this Botble version. Draft remains pending review.');
            }

            $this->assertCompatible();

            $sourceId = (string) $payload['source_product_id'];
            $mapping = ProductMapping::query()
                ->where('pairing_id', $pair->id)
                ->where('source', 'cj')
                ->where('source_product_id', $sourceId)
                ->lockForUpdate()
                ->first();

            $productClass = \Botble\Ecommerce\Models\Product::class;
            $product = $mapping ? $productClass::query()->find($mapping->botble_product_id) : null;
            if ($mapping && (!$product || (bool) $product->is_variation)) {
                throw new RuntimeException('Existing mapped product is missing or invalid; manual repair required.');
            }

            $variants = $payload['variants'] ?? [];
            if (!is_array($variants) || count($variants) > 20) {
                throw new RuntimeException('Invalid variation count.');
            }
            if (count($variants) > 0 && empty($payload['sku'])) {
                throw new RuntimeException('Configurable parent SKU is required.');
            }

            $price = $payload['price'] ?? null;
            if ($price === null && count($variants) > 0) {
                $prices = array_column($variants, 'price');
                if (count($prices) === count($variants) && !in_array(null, $prices, true)) {
                    $price = min(array_map('floatval', $prices));
                }
            }
            if ($price === null || !is_numeric($price) || (float) $price < 0) {
                throw new RuntimeException('A reviewed retail price is required; supplier cost cannot be used as a fallback.');
            }

            $sku = trim((string) ($payload['sku'] ?? ''));
            if ($sku === '' && $variants === []) {
                throw new RuntimeException('Product SKU is required.');
            }
            $this->assertSkuAvailable($sku, $product?->getKey());

            if (!$product) {
                $product = new $productClass();
                $product->status = 'draft';
                $product->is_variation = false;
            }
            $product->name = $payload['title'];
            // Treat CJ HTML as plain text until a reviewed sanitizer is available.
            $description = strip_tags((string) ($payload['description'] ?? ''));
            $product->description = $description;
            $product->content = e($description);
            $product->sku = $sku;
            $product->price = $price;
            $product->with_storehouse_management = array_key_exists('quantity', $payload) && $variants === [];
            if ($variants === [] && array_key_exists('quantity', $payload)) {
                $product->quantity = (int) $payload['quantity'];
            }
            $product->save();

            // Categories are native Botble IDs selected/mapped by the store.
            // Reject unknown IDs instead of silently dropping associations.
            if (!empty($payload['category_ids'])) {
                $categoryClass = \Botble\Ecommerce\Models\ProductCategory::class;
                if (!class_exists($categoryClass) || !method_exists($product, 'categories')) {
                    throw new RuntimeException('Category mapping not supported by this Botble version.');
                }
                $ids = array_values(array_unique(array_map('intval', $payload['category_ids'])));
                if ($categoryClass::query()->whereIn('id', $ids)->count() !== count($ids)) {
                    throw new RuntimeException('One or more mapped native category IDs are invalid.');
                }
                $product->categories()->syncWithoutDetaching($ids);
            }

            if (!$mapping) {
                $mapping = ProductMapping::query()->create([
                    'pairing_id' => $pair->id,
                    'source' => 'cj',
                    'source_product_id' => $sourceId,
                    'botble_product_id' => $product->getKey(),
                ]);
            }

            $variantCount = 0;
            $seenIds = [];
            $seenSku = [];
            foreach ($variants as $variant) {
                $id = trim((string) ($variant['source_variant_id'] ?? $variant['sku'] ?? ''));
                $variantSku = trim((string) ($variant['sku'] ?? ''));
                if ($id === '' || $variantSku === '' || isset($seenIds[$id]) || isset($seenSku[$variantSku])) {
                    throw new RuntimeException('Each variation requires a unique source variant ID and SKU.');
                }
                $seenIds[$id] = true;
                $seenSku[$variantSku] = true;
                $this->upsertVariation($product, $mapping, $id, $variant);
                $variantCount++;
            }

            $draft->status = 'imported_draft';
            $draft->save();

            return [
                'product_id' => (int) $product->getKey(),
                'variation_count' => $variantCount,
                'status' => 'imported_draft',
            ];
        }, 3);
    }

    private function upsertVariation(object $parent, ProductMapping $mapping, string $sourceId, array $data): void
    {
        $sku = trim((string) $data['sku']);
        $attributes = $data['attributes'] ?? [];
        if (!is_array($attributes) || $attributes === [] || array_is_list($attributes)) {
            throw new RuntimeException('Each variation requires named Botble attributes (e.g. Color and Size).');
        }

        $mapped = VariantMapping::query()
            ->where('product_mapping_id', $mapping->getKey())
            ->where('source_variant_id', $sourceId)
            ->lockForUpdate()->first();

        $productClass = \Botble\Ecommerce\Models\Product::class;
        $variationClass = \Botble\Ecommerce\Models\ProductVariation::class;
        $child = $mapped ? $productClass::query()->find($mapped->botble_product_id) : null;
        if ($mapped && (!$child || !(bool) $child->is_variation)) {
            throw new RuntimeException('Mapped variation missing; manual repair required.');
        }
        $this->assertSkuAvailable($sku, $child?->getKey());

        $attributeIds = [];
        $setIds = [];
        foreach ($attributes as $setName => $value) {
            if (!is_string($setName) || !is_string($value)
                || trim($setName) === '' || trim($value) === '') {
                throw new RuntimeException('Variation attribute names and values must be nonempty strings.');
            }
            $setClass = \Botble\Ecommerce\Models\ProductAttributeSet::class;
            $attrClass = \Botble\Ecommerce\Models\ProductAttribute::class;
            $set = $setClass::query()->firstOrCreate(
                ['slug' => Str::slug($setName)],
                ['title' => trim($setName), 'status' => 'published', 'order' => 0]
            );
            $attribute = $attrClass::query()->firstOrCreate(
                ['attribute_set_id' => $set->getKey(), 'slug' => Str::slug($value)],
                ['title' => trim($value), 'order' => 0, 'is_default' => false]
            );
            $setIds[] = $set->getKey();
            $attributeIds[] = $attribute->getKey();
        }

        if (!method_exists($parent, 'productAttributeSets')
            || !method_exists($variationClass, 'getVariationByAttributesOrCreate')) {
            throw new RuntimeException('Installed Botble variation API is unsupported; no catalog changes committed.');
        }

        $parent->productAttributeSets()->syncWithoutDetaching(array_unique($setIds));

        // Use Botble's native creation helper to maintain variation pivot/items.
        $result = $variationClass::getVariationByAttributesOrCreate($parent->getKey(), $attributeIds);
        $relation = is_array($result) ? ($result['variation'] ?? null) : null;
        if (!$relation || !$relation->product_id) {
            throw new RuntimeException('Botble did not return a valid variation product.');
        }
        if ($mapped && ((int) $mapped->botble_variation_id !== (int) $relation->getKey()
            || (int) $mapped->botble_product_id !== (int) $relation->product_id)) {
            throw new RuntimeException('Variation attributes no longer match mapping; manual review required.');
        }
        if ($child && (int) $child->getKey() !== (int) $relation->product_id) {
            throw new RuntimeException('Variation is associated with a different native product.');
        }

        $child = $productClass::query()->find($relation->product_id) ?? new $productClass();
        if ($child->exists && !(bool) $child->is_variation && !$mapped) {
            throw new RuntimeException('Variation relation unexpectedly points to a non-variation product.');
        }
        if (!$child->exists) {
            // If the installed Botble version returns a reserved variation ID
            // without a native Product, fail rather than creating a mismatched row.
            throw new RuntimeException('Botble variation API returned a missing native child product.');
        }
        if (!$mapped) {
            // Native helper may create a child with a different default status.
            $child->status = 'draft';
        }
        $child->name = $parent->name;
        $child->sku = $sku;
        $child->price = $data['price'] ?? $parent->price;
        $child->is_variation = true;
        $child->with_storehouse_management = array_key_exists('quantity', $data);
        if (array_key_exists('quantity', $data)) {
            $child->quantity = (int) $data['quantity'];
        }
        $child->save();

        if (!$mapped) {
            VariantMapping::query()->create([
                'product_mapping_id' => $mapping->getKey(),
                'source_variant_id' => $sourceId,
                'botble_product_id' => $child->getKey(),
                'botble_variation_id' => $relation->getKey(),
            ]);
        }
    }

    private function assertSkuAvailable(string $sku, ?int $allowedProductId): void
    {
        if ($sku === '') {
            return;
        }
        $query = \Botble\Ecommerce\Models\Product::query()->where('sku', $sku);
        if ($allowedProductId !== null) {
            $query->whereKeyNot($allowedProductId);
        }
        if ($query->exists()) {
            throw new RuntimeException('SKU is already used by another Botble product; manual mapping required.');
        }
    }

    private function assertCompatible(): void
    {
        foreach ([
            \Botble\Ecommerce\Models\Product::class,
            \Botble\Ecommerce\Models\ProductVariation::class,
            \Botble\Ecommerce\Models\ProductAttributeSet::class,
            \Botble\Ecommerce\Models\ProductAttribute::class,
        ] as $model) {
            if (!class_exists($model)) {
                throw new RuntimeException('Native Botble eCommerce model missing; no product was changed.');
            }
        }
        foreach ([
            'ec_products', 'ec_product_variations', 'ec_product_variation_items',
            'ec_product_attribute_sets', 'ec_product_attributes', 'ec_product_with_attribute_set',
        ] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException('Botble eCommerce schema differs from expected release: '.$table);
            }
        }
    }
}
