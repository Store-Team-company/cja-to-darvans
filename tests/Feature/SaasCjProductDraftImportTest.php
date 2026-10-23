<?php

namespace Tests\Feature;

use App\Models\SaasWorkspace;
use App\Models\User;
use App\Services\Saas\SaasCjCatalogBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        config()->set('saas.admin_grants_enabled', false);
        config()->set('saas.registration_enabled', false);
        config()->set('saas.cj_draft_import_enabled', true);
        config()->set('saas.cj_pilot.enabled', false);
        Http::preventStrayRequests();
        Cache::clear();
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
            'status' => 'connected', 'last_verified_at' => now(),
        ]);
        $connection->access_token = 'synthetic-cj-access-token-private';
        $connection->refresh_token = 'synthetic-refresh-token-private';
        $connection->access_token_expires_at = now()->addDays(10);
        $connection->refresh_token_expires_at = now()->addDays(20);
        $connection->credential_revision = 3;
        $connection->save();

        return [$owner, $workspace, $connection];
    }

    private function product(string $id = 'CJ-PRODUCT-001', string $title = 'Safe test tablecloth'): array
    {
        return [
            'id' => $id, 'nameEn' => $title, 'sku' => 'CJ-TEST-SKU-001',
            'bigImage' => 'https://example.test/images/tablecloth.jpg',
            'sellPrice' => '18.50', 'nowPrice' => '15.25',
            'threeCategoryName' => 'Table Linens',
            'description' => '<script>untrusted description</script>',
            'untrustedCredential' => 'SHOULD-NOT-BE-SAVED',
        ];
    }

    private function success(array $products): array
    {
        return [
            'code' => 200, 'result' => true, 'message' => 'Success',
            'data' => ['totalRecords' => 6000, 'pageSize' => 20, 'pageNumber' => 1,
                'content' => [['productList' => $products]]],
        ];
    }

    private function search(User $actor, SaasWorkspace $workspace, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($actor)->post(
            route('saas.workspace.products.cj.search', $workspace->public_id),
            array_replace([
                'mode' => 'search', 'keyword' => 'tablecloth', 'page' => 1, 'size' => 20,
                'country_code' => 'US', 'min_price' => 10, 'max_price' => 80,
                'order_by' => 2, 'sort' => 'asc', 'view' => 'grid',
            ], $overrides)
        );
    }

    private function previewKey(\Illuminate\Testing\TestResponse $response): string
    {
        $query = parse_url($response->headers->get('Location'), PHP_URL_QUERY);
        parse_str($query ?: '', $params);
        return (string) ($params['cj_preview'] ?? '');
    }

    private function import(User $actor, SaasWorkspace $workspace, string $key, array $ids): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($actor)->post(
            route('saas.workspace.products.cj.import', $workspace->public_id),
            ['cj_preview' => $key, 'items' => $ids, 'view' => 'grid']
        );
    }

    public function test_search_does_not_import_and_selected_only_creates_private_drafts(): void
    {
        [$owner, $workspace] = $this->fixture();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response(
            $this->success([$this->product(), $this->product('CJ-PRODUCT-002', 'Second draft')]), 200
        )]);

        $response = $this->search($owner, $workspace);
        $response->assertRedirect();
        $key = $this->previewKey($response);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/D', $key);
        $this->assertDatabaseCount('saas_products', 0);
        $this->actingAs($owner)->get(route('saas.workspace.products.cj', [
            'workspace' => $workspace->public_id, 'cj_preview' => $key,
        ]))->assertOk()->assertSee('Safe test tablecloth')->assertSee('Second draft')
            ->assertSee('Import selected as drafts')
            ->assertDontSee('synthetic-cj-access-token-private')
            ->assertDontSee('SHOULD-NOT-BE-SAVED');

        $this->import($owner, $workspace, $key, ['CJ-PRODUCT-002'])
            ->assertRedirect()->assertSessionHas('cj_product_import');
        $this->assertSame(1, $workspace->products()->count());
        $this->assertFalse($workspace->products()->where('cj_product_id', 'CJ-PRODUCT-001')->exists());
        $saved = $workspace->products()->firstOrFail();
        $this->assertSame('CJ-PRODUCT-002', $saved->cj_product_id);
        $this->assertSame('draft', $saved->status);
        $this->assertArrayNotHasKey('description', $saved->source_snapshot);
        $this->assertArrayNotHasKey('untrustedCredential', $saved->source_snapshot);
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => $req->method() === 'GET'
            && str_contains($req->url(), '/api2.0/v1/product/listV2?')
            && str_contains($req->url(), 'countryCode=US')
            && str_contains($req->url(), 'startSellPrice=10')
            && str_contains($req->url(), 'endSellPrice=80')
            && str_contains($req->url(), 'size=20'));
    }

    public function test_bulk_import_uses_exact_preview_selection_without_overwriting_approved_products(): void
    {
        [$owner, $workspace] = $this->fixture();
        $workspace->products()->create(['cj_product_id' => 'CJ-PRODUCT-001', 'title' => 'Approved',
            'status' => 'approved']);
        Http::fake(['developers.cjdropshipping.com/*' => Http::response(
            $this->success([$this->product(), $this->product('CJ-PRODUCT-002', 'Other')]), 200
        )]);
        $key = $this->previewKey($this->search($owner, $workspace));
        $this->import($owner, $workspace, $key, ['CJ-PRODUCT-001', 'CJ-PRODUCT-002'])
            ->assertSessionHas('cj_product_import');
        $this->assertSame(2, $workspace->products()->count());
        $this->assertSame('Approved', $workspace->products()->where('cj_product_id', 'CJ-PRODUCT-001')->firstOrFail()->title);
        $this->assertSame('draft', $workspace->products()->where('cj_product_id', 'CJ-PRODUCT-002')->firstOrFail()->status);
        Http::assertSentCount(1);
    }

    public function test_tampered_missing_or_expired_search_preview_never_imports(): void
    {
        [$owner, $workspace] = $this->fixture();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response($this->success([$this->product()]), 200)]);
        $key = $this->previewKey($this->search($owner, $workspace));
        $this->import($owner, $workspace, $key, ['FORGED-ID'])->assertSessionHasErrors('cj_product');
        $this->import($owner, $workspace, $key, ['CJ-PRODUCT-001', 'CJ-PRODUCT-001'])->assertSessionHasErrors('cj_product');
        $this->travel(16)->minutes();
        $this->import($owner, $workspace, $key, ['CJ-PRODUCT-001'])->assertSessionHasErrors('cj_product');
        $this->assertDatabaseCount('saas_products', 0);
    }

    public function test_another_user_cannot_reuse_private_search_even_in_same_workspace(): void
    {
        [$owner, $workspace] = $this->fixture();
        $manager = User::factory()->create(['email_verified_at' => now()]);
        $workspace->members()->attach($manager->id, ['role' => 'manager', 'status' => 'active']);
        Http::fake(['developers.cjdropshipping.com/*' => Http::response($this->success([$this->product()]), 200)]);
        $key = $this->previewKey($this->search($owner, $workspace));
        $this->actingAs($manager)->get(route('saas.workspace.products.cj', [
            'workspace' => $workspace->public_id, 'cj_preview' => $key,
        ]))->assertOk()->assertDontSee('Safe test tablecloth');
        $this->import($manager, $workspace, $key, ['CJ-PRODUCT-001'])->assertForbidden();
        $this->assertDatabaseCount('saas_products', 0);
    }

    public function test_denies_other_tenant_viewer_disconnected_and_disabled_before_cj_call(): void
    {
        [$owner, $workspace, $connection] = $this->fixture();
        $viewer = User::factory()->create(['email_verified_at' => now()]);
        $workspace->members()->attach($viewer->id, ['role' => 'viewer', 'status' => 'active']);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $otherWorkspace = SaasWorkspace::create(['name' => 'Another tenant']);
        $otherWorkspace->forceFill(['status' => 'active'])->save();
        $otherWorkspace->members()->attach($other->id, ['role' => 'owner', 'status' => 'active']);

        $this->search($viewer, $workspace)->assertForbidden();
        $this->search($other, $workspace)->assertNotFound();
        $this->search($other, $otherWorkspace)->assertForbidden();
        $connection->forceFill(['status' => 'disconnected'])->save();
        $this->search($owner, $workspace)->assertForbidden();
        $connection->forceFill(['status' => 'connected'])->save();
        config()->set('saas.cj_draft_import_enabled', false);
        $this->search($owner, $workspace)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_second_search_during_cooldown_returns_error_without_another_provider_call(): void
    {
        [$owner, $workspace] = $this->fixture();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response($this->success([]), 200)]);
        $this->search($owner, $workspace)->assertRedirect();
        $this->search($owner, $workspace)->assertSessionHasErrors('cj_product');
        Http::assertSentCount(1);
    }

    public function test_credential_rotation_between_preview_and_import_cannot_save_stale_results(): void
    {
        [$owner, $workspace, $connection] = $this->fixture();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response($this->success([$this->product()]), 200)]);
        $key = $this->previewKey($this->search($owner, $workspace));
        $connection->forceFill(['credential_revision' => 4])->save();
        $this->import($owner, $workspace, $key, ['CJ-PRODUCT-001'])->assertSessionHasErrors('cj_product');
        $this->assertDatabaseCount('saas_products', 0);
    }

    public function test_direct_pid_lookup_is_preview_only_then_user_selects_product(): void
    {
        [$owner, $workspace] = $this->fixture();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response([
            'code' => 200, 'result' => true,
            'data' => ['pid' => 'PID-0001', 'productNameEn' => 'Exact tablecloth',
                'productSku' => 'CJ-PRODUCT-SKU-1', 'bigImage' => 'https://example.test/item.jpg'],
        ], 200)]);
        $response = $this->search($owner, $workspace, [
            'mode' => 'direct', 'id_type' => 'pid', 'identifier' => 'PID-0001',
        ]);
        $key = $this->previewKey($response);
        $this->assertSame(0, $workspace->products()->count());
        $this->import($owner, $workspace, $key, ['PID-0001'])->assertSessionHas('cj_product_import');
        $this->assertSame(1, $workspace->products()->count());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api2.0/v1/product/query?pid=PID-0001'));
    }

    public function test_malformed_cj_response_and_invalid_search_filters_never_save(): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->search($owner, $workspace, ['size' => 100])->assertSessionHasErrors('size');
        Http::assertNothingSent();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response([
            'code' => 200, 'result' => true,
            'data' => ['content' => [['productList' => [['id' => 'BAD', 'nameEn' => null]]]]],
        ], 200)]);
        $this->search($owner, $workspace)->assertSessionHasErrors('cj_product');
        $this->assertDatabaseCount('saas_products', 0);
    }

    public function test_workspace_pilot_capacity_and_plan_limits_still_enforced_on_selected_import(): void
    {
        [$owner, $workspace] = $this->fixture();
        for ($i = 0; $i < 49; $i++) {
            $workspace->products()->create(['cj_product_id' => 'EXISTING-'.$i, 'title' => 'Old '.$i, 'status' => 'draft']);
        }
        Http::fake(['developers.cjdropshipping.com/*' => Http::response($this->success([
            $this->product('NEW-1', 'First'), $this->product('NEW-2', 'Second'),
        ]), 200)]);
        $key = $this->previewKey($this->search($owner, $workspace));
        $this->import($owner, $workspace, $key, ['NEW-1', 'NEW-2'])->assertSessionHas('cj_product_import');
        $this->assertSame(50, $workspace->products()->count());
        $this->assertFalse($workspace->products()->where('cj_product_id', 'NEW-2')->exists());
    }
}
