<?php

namespace Tests\Feature;

use App\Models\SaasAdminPlanGrant;
use App\Models\SaasBotbleProductDelivery;
use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SaasBotbleDraftDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        config()->set('saas.admin_grants_enabled', true);
        config()->set('saas.botble_draft_delivery.enabled', true);
        Http::preventStrayRequests();
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['email_verified_at' => now(), 'saas_manually_approved_at' => now()]);
        $workspace = SaasWorkspace::create(['name' => 'Private Botble test']);
        $workspace->forceFill(['status' => 'active'])->save();
        $workspace->members()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        $grant = new SaasAdminPlanGrant([
            'plan_code' => 'agency', 'starts_at' => now()->subMinute(),
            'expires_at' => now()->addDays(7), 'reason' => 'staging', 'granted_by' => 'admin',
        ]);
        $grant->workspace_id = $workspace->id;
        $grant->save();
        $product = $workspace->products()->create([
            'title' => 'Tablecloth', 'sku' => 'CJ-TABLE-01', 'cj_product_id' => 'CJ-ID-01',
            'status' => 'draft', 'selling_price_usd' => '39.99',
            'long_description_html' => '<p>Very nice tablecloth</p>',
            'cj_details_fetched_at' => now(),
            'featured_image_url' => 'https://images.example.test/tablecloth.jpg',
            'gallery_images' => ['https://images.example.test/second.jpg'],
            'source_variants' => [],
            'source_snapshot' => ['sell_price_usd' => '17.00'],
        ]);
        $store = $workspace->stores()->create([
            'name' => 'Botble sandbox', 'base_url' => 'https://sandbox.daystart.pk',
            'status' => 'connected', 'last_verified_at' => now(),
        ]);
        $store->shared_secret = 'synthetic-64-byte-test-secret-do-not-deploy';
        $store->save();
        config()->set('saas.botble_pilot', [
            'enabled' => true, 'workspace_id' => $workspace->public_id,
            'store_id' => $store->public_id, 'store_url' => 'https://sandbox.daystart.pk',
        ]);

        return [$owner, $workspace, $product, $store];
    }

    private function url(SaasWorkspace $workspace, $product, $store): string
    {
        return route('saas.workspace.products.botble.stage', [
            'workspace' => $workspace->public_id, 'product' => $product->id, 'store' => $store->id,
        ]);
    }

    public function test_signed_stage_delivers_to_inbox_only_and_never_creates_a_remote_catalog_product(): void
    {
        [$owner, $workspace, $product, $store] = $this->fixture();
        Http::fake(['sandbox.daystart.pk/*' => Http::response([
            'message' => 'Draft staged for review only.', 'draft_id' => 17,
        ], 202)]);

        $this->actingAs($owner)->post($this->url($workspace, $product, $store))
            ->assertRedirect()->assertSessionHas('status');

        $delivery = SaasBotbleProductDelivery::query()->sole();
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(17, $delivery->remote_draft_id);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('draft', $product->fresh()->status);
        $this->assertSame('39.99', $product->fresh()->selling_price_usd);

        Http::assertSentCount(1);
        Http::assertSent(function ($req) use ($store): bool {
            $body = $req->body();
            $data = json_decode($body, true);
            $timestamp = $req->header('X-DataPilot-Timestamp')[0] ?? '';
            $nonce = $req->header('X-DataPilot-Nonce')[0] ?? '';
            $signature = $req->header('X-DataPilot-Signature')[0] ?? '';
            $expected = hash_hmac('sha256',
                "POST\n/api/datapilot/v1/drafts\n".$timestamp."\n".$nonce."\n".$body,
                $store->shared_secret
            );
            return $req->method() === 'POST'
                && $req->url() === 'https://sandbox.daystart.pk/api/datapilot/v1/drafts'
                && ($req->header('X-DataPilot-Store')[0] ?? '') === $store->public_id
                && $signature === 'sha256='.$expected
                && $data['source'] === 'cj'
                && $data['source_product_id'] === 'CJ-ID-01'
                && $data['price'] === '39.99'
                && $data['images'] === [
                    'https://images.example.test/tablecloth.jpg',
                    'https://images.example.test/second.jpg',
                ]
                && !array_key_exists('publish', $data)
                && !array_key_exists('api_key', $data)
                && !array_key_exists('source_cost', $data);
        });
    }

    public function test_same_product_revision_is_idempotent_and_not_sent_twice(): void
    {
        [$owner, $workspace, $product, $store] = $this->fixture();
        Http::fake(['sandbox.daystart.pk/*' => Http::response(['draft_id' => 9], 202)]);
        $url = $this->url($workspace, $product, $store);
        $this->actingAs($owner)->post($url)->assertSessionHas('status');
        $this->actingAs($owner)->post($url)->assertSessionHas('status');
        $this->assertSame(1, SaasBotbleProductDelivery::sole()->attempts);
        Http::assertSentCount(1);
    }

    public function test_disabled_flag_or_other_tenant_or_wrong_store_never_sends(): void
    {
        [$owner, $workspace, $product, $store] = $this->fixture();
        $other = User::factory()->create(['email_verified_at' => now()]);
        config()->set('saas.botble_draft_delivery.enabled', false);
        $this->actingAs($owner)->post($this->url($workspace, $product, $store))->assertForbidden();
        config()->set('saas.botble_draft_delivery.enabled', true);
        $this->actingAs($other)->post($this->url($workspace, $product, $store))->assertNotFound();
        $store->base_url = 'https://malicious.test';
        $store->save();
        $this->actingAs($owner)->post($this->url($workspace, $product, $store))->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('saas_botble_product_deliveries', 0);
    }

    public function test_missing_price_details_or_variant_mapping_blocks_delivery(): void
    {
        [$owner, $workspace, $product, $store] = $this->fixture();
        $url = $this->url($workspace, $product, $store);
        $product->selling_price_usd = null;
        $product->save();
        $this->actingAs($owner)->post($url)->assertSessionHasErrors('botble_delivery');
        $product->selling_price_usd = '39.99';
        $product->source_variants = [['id' => 'CJ-RED', 'sku' => 'CJ-RED-SKU']];
        $product->save();
        $this->actingAs($owner)->post($url)->assertSessionHasErrors('botble_delivery');
        Http::assertNothingSent();
    }

    public function test_rejected_inbox_can_retry_without_rotating_idempotency_key(): void
    {
        [$owner, $workspace, $product, $store] = $this->fixture();
        $responses = Http::sequence()
            ->push(['message' => 'Inbound product drafts are disabled.'], 503)
            ->push(['draft_id' => 30], 202);
        Http::fake(['sandbox.daystart.pk/*' => $responses]);
        $url = $this->url($workspace, $product, $store);
        $this->actingAs($owner)->post($url)->assertSessionHasErrors('botble_delivery');
        $first = SaasBotbleProductDelivery::sole();
        $this->assertSame('failed', $first->status);
        $key = $first->idempotency_key;
        $this->actingAs($owner)->post($url)->assertSessionHas('status');
        $this->assertSame($key, $first->fresh()->idempotency_key);
        $this->assertSame('delivered', $first->fresh()->status);
        $this->assertSame(2, $first->fresh()->attempts);
        Http::assertSentCount(2);
    }

    public function test_changed_reviewed_price_creates_new_idempotency_revision_not_duplicate_catalog_publishing(): void
    {
        [$owner, $workspace, $product, $store] = $this->fixture();
        Http::fake(['sandbox.daystart.pk/*' => Http::response(['draft_id' => 42], 202)]);
        $this->actingAs($owner)->post($this->url($workspace, $product, $store));
        $original = SaasBotbleProductDelivery::sole()->idempotency_key;
        $product->selling_price_usd = '49.99';
        $product->save();
        $this->actingAs($owner)->post($this->url($workspace, $product, $store));
        $this->assertNotSame($original, SaasBotbleProductDelivery::sole()->idempotency_key);
        $this->assertSame(1, SaasBotbleProductDelivery::count());
        Http::assertSentCount(2);
    }
}
