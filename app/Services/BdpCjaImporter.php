<?php

namespace App\Services;

use App\Models\BdpProduct;
use App\Models\BdpProductVariation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;
use SimpleXMLElement;

class BdpCjaImporter
{
    public function import(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();

        if (in_array($extension, ['xlsx', 'xlsm'], true)) {
            [$rows, $imagesByExcelRow] = $this->readXlsx($path);
        } else {
            $rows = $this->readCsv($path);
            $imagesByExcelRow = [];
        }

        $groups = [];
        foreach ($rows as $rowIndex => $row) {
            $title = trim((string) ($row['Product Title'] ?? ''));
            $variationSku = trim((string) ($row['SKU'] ?? ''));
            $spec = trim((string) ($row['Specification'] ?? ''));

            if ($title === '' || $variationSku === '' || $spec === '') {
                continue;
            }

            $parentSku = $this->parentSkuFromCjaSku($variationSku);
            $groupKey = $parentSku ?: Str::slug($title);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'title' => $title,
                    'parent_sku' => $parentSku ?: $variationSku,
                    'rows' => [],
                ];
            }

            // Excel row number is 1-based. Our rows array index is also set to Excel row number in readXlsx().
            $row['_bdp_image'] = $imagesByExcelRow[$rowIndex] ?? '';
            $groups[$groupKey]['rows'][] = $row;
        }

        $importedProducts = 0;
        $importedVariations = 0;

        DB::transaction(function () use ($groups, &$importedProducts, &$importedVariations) {
            foreach ($groups as $group) {
                $product = $this->saveParentProduct($group);
                $sortOrder = 1;

                // Replace CJA variations for this product so repeated imports stay clean.
                BdpProductVariation::where('bdp_product_id', $product->id)->delete();

                foreach ($group['rows'] as $row) {
                    $this->saveVariation($product, $row, $sortOrder++);
                    $importedVariations++;
                }

                $this->normalizeVariationImagesByColor((int) $product->id);
                $this->updateParentTotals((int) $product->id);
                $importedProducts++;
            }
        });

        return [
            'products' => $importedProducts,
            'variations' => $importedVariations,
            'skipped_variations' => 0,
        ];
    }

    private function saveParentProduct(array $group): BdpProduct
    {
        $title = trim((string) $group['title']);
        $sku = trim((string) $group['parent_sku']);
        $firstRow = $group['rows'][0] ?? [];
        $defaultCategory = config('balenco-data-pilot.default_categories', 'Heels & Pumps');

        return BdpProduct::updateOrCreate(
            ['source' => 'cja', 'source_id' => $sku],
            [
                'name' => $title,
                'slug' => Str::slug($title),
                'sku' => $sku,
                'short_description' => $this->defaultShortDescription($title),
                'description' => '',
                'content' => $this->defaultContent($title),
                'price' => $this->retailPrice($firstRow),
                'sale_price' => null,
                'cost_per_item' => $this->cost($firstRow),
                'stock_status' => 'in_stock',
                'quantity' => 0,
                'categories' => $defaultCategory,
                'tags' => '',
                'brand' => config('balenco-data-pilot.default_brand', 'Balenco'),
                // Parent images are intentionally blank. You will upload/optimize parent gallery manually in Balenco.
                'featured_image' => null,
                'gallery_images' => null,
                'seo_title' => $title . ' | Balenco',
                'seo_description' => 'Shop ' . $title . ' at Balenco. Stylish women’s shoes in trendy colors and easy everyday looks.',
                'focus_keyword' => Str::lower($title),
                'status' => 'published',
            ]
        );
    }

    private function saveVariation(BdpProduct $product, array $row, int $sortOrder): BdpProductVariation
    {
        [$color, $size] = $this->splitSpecification((string) ($row['Specification'] ?? ''));
        $variationSku = trim((string) ($row['SKU'] ?? ''));
        $sourceId = $variationSku ?: md5($product->sku . '|' . $color . '|' . $size);

        return BdpProductVariation::updateOrCreate(
            ['bdp_product_id' => $product->id, 'source_id' => $sourceId],
            [
                'sku' => $product->sku,
                'barcode' => $variationSku,
                'color' => $color,
                'size_raw' => $size,
                'size_us' => BdpSizeService::toUsWomen($size),
                'price' => $this->retailPrice($row),
                'sale_price' => null,
                'cost_per_item' => $this->cost($row),
                'quantity' => $this->quantity($row),
                'stock_status' => $this->stockStatus($row),
                'image' => trim((string) ($row['_bdp_image'] ?? '')),
                'gallery_images' => null,
                'is_default' => $sortOrder === 1,
                'sort_order' => $sortOrder,
            ]
        );
    }

    private function updateParentTotals(int $productId): void
    {
        $product = BdpProduct::find($productId);
        if (!$product) {
            return;
        }

        $minPrice = BdpProductVariation::where('bdp_product_id', $productId)->whereNotNull('price')->min('price');
        $quantity = BdpProductVariation::where('bdp_product_id', $productId)->sum('quantity');

        $product->forceFill([
            'price' => $minPrice ?: $product->price,
            'quantity' => (int) $quantity,
        ])->save();
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

    private function splitSpecification(string $specification): array
    {
        $parts = array_map('trim', explode('/', $specification));
        $color = $parts[0] ?? null;
        $size = $parts[1] ?? null;

        if ($color !== null && $color !== '') {
            $color = Str::of($color)->replace(['_', '-'], ' ')->title()->toString();
        }

        return [$color ?: null, $size ?: null];
    }

    private function parentSkuFromCjaSku(string $sku): string
    {
        $sku = trim($sku);
        // CJA variation SKUs often look like CJNS283269721UF, where 21UF is the variant suffix.
        if (preg_match('/^(.+?)(\d{2}[A-Z]{2})$/i', $sku, $m)) {
            return strtoupper($m[1]);
        }

        return $sku;
    }

    private function retailPrice(array $row): ?float
    {
        $base = $this->cost($row);
        if ($base === null) {
            return null;
        }

        $multiplier = (float) env('BDP_CJA_PRICE_MULTIPLIER', 1);
        $add = (float) env('BDP_CJA_PRICE_ADD', 0);
        $min = (float) env('BDP_CJA_MIN_PRICE', 0);

        $price = ($base * max(0, $multiplier)) + $add;
        if ($min > 0) {
            $price = max($price, $min);
        }

        if (filter_var(env('BDP_CJA_ROUND_TO_99', false), FILTER_VALIDATE_BOOLEAN)) {
            $price = floor($price) + 0.99;
        }

        return round($price, 2);
    }

    private function cost(array $row): ?float
    {
        return $this->decimal($row['Total Cost ($)'] ?? $row['Product Base Price ($)'] ?? null);
    }

    private function quantity(array $row): int
    {
        foreach (['Available Inventory', 'Inventory', 'CJ Inventory (Total)', 'Factory Inventory (Total)'] as $column) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '' && is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        return (int) config('balenco-data-pilot.default_variation_quantity', 100);
    }

    private function stockStatus(array $row): string
    {
        $status = strtolower(trim((string) ($row['Product Status'] ?? '')));
        if (str_contains($status, 'off') || str_contains($status, 'out')) {
            return 'out_of_stock';
        }

        return $this->quantity($row) > 0 ? 'in_stock' : 'out_of_stock';
    }

    private function defaultShortDescription(string $title): string
    {
        return 'Stylish ' . $title . ' for women, available in multiple colors and US shoe sizes.';
    }

    private function defaultContent(string $title): string
    {
        return '<h2>' . e($title) . '</h2>\n<p>Upgrade your everyday look with ' . e($title) . ' from Balenco. Review available colors and sizes before syncing to Balenco.</p>';
    }

    private function decimal($value): ?float
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '--') {
            return null;
        }
        return (float) preg_replace('/[^0-9.]/', '', $value);
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
        $rowNumber = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $data[$index] ?? '';
            }
            $rows[$rowNumber] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [[], []];
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            $zip->close();
            return [[], []];
        }

        $rows = $this->parseSheetRows($sheetXml, $sharedStrings);
        $imagesByExcelRow = $this->extractImagesByRow($zip);
        $zip->close();

        return [$rows, $imagesByExcelRow];
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $strings = [];
        $sx = simplexml_load_string($xml);
        if (!$sx) {
            return [];
        }

        foreach ($sx->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
            } else {
                $text = '';
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
                $strings[] = $text;
            }
        }

        return $strings;
    }

    private function parseSheetRows(string $sheetXml, array $sharedStrings): array
    {
        $sx = simplexml_load_string($sheetXml);
        if (!$sx) {
            return [];
        }

        $rawRows = [];
        $headers = [];

        foreach ($sx->sheetData->row as $rowNode) {
            $rowNumber = (int) $rowNode['r'];
            $rowValues = [];
            foreach ($rowNode->c as $cell) {
                $cellRef = (string) $cell['r'];
                $column = preg_replace('/\d+/', '', $cellRef);
                $index = $this->columnIndex($column);
                $rowValues[$index] = $this->cellValue($cell, $sharedStrings);
            }

            if ($rowNumber === 1) {
                ksort($rowValues);
                $headers = array_values($rowValues);
                continue;
            }

            if (empty($headers)) {
                continue;
            }

            $row = [];
            foreach ($headers as $i => $header) {
                $row[(string) $header] = $rowValues[$i] ?? '';
            }
            $rawRows[$rowNumber] = $row;
        }

        return $rawRows;
    }

    private function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];
        if ($type === 's') {
            $index = (int) $cell->v;
            return (string) ($sharedStrings[$index] ?? '');
        }
        if ($type === 'inlineStr') {
            return (string) ($cell->is->t ?? '');
        }

        return (string) ($cell->v ?? '');
    }

    private function columnIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $num = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $num = $num * 26 + (ord($letters[$i]) - 64);
        }
        return $num - 1;
    }

    private function extractImagesByRow(ZipArchive $zip): array
    {
        $drawingXml = $zip->getFromName('xl/drawings/drawing1.xml');
        $relsXml = $zip->getFromName('xl/drawings/_rels/drawing1.xml.rels');
        if ($drawingXml === false || $relsXml === false) {
            return [];
        }

        $rels = $this->parseDrawingRels($relsXml);
        $sx = simplexml_load_string($drawingXml);
        if (!$sx) {
            return [];
        }

        $sx->registerXPathNamespace('xdr', 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing');
        $sx->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $sx->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $images = [];
        foreach ($sx->xpath('//xdr:twoCellAnchor') as $anchor) {
            $anchor->registerXPathNamespace('xdr', 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing');
            $anchor->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $anchor->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

            $rowNodes = $anchor->xpath('./xdr:from/xdr:row');
            $blipNodes = $anchor->xpath('.//a:blip');
            if (empty($rowNodes) || empty($blipNodes)) {
                continue;
            }

            $excelRow = ((int) $rowNodes[0]) + 1;
            $attributes = $blipNodes[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid = (string) ($attributes['embed'] ?? '');
            $target = $rels[$rid] ?? null;
            if (!$target) {
                continue;
            }

            $mediaPath = 'xl/' . ltrim(str_replace('../', '', $target), '/');
            $content = $zip->getFromName($mediaPath);
            if ($content === false) {
                continue;
            }

            $images[$excelRow] = $this->storeExtractedImage($content, $mediaPath);
        }

        return $images;
    }

    private function parseDrawingRels(string $relsXml): array
    {
        $rels = [];
        $sx = simplexml_load_string($relsXml);
        if (!$sx) {
            return $rels;
        }

        $relationships = $sx->children('http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($relationships->Relationship as $relationship) {
            $rels[(string) $relationship['Id']] = (string) $relationship['Target'];
        }

        return $rels;
    }

    private function storeExtractedImage(string $content, string $originalPath): string
    {
        $extension = strtolower(pathinfo($originalPath, PATHINFO_EXTENSION)) ?: 'jpg';
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $hash = sha1($content);
        $relativeDir = 'storage/balenco-data-pilot/cja-images';
        $publicDir = public_path($relativeDir);
        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0775, true);
        }

        $filename = $hash . '.' . $extension;
        $fullPath = $publicDir . DIRECTORY_SEPARATOR . $filename;
        if (!file_exists($fullPath)) {
            file_put_contents($fullPath, $content);
        }

        return rtrim((string) config('app.url'), '/') . '/' . $relativeDir . '/' . $filename;
    }
}
