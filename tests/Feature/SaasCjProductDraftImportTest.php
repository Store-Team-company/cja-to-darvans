<?php

namespace Tests\Feature;

use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SaasCjProductDraftImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        config()->set('saas.billing_enabled', false);
        config()->set('saas.registration_enabled', false);
        config()->set('saas.cj_pilot.enabled', false);
        config()->set('saas.cj_draft_import_enabled', true);
        Http::preventStrayRequests();
    }

    private function fixture(): array
    {
        $owner = User::factory()->create([
            'email_verified_at' => null, 'saas_manually_approved_at' => now(),
        ]);
        $workspace = SaasWorkspace::create(['name' => 'Product research staging']);
        $workspace->forceFill(['status' => 'active'])->save();
        $workspace->members()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        config()->set('saas.cj_pilot', ['enabled' => true, 'workspace_id' => $workspace->public_id]);

        $connection = $workspace->cjConnections()->create([
            'status' => 'connected',
            'last_verified_at' => now(),
        ]);
        $connection->access_token = 'synthetic-cj-access-token-that-must-never-display';
        $connection->refresh_token = 'synthetic-refresh-token-private';
        $connection->access_token_expires_at = now()->addDays(10);
        $connection->refresh_token_expires_at = now()->addDays(20);
        $connection->credential_revision = 3;
        $connection->save();

        return [$owner, $workspace, $connection];
    }

    private function url(SaasWorkspace $workspace): string
    {
        return route('saas.workspace.products.cj.fetch', $workspace->public_id);
    }

    private function product(string $id = 'CJ-PRODUCT-001', string $title = 'Safe test tablecloth'): array
    {
        return [
            'id' => $id, 'nameEn' => $title, 'sku' => 'CJ-TEST-SKU-001',
            'bigImage' => 'https://example.test/images/tablecloth.jpg',
            'sellPrice' => '18.50', 'nowPrice' => '15.25',
            'threeCategoryName' => 'Table Linens',
            'description' => '<script>provider html never persisted</script>',
            'untrustedCredential' => 'SHOULD-NOT-BE-SAVED',
        ];
    }

    private function success(array $products): array
    {
        return [
            'code' => 200, 'result' => true, 'message' => 'Success',
            'data' => [
                'pageSize' => 10, 'pageNumber' => 1,
                'content' => [['productList' => $products]],
            ],
        ];
    }

    private function fetch(User $owner, SaasWorkspace $workspace, string $keyword = 'tablecloth'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($owner)->post($this->url($workspace), [
            'keyword' => $keyword, 'page' => 1,
        ]);
    }

    public function test_owner_imports_product_drafts_using_exact_fixed_cj_v2_endpoint(): void
    {
        [$owner, $workspace, $connection] = $this->fixture();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response(
            $this->success([$this->product(), $this->product('CJ-PRODUCT-002', 'Other linen')]), 200
        )]);

        $this->fetch($owner, $workspace)->assertRedirect()->assertSessionHas('cj_product_import');
        $this->assertSame(2, $workspace->products()->count());
        $product = $workspace->products()->where('cj_product_id', 'CJ-PRODUCT-001')->firstOrFail();
        $this->assertSame('draft', $product->status);
        $this->assertSame('Safe test tablecloth', $product->title);
        $this->assertSame('18.50', $product->source_snapshot['sell_price_usd']);
        $this->assertSame('15.25', $product->source_snapshot['discount_price_usd']);
        $this->assertArrayNotHasKey('untrustedCredential', $product->source_snapshot);
        $this->assertArrayNotHasKey('description', $product->source_snapshot);
        $this->assertNotNull($connection->fresh()->last_product_fetch_at);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://developers.cjdropshipping.com/api2.0/v1/product/listV2?')
            && $request->hasHeader('CJ-Access-Token', 'synthetic-cj-access-token-that-must-never-display')
            && str_contains($request->url(), 'keyWord=tablecloth')
            && str_contains($request->url(), 'size=10'));

        $this->actingAs($owner)->get(route('saas.workspace.products', $workspace->public_id))
            ->assertOk()->assertSee('Safe test tablecloth')->assertSee('18.50')
            ->assertDontSee('synthetic-cj-access-token-that-must-never-display')
            ->assertDontSee('SHOULD-NOT-BE-SAVED');
    }

    public function test_duplicate_cj_id_updates_draft_without_new_row_or_overwriting_approved_entry(): void
    {
        [$owner, $workspace, $connection] = $this->fixture();
        $workspace->products()->create([
            'cj_product_id' => 'CJ-PRODUCT-001',
            'title' => 'Original title', 'status' => 'draft',
        ]);
        Http::fake(['developers.cjdropshipping.com/*' => Http::response(
            $this->success([$this->product()]), 200
        )]);
        $this->fetch($owner, $workspace)->assertSessionHas('cj_product_import');
        $this->assertSame(1, $workspace->products()->count());
        $this->assertSame('Safe test tablecloth', $workspace->products()->first()->title);

        $workspace->products()->first()->forceFill(['status' => 'approved', 'title' => 'Reviewed by merchant'])->save();
        $connection->forceFill(['last_product_fetch_at' => now()->subMinutes(2)])->save();
        $this->fetch($owner, $workspace)->assertSessionHas('cj_product_import');
        $this->assertSame('Reviewed by merchant', $workspace->products()->first()->title);
        $this->assertSame('approved', $workspace->products()->first()->status);
        $this->assertSame(1, $workspace->products()->count());
    }

    public function test_import_is_never_enabled_by_default_or_when_pilot_is_not_pinned(): void
    {
        [$owner, $workspace] = $this->fixture();
        config()->set('saas.cj_draft_import_enabled', false);
        $this->fetch($owner, $workspace)->assertForbidden();
        $this->actingAs($owner)->get(route('saas.workspace.products', $workspace->public_id))
            ->assertOk()->assertDontSee('Fetch and save CJ drafts');

        config()->set('saas.cj_draft_import_enabled', true);
        config()->set('saas.cj_pilot.workspace_id', 'different-workspace-uuid');
        $this->fetch($owner, $workspace)->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('saas_products', 0);
    }

    public function test_other_tenants_viewers_and_unapproved_customers_cannot_import_or_read_drafts(): void
    {
        [$owner, $workspace] = $this->fixture();
        $viewer = User::factory()->create(['email_verified_at' => now()]);
        $workspace->members()->attach($viewer->id, ['role' => 'viewer', 'status' => 'active']);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $second = SaasWorkspace::create(['name' => 'Another tenant']);
        $second->forceFill(['status' => 'active'])->save();
        $second->members()->attach($other->id, ['role' => 'owner', 'status' => 'active']);
        $workspace->products()->create([
            'cj_product_id' => 'PRIVATE-CJ-100', 'title' => 'Top secret draft', 'status' => 'draft',
        ]);

        $this->fetch($viewer, $workspace)->assertForbidden();
        $this->fetch($other, $second)->assertForbidden();
        $this->fetch($other, $workspace)->assertNotFound();
        $this->actingAs($other)->get(route('saas.workspace.products', $workspace->public_id))
            ->assertNotFound();
        $this->actingAs($viewer)->get(route('saas.workspace.products', $workspace->public_id))
            ->assertOk()->assertSee('Top secret draft')
            ->assertDontSee('Fetch and save CJ drafts');
        Http::assertNothingSent();
    }

    public function test_disconnected_expired_token_or_suspended_workspace_never_calls_cj(): void
    {
        [$owner, $workspace, $connection] = $this->fixture();
        $connection->forceFill(['status' => 'disconnected'])->save();
        $this->fetch($owner, $workspace)->assertForbidden();
        $connection->forceFill(['status' => 'connected', 'access_token_expires_at' => now()->subMinute()])->save();
        $this->fetch($owner, $workspace)->assertForbidden();

        $connection->forceFill(['access_token_expires_at' => now()->addDay()])->save();
        $workspace->forceFill(['status' => 'suspended'])->save();
        $this->fetch($owner, $workspace)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_cj_search_is_rate_limited_for_sixty_seconds_without_extra_provider_calls(): void
    {
        [$owner, $workspace] = $this->fixture();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response($this->success([]), 200)]);
        $this->fetch($owner, $workspace)->assertSessionHas('cj_product_import');
        $this->fetch($owner, $workspace)->assertSessionHasErrors('cj_product');
        Http::assertSentCount(1);
    }

    public function test_malformed_provider_response_is_rejected_without_partial_drafts(): void
    {
        [$owner, $workspace] = $this->fixture();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response([
            'code' => 200, 'result' => true, 'data' => [
                'content' => [
                    ['productList' => [$this->product()]],
                    ['productList' => [['id' => 'BROKEN', 'nameEn' => null]]],
                ],
            ],
        ], 200)]);
        $this->fetch($owner, $workspace)->assertSessionHasErrors('cj_product');
        $this->assertDatabaseCount('saas_products', 0);
    }

    public function test_provider_unauthorized_or_rate_limited_cannot_create_drafts(): void
    {
        [$owner, $workspace, $connection] = $this->fixture();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response([], 401)]);
        $this->fetch($owner, $workspace)->assertSessionHasErrors('cj_product');
        $connection->forceFill(['last_product_fetch_at' => now()->subMinutes(2)])->save();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response([], 429)]);
        $this->fetch($owner, $workspace)->assertSessionHasErrors('cj_product');
        $this->assertDatabaseCount('saas_products', 0);
    }

    public function test_revoking_or_rotating_cj_connection_during_fetch_cannot_store_stale_data(): void
    {
        [$owner, $workspace, $connection] = $this->fixture();
        Http::fake(['developers.cjdropshipping.com/*' => function ($request) use ($connection) {
            $connection->forceFill([
                'credential_revision' => $connection->credential_revision + 1,
                'status' => 'pending_verification',
                'access_token' => null,
            ])->save();
            return Http::response($this->success([$this->product()]), 200);
        }]);
        $this->fetch($owner, $workspace)->assertSessionHasErrors('cj_product');
        $this->assertDatabaseCount('saas_products', 0);
    }

    public function test_pilot_cap_is_enforced_and_no_botble_data_is_touched(): void
    {
        [$owner, $workspace] = $this->fixture();
        for ($i = 1; $i <= 49; $i++) {
            $workspace->products()->create([
                'title' => 'Existing draft '.$i,
                'cj_product_id' => 'SAVED-'.$i,
                'status' => 'draft',
            ]);
        }
        Http::fake(['developers.cjdropshipping.com/*' => Http::response(
            $this->success([$this->product('NEW-A', 'Allowed'), $this->product('NEW-B', 'Skipped limit')]), 200
        )]);
        $this->fetch($owner, $workspace)->assertSessionHas('cj_product_import');
        $this->assertSame(50, $workspace->products()->count());
        $this->assertDatabaseCount('saas_botble_stores', 0);
        $this->assertSame('draft', $workspace->products()->where('cj_product_id', 'NEW-A')->firstOrFail()->status);
        $this->assertFalse($workspace->products()->where('cj_product_id', 'NEW-B')->exists());
    }
}
