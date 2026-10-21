<?php

namespace App\Services;

use App\Models\BdpProduct;
use App\Models\BdpProductVariation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BdpWooCsvImporter
{
    public function import(UploadedFile $file): array
    {
        $rows = $this->readCsv($file->getRealPath());
        $parentsBySourceId = [];
        $parentsBySku = [];
        $pendingVariations = [];
        $importedProducts = 0;
        $importedVariations = 0;
        $skippedVariations = 0;
        $touchedProductIds = [];

        DB::transaction(function () use ($rows, &$parentsBySourceId, &$parentsBySku, &$pendingVariations, &$importedProducts, &$importedVariations, &$skippedVariations, &$touchedProductIds) {
            foreach ($rows as $row) {
                $type = strtolower(trim($row['Type'] ?? ''));

                if (in_array($type, ['variable', 'simple'], true)) {
                    $product = $this->saveParentProduct($row);

                    $sourceId = trim((string)($row['ID'] ?? ''));
                    $sku = trim((string)($row['SKU'] ?? ''));

                    if ($sourceId !== '') {
                        $parentsBySourceId[$sourceId] = $product;
                    }
                    if ($sku !== '') {
                        $parentsBySku[$sku] = $product;
                    }

                    $importedProducts++;
                } elseif ($type === 'variation') {
                    $pendingVariations[] = $row;
                }
            }

            foreach ($pendingVariations as $row) {
                $parentReference = $this->normalizeParentReference($row['Parent'] ?? '');

                // WooCommerce exports may use either parent ID (example: id:11790)
                // or parent SKU (example: CJNS2832697). Support both.
                $parent = $parentsBySourceId[$parentReference] ?? $parentsBySku[$parentReference] ?? null;

                if (!$parent) {
                    $skippedVariations++;
                    continue;
                }

                $this->saveVariation($parent, $row);
                $touchedProductIds[$parent->id] = $parent->id;
                $importedVariations++;
            }

            foreach ($touchedProductIds as $productId) {
                $this->normalizeVariationImagesByColor((int) $productId);
            }
        });

        return [
            'products' => $importedProducts,
            'variations' => $importedVariations,
            'skipped_variations' => $skippedVariations,
        ];
    }

    private function saveParentProduct(array $row): BdpProduct
    {
        $images = $this->normalizeImages($row['Images'] ?? '');
        $featured = $images[0] ?? null;
        $name = trim($row['Name'] ?? 'Untitled Product');

        return BdpProduct::updateOrCreate(
            ['source' => 'woocommerce', 'source_id' => (string)($row['ID'] ?? '')],
            [
                'name' => $name,
                'slug' => Str::slug($row['Slug'] ?? $name),
                'sku' => trim($row['SKU'] ?? ''),
                'short_description' => $row['Short description'] ?? '',
                'description' => $row['Description'] ?? '',
                'content' => $row['Description'] ?? '',
                'price' => $this->decimal($row['Regular price'] ?? $row['Sale price'] ?? null),
                'sale_price' => $this->decimal($row['Sale price'] ?? null),
                'stock_status' => $this->stockStatus($row),
                'quantity' => $this->quantity($row),
                'categories' => $row['Categories'] ?? config('balenco-data-pilot.default_categories'),
                'tags' => $row['Tags'] ?? '',
                'brand' => config('balenco-data-pilot.default_brand'),
                'featured_image' => $featured,
                'gallery_images' => implode(',', $images),
                'seo_title' => $row['Meta: rank_math_title'] ?? $name,
                'seo_description' => $row['Meta: rank_math_description'] ?? strip_tags($row['Short description'] ?? ''),
                'focus_keyword' => $row['Meta: rank_math_focus_keyword'] ?? '',
                'status' => !empty($row['Published']) ? 'published' : 'draft',
            ]
        );
    }

    private function saveVariation(BdpProduct $product, array $row): BdpProductVariation
    {
        [$color, $size] = $this->extractVariationAttributes($row);
        $images = $this->normalizeImages($row['Images'] ?? '');
        $image = $images[0] ?? $product->featured_image;
        $sourceId = trim((string)($row['ID'] ?? ''));

        if ($sourceId === '') {
            $sourceId = md5(($row['SKU'] ?? '') . '|' . ($color ?? '') . '|' . ($size ?? ''));
        }

        $existing = BdpProductVariation::where('bdp_product_id', $product->id)
            ->where('source_id', $sourceId)
            ->first();

        $sortOrder = $existing?->sort_order ?: ((int) BdpProductVariation::where('bdp_product_id', $product->id)->count() + 1);

        return BdpProductVariation::updateOrCreate(
            ['bdp_product_id' => $product->id, 'source_id' => $sourceId],
            [
                'sku' => $product->sku,
                'barcode' => trim($row['SKU'] ?? ''),
                'color' => $color,
                'size_raw' => $size,
                'size_us' => BdpSizeService::toUsWomen($size),
                'price' => $this->decimal($row['Regular price'] ?? $product->price),
                'sale_price' => $this->decimal($row['Sale price'] ?? null),
                'stock_status' => $this->stockStatus($row),
                'quantity' => $this->quantity($row),
                'image' => $image,
                'gallery_images' => $product->gallery_images,
                'is_default' => $sortOrder === 1,
                'sort_order' => $sortOrder,
            ]
        );
    }


    private function normalizeVariationImagesByColor(int $productId): void
    {
        $variations = BdpProductVariation::where('bdp_product_id', $productId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $colorImages = [];

        foreach ($variations as $variation) {
            $color = strtolower(trim((string) $variation->color));
            $image = trim((string) $variation->image);
            if ($color !== '' && $image !== '' && !isset($colorImages[$color])) {
                $colorImages[$color] = $image;
            }
        }

        foreach ($variations as $variation) {
            $color = strtolower(trim((string) $variation->color));
            if ($color !== '' && isset($colorImages[$color]) && $variation->image !== $colorImages[$color]) {
                $variation->forceFill(['image' => $colorImages[$color]])->save();
            }
        }
    }

    private function extractVariationAttributes(array $row): array
    {
        $color = null;
        $size = null;

        for ($i = 1; $i <= 5; $i++) {
            $name = strtolower(trim($row["Attribute {$i} name"] ?? ''));
            $value = trim($row["Attribute {$i} value(s)"] ?? '');
            if ($value === '') {
                continue;
            }
            if (str_contains($name, 'color') || str_contains($name, 'colour')) {
                $color = $value;
            }
            if (str_contains($name, 'size')) {
                $size = $value;
            }
        }

        return [$color, $size];
    }

    private function normalizeParentReference(string $parent): string
    {
        $parent = trim($parent);
        if (preg_match('/id:(\d+)/', $parent, $m)) {
            return $m[1];
        }
        return $parent;
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return [];
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return [];
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $data[$index] ?? '';
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function normalizeImages(string $images): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $images))));
    }

    private function decimal($value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        return (float) preg_replace('/[^0-9.]/', '', $value);
    }

    private function quantity(array $row): ?int
    {
        // WooCommerce exports quantity in the Stock column only when stock management is enabled.
        // CJA/Woo staging products often have Stock blank but In stock? = 1.
        // In that case, use a safe default quantity so Shofy does not receive empty variation qty.
        $possibleColumns = [
            'Stock',
            'Stock quantity',
            'Quantity',
            'Qty',
            'Inventory',
            'Available Inventory',
        ];

        foreach ($possibleColumns as $column) {
            $value = trim((string)($row[$column] ?? ''));
            if ($value !== '' && is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        $inStock = trim((string)($row['In stock?'] ?? ''));
        if ($inStock === '1' || strtolower($inStock) === 'yes' || strtolower($inStock) === 'instock' || strtolower($inStock) === 'in stock') {
            return (int) config('balenco-data-pilot.default_variation_quantity', 100);
        }

        return 0;
    }

    private function stockStatus(array $row): string
    {
        $stock = strtolower((string)($row['In stock?'] ?? $row['Stock'] ?? ''));
        if ($stock === '0' || $stock === 'outofstock' || $stock === 'out of stock') {
            return 'out_of_stock';
        }
        return 'in_stock';
    }
}
