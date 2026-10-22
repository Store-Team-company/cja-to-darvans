<?php

namespace Tests\Feature;

use App\Models\BdpProduct;
use App\Models\BdpSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminNavigationUsabilityTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $this->withSession(['bdp_logged_in' => true]);
    }

    public function test_sync_queue_has_an_active_sidebar_link_and_real_header_actions(): void
    {
        $this->signIn();

        $this->get(route('bdp.sync.queue.index'))
            ->assertOk()
            ->assertSee('Your sync queue is empty')
            ->assertSee('Choose Products')
            ->assertSee('Import your first product')
            ->assertSee('aria-current="page"', false)
            ->assertSee('href="'.route('bdp.sync.queue.index').'"', false)
            ->assertSee('href="'.route('bdp.cj.import').'"', false)
            ->assertDontSee('>Reports<', false)
            ->assertDontSee('>Team<', false);
    }

    public function test_header_search_is_an_actual_product_search_form(): void
    {
        $this->signIn();

        $this->get(route('bdp.index'))
            ->assertOk()
            ->assertSee('role="search"', false)
            ->assertSee('name="q"', false)
            ->assertSee('action="'.route('bdp.products').'"', false)
            ->assertSee('aria-controls="bdpSidebar"', false)
            ->assertDontSee('class="bdp-bell-dot"', false)
            ->assertDontSee('>JD<', false);
    }

    public function test_empty_dashboard_shows_getting_started_instead_of_fake_success(): void
    {
        $this->signIn();

        $this->get(route('bdp.index'))
            ->assertOk()
            ->assertSee('Bring your first product into Data Pilot')
            ->assertSee('Ready for your first product import')
            ->assertSee('0 percent of products synced')
            ->assertDontSee('Synced 0 products and 0 variations to Balenco');
    }

    public function test_dashboard_pricing_summary_uses_saved_settings(): void
    {
        BdpSetting::setValue('default_shipping_country', 'GB');
        BdpSetting::setValue('default_margin_type', 'percentage');
        BdpSetting::setValue('default_margin_value', '17.50');
        BdpSetting::setValue('minimum_selling_price', '45.00');
        BdpSetting::setValue('retail_rounding', 'none');
        $this->signIn();

        $this->get(route('bdp.index'))
            ->assertOk()
            ->assertSee('17.50%')
            ->assertSee('$45.00')
            ->assertSee('No rounding');
    }

    public function test_sync_queue_filtered_no_results_has_clear_filters_action(): void
    {
        $this->signIn();
        $product = BdpProduct::create([
            'source' => 'cj_api', 'source_id' => 'test-123', 'name' => 'Sample CJ Product',
            'sku' => 'TEST-123', 'status' => 'draft',
        ]);

        DB::table('bdp_product_sync_queue')->insert([
            'product_id' => $product->id,
            'status' => 'queued',
            'attempts' => 0,
            'queued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get(route('bdp.sync.queue.index', ['status' => 'failed']))
            ->assertOk()
            ->assertSee('No items match your filters')
            ->assertSee('Clear filters');
    }
}
