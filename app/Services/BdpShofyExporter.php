<?php

namespace App\Services;

use App\Models\BdpProduct;
use Illuminate\Support\Str;

class BdpShofyExporter
{
    public function stream(array $productIds)
    {
        $filename = 'balenco-data-pilot-shofy-export-' . now()->format('Y-m-d-H-i-s') . '.csv';
        $headers = config('balenco-data-pilot.shofy_headers');

        return response()->streamDownload(function () use ($productIds, $headers) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, $headers);

            BdpProduct::with('variations')->whereIn('id', $productIds)->chunk(50, function ($products) use ($out, $headers) {
                foreach ($products as $product) {
                    fputcsv($out, $this->row($headers, $this->productRow($product)));

                    $order = 1;
                    foreach ($product->variations as $variation) {
                        fputcsv($out, $this->row($headers, $this->variationRow($product, $variation, $order)));
                        $order++;
                    }

                    $product->forceFill(['last_exported_at' => now()])->save();
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function productRow(BdpProduct $product): array
    {
        $price = $this->price($product->sale_price ?: $product->price);

        return [
            'ID' => '',
            'Name' => $product->name,
            'Description' => $product->short_description,
            'Slug' => $product->slug ?: Str::slug($product->name),
            'URL' => '',
            'SKU' => $product->sku,
            'Categories' => $product->categories,
            'Status' => $product->status ?: 'published',
            'Is Featured' => $product->is_featured ? 'TRUE' : 'False',
            'Brand' => $product->brand ?: config('balenco-data-pilot.default_brand'),
            'Product Collections' => '',
            'Labels' => 'New',
            'Taxes' => '',
            'Image' => $product->featured_image,
            'Images' => $product->gallery_images,
            'Price' => $price,
            'Product Attributes' => 'Color,Size',
            'Import Type' => 'product',
            'Is Variation Default' => '',
            'Stock Status' => $product->stock_status ?: 'in_stock',
            'With Storehouse Management' => '1',
            'Quantity' => $product->quantity,
            'Allow Checkout When Out Of Stock' => '0',
            'Sale Price' => $product->sale_price ?: '',
            'Start Date Sale Price' => '',
            'End Date Sale Price' => '',
            'Weight' => '',
            'Length' => '',
            'Wide' => '',
            'Height' => '',
            'Cost Per Item' => $product->cost_per_item ?: '',
            'Barcode' => '',
            'Content' => $product->content ?: $product->description,
            'Tags' => $product->tags,
            'Generate License Code' => '0',
            'Minimum Order Quantity' => '',
            'Maximum Order Quantity' => '',
            'Order' => '1',
        ];
    }

    private function variationRow(BdpProduct $product, $variation, int $order): array
    {
        $size = config('balenco-data-pilot.size_label_mode') === 'raw'
            ? $variation->size_raw
            : ($variation->size_us ?: $variation->size_raw);

        $attributes = 'Color:' . $variation->color . ',Size:' . $size;
        $price = $this->price($variation->sale_price ?: $variation->price ?: $product->price);

        return [
            'ID' => '',
            'Name' => $product->name,
            'Description' => '',
            'Slug' => '',
            'URL' => '',
            'SKU' => $product->sku,
            'Categories' => '',
            'Status' => 'published',
            'Is Featured' => '',
            'Brand' => '',
            'Product Collections' => '',
            'Labels' => '',
            'Taxes' => '',
            'Image' => $variation->image ?: $product->featured_image,
            'Images' => $variation->gallery_images ?: $product->gallery_images,
            'Price' => $price,
            'Product Attributes' => $attributes,
            'Import Type' => 'variation',
            'Is Variation Default' => $variation->is_default ? '1' : '',
            'Stock Status' => $variation->stock_status ?: 'in_stock',
            'With Storehouse Management' => '1',
            'Quantity' => $variation->quantity,
            'Allow Checkout When Out Of Stock' => '0',
            'Sale Price' => $variation->sale_price ?: '',
            'Start Date Sale Price' => '',
            'End Date Sale Price' => '',
            'Weight' => '',
            'Length' => '',
            'Wide' => '',
            'Height' => '',
            'Cost Per Item' => $variation->cost_per_item ?: '',
            'Barcode' => $variation->barcode ?: $variation->sku,
            'Content' => '',
            'Tags' => '',
            'Generate License Code' => '0',
            'Minimum Order Quantity' => '',
            'Maximum Order Quantity' => '',
            'Order' => (string) $order,
        ];
    }

    private function row(array $headers, array $data): array
    {
        return array_map(fn($header) => $data[$header] ?? '', $headers);
    }

    private function price($price): string
    {
        $price = (float) $price;
        $markup = (float) config('balenco-data-pilot.price_markup_percent', 0);
        if ($markup > 0) {
            $price += ($price * $markup / 100);
        }
        if (config('balenco-data-pilot.round_price_to_99')) {
            $price = floor($price) + 0.99;
        }
        return number_format($price, 2, '.', '');
    }
}
