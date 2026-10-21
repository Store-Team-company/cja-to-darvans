<?php

namespace App\Console\Commands;

use App\Models\BdpProduct;
use App\Services\BdpCjApiService;
use Illuminate\Console\Command;

class BdpRefreshShippingCosts extends Command
{
    protected $signature = 'bdp:refresh-shipping-costs
        {--limit=1 : Number of parent products to successfully refresh per run}
        {--country=US : Destination country code}
        {--quantity=1 : Quantity used for shipping estimate}
        {--scan=50 : Number of candidate products to scan so invalid CJ data does not block the queue}
        {--force : Recheck products even if shipping already exists or they were previously skipped}
        {--sleep=0 : Seconds to wait between successful product refreshes if limit is more than 1}';

    protected $description = 'Refresh parent-product CJ shipping and landed cost for Data Pilot products, one product per cron run.';

    public function handle(BdpCjApiService $cj): int
    {
        $limit = max(1, min(50, (int) $this->option('limit')));
        $scan = max($limit, min(500, (int) $this->option('scan')));
        $country = strtoupper((string) ($this->option('country') ?: 'US'));
        $quantity = max(1, min(100, (int) $this->option('quantity')));
        $sleep = max(0, min(30, (int) $this->option('sleep')));
        $force = (bool) $this->option('force');

        $query = BdpProduct::query()
            ->with('variations')
            ->whereHas('variations')
            ->orderByRaw('cj_shipping_fee IS NOT NULL')
            ->orderBy('id');

        if (!$force) {
            $query->where(function ($q) {
                $q->whereNull('cj_shipping_fee')
                    ->orWhereNull('landed_cost')
                    ->orWhere('cj_shipping_fee', '<=', 0);
            });

            // Products that do not have real CJ freight data should not block the cron forever.
            $query->where(function ($q) {
                $q->whereNull('cj_shipping_details')
                    ->orWhere('cj_shipping_details', 'not like', '%bdp_cron_skip_missing_cj_vid%');
            });
        }

        $products = $query->limit($scan)->get();

        if ($products->isEmpty()) {
            $this->info($force
                ? 'No products found.'
                : 'No products need shipping refresh, or remaining products were skipped because real CJ freight data is missing. Use --force to recheck.'
            );

            return self::SUCCESS;
        }

        $attempted = 0;
        $refreshed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($products as $product) {
            if ($refreshed >= $limit) {
                break;
            }

            $attempted++;

            try {
                $this->line("Refreshing parent product #{$product->id} | {$product->sku} | {$product->name}");

                // Existing service already saves parent product cj_shipping_fee, cj_shipping_method,
                // cj_shipping_details, landed_cost, and total quantity.
                // It does not save shipping cost per variation.
                $result = $cj->refreshInventoryAndShipping($product, $country, $quantity);

                $product->refresh();

                $hasFee = $product->cj_shipping_fee !== null && (float) $product->cj_shipping_fee > 0;

                if ($hasFee) {
                    $refreshed++;
                    $this->info("OK: parent shipping={$product->cj_shipping_fee}, landed={$product->landed_cost}");

                    if ($sleep > 0 && $refreshed < $limit) {
                        sleep($sleep);
                    }

                    continue;
                }

                $errors = $result['errors'] ?? [];
                $errorText = implode(' | ', array_slice($errors, 0, 3));

                if ($this->isMissingVidError($errorText)) {
                    $skipped++;
                    $this->markSkippedMissingVid($product, $country, $quantity, $errorText);
                    $this->warn("Skipped product #{$product->id}: CJ freight needs real CJ VID in source data. Marked so cron can move to next product.");
                    continue;
                }

                $failed++;
                $this->warn('Failed: ' . ($errorText ?: 'No shipping fee returned.'));
            } catch (\Throwable $e) {
                $failed++;

                if ($this->isMissingVidError($e->getMessage())) {
                    $skipped++;
                    $this->markSkippedMissingVid($product, $country, $quantity, $e->getMessage());
                    $this->warn("Skipped product #{$product->id}: CJ freight needs real CJ VID in source data. Marked so cron can move to next product.");
                    continue;
                }

                $this->error("Error product #{$product->id}: {$e->getMessage()}");
            }
        }

        $this->info("Done. Attempted {$attempted}. Refreshed {$refreshed}. Skipped {$skipped}. Failed {$failed}.");

        return self::SUCCESS;
    }

    private function isMissingVidError(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'missing real cj variant id')
            || str_contains($message, 'missing cj vid')
            || str_contains($message, 'needs a cj vid')
            || str_contains($message, 'no representative variation');
    }

    private function markSkippedMissingVid(BdpProduct $product, string $country, int $quantity, string $error): void
    {
        $details = [
            'bdp_cron_skip_missing_cj_vid' => true,
            'reason' => 'CJ freight API could not calculate shipping because the product import does not contain a real CJ VID.',
            'error' => $error,
            'country' => $country,
            'quantity' => $quantity,
            'skipped_at' => now()->toDateTimeString(),
        ];

        $product->forceFill([
            'cj_shipping_country' => $country,
            'cj_shipping_fee' => null,
            'cj_shipping_method' => null,
            'cj_shipping_details' => json_encode($details),
            'landed_cost' => null,
        ])->save();
    }
}