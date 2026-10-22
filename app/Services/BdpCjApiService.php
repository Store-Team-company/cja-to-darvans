<?php

namespace App\Services;

use App\Models\BdpApiLog;
use App\Models\BdpCjApiToken;
use App\Models\BdpProduct;
use App\Models\BdpProductVariation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class BdpCjApiService
{
    protected string $baseUrl = 'https://developers.cjdropshipping.com/api2.0/v1';

    public function token(): BdpCjApiToken
    {
        return BdpCjApiToken::current();
    }

    public function saveApiKey(string $apiKey): BdpCjApiToken
    {
        $token = $this->token();
        $token->api_key = trim($apiKey);
        $token->save();
        return $token;
    }

    public function getAccessToken(): array
    {
        $token = $this->token();
        if (! $token->api_key) {
            throw new RuntimeException('CJ API key is missing.');
        }

        $response = Http::timeout(45)->acceptJson()->post($this->baseUrl . '/authentication/getAccessToken', [
            'apiKey' => $token->api_key,
        ]);

        $json = $response->json() ?: [];
        $this->log('POST', '/authentication/getAccessToken', $response->status(), $response->successful(), ['apiKey' => $this->mask($token->api_key)], $json);

        if (! $response->successful() || ! ($json['result'] ?? $json['success'] ?? false)) {
            throw new RuntimeException('CJ token request failed: ' . ($json['message'] ?? $response->body()));
        }

        $data = $json['data'] ?? [];
        $token->update([
            'open_id' => (string) ($data['openId'] ?? ''),
            'access_token' => $data['accessToken'] ?? null,
            'access_token_expires_at' => $this->dateOrNull($data['accessTokenExpiryDate'] ?? null),
            'refresh_token' => $data['refreshToken'] ?? null,
            'refresh_token_expires_at' => $this->dateOrNull($data['refreshTokenExpiryDate'] ?? null),
            'last_response' => json_encode(['result' => (bool) ($json['result'] ?? $json['success'] ?? false), 'message' => $json['message'] ?? null]),
            'last_tested_at' => now(),
        ]);

        return $json;
    }

    public function refreshAccessToken(): array
    {
        $token = $this->token();
        if (! $token->refresh_token) {
            return $this->getAccessToken();
        }

        $response = Http::timeout(45)->acceptJson()->post($this->baseUrl . '/authentication/refreshAccessToken', [
            'refreshToken' => $token->refresh_token,
        ]);

        $json = $response->json() ?: [];
        $this->log('POST', '/authentication/refreshAccessToken', $response->status(), $response->successful(), ['refreshToken' => $this->mask($token->refresh_token)], $json);

        if (! $response->successful() || ! ($json['result'] ?? $json['success'] ?? false)) {
            return $this->getAccessToken();
        }

        $data = $json['data'] ?? [];
        $token->update([
            'access_token' => $data['accessToken'] ?? $token->access_token,
            'access_token_expires_at' => $this->dateOrNull($data['accessTokenExpiryDate'] ?? null),
            'refresh_token' => $data['refreshToken'] ?? $token->refresh_token,
            'refresh_token_expires_at' => $this->dateOrNull($data['refreshTokenExpiryDate'] ?? null),
            'last_response' => json_encode($json),
            'last_tested_at' => now(),
        ]);

        return $json;
    }

    protected function ensureAccessToken(): string
    {
        $token = $this->token();
        if (! $token->access_token || ($token->access_token_expires_at && $token->access_token_expires_at->copy()->subMinutes(5)->isPast())) {
            if ($token->refresh_token && (! $token->refresh_token_expires_at || $token->refresh_token_expires_at->isFuture())) {
                $this->refreshAccessToken();
            } else {
                $this->getAccessToken();
            }
            $token = $token->fresh();
        }

        if (! $token->access_token) {
            throw new RuntimeException('CJ access token is missing.');
        }

        return $token->access_token;
    }

    public function testConnection(): array
    {
        $this->ensureAccessToken();
        return $this->categories();
    }

    public function categories(): array
    {
        return $this->get('/product/getCategory');
    }

    public function searchProducts(array $params): array
    {
        $keyword = trim((string) ($params['keyword'] ?? ''));
        $countryCode = strtoupper(trim((string) ($params['country_code'] ?? '')));
        if (str_contains($countryCode, ',')) {
            // CJ accepts one inventory country code at a time, e.g. US, CN, GB.
            $countryCode = trim(explode(',', $countryCode)[0]);
        }

        $size = min(max((int) ($params['size'] ?? 20), 1), 100);
        $page = max((int) ($params['page'] ?? 1), 1);

        // First try CJ's newer ElasticSearch endpoint. Keep the query minimal because
        // strict filters such as country/price/platform can easily return zero results.
        $v2Query = array_filter([
            'page' => $page,
            'size' => $size,
            'keyWord' => $keyword ?: null,
            'countryCode' => $countryCode ?: null,
            'startSellPrice' => $params['min_price'] ?? null,
            'endSellPrice' => $params['max_price'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $v2 = $this->get('/product/listV2', $v2Query);
        $v2Products = $this->extractSearchProducts($v2);

        if (! empty($v2Products)) {
            $v2['bdp_search_source'] = 'product/listV2';
            $v2['bdp_products'] = $v2Products;
            return $v2;
        }

        // Fallback to older product/list endpoint. In practice CJ web search and API
        // sometimes behave differently, so this gives us a second chance for terms
        // like "pumps" or "women shoes".
        $listQuery = array_filter([
            'pageNum' => $page,
            'pageSize' => min($size, 200),
            'productNameEn' => $keyword ?: null,
            'countryCode' => $countryCode ?: null,
            'startSellPrice' => $params['min_price'] ?? null,
            'endSellPrice' => $params['max_price'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $legacy = $this->get('/product/list', $listQuery);
        $legacy['bdp_search_source'] = 'product/list fallback';
        $legacy['bdp_products'] = $this->extractSearchProducts($legacy);
        $legacy['bdp_first_response'] = [
            'source' => 'product/listV2',
            'query' => $v2Query,
            'message' => $v2['message'] ?? null,
            'totalRecords' => data_get($v2, 'data.totalRecords'),
        ];

        return $legacy;
    }

    public function extractSearchProducts(array $response): array
    {
        $data = $response['data'] ?? [];
        $products = [];

        $content = $data['content'] ?? [];
        if (is_array($content)) {
            foreach ($content as $block) {
                if (isset($block['productList']) && is_array($block['productList'])) {
                    foreach ($block['productList'] as $item) {
                        if (is_array($item)) $products[] = $item;
                    }
                } elseif (is_array($block) && (isset($block['pid']) || isset($block['id']) || isset($block['productSku']) || isset($block['sku']))) {
                    $products[] = $block;
                }
            }
        }

        foreach (['list', 'result', 'records', 'rows'] as $key) {
            if (empty($products) && isset($data[$key]) && is_array($data[$key])) {
                foreach ($data[$key] as $item) {
                    if (is_array($item)) $products[] = $item;
                }
            }
        }

        if (empty($products) && isset($data[0]) && is_array($data[0])) {
            foreach ($data as $item) {
                if (is_array($item)) $products[] = $item;
            }
        }

        return $products;
    }

    public function productDetails(?string $pid = null, ?string $productSku = null, ?string $variantSku = null): array
    {
        $query = array_filter([
            'pid' => $pid,
            'productSku' => $productSku,
            'variantSku' => $variantSku,
            'features' => 'enable_inventory,enable_video,enable_combine,enable_description,enable_category',
        ], fn ($v) => $v !== null && $v !== '');

        return $this->get('/product/query', $query);
    }

    /**
     * CJ product details does not always include a real VID for every variation.
     * CJ's official variant endpoint is the reliable source of VID values.
     */

    public function productReviews(string $pid, ?int $score = null, int $pageNum = 1, int $pageSize = 20): array
    {
        $query = array_filter([
            'pid' => $pid,
            'score' => $score,
            'pageNum' => max(1, $pageNum),
            'pageSize' => min(max(1, $pageSize), 100),
        ], fn ($v) => $v !== null && $v !== '');

        return $this->get('/product/productComments', $query);
    }

    public function productVariants(?string $pid = null, ?string $productSku = null, ?string $variantSku = null, ?string $countryCode = null): array
    {
        $query = array_filter([
            'pid' => $pid,
            'productSku' => $productSku,
            'variantSku' => $variantSku,
            'countryCode' => $countryCode ? strtoupper($countryCode) : null,
        ], fn ($v) => $v !== null && $v !== '');

        if (empty($query)) {
            return [];
        }

        $response = $this->get('/product/variant/query', $query);
        $data = $response['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    public function importProduct(string $pid = null, string $productSku = null, string $variantSku = null): BdpProduct
    {
        $details = $this->productDetails($pid, $productSku, $variantSku);
        $data = $details['data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException('CJ product details response did not include product data.');
        }

        return $this->saveProductFromCj($data);
    }

    protected function saveProductFromCj(array $data): BdpProduct
    {
        $pid = (string) ($data['pid'] ?? $data['id'] ?? '');
        $sku = (string) ($data['productSku'] ?? $data['sku'] ?? $data['spu'] ?? '');
        $name = (string) ($data['productNameEn'] ?? $data['nameEn'] ?? $data['productName'] ?? $sku ?: 'CJ Product');
        $category = (string) ($data['categoryName'] ?? $data['threeCategoryName'] ?? $data['twoCategoryName'] ?? '');
        $images = $this->extractImages($data['productImageSet'] ?? $data['productImage'] ?? $data['bigImage'] ?? []);
        $bigImage = (string) ($data['bigImage'] ?? ($images[0] ?? ''));
        $rawContent = $this->extractDescription($data);
        $content = $this->cleanOrGenerateContent($rawContent, $name, $category);
        $shortDescription = $this->cleanOrGenerateShortDescription($rawContent, $name, $category);
        $price = $this->decimal($data['sellPrice'] ?? $data['nowPrice'] ?? $data['discountPrice'] ?? null);

        $product = BdpProduct::updateOrCreate(
            ['source' => 'cj_api', 'source_id' => $pid ?: $sku],
            [
                'name' => $name,
                'slug' => Str::slug($name),
                'sku' => $sku,
                'short_description' => $shortDescription,
                'content' => $content,
                'description' => strip_tags($content),
                'price' => $price,
                'sale_price' => null,
                'cost_per_item' => $price,
                'stock_status' => 'in_stock',
                'quantity' => (int) ($data['warehouseInventoryNum'] ?? $data['totalVerifiedInventory'] ?? 0) ?: null,
                'categories' => $category,
                'tags' => $this->defaultTags($name, $category),
                'brand' => 'CJ Dropshipping',
                'featured_image' => $bigImage,
                'gallery_images' => implode(',', $images),
                'seo_title' => Str::limit($name . ' | Balenco', 60, ''),
                'seo_description' => Str::limit('Shop ' . $name . ' at Balenco. Stylish women’s shoes in trendy colors and everyday looks.', 155, ''),
                'focus_keyword' => Str::of($name)->lower()->limit(80, ''),
                'status' => 'draft',
                'balenco_sync_status' => 'not_synced',
                'balenco_sync_message' => 'Imported from CJ API. Review before syncing.',
            ]
        );

        $variants = $this->variantsFromProductData($data);
        try {
            // Prefer CJ's official variant endpoint because it returns the real VID required by freight APIs.
            $apiVariants = $this->productVariants($pid ?: null, $sku ?: null, null, null);
            if (!empty($apiVariants)) {
                $variants = $apiVariants;
            }
        } catch (\Throwable $e) {
            // Keep the product import working even if the secondary variant endpoint fails.
        }

        $product->variations()->delete();
        $sort = 0;
        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $colorSize = $this->parseVariantColorSize((string) ($variant['variantKey'] ?? $variant['variantNameEn'] ?? $variant['variantName'] ?? ''));
            $variantPrice = $this->decimal($variant['variantSellPrice'] ?? $variant['variantSugSellPrice'] ?? $price);
            $vid = $this->extractVariantVid($variant);
            $inventoryInfo = $this->inventoryInfoForVariant($variant, $vid);
            $qty = $inventoryInfo['total'] ?? $this->variantInventory($variant);

            BdpProductVariation::create([
                'bdp_product_id' => $product->id,
                'source_id' => $vid ?: (string) ($variant['variantSku'] ?? ''),
                'cj_variant_id' => $vid ?: null,
                'sku' => (string) ($variant['variantSku'] ?? ''),
                'color' => $colorSize['color'],
                'size_raw' => $colorSize['raw_size'],
                'size_us' => $colorSize['us_size'],
                'price' => $variantPrice,
                'sale_price' => null,
                'cost_per_item' => $variantPrice,
                'quantity' => $qty !== null ? max(0, (int) $qty) : (int) config('balenco-data-pilot.default_variation_quantity', 100),
                'cj_inventory_total' => $inventoryInfo['total'] ?? null,
                'cj_inventory_details' => !empty($inventoryInfo['details']) ? json_encode($inventoryInfo['details']) : null,
                'stock_status' => ($qty ?? 0) > 0 ? 'in_stock' : 'out_of_stock',
                'image' => (string) ($variant['variantImage'] ?? ''),
                'weight' => $variant['variantWeight'] ?? null,
                'length' => $variant['variantLength'] ?? null,
                'width' => $variant['variantWidth'] ?? null,
                'height' => $variant['variantHeight'] ?? null,
                'is_default' => $sort === 0,
                'sort_order' => $sort++,
            ]);
        }

        return $product->fresh(['variations']);
    }


    public function refreshInventoryAndShipping(BdpProduct $product, string $countryCode = 'US', int $quantity = 1): array
    {
        $updatedInventory = 0;
        $updatedShipping = 0;
        $errors = [];

        // Repair/hydrate missing CJ VID values first. Freight needs VID, and older imports may only have SKU.
        $this->hydrateMissingVariantIds($product);
        $product->load('variations');

        // Inventory is still variation-level because stock can change by color/size.
        foreach ($product->variations as $variation) {
            try {
                $rawVid = (string) ($variation->cj_variant_id ?: $variation->source_id);
                $vid = $this->isCjVid($rawVid) ? $rawVid : '';
                if ($vid !== '') {
                    $inventory = $this->inventoryInfoByVid($vid);
                    if (!empty($inventory)) {
                        $variation->cj_inventory_total = $inventory['total'] ?? null;
                        $variation->cj_inventory_details = json_encode($inventory['details'] ?? []);
                        if (($inventory['total'] ?? null) !== null) {
                            $variation->quantity = (int) $inventory['total'];
                            $variation->stock_status = $variation->quantity > 0 ? 'in_stock' : 'out_of_stock';
                        }
                        $updatedInventory++;
                    }
                }

                // Shipping is intentionally product-level for shoes. Color/size do not normally
                // change package cost enough to justify 20–60 CJ freight API calls.
                $variation->save();
            } catch (\Throwable $e) {
                $errors[] = 'Inventory variation ' . $variation->id . ': ' . $e->getMessage();
            }
        }

        $product->load('variations');
        $representative = $this->representativeShippingVariation($product);

        if ($representative) {
            try {
                $shipping = $this->shippingEstimateForProduct($product, $representative, $countryCode, $quantity);
                $product->cj_shipping_country = strtoupper($countryCode ?: env('BDP_CJ_DEFAULT_SHIPPING_COUNTRY', 'US'));
                $product->cj_shipping_fee = $shipping['fee'] ?? null;
                $product->cj_shipping_details = json_encode($shipping['details'] ?? []);
                $product->cj_shipping_method = $this->shippingMethodName($shipping['details']['selected_option'] ?? null);
                $baseCost = $product->variations->pluck('price')->filter(fn ($v) => $v !== null && $v !== '')->min() ?: $product->price;
                $product->landed_cost = ($baseCost !== null && ($shipping['fee'] ?? null) !== null)
                    ? round(((float) $baseCost + (float) $shipping['fee']), 2)
                    : null;

                if (($shipping['fee'] ?? null) !== null) {
                    $updatedShipping = 1;
                } elseif (!empty($shipping['details']['error'])) {
                    $errors[] = 'Product shipping: ' . $shipping['details']['error'];
                }
            } catch (\Throwable $e) {
                $errors[] = 'Product shipping: ' . $e->getMessage();
            }
        } else {
            $errors[] = 'Product shipping: no representative variation with CJ VID/SKU was found.';
        }

        $product->forceFill([
            'quantity' => (int) $product->variations()->sum('quantity'),
        ])->save();

        return [
            'inventory' => $updatedInventory,
            'shipping' => $updatedShipping,
            'errors' => $errors,
        ];
    }

    protected function representativeShippingVariation(BdpProduct $product): ?BdpProductVariation
    {
        $variations = $product->variations;

        return $variations
            ->filter(fn ($v) => $this->isCjVid($v->cj_variant_id ?: $v->source_id) && (int) ($v->quantity ?? 0) > 0)
            ->sortBy(fn ($v) => (float) ($v->price ?: PHP_INT_MAX))
            ->first()
            ?: $variations->filter(fn ($v) => $this->isCjVid($v->cj_variant_id ?: $v->source_id))->first()
            ?: $variations->filter(fn ($v) => !empty($v->sku))->first()
            ?: $variations->first();
    }

    protected function shippingMethodName($option): ?string
    {
        if (!is_array($option)) {
            return null;
        }

        foreach (['logisticName', 'logisticNameEn', 'channelName', 'name', 'enName'] as $key) {
            if (!empty($option[$key])) {
                return (string) $option[$key];
            }
        }

        foreach (['option', 'channel', 'logistic', 'logistics'] as $nestedKey) {
            if (!empty($option[$nestedKey]) && is_array($option[$nestedKey])) {
                foreach (['enName', 'name', 'logisticName', 'channelName'] as $key) {
                    if (!empty($option[$nestedKey][$key])) {
                        return (string) $option[$nestedKey][$key];
                    }
                }
            }
        }

        return null;
    }

    public function inventoryInfoByVid(string $vid): array
    {
        if ($vid === '') {
            return [];
        }

        try {
            $response = $this->get('/product/stock/queryByVid', ['vid' => $vid]);
            $data = $response['data'] ?? [];
            return [
                'total' => $this->sumInventory($data),
                'details' => $data,
            ];
        } catch (\Throwable $e) {
            // Some CJ accounts/endpoints return stock inside variant details only. Do not break imports.
            return [];
        }
    }

    protected function inventoryInfoForVariant(array $variant, string $vid): array
    {
        $details = [];
        if (!empty($variant['inventories']) && is_array($variant['inventories'])) {
            $details = $variant['inventories'];
        } elseif (!empty($variant['inventoryList']) && is_array($variant['inventoryList'])) {
            $details = $variant['inventoryList'];
        }

        $total = $this->variantInventory($variant);
        if (($total === null || $total === 0) && $vid !== '' && filter_var(env('BDP_CJ_FETCH_INVENTORY_ON_IMPORT', true), FILTER_VALIDATE_BOOLEAN)) {
            $fresh = $this->inventoryInfoByVid($vid);
            if (!empty($fresh)) {
                return $fresh;
            }
        }

        return [
            'total' => $total,
            'details' => $details,
        ];
    }

    protected function shippingEstimateForProduct(BdpProduct $product, BdpProductVariation $variation, string $countryCode = 'US', int $quantity = 1): array
    {
        $quantity = max(1, (int) $quantity);
        $destination = strtoupper($countryCode ?: env('BDP_CJ_DEFAULT_SHIPPING_COUNTRY', 'US'));
        $origin = strtoupper(env('BDP_CJ_SHIP_FROM_COUNTRY', 'CN'));
        $sku = trim((string) $variation->sku);
        $vid = $this->resolveVariationVid($product, $variation);

        if ($vid === '') {
            return [
                'fee' => null,
                'details' => [
                    'error' => 'Missing real CJ variant ID (VID). Product-level freight needs a CJ VID. Try re-importing this product from CJ API or check API Logs.',
                    'destination' => $destination,
                    'sku' => $sku,
                    'product_source_id' => $product->source_id,
                ],
            ];
        }

        // For shoes we calculate shipping once at parent-product level using one representative VID.
        // The simple freightCalculate endpoint is the closest match to what CJ product pages show
        // for one selected color/size. It is also much more stable than freightCalculateTip.
        $payloads = [
            [
                'path' => '/logistic/freightCalculate',
                'payload' => [
                    'startCountryCode' => $origin,
                    'endCountryCode' => $destination,
                    'products' => [
                        ['quantity' => $quantity, 'vid' => $vid],
                    ],
                ],
            ],
            [
                'path' => '/logistic/partnerFreightCalculate',
                'payload' => [
                    'orderNumber' => 'BDP-' . time() . '-' . $product->id,
                    'shippingZip' => $this->defaultZipForCountry($destination),
                    'shippingCountryCode' => $destination,
                    'shippingCountry' => $this->countryName($destination),
                    'shippingProvince' => $this->defaultProvinceForCountry($destination),
                    'shippingCity' => $this->defaultCityForCountry($destination),
                    'shippingAddress' => '123 Estimate Street',
                    'shippingCustomerName' => 'Balenco Estimate',
                    'shippingPhone' => '1234567890',
                    'remark' => 'Balenco Data Pilot product-level shipping estimate',
                    'fromCountryCode' => $origin,
                    'iossType' => 1,
                    'products' => [
                        ['vid' => $vid, 'quantity' => $quantity],
                    ],
                ],
            ],
        ];

        // Final fallback: CJ's freightCalculateTip endpoint. Keep the JSON shape very close
        // to CJ's official sample to avoid "request parameter is not in a correct JSON format".
        if ($sku !== '') {
            $length = (float) ($variation->length ?: 1);
            $width = (float) ($variation->width ?: 1);
            $height = (float) ($variation->height ?: 1);
            $weightGrams = $this->normalizeWeightToGrams($variation->weight);
            $volume = max(1, round($length * $width * $height, 4));
            $payloads[] = [
                'path' => '/logistic/freightCalculateTip',
                'payload' => [
                    'reqDTOS' => [[
                        'srcAreaCode' => $origin,
                        'destAreaCode' => $destination,
                        'length' => $length,
                        'width' => $width,
                        'height' => $height,
                        'volume' => $volume,
                        'weight' => $weightGrams,
                        'wrapWeight' => $weightGrams,
                        'totalGoodsAmount' => max(0.01, (float) ($variation->price ?: $product->price ?: 1)),
                        'productProp' => ['COMMON'],
                        'freightTrialSkuList' => [[
                            'skuQuantity' => $quantity,
                            'sku' => $sku,
                            'vid' => $vid,
                        ]],
                        'skuList' => [$sku],
                        'platforms' => ['Shopify'],
                    ]],
                ],
            ];
        }

        $errors = [];
        foreach ($payloads as $attempt) {
            try {
                $response = $this->post($attempt['path'], $attempt['payload']);
                $data = $response['data'] ?? $response;
                $best = $this->extractBestShippingOption($data);

                if ($best['fee'] !== null) {
                    return [
                        'fee' => $best['fee'],
                        'details' => [
                            'endpoint' => $attempt['path'],
                            'origin' => $origin,
                            'destination' => $destination,
                            'quantity' => $quantity,
                            'sku' => $sku,
                            'vid' => $vid,
                            'selected_option' => $best['option'],
                            'raw' => $data,
                        ],
                    ];
                }

                $errors[] = $attempt['path'] . ': success but no numeric fee found. Payload=' . json_encode($attempt['payload']);
            } catch (\Throwable $e) {
                $errors[] = $attempt['path'] . ': ' . $e->getMessage();
            }
        }

        return [
            'fee' => null,
            'details' => [
                'error' => implode(' | ', $errors) ?: 'No shipping estimate returned.',
                'origin' => $origin,
                'destination' => $destination,
                'quantity' => $quantity,
                'sku' => $sku,
                'vid' => $vid,
            ],
        ];
    }

    protected function resolveVariationVid(BdpProduct $product, BdpProductVariation $variation): string
    {
        $raw = trim((string) ($variation->cj_variant_id ?: $variation->source_id));
        if ($this->isCjVid($raw)) {
            return $raw;
        }

        $this->hydrateMissingVariantIds($product);
        $variation->refresh();

        $raw = trim((string) ($variation->cj_variant_id ?: $variation->source_id));
        return $this->isCjVid($raw) ? $raw : '';
    }

    protected function hydrateMissingVariantIds(BdpProduct $product): int
    {
        $product->load('variations');

        $needsRepair = $product->variations->contains(function ($variation) {
            return ! $this->isCjVid($variation->cj_variant_id ?: $variation->source_id);
        });

        if (! $needsRepair) {
            return 0;
        }

        $variants = [];
        try {
            $variants = $this->productVariants($product->source_id ?: null, $product->sku ?: null, null, null);
        } catch (\Throwable $e) {
            try {
                $details = $this->productDetails($product->source_id ?: null, $product->sku ?: null, null);
                $variants = $this->variantsFromProductData($details['data'] ?? []);
            } catch (\Throwable $ignored) {
                $variants = [];
            }
        }

        if (empty($variants)) {
            return 0;
        }

        $bySku = [];
        $byKey = [];
        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $vid = $this->extractVariantVid($variant);
            if (! $this->isCjVid($vid)) {
                continue;
            }

            $sku = strtolower(trim((string) ($variant['variantSku'] ?? $variant['sku'] ?? '')));
            if ($sku !== '') {
                $bySku[$sku] = [$vid, $variant];
            }

            $parsed = $this->parseVariantColorSize((string) ($variant['variantKey'] ?? $variant['variantNameEn'] ?? $variant['variantName'] ?? ''));
            $key = strtolower(trim($parsed['color'] . '|' . $parsed['raw_size']));
            if ($key !== '|') {
                $byKey[$key] = [$vid, $variant];
            }
        }

        $repaired = 0;
        foreach ($product->variations as $variation) {
            if ($this->isCjVid($variation->cj_variant_id ?: $variation->source_id)) {
                continue;
            }

            $match = null;
            $sku = strtolower(trim((string) $variation->sku));
            if ($sku !== '' && isset($bySku[$sku])) {
                $match = $bySku[$sku];
            }

            if (! $match) {
                $key = strtolower(trim((string) $variation->color . '|' . (string) $variation->size_raw));
                if (isset($byKey[$key])) {
                    $match = $byKey[$key];
                }
            }

            if ($match) {
                [$vid, $variant] = $match;
                $variation->forceFill([
                    'cj_variant_id' => $vid,
                    'source_id' => $vid,
                    'weight' => $variation->weight ?: ($variant['variantWeight'] ?? null),
                    'length' => $variation->length ?: ($variant['variantLength'] ?? null),
                    'width' => $variation->width ?: ($variant['variantWidth'] ?? null),
                    'height' => $variation->height ?: ($variant['variantHeight'] ?? null),
                ])->save();
                $repaired++;
            }
        }

        return $repaired;
    }

    protected function extractVariantVid(array $variant): string
    {
        foreach (['vid', 'variantId', 'variant_id', 'id'] as $key) {
            $value = trim((string) ($variant[$key] ?? ''));
            if ($this->isCjVid($value)) {
                return $value;
            }
        }

        return '';
    }

    protected function variantsFromProductData(array $data): array
    {
        foreach (['variants', 'variantList', 'variant_list', 'productVariantList'] as $key) {
            if (!empty($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return [];
    }

    protected function extractBestShippingOption($data): array
    {
        $options = [];
        $this->collectShippingOptions($data, $options);

        if (empty($options)) {
            return ['fee' => null, 'option' => null];
        }

        usort($options, fn ($a, $b) => ($a['fee'] <=> $b['fee']));
        return ['fee' => (float) $options[0]['fee'], 'option' => $options[0]['raw']];
    }

    protected function collectShippingOptions($data, array &$options): void
    {
        if (! is_array($data)) {
            return;
        }

        $feeKeys = ['logisticPrice', 'totalPostageFee', 'postageAmount', 'wrapPostage', 'discountFee', 'postage', 'shippingFee', 'freight', 'price', 'fee', 'amount'];

        foreach ($feeKeys as $key) {
            if (array_key_exists($key, $data) && is_numeric($data[$key])) {
                $fee = (float) $data[$key];
                if ($fee > 0) {
                    $options[] = ['fee' => $fee, 'raw' => $data];
                    break;
                }
            }
        }

        foreach ($data as $item) {
            if (is_array($item)) {
                $this->collectShippingOptions($item, $options);
            }
        }
    }

    protected function extractShippingFee($data): ?float
    {
        return $this->extractBestShippingOption($data)['fee'];
    }



    protected function isCjVid(?string $value): bool
    {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }

        // CJ uses both UUID-style VIDs and long numeric VIDs depending on API/product type.
        // Product SKUs usually start with CJ and must not be treated as VIDs.
        if (preg_match('/^CJ/i', $value)) {
            return false;
        }

        return (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value)
            || (bool) preg_match('/^\d{6,30}$/', $value);
    }

    protected function normalizeWeightToGrams($weight): int
    {
        $weight = (float) ($weight ?: 1);
        // CJ product page displays shoe weights like 517g. Some APIs return kg-like decimals.
        if ($weight > 0 && $weight < 10) {
            $weight *= 1000;
        }
        return (int) max(1, round($weight));
    }

    protected function countryName(string $code): string
    {
        return match (strtoupper($code)) {
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'DE' => 'Germany',
            'FR' => 'France',
            default => strtoupper($code),
        };
    }

    protected function defaultZipForCountry(string $code): string
    {
        return match (strtoupper($code)) {
            'US' => '10001',
            'GB' => 'SW1A 1AA',
            'CA' => 'M5V 2T6',
            'AU' => '2000',
            'DE' => '10115',
            'FR' => '75001',
            default => '10001',
        };
    }

    protected function defaultCityForCountry(string $code): string
    {
        return match (strtoupper($code)) {
            'US' => 'New York',
            'GB' => 'London',
            'CA' => 'Toronto',
            'AU' => 'Sydney',
            'DE' => 'Berlin',
            'FR' => 'Paris',
            default => 'New York',
        };
    }

    protected function defaultProvinceForCountry(string $code): string
    {
        return match (strtoupper($code)) {
            'US' => 'New York',
            'GB' => 'England',
            'CA' => 'Ontario',
            'AU' => 'New South Wales',
            'DE' => 'Berlin',
            'FR' => 'Île-de-France',
            default => 'New York',
        };
    }

    protected function variationVolume(BdpProductVariation $variation): float
    {
        $length = (float) ($variation->length ?: 1);
        $width = (float) ($variation->width ?: 1);
        $height = (float) ($variation->height ?: 1);

        return max(1, round($length * $width * $height, 4));
    }

    protected function sumInventory($data): ?int
    {
        if ($data === null || $data === '') return null;
        if (is_numeric($data)) return (int) $data;

        $total = 0;
        $found = false;
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (in_array($key, ['totalInventoryNum', 'totalInventory', 'inventoryNum', 'stock', 'cjInventoryNum', 'factoryInventoryNum'], true) && is_numeric($value)) {
                    $total += (int) $value;
                    $found = true;
                } elseif (is_array($value)) {
                    $nested = $this->sumInventory($value);
                    if ($nested !== null) {
                        $total += $nested;
                        $found = true;
                    }
                }
            }
        }

        return $found ? $total : null;
    }

    protected function get(string $path, array $query = []): array
    {
        $token = $this->ensureAccessToken();
        $response = Http::timeout(60)->acceptJson()->withHeaders([
            'CJ-Access-Token' => $token,
        ])->get($this->baseUrl . $path, $query);
        $json = $response->json() ?: [];
        $this->log('GET', $path, $response->status(), $response->successful(), $query, $json);
        if (! $response->successful() || ! ($json['result'] ?? $json['success'] ?? false)) {
            throw new RuntimeException('CJ API error: ' . ($json['message'] ?? $response->body()));
        }
        return $json;
    }


    protected function post(string $path, array $payload = []): array
    {
        $token = $this->ensureAccessToken();
        $response = Http::timeout(60)->acceptJson()->withHeaders([
            'CJ-Access-Token' => $token,
        ])->post($this->baseUrl . $path, $payload);
        $json = $response->json() ?: [];
        $this->log('POST', $path, $response->status(), $response->successful(), $payload, $json);
        if (! $response->successful() || ! ($json['result'] ?? $json['success'] ?? false)) {
            throw new RuntimeException('CJ API error: ' . ($json['message'] ?? $response->body()));
        }
        return $json;
    }

    protected function log(string $method, string $endpoint, int $status, bool $success, array $request, array $response): void
    {
        try {
            BdpApiLog::create([
                'method' => $method,
                'endpoint' => 'CJ ' . $endpoint,
                'status_code' => $status,
                'authorized' => $success,
                'message' => $success ? 'CJ API call successful.' : 'CJ API call failed.',
                'request_body' => $request,
                'response_body' => $response,
            ]);
        } catch (\Throwable $e) {
            // Never break importing because logging failed.
        }
    }

    protected function dateOrNull(?string $value)
    {
        if (! $value) return null;
        try { return \Carbon\Carbon::parse($value); } catch (\Throwable $e) { return null; }
    }

    protected function mask(?string $value): string
    {
        if (! $value) return '';
        return substr($value, 0, 8) . '...' . substr($value, -6);
    }

    protected function decimal($value): ?float
    {
        if ($value === null || $value === '') return null;
        if (is_string($value) && str_contains($value, '-')) {
            $parts = array_map('trim', explode('-', $value));
            return is_numeric($parts[0] ?? null) ? (float) $parts[0] : null;
        }
        return is_numeric($value) ? (float) $value : null;
    }

    protected function extractImages($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }
        $value = trim((string) $value);
        if ($value === '') return [];
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }


    protected function extractDescription(array $data): string
    {
        foreach (['description', 'productDescription', 'descriptionEn', 'productDescriptionEn', 'content', 'remark'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') return $value;
        }
        return '';
    }

    protected function cleanOrGenerateShortDescription(string $rawContent, string $name, string $category): string
    {
        $plain = trim(html_entity_decode(strip_tags($rawContent)));
        $plain = preg_replace('/\s+/', ' ', $plain ?: '');

        if ($plain !== '' && ! $this->looksLikeSizeChartOnly($plain)) {
            return Str::limit($plain, 300, '');
        }

        $type = $this->guessProductType($name, $category);
        return "Stylish {$type} for women, designed for everyday outfits, office looks, parties, and easy fashion styling. Review colors, sizes, and fit before syncing to Balenco.";
    }

    protected function cleanOrGenerateContent(string $rawContent, string $name, string $category): string
    {
        $plain = trim(html_entity_decode(strip_tags($rawContent)));
        $plain = preg_replace('/\s+/', ' ', $plain ?: '');

        if ($rawContent !== '' && ! $this->looksLikeSizeChartOnly($plain)) {
            return $rawContent;
        }

        $type = $this->guessProductType($name, $category);
        return '<h2>' . e($name) . '</h2>'
            . '<p>Upgrade your everyday style with these women’s ' . e($type) . '. This product was imported from CJ API and should be reviewed before syncing to Balenco.</p>'
            . '<h3>Key Benefits</h3><ul>'
            . '<li>Stylish design for casual, office, party, and everyday outfits</li>'
            . '<li>Available in multiple colors and women’s shoe sizes</li>'
            . '<li>Variation images are kept by color for a better shopping experience</li>'
            . '<li>Inventory and shipping details can be refreshed from CJ before publishing</li>'
            . '</ul><h3>Style Tip</h3>'
            . '<p>Pair these shoes with dresses, skirts, trousers, denim, or smart casual outfits depending on the final product style.</p>';
    }

    protected function looksLikeSizeChartOnly(string $plain): bool
    {
        $text = mb_strtolower($plain);
        $hasSizes = str_contains($text, 'eur') || str_contains($text, 'us/') || str_contains($text, 'size') || str_contains($text, 'yards');
        $hasStory = str_contains($text, 'comfort') || str_contains($text, 'style') || str_contains($text, 'outfit') || str_contains($text, 'heel') || str_contains($text, 'sneaker');
        return $hasSizes && ! $hasStory;
    }

    protected function guessProductType(string $name, string $category): string
    {
        $text = mb_strtolower($name . ' ' . $category);
        foreach (['pumps', 'heels', 'sneakers', 'sandals', 'flats', 'boots'] as $word) {
            if (str_contains($text, $word)) return $word;
        }
        return 'shoes';
    }

    protected function defaultTags(string $name, string $category): string
    {
        $tags = ['women shoes'];
        foreach ([$category, $name] as $text) {
            $text = mb_strtolower($text);
            foreach (['sneakers', 'heels', 'pumps', 'sandals', 'flats', 'boots'] as $word) {
                if (str_contains($text, $word)) $tags[$word] = $word;
            }
        }
        return implode(', ', array_values(array_unique($tags)));
    }

    protected function parseVariantColorSize(string $text): array
    {
        $raw = trim($text);
        $parts = preg_split('/[\-\/|,]+/', $raw) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts)));

        $color = $parts[0] ?? 'Default';
        $size = $parts[1] ?? '';

        foreach ($parts as $part) {
            if (preg_match('/^(US\s*)?\d+(\.5)?$/i', $part) || preg_match('/^(3[5-9]|4[0-4])$/', $part)) {
                $size = $part;
            }
        }

        return [
            'color' => $color ?: 'Default',
            'raw_size' => $size ?: $raw,
            'us_size' => $this->toUsSize($size),
        ];
    }

    protected function toUsSize(?string $size): string
    {
        $size = trim((string) $size);
        if ($size === '') return '';
        if (stripos($size, 'US') === 0) return strtoupper($size);
        $euMap = ['35'=>'US 5','36'=>'US 6','37'=>'US 6.5','38'=>'US 7.5','39'=>'US 8.5','40'=>'US 9','41'=>'US 10','42'=>'US 10.5','43'=>'US 11','44'=>'US 12'];
        return $euMap[$size] ?? ('US ' . $size);
    }

    protected function variantInventory(array $variant): ?int
    {
        if (isset($variant['inventoryNum']) && is_numeric($variant['inventoryNum'])) return (int) $variant['inventoryNum'];
        if (isset($variant['inventory']) && is_numeric($variant['inventory'])) return (int) $variant['inventory'];
        if (!empty($variant['inventories']) && is_array($variant['inventories'])) {
            $total = 0;
            foreach ($variant['inventories'] as $inventory) {
                $total += (int) ($inventory['totalInventory'] ?? $inventory['totalInventoryNum'] ?? 0);
            }
            return $total ?: null;
        }
        return null;
    }
}
