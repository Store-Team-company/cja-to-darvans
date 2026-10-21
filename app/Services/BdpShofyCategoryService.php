<?php

namespace App\Services;

use App\Models\BdpProduct;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class BdpShofyCategoryService
{
    private string $connection = 'shofy';

    public function sourceCategories(): array
    {
        $categories = [];

        BdpProduct::query()->whereNotNull('categories')->pluck('categories')->each(function ($value) use (&$categories) {
            foreach (explode(',', (string) $value) as $category) {
                $category = trim($category);
                if ($category !== '') {
                    $categories[mb_strtolower($category)] = $category;
                }
            }
        });

        natcasesort($categories);
        return array_values($categories);
    }

    public function balencoCategories(): array
    {
        $this->configureConnection();
        $this->assertConnectionReady();

        $columns = Schema::connection($this->connection)->getColumnListing('ec_product_categories');
        $select = array_values(array_intersect(['id', 'name', 'parent_id', 'status', 'order'], $columns));
        if (!in_array('id', $select, true)) {
            $select[] = 'id';
        }
        if (!in_array('name', $select, true)) {
            $select[] = 'name';
        }

        return DB::connection($this->connection)
            ->table('ec_product_categories')
            ->select($select)
            ->orderBy('name')
            ->get()
            ->map(fn ($cat) => (array) $cat)
            ->all();
    }

    public function configureConnection(): void
    {
        Config::set('database.connections.' . $this->connection, [
            'driver' => 'mysql',
            'host' => config('balenco-data-pilot.shofy_db.host'),
            'port' => config('balenco-data-pilot.shofy_db.port'),
            'database' => config('balenco-data-pilot.shofy_db.database'),
            'username' => config('balenco-data-pilot.shofy_db.username'),
            'password' => config('balenco-data-pilot.shofy_db.password'),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => false,
            'engine' => null,
        ]);
        DB::purge($this->connection);
    }

    private function assertConnectionReady(): void
    {
        if (!config('balenco-data-pilot.shofy_db.database') || !config('balenco-data-pilot.shofy_db.username')) {
            throw new RuntimeException('Balenco/Shofy DB connection is not configured. Add SHOFY_DB_* values in .env.');
        }

        try {
            if (!Schema::connection($this->connection)->hasTable('ec_product_categories')) {
                throw new RuntimeException('Connected database does not have ec_product_categories table.');
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to read Balenco categories: ' . $e->getMessage());
        }
    }
}
