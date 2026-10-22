<?php

namespace App\Services;

use App\Models\BdpProduct;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;
use Illuminate\Support\Facades\Log;

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
        // Prevent common installation-wizard placeholders from reaching MySQL.
        // A failed remote Botble connection must not expose credentials,
        // server names, SQL statements or stack traces to the web dashboard.
        $database = trim((string) config('balenco-data-pilot.shofy_db.database', ''));
        $username = trim((string) config('balenco-data-pilot.shofy_db.username', ''));
        $host = trim((string) config('balenco-data-pilot.shofy_db.host', ''));
        $password = (string) config('balenco-data-pilot.shofy_db.password', '');

        foreach ([$database, $username, $host] as $value) {
            if ($value === '' || preg_match('/^(YOUR_|REPLACE_|CHANGE_ME|EXAMPLE_|SET_ME)/i', $value)) {
                throw new RuntimeException(
                    'The Botble store database is not connected. Configure the private SHOFY_DB_* '.
                    'values for your existing store, or connect through the upcoming Data Pilot Botble Connector.'
                );
            }
        }

        if (preg_match('/^(YOUR_|REPLACE_|CHANGE_ME|EXAMPLE_|SET_ME)/i', $password)) {
            throw new RuntimeException('The Botble store password is still a placeholder. Update the private store connection settings.');
        }

        try {
            $hasCategories = Schema::connection($this->connection)->hasTable('ec_product_categories');
        } catch (Throwable $e) {
            Log::warning('Botble category sync could not connect to the configured store', [
                'error_type' => get_class($e),
            ]);

            throw new RuntimeException(
                'Could not read Botble categories. Check the store database host, '.
                'credentials and permissions in your private configuration.',
                0,
                $e
            );
        }

        if (!$hasCategories) {
            throw new RuntimeException(
                'Botble was reached, but its ec_product_categories table is missing. '.
                'Check the selected store database and Botble eCommerce installation.'
            );
        }
    }
}
