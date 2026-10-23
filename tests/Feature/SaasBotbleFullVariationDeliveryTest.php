<?php

namespace Tests\Feature;

use App\Models\SaasAdminPlanGrant;
use App\Models\SaasBotbleProductDelivery;
use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SaasBotbleFullVariationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        config()->set('saas.admin_grants_enabled', true);
        config()->set('saas.botble_draft_delivery.enabled', true);
        config()->set('saas.botble_variant_drafts_enabled', true);
        Http::preventStrayRequests();
    }

    private function fixture(): array
    {
        $owner = User::factory()->create([
            'email_verified_at' => now(), 'saas_manually_approved_at' => now(),
        ]);
        $workspace = SaasWorkspace::create(['name' => 'Complete variants']);
        $workspace->forceFill(['status' => 'active'])->save();
        $workspace->members()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        $grant = new SaasAdminPlanGrant([
            'plan_code' => 'agency', 'starts_at' => now()->subMinute(),
            'expires_at' => now()->addWeek(), 'reason' => 'sandbox', 'granted_by' => 'admin',
        ]);
        $grant->workspace_id = $workspace->id;
        $grant->save();

        $variants = [];
        for ($i = 1; $i <= 40; $i++) {
            $variants[] = [
                'id' => 'CJ-VID-'.$i,
                'sku' => 'BED-EXAMPLE-'.$i,
                'key' => 'Powder blue - '.$i.'x240 bedding',
                'price_usd' => '14.00', // supplier COST must not be published as retail
                'image' => 'https://images.example.test/variant-'.$i.'.jpg',
                'weight_g' => '1250',
            ];
        }
        $product = $workspace->products()->create([
            'title' => 'Full 40-variation bedding set', 'sku' => 'PARENT-BED-1',
            'cj_product_id' => 'CJ-BEDDING-40', 'status' => 'draft',
            'selling_price_usd' => '69.99',
            'cj_details_fetched_at' => now(), 'source_variants' => $variants,
            'long_description_html' => '<p>Clean cotton.</p><script>alert(1)</script>',
            'short_description_html' => '<p>All sizes.</p>',
            'source_snapshot' => ['sell_price_usd' => '14.00'],
            'featured_image_url' => 'https://images.example.test/featured.jpg',
        ]);
        $store = $workspace->stores()->create([
            'name' => 'Dravans', 'base_url' => 'https://sandbox.daystart.pk',
            'status' => 'connected', 'last_verified_at' => now(),
        ]);
        $store->shared_secret = 'strictly-synthetic-fixture-secret';
        $store->save();
        config()->set('saas.botble_pilot', [
            'enabled' => true, 'workspace_id' => $workspace->public_id,
            'store_id' => $store->public_id, 'store_url' => 'https://sandbox.daystart.pk',
        ]);

        $url = route('saas.workspace.products.botble.stage', [
            'workspace' => $workspace->public_id,
            'product' => $product->id,
            'store' => $store->id,
        ]);
        return [$owner, $workspace, $product, $store, $url];
    }

    public function test_forty_complete_variants_are_signed_and_staged_with_merchant_price(): void
    {
        [$owner, $workspace, $product, $store, $url] = $this->fixture();
        Http::fake(['sandbox.daystart.pk/*' => Http::response(['draft_id' => 92], 202)]);
        $this->actingAs($owner)->post($url, ['confirm_uniform_variant_price' => '1'])
            ->assertRedirect()->assertSessionHas('status');

        $delivery = SaasBotbleProductDelivery::sole();
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(92, $delivery->remote_draft_id);
        $body = json_decode($delivery->payload_json, true);
        $this->assertSame(2, $body['schema_version']);
        $this->assertSame(40, $body['variant_count']);
        $this->assertCount(40, $body['variants']);
        $this->assertSame('BED-EXAMPLE-40', $body['variants'][39]['sku']);
        $this->assertSame('69.99', $body['variants'][0]['price']);
        $this->assertSame('Powder blue - 1x240 bedding', $body['variants'][0]['attributes']['CJ Option']);
        $this->assertArrayNotHasKey('quantity', $body['variants'][0]);
        $this->assertStringNotContainsString('<script', $body['long_description_html']);
        $this->assertSame('draft', $product->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.daystart.pk/api/datapilot/v1/drafts'
            && (json_decode($request->body(), true)['variant_count'] ?? null) === 40);
    }

    public function test_variation_staging_requires_operator_price_confirmation(): void
    {
        [$owner, $workspace, $product, $store, $url] = $this->fixture();
        $this->actingAs($owner)->post($url)->assertSessionHasErrors('botble_delivery');
        Http::assertNothingSent();
        $this->assertDatabaseCount('saas_botble_product_deliveries', 0);
    }

    public function test_variation_staging_is_off_without_explicit_version_two_flag(): void
    {
        [$owner, $workspace, $product, $store, $url] = $this->fixture();
        config()->set('saas.botble_variant_drafts_enabled', false);
        $this->actingAs($owner)->post($url, ['confirm_uniform_variant_price' => '1'])
            ->assertSessionHasErrors('botble_delivery');
        Http::assertNothingSent();
    }

    public function test_missing_or_duplicate_option_labels_refuse_partial_payload(): void
    {
        [$owner, $workspace, $product, $store, $url] = $this->fixture();
        $variants = $product->source_variants;
        $variants[10]['key'] = $variants[0]['key'];
        $product->source_variants = $variants;
        $product->save();
        $this->actingAs($owner)->post($url, ['confirm_uniform_variant_price' => '1'])
            ->assertSessionHasErrors('botble_delivery');
        $variants[10]['key'] = '';
        $product->source_variants = $variants;
        $product->save();
        $this->actingAs($owner)->post($url, ['confirm_uniform_variant_price' => '1'])
            ->assertSessionHasErrors('botble_delivery');
        Http::assertNothingSent();
        $this->assertDatabaseCount('saas_botble_product_deliveries', 0);
    }

    public function test_second_click_does_not_duplicate_signed_variants(): void
    {
        [$owner, $workspace, $product, $store, $url] = $this->fixture();
        Http::fake(['sandbox.daystart.pk/*' => Http::response(['draft_id' => 92], 202)]);
        $this->actingAs($owner)->post($url, ['confirm_uniform_variant_price' => '1'])
            ->assertSessionHas('status');
        $this->actingAs($owner)->post($url, ['confirm_uniform_variant_price' => '1'])
            ->assertSessionHas('status');
        Http::assertSentCount(1);
        $this->assertSame(1, SaasBotbleProductDelivery::sole()->attempts);
    }
}
