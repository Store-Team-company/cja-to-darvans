<?php

namespace App\Console\Commands;

use App\Services\BdpShofyDirectPusher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BdpSyncQueuedProducts extends Command
{
    protected $signature = 'bdp:sync-queued-products
        {--limit=1 : Number of queued products to sync per run}
        {--sleep=0 : Seconds to wait between products if limit is more than 1}
        {--retry-failed : Include failed items for retry}
        {--max-attempts=3 : Maximum retry attempts for failed products}';

    protected $description = 'Sync queued Data Pilot products to the connected Shofy/Botble store, safely by cron.';

    public function handle(BdpShofyDirectPusher $pusher): int
    {
        $limit = max(1, min(20, (int) $this->option('limit')));
        $sleep = max(0, min(30, (int) $this->option('sleep')));
        $retryFailed = (bool) $this->option('retry-failed');
        $maxAttempts = max(1, min(10, (int) $this->option('max-attempts')));

        $processed = 0;
        $synced = 0;
        $failed = 0;

        for ($i = 0; $i < $limit; $i++) {
            $item = DB::transaction(function () use ($retryFailed, $maxAttempts) {
                // Release stuck processing items older than 30 minutes.
                DB::table('bdp_product_sync_queue')
                    ->where('status', 'processing')
                    ->whereNotNull('started_at')
                    ->where('started_at', '<', now()->subMinutes(30))
                    ->update([
                        'status' => 'queued',
                        'last_error' => 'Processing lock expired and item was returned to queue.',
                        'updated_at' => now(),
                    ]);

                $query = DB::table('bdp_product_sync_queue')
                    ->where(function ($q) use ($retryFailed, $maxAttempts) {
                        $q->where('status', 'queued');

                        if ($retryFailed) {
                            $q->orWhere(function ($failedQuery) use ($maxAttempts) {
                                $failedQuery->where('status', 'failed')
                                    ->where('attempts', '<', $maxAttempts);
                            });
                        }
                    })
                    ->orderBy('queued_at')
                    ->orderBy('id')
                    ->lockForUpdate();

                $row = $query->first();

                if (!$row) {
                    return null;
                }

                DB::table('bdp_product_sync_queue')
                    ->where('id', $row->id)
                    ->update([
                        'status' => 'processing',
                        'started_at' => now(),
                        'attempts' => ((int) $row->attempts) + 1,
                        'updated_at' => now(),
                    ]);

                return DB::table('bdp_product_sync_queue')->where('id', $row->id)->first();
            });

            if (!$item) {
                if ($processed === 0) {
                    $this->info('No queued products found.');
                }
                break;
            }

            $processed++;
            $productId = (int) $item->product_id;

            try {
                $this->line("Syncing queued product #{$productId}");

                $result = $pusher->push([$productId], [
                    'copy_variation_images' => true,
                    'sync_status' => 'draft',
                ]);

                if (!empty($result['errors'])) {
                    throw new \RuntimeException(implode(' | ', array_slice($result['errors'], 0, 5)));
                }

                DB::table('bdp_product_sync_queue')
                    ->where('id', $item->id)
                    ->update([
                        'status' => 'synced',
                        'last_error' => null,
                        'synced_at' => now(),
                        'updated_at' => now(),
                    ]);

                $synced++;
                $this->info("Synced queued product #{$productId}");
            } catch (Throwable $e) {
                DB::table('bdp_product_sync_queue')
                    ->where('id', $item->id)
                    ->update([
                        'status' => 'failed',
                        'last_error' => mb_substr($e->getMessage(), 0, 5000),
                        'updated_at' => now(),
                    ]);

                $failed++;
                $this->error("Failed product #{$productId}: {$e->getMessage()}");
            }

            if ($sleep > 0 && $i < $limit - 1) {
                sleep($sleep);
            }
        }

        $this->info("Done. Processed {$processed}. Synced {$synced}. Failed {$failed}.");

        return $failed > 0 && $synced === 0 ? self::FAILURE : self::SUCCESS;
    }
}