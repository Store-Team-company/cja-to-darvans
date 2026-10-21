<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BalencoDataPilotImageBridgeController extends Controller
{
    public function upload(Request $request)
    {
        $expectedToken = (string) env('BDP_IMAGE_UPLOAD_TOKEN', '');
        $providedToken = (string) $request->bearerToken();

        if (! $providedToken) {
            $providedToken = (string) $request->input('token', '');
        }

        if (! $expectedToken || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized image upload request.',
            ], 401);
        }

        $imageUrl = trim((string) $request->input('image_url', ''));
        $filename = trim((string) $request->input('filename', ''));
        $subdir = trim((string) $request->input('subdir', 'products')) ?: 'products';

        $subdir = trim(str_replace(['..', '\\'], ['', '/'], $subdir), '/');

        if (! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            return response()->json([
                'success' => false,
                'message' => 'Valid image_url is required.',
            ], 422);
        }

        try {
            $response = Http::timeout(30)
                ->retry(2, 500)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 BalencoDataPilot/0.6.4',
                    'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                    'Referer' => parse_url($imageUrl, PHP_URL_SCHEME) . '://' . parse_url($imageUrl, PHP_URL_HOST),
                ])
                ->get($imageUrl);

            if (! $response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Image download failed with HTTP ' . $response->status(),
                ], 422);
            }

            $content = $response->body();
            if (! $content || strlen($content) < 100) {
                return response()->json([
                    'success' => false,
                    'message' => 'Downloaded image content is empty or invalid.',
                ], 422);
            }

            $contentType = strtolower((string) $response->header('Content-Type'));
            $extension = $this->extensionFromContentType($contentType) ?: $this->extensionFromUrl($imageUrl) ?: 'jpg';

            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $extension = 'jpg';
            }

            $baseName = $filename ?: pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?: 'balenco-variation-image', PATHINFO_FILENAME);
            $baseName = Str::slug($baseName) ?: 'balenco-variation-image';

            $publicStorage = public_path('storage');
            $targetDir = $publicStorage . DIRECTORY_SEPARATOR . $subdir;

            if (! File::exists($targetDir)) {
                File::makeDirectory($targetDir, 0755, true);
            }

            if (! is_writable($targetDir)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Target directory is not writable: ' . $targetDir,
                ], 500);
            }

            $finalName = $baseName . '.' . $extension;
            $absolutePath = $targetDir . DIRECTORY_SEPARATOR . $finalName;
            $counter = 1;

            while (File::exists($absolutePath)) {
                // If an identical file already exists, reuse it instead of duplicating.
                if (@md5_file($absolutePath) === md5($content)) {
                    $relativePath = $subdir . '/' . $finalName;
                    return response()->json([
                        'success' => true,
                        'message' => 'Image already exists and was reused.',
                        'path' => $relativePath,
                        'url' => url('storage/' . $relativePath),
                    ]);
                }

                $finalName = $baseName . '-' . $counter . '.' . $extension;
                $absolutePath = $targetDir . DIRECTORY_SEPARATOR . $finalName;
                $counter++;
            }

            File::put($absolutePath, $content);

            $relativePath = $subdir . '/' . $finalName;

            return response()->json([
                'success' => true,
                'message' => 'Image uploaded to Balenco storage.',
                'path' => $relativePath,
                'url' => url('storage/' . $relativePath),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function extensionFromContentType(?string $contentType): ?string
    {
        return match (true) {
            str_contains((string) $contentType, 'image/jpeg') => 'jpg',
            str_contains((string) $contentType, 'image/png') => 'png',
            str_contains((string) $contentType, 'image/webp') => 'webp',
            str_contains((string) $contentType, 'image/gif') => 'gif',
            default => null,
        };
    }

    private function extensionFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension ?: null;
    }
}
