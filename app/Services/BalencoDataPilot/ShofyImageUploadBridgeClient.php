<?php

namespace App\Services\BalencoDataPilot;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ShofyImageUploadBridgeClient
{
    public function uploadRemoteImage(string $imageUrl, string $productName, string $color = ''): array
    {
        $uploadUrl = rtrim((string) env('SHOFY_IMAGE_UPLOAD_URL', ''), '/');
        $token = (string) env('SHOFY_IMAGE_UPLOAD_TOKEN', '');
        $subdir = trim((string) env('SHOFY_VARIATION_IMAGE_SUBDIR', 'products'), '/') ?: 'products';

        if (! $uploadUrl || ! $token) {
            return [
                'success' => false,
                'path' => $imageUrl,
                'message' => 'Shofy image upload bridge is not configured. Using original image URL.',
            ];
        }

        if (! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            return [
                'success' => false,
                'path' => $imageUrl,
                'message' => 'Invalid variation image URL. Using original value.',
            ];
        }

        $filename = Str::slug(trim($productName . ' ' . $color . ' women shoes')) ?: 'balenco-variation-image';

        try {
            $response = Http::timeout(45)
                ->retry(2, 700)
                ->withToken($token)
                ->asForm()
                ->post($uploadUrl, [
                    'image_url' => $imageUrl,
                    'filename' => $filename,
                    'subdir' => $subdir,
                ]);

            $json = $response->json();

            if ($response->successful() && is_array($json) && ($json['success'] ?? false) && ! empty($json['path'])) {
                return [
                    'success' => true,
                    'path' => $json['path'],
                    'url' => $json['url'] ?? null,
                    'message' => $json['message'] ?? 'Variation image copied to Balenco storage.',
                ];
            }

            return [
                'success' => false,
                'path' => $imageUrl,
                'message' => 'Shofy bridge upload failed: ' . ($json['message'] ?? $response->body() ?: 'Unknown error'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'path' => $imageUrl,
                'message' => 'Shofy bridge upload exception: ' . $e->getMessage(),
            ];
        }
    }
}
