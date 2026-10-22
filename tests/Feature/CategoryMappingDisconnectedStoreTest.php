<?php

namespace Tests\Feature;

use App\Models\BdpBalencoCategory;
use App\Models\BdpCategoryMapping;
use App\Models\BdpProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryMappingDisconnectedStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mirror the incomplete store settings reported on SiteGround.
        config()->set('balenco-data-pilot.shofy_db.host', '127.0.0.1');
        config()->set('balenco-data-pilot.shofy_db.database', 'YOUR_SHOFY_DB_NAME');
        config()->set('balenco-data-pilot.shofy_db.username', 'YOUR_SHOFY_DB_USER');
        config()->set('balenco-data-pilot.shofy_db.password', 'YOUR_SHOFY_DB_PASSWORD');
    }

    private function asLegacyAdmin(): static
    {
        return $this->withSession(['bdp_logged_in' => true]);
    }

    public function test_category_mapping_get_does_not_connect_to_an_unconfigured_store(): void
    {
        $this->asLegacyAdmin()
            ->get(route('bdp.categories.mapping'))
            ->assertOk()
            ->assertSee('Category Mapping')
            ->assertSee('Connect your Botble store to load categories')
            ->assertSee('Check Store Settings')
            ->assertDontSee('SQLSTATE');
    }

    public function test_refresh_returns_a_safe_error_without_changing_saved_mappings(): void
    {
        $cached = BdpBalencoCategory::create([
            'balenco_category_id' => 123,
            'name' => 'Home Textiles',
            'path' => 'Home > Home Textiles',
        ]);
        BdpCategoryMapping::create([
            'source_category' => 'Pillows',
            'balenco_category_id' => (string) $cached->balenco_category_id,
            'balenco_category_name' => $cached->name,
        ]);

        $this->asLegacyAdmin()
            ->from(route('bdp.categories.mapping'))
            ->post(route('bdp.categories.mapping.fetch'))
            ->assertRedirect(route('bdp.categories.mapping'))
            ->assertSessionHas('error', function (string $message): bool {
                return str_contains($message, 'Could not fetch Botble categories')
                    && str_contains($message, 'No saved category mappings were changed')
                    && !str_contains($message, 'SQLSTATE')
                    && !str_contains($message, 'YOUR_SHOFY_DB_USER');
            });

        $this->assertDatabaseCount('bdp_category_mappings', 1);
        $this->assertDatabaseHas('bdp_balenco_categories', ['balenco_category_id' => 123]);

        $this->asLegacyAdmin()
            ->get(route('bdp.categories.mapping'))
            ->assertOk()
            ->assertSee('Home Textiles')
            ->assertSee('Pillows');
    }

    public function test_save_category_mapping_works_on_the_original_legacy_table(): void
    {
        BdpBalencoCategory::create([
            'balenco_category_id' => 777,
            'name' => 'Bedding',
            'path' => 'Home > Bedding',
        ]);

        $this->asLegacyAdmin()
            ->from(route('bdp.categories.mapping'))
            ->post(route('bdp.categories.mapping.store'), [
                'source_category' => 'Pillows',
                'balenco_category_id' => 777,
            ])
            ->assertRedirect(route('bdp.categories.mapping'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bdp_category_mappings', [
            'source_category' => 'Pillows',
            'balenco_category_id' => 777,
            'balenco_category_name' => 'Bedding',
        ]);
    }

    public function test_auto_map_supports_the_original_legacy_table(): void
    {
        BdpProduct::create([
            'source' => 'cj_api',
            'source_id' => 'cj-cases-123',
            'name' => 'Pillowcase Set',
            'sku' => 'PILLOW-CASE-01',
            'categories' => 'Home > Pillowcases',
            'status' => 'draft',
        ]);
        BdpBalencoCategory::create([
            'balenco_category_id' => 778,
            'name' => 'Pillowcases',
            'path' => 'Bedding > Pillowcases',
        ]);

        $this->asLegacyAdmin()
            ->from(route('bdp.categories.mapping'))
            ->post(route('bdp.categories.mapping.auto'))
            ->assertRedirect(route('bdp.categories.mapping'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bdp_category_mappings', [
            'source_category' => 'Home > Pillowcases',
            'balenco_category_id' => 778,
        ]);
    }

    public function test_dashboard_authentication_is_intact_and_queue_remains_a_menu_link(): void
    {
        $this->get(route('bdp.index'))->assertRedirect(route('bdp.login.form'));

        $this->asLegacyAdmin()
            ->get(route('bdp.index'))
            ->assertOk()
            ->assertSee('Sync Queue')
            ->assertSee('href="'.route('bdp.sync.queue.index').'"', false);
    }
}
