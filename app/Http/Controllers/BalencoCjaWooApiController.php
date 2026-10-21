<?php

namespace App\Http\Controllers;

use App\Models\BdpApiLog;
use App\Models\BdpProduct;
use App\Models\BdpProductVariation;
use App\Services\BdpSizeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BalencoCjaWooApiController extends Controller
{

    public function wcAuthorize(Request $request)
    {
        $callbackUrl = (string) $request->query('callback_url', $request->input('callback_url', ''));
        $returnUrl = (string) $request->query('return_url', $request->input('return_url', ''));
        $appName = (string) $request->query('app_name', $request->input('app_name', 'CJ Dropshipping'));
        $scope = (string) $request->query('scope', $request->input('scope', 'read_write'));
        $userId = (string) $request->query('user_id', $request->input('user_id', 'cja'));

        $key = (string) config('balenco-data-pilot.cja_consumer_key');
        $secret = (string) config('balenco-data-pilot.cja_consumer_secret');

        $payload = [
            'key_id' => 1,
            'user_id' => $userId ?: 'cja',
            'consumer_key' => $key,
            'consumer_secret' => $secret,
            'key_permissions' => $scope ?: 'read_write',
        ];

        BdpApiLog::create([
            'source' => 'cja_wc_auth',
            'method' => $request->method(),
            'path' => '/' . trim($request->path(), '/'),
            'endpoint' => 'wc-auth/v1/authorize',
            'ip' => $request->ip(),
            'authorized' => true,
            'status_code' => 200,
            'request_headers' => $this->safeHeaders($request),
            'request_body' => $this->safeRequestBody($request),
            'response_body' => ['callback_url' => $callbackUrl, 'return_url' => $returnUrl, 'payload' => $payload],
            'note' => 'WooCommerce auth page opened by CJA Default connection.',
        ]);

        if ($callbackUrl === '') {
            return response()->json([
                'code' => 'missing_callback_url',
                'message' => 'Missing callback_url. Balenco Data Pilot WooCommerce auth endpoint is ready.',
                'data' => $payload,
            ], 200);
        }

        $host = $request->getHost();
        $siteUrl = 'https://' . $host;
        $safeAppName = e($appName ?: 'CJ Dropshipping');
        $approveUrl = e(url('/wc-auth/v1/authorize/approve'));

        $csrfToken = csrf_token();

        $hidden = [
            '_token' => $csrfToken,
            'callback_url' => $callbackUrl,
            'return_url' => $returnUrl,
            'app_name' => $appName ?: 'CJDropshipping',
            'scope' => $scope ?: 'read_write',
            'user_id' => $userId ?: 'cja',
            'site_url' => $siteUrl,
            'domain' => $host,
        ];
        $inputs = '';
        foreach ($hidden as $name => $value) {
            $inputs .= '<input type="hidden" name="' . e($name) . '" value="' . e((string) $value) . '">' . "\n";
        }

        // WooCommerce's official app auth flow does two things after approve:
        // 1) Server posts the generated keys to callback_url.
        // 2) Browser redirects to return_url with success indicators.
        // CJA accepted our callback JSON, but did not show the store until the return step is completed.
        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connect {$safeAppName} - Balenco Data Pilot</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f6fb;margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;color:#10233f}
        .card{background:#fff;border:1px solid #e3e7ef;border-radius:14px;box-shadow:0 8px 30px rgba(16,35,63,.08);max-width:560px;padding:28px;text-align:center}
        h1{font-size:24px;margin:0 0 10px}.muted{color:#5b667a;font-size:14px;line-height:1.5}.btn{border:0;background:#ff6b18;color:#fff;border-radius:8px;padding:13px 22px;font-size:16px;cursor:pointer;margin-top:18px}.btn[disabled]{opacity:.7;cursor:wait}.small{font-size:12px;color:#7a8496;word-break:break-all;margin-top:16px}.secondary{display:block;margin-top:12px;color:#10233f;text-decoration:none;font-size:13px}
    </style>
</head>
<body>
    <div class="card">
        <h1>Authorize {$safeAppName}</h1>
        <p class="muted">Balenco Data Pilot is ready to connect as a WooCommerce-compatible store. Click approve to send the API keys back to CJA.</p>
        <p id="bdp-status" class="muted"></p>
        <form id="bdp-auth-form" action="{$approveUrl}" method="post">
            {$inputs}
            <button id="bdp-approve-btn" class="btn" type="submit">Approve Connection</button>
        </form>
        <p class="small">Callback: {$callbackUrl}</p>
        <a class="secondary" href="{$returnUrl}">Return without approving</a>
    </div>
    <script>
        document.getElementById('bdp-auth-form').addEventListener('submit', function () {
            var btn = document.getElementById('bdp-approve-btn');
            btn.disabled = true;
            btn.textContent = 'Approving...';
            document.getElementById('bdp-status').textContent = 'Sending credentials to CJA and returning you to CJ Dropshipping...';
        });
    </script>
</body>
</html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function wcAuthorizeApprove(Request $request)
    {
        $callbackUrl = (string) $request->input('callback_url', '');
        $returnUrl = (string) $request->input('return_url', '');
        $scope = (string) $request->input('scope', 'read_write');
        $userId = (string) $request->input('user_id', 'cja');

        // Exact WooCommerce WC-Auth callback payload. Keep this minimal because
        // CJA appears to reject/ignore non-standard extra fields.
        $payload = [
            'key_id' => 1,
            'user_id' => $userId ?: 'cja',
            'consumer_key' => (string) config('balenco-data-pilot.cja_consumer_key'),
            'consumer_secret' => (string) config('balenco-data-pilot.cja_consumer_secret'),
            'key_permissions' => $scope ?: 'read_write',
        ];

        $callbackStatus = null;
        $callbackBody = null;
        $callbackSuccess = false;
        $callbackError = null;

        if ($callbackUrl !== '') {
            try {
                $response = Http::asForm()
                    ->timeout(20)
                    ->post($callbackUrl, $payload);

                $callbackStatus = $response->status();
                $callbackBody = $response->body();
                $json = $response->json();

                $callbackSuccess = $response->successful();
                if (is_array($json) && array_key_exists('success', $json)) {
                    $callbackSuccess = $callbackSuccess && (bool) $json['success'];
                }
            } catch (\Throwable $e) {
                $callbackError = $e->getMessage();
                $callbackSuccess = false;
            }
        }

        $finalReturnUrl = $returnUrl;
        if ($finalReturnUrl !== '') {
            $separator = str_contains($finalReturnUrl, '?') ? '&' : '?';
            // WooCommerce core returns only success and user_id. Avoid custom CJ
            // parameters because they previously caused CJ to show Authorization Failed.
            $finalReturnUrl .= $separator . http_build_query([
                'success' => $callbackSuccess ? '1' : '0',
                'user_id' => $userId ?: 'cja',
            ]);
        }

        BdpApiLog::create([
            'source' => 'cja_wc_auth_approve',
            'method' => $request->method(),
            'path' => '/' . trim($request->path(), '/'),
            'endpoint' => 'wc-auth/v1/authorize/approve',
            'ip' => $request->ip(),
            'authorized' => true,
            'status_code' => $callbackSuccess ? 200 : 502,
            'request_headers' => $this->safeHeaders($request),
            'request_body' => $this->safeRequestBody($request),
            'response_body' => [
                'callback_url' => $callbackUrl,
                'return_url' => $returnUrl,
                'final_return_url' => $finalReturnUrl,
                'payload_sent_to_cja' => $payload,
                'cja_callback_status' => $callbackStatus,
                'cja_callback_body' => $callbackBody,
                'cja_callback_error' => $callbackError,
                'cja_callback_success' => $callbackSuccess,
            ],
            'note' => 'Posted exact WooCommerce WC-Auth payload to CJA callback and redirected to return_url.',
        ]);

        if ($callbackUrl === '') {
            return response()->json([
                'success' => false,
                'message' => 'Missing callback_url.',
                'payload' => $payload,
            ], 422);
        }

        if ($finalReturnUrl !== '') {
            return redirect()->away($finalReturnUrl);
        }

        return response()->json([
            'success' => $callbackSuccess,
            'message' => $callbackSuccess ? 'Authorization sent to CJA.' : 'Authorization callback failed.',
            'callback_status' => $callbackStatus,
            'callback_body' => $callbackBody,
            'callback_error' => $callbackError,
        ], $callbackSuccess ? 200 : 502);
    }

    public function handle(Request $request, ?string $endpoint = null): JsonResponse
    {
        $endpoint = trim((string) $endpoint, '/');
        $authorized = $this->isAuthorized($request);

        if (!$authorized) {
            return $this->respond($request, $endpoint, 401, [
                'code' => 'woocommerce_rest_authentication_error',
                'message' => 'Invalid consumer key or secret.',
                'data' => ['status' => 401],
            ], false, 'Unauthorized request.');
        }

        try {
            $method = strtoupper($request->method());

            if ($endpoint === '' || $endpoint === 'system_status') {
                return $this->respond($request, $endpoint, 200, $this->systemStatus(), true, 'Connection test response.');
            }

            if ($method === 'GET' && $endpoint === 'products/categories') {
                return $this->respond($request, $endpoint, 200, [], true, 'Category list placeholder.');
            }

            if ($method === 'GET' && $endpoint === 'products/attributes') {
                return $this->respond($request, $endpoint, 200, [
                    ['id' => 1, 'name' => 'Color', 'slug' => 'pa_color', 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => true],
                    ['id' => 2, 'name' => 'Size', 'slug' => 'pa_size', 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => true],
                ], true, 'Attributes list placeholder.');
            }

            if ($method === 'GET' && $endpoint === 'products') {
                $products = BdpProduct::query()->latest('id')->limit((int) $request->query('per_page', 20))->get();
                return $this->respond($request, $endpoint, 200, $products->map(fn (BdpProduct $p) => $this->wooProductResponse($p))->values()->all(), true, 'Product list.');
            }

            if ($method === 'POST' && $endpoint === 'products') {
                $product = $this->storeProduct($request->all());
                return $this->respond($request, $endpoint, 201, $this->wooProductResponse($product), true, 'Product received from CJA.');
            }

            if ($method === 'POST' && $endpoint === 'products/batch') {
                return $this->handleProductBatch($request, $endpoint);
            }

            if (preg_match('#^products/(\d+)$#', $endpoint, $m) && in_array($method, ['PUT', 'PATCH', 'POST'], true)) {
                $product = BdpProduct::findOrFail((int) $m[1]);
                $product = $this->storeProduct($request->all(), $product);
                return $this->respond($request, $endpoint, 200, $this->wooProductResponse($product), true, 'Product updated from CJA.');
            }

            if (preg_match('#^products/(\d+)/variations$#', $endpoint, $m) && $method === 'POST') {
                $product = BdpProduct::findOrFail((int) $m[1]);
                $variation = $this->storeVariation($product, $request->all());
                return $this->respond($request, $endpoint, 201, $this->wooVariationResponse($variation), true, 'Variation received from CJA.');
            }

            if (preg_match('#^products/(\d+)/variations/batch$#', $endpoint, $m) && $method === 'POST') {
                return $this->handleVariationBatch($request, $endpoint, (int) $m[1]);
            }

            // Generic success for endpoints CJA may call during connection checks.
            return $this->respond($request, $endpoint, 200, [
                'success' => true,
                'message' => 'Balenco Data Pilot received this WooCommerce-compatible request.',
                'endpoint' => $endpoint,
            ], true, 'Generic WooCommerce-compatible response.');
        } catch (\Throwable $e) {
            return $this->respond($request, $endpoint, 500, [
                'code' => 'balenco_data_pilot_error',
                'message' => $e->getMessage(),
                'data' => ['status' => 500],
            ], true, $e->getMessage());
        }
    }

    private function handleProductBatch(Request $request, string $endpoint): JsonResponse
    {
        $created = [];
        $updated = [];
        $deleted = [];

        foreach ((array) $request->input('create', []) as $payload) {
            $product = $this->storeProduct((array) $payload);
            $created[] = $this->wooProductResponse($product);
        }

        foreach ((array) $request->input('update', []) as $payload) {
            $id = (int) ($payload['id'] ?? 0);
            $product = $id ? BdpProduct::find($id) : null;
            $product = $this->storeProduct((array) $payload, $product);
            $updated[] = $this->wooProductResponse($product);
        }

        foreach ((array) $request->input('delete', []) as $payload) {
            $id = is_array($payload) ? (int) ($payload['id'] ?? 0) : (int) $payload;
            if ($id && ($product = BdpProduct::find($id))) {
                $product->variations()->delete();
                $product->delete();
                $deleted[] = ['id' => $id, 'deleted' => true];
            }
        }

        return $this->respond($request, $endpoint, 200, [
            'create' => $created,
            'update' => $updated,
            'delete' => $deleted,
        ], true, 'Product batch received from CJA.');
    }

    private function handleVariationBatch(Request $request, string $endpoint, int $productId): JsonResponse
    {
        $product = BdpProduct::findOrFail($productId);
        $created = [];
        $updated = [];
        $deleted = [];

        foreach ((array) $request->input('create', []) as $payload) {
            $variation = $this->storeVariation($product, (array) $payload);
            $created[] = $this->wooVariationResponse($variation);
        }

        foreach ((array) $request->input('update', []) as $payload) {
            $variation = $this->storeVariation($product, (array) $payload);
            $updated[] = $this->wooVariationResponse($variation);
        }

        foreach ((array) $request->input('delete', []) as $payload) {
            $id = is_array($payload) ? (int) ($payload['id'] ?? 0) : (int) $payload;
            if ($id && ($variation = BdpProductVariation::where('bdp_product_id', $product->id)->where('id', $id)->first())) {
                $variation->delete();
                $deleted[] = ['id' => $id, 'deleted' => true];
            }
        }

        $this->normalizeVariationImagesByColor($product->id);

        return $this->respond($request, $endpoint, 200, [
            'create' => $created,
            'update' => $updated,
            'delete' => $deleted,
        ], true, 'Variation batch received from CJA.');
    }

    private function storeProduct(array $payload, ?BdpProduct $existing = null): BdpProduct
    {
        $name = trim((string) ($payload['name'] ?? $payload['title'] ?? 'Untitled CJA Product'));
        $sourceId = (string) ($payload['id'] ?? $payload['parent_id'] ?? '');
        $sku = trim((string) ($payload['sku'] ?? ''));
        $images = $this->imageUrls((array) ($payload['images'] ?? []));
        $categories = $this->namesFromObjects((array) ($payload['categories'] ?? []));
        $tags = $this->namesFromObjects((array) ($payload['tags'] ?? []));
        $price = $this->decimal($payload['regular_price'] ?? $payload['price'] ?? null);
        $salePrice = $this->decimal($payload['sale_price'] ?? null);

        $product = $existing;
        if (!$product) {
            $query = BdpProduct::where('source', 'cja_api');
            if ($sourceId !== '') {
                $product = (clone $query)->where('source_id', $sourceId)->first();
            }
            if (!$product && $sku !== '') {
                $product = (clone $query)->where('sku', $sku)->first();
            }
        }

        if (!$product) {
            $product = new BdpProduct();
        }

        $product->forceFill([
            'source' => 'cja_api',
            'source_id' => $sourceId !== '' ? $sourceId : ($product->source_id ?: (string) Str::uuid()),
            'name' => $name,
            'slug' => Str::slug($payload['slug'] ?? $name),
            'sku' => $sku,
            'short_description' => (string) ($payload['short_description'] ?? ''),
            'description' => (string) ($payload['description'] ?? ''),
            'content' => (string) ($payload['description'] ?? $payload['short_description'] ?? ''),
            'price' => $price,
            'sale_price' => $salePrice,
            'stock_status' => $this->stockStatus($payload),
            'quantity' => $this->quantity($payload),
            'categories' => $categories ?: config('balenco-data-pilot.default_categories'),
            'tags' => $tags,
            'brand' => config('balenco-data-pilot.default_brand', 'Balenco'),
            'featured_image' => $images[0] ?? null,
            'gallery_images' => implode(',', $images),
            'seo_title' => $name,
            'seo_description' => strip_tags((string) ($payload['short_description'] ?? '')),
            'status' => ($payload['status'] ?? 'publish') === 'publish' ? 'published' : 'draft',
        ])->save();

        return $product;
    }

    private function storeVariation(BdpProduct $product, array $payload): BdpProductVariation
    {
        [$color, $size] = $this->attributesFromPayload((array) ($payload['attributes'] ?? []));
        $images = $this->imageUrls(array_filter([(array) ($payload['image'] ?? [])]) ?: (array) ($payload['images'] ?? []));
        $sourceId = (string) ($payload['id'] ?? '');
        $barcode = trim((string) ($payload['sku'] ?? ''));

        if ($sourceId === '') {
            $sourceId = md5($product->id . '|' . $barcode . '|' . $color . '|' . $size);
        }

        $existing = BdpProductVariation::where('bdp_product_id', $product->id)->where('source_id', $sourceId)->first();
        $sortOrder = $existing?->sort_order ?: ((int) BdpProductVariation::where('bdp_product_id', $product->id)->count() + 1);

        $variation = BdpProductVariation::updateOrCreate(
            ['bdp_product_id' => $product->id, 'source_id' => $sourceId],
            [
                'sku' => $product->sku,
                'barcode' => $barcode,
                'color' => $color,
                'size_raw' => $size,
                'size_us' => BdpSizeService::toUsWomen($size),
                'price' => $this->decimal($payload['regular_price'] ?? $payload['price'] ?? $product->price),
                'sale_price' => $this->decimal($payload['sale_price'] ?? null),
                'stock_status' => $this->stockStatus($payload),
                'quantity' => $this->quantity($payload),
                'image' => $images[0] ?? null,
                'gallery_images' => $product->gallery_images,
                'is_default' => $sortOrder === 1,
                'sort_order' => $sortOrder,
            ]
        );

        $this->normalizeVariationImagesByColor($product->id);

        return $variation;
    }

    private function normalizeVariationImagesByColor(int $productId): void
    {
        $variations = BdpProductVariation::where('bdp_product_id', $productId)->orderBy('sort_order')->orderBy('id')->get();
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

    private function attributesFromPayload(array $attributes): array
    {
        $color = null;
        $size = null;
        foreach ($attributes as $attribute) {
            $name = strtolower((string) ($attribute['name'] ?? $attribute['slug'] ?? $attribute['id'] ?? ''));
            $option = trim((string) ($attribute['option'] ?? $attribute['value'] ?? ''));
            if ($option === '') {
                continue;
            }
            if (str_contains($name, 'color') || str_contains($name, 'colour') || $name === '1') {
                $color = $option;
            }
            if (str_contains($name, 'size') || $name === '2') {
                $size = $option;
            }
        }
        return [$color, $size];
    }

    private function imageUrls(array $images): array
    {
        $urls = [];
        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }
            $src = trim((string) ($image['src'] ?? $image['url'] ?? ''));
            if ($src !== '') {
                $urls[] = $src;
            }
        }
        return array_values(array_unique($urls));
    }

    private function namesFromObjects(array $items): string
    {
        $names = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $name = trim((string) ($item['name'] ?? $item['slug'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
        return implode(', ', array_values(array_unique($names)));
    }

    private function decimal($value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        return (float) preg_replace('/[^0-9.]/', '', $value);
    }

    private function quantity(array $payload): int
    {
        foreach (['stock_quantity', 'quantity', 'inventory', 'available_inventory'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return max(0, (int) $payload[$key]);
            }
        }
        return strtolower((string) ($payload['stock_status'] ?? 'instock')) === 'instock'
            ? (int) config('balenco-data-pilot.default_variation_quantity', 100)
            : 0;
    }

    private function stockStatus(array $payload): string
    {
        $status = strtolower((string) ($payload['stock_status'] ?? 'instock'));
        return in_array($status, ['outofstock', 'out_of_stock'], true) ? 'out_of_stock' : 'in_stock';
    }

    private function systemStatus(): array
    {
        return [
            'environment' => [
                'home_url' => url('/'),
                'site_url' => url('/'),
                'version' => '8.0.0',
                'wp_version' => '6.6',
                'wp_multisite' => false,
                'wp_memory_limit' => '512 MB',
            ],
            'database' => ['wc_database_version' => '8.0.0'],
        ];
    }

    private function wooProductResponse(BdpProduct $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'type' => $product->variations()->exists() ? 'variable' : 'simple',
            'status' => $product->status === 'published' ? 'publish' : 'draft',
            'regular_price' => (string) ($product->price ?? ''),
            'sale_price' => (string) ($product->sale_price ?? ''),
            'permalink' => url('/products/' . $product->id),
        ];
    }

    private function wooVariationResponse(BdpProductVariation $variation): array
    {
        return [
            'id' => $variation->id,
            'sku' => $variation->barcode,
            'regular_price' => (string) ($variation->price ?? ''),
            'sale_price' => (string) ($variation->sale_price ?? ''),
            'stock_quantity' => $variation->quantity,
            'stock_status' => $variation->stock_status === 'out_of_stock' ? 'outofstock' : 'instock',
        ];
    }

    private function isAuthorized(Request $request): bool
    {
        $expectedKey = (string) config('balenco-data-pilot.cja_consumer_key');
        $expectedSecret = (string) config('balenco-data-pilot.cja_consumer_secret');

        if ($expectedKey === '' || $expectedSecret === '') {
            return true;
        }

        // WooCommerce integrations may authenticate in different ways:
        // 1) Query/body: consumer_key + consumer_secret
        // 2) OAuth 1.0: oauth_consumer_key + oauth_signature
        // 3) Basic Auth: Authorization: Basic base64(ck:cs)
        // CJA's "OAuth Plugin" screen commonly sends oauth_consumer_key, so we accept it
        // when it matches our configured key. The request is still logged for review.
        $key = (string) (
            $request->query('consumer_key')
            ?? $request->input('consumer_key')
            ?? $request->query('oauth_consumer_key')
            ?? $request->input('oauth_consumer_key')
            ?? $request->query('client_key')
            ?? $request->input('client_key')
            ?? ''
        );

        $secret = (string) (
            $request->query('consumer_secret')
            ?? $request->input('consumer_secret')
            ?? $request->query('client_secret')
            ?? $request->input('client_secret')
            ?? ''
        );

        if ($key === '' || ($secret === '' && !$request->has('oauth_signature'))) {
            [$basicUser, $basicPass] = $this->basicAuth($request);
            if ($key === '') {
                $key = $basicUser;
            }
            if ($secret === '') {
                $secret = $basicPass;
            }
        }

        // OAuth-signed requests do not send consumer_secret directly. For CJA connection
        // testing we accept matching oauth_consumer_key and log the full request.
        if ($request->has('oauth_signature')) {
            return hash_equals($expectedKey, $key);
        }

        return hash_equals($expectedKey, $key) && hash_equals($expectedSecret, $secret);
    }

    private function basicAuth(Request $request): array
    {
        $user = (string) $request->getUser();
        $pass = (string) $request->getPassword();

        if ($user !== '' || $pass !== '') {
            return [$user, $pass];
        }

        $header = (string) $request->header('Authorization', '');
        if (str_starts_with($header, 'Basic ')) {
            $decoded = base64_decode(substr($header, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                return explode(':', $decoded, 2);
            }
        }

        return ['', ''];
    }

    private function respond(Request $request, string $endpoint, int $status, array $body, bool $authorized, ?string $note = null): JsonResponse
    {
        BdpApiLog::create([
            'source' => 'cja_woocommerce_api',
            'method' => $request->method(),
            'path' => '/' . trim($request->path(), '/'),
            'endpoint' => $endpoint,
            'ip' => $request->ip(),
            'authorized' => $authorized,
            'status_code' => $status,
            'request_headers' => $this->safeHeaders($request),
            'request_body' => $this->safeRequestBody($request),
            'response_body' => $body,
            'note' => $note,
        ]);

        return response()->json($body, $status);
    }

    private function safeHeaders(Request $request): array
    {
        $headers = $request->headers->all();
        unset($headers['authorization']);
        return $headers;
    }

    private function safeRequestBody(Request $request): array
    {
        $data = $request->all();
        unset($data['consumer_secret'], $data['client_secret']);
        return $data;
    }
}
