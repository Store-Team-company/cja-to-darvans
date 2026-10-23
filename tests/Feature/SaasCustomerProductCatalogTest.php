<?php

namespace Tests\Feature;

use App\Models\SaasAdminPlanGrant;
use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SaasCustomerProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        config()->set('saas.billing_enabled', false);
        config()->set('saas.admin_grants_enabled', true);
        config()->set('saas.registration_enabled', false);
        Http::preventStrayRequests();
    }

    private function workspace(): array
    {
        $owner = User::factory()->create(['saas_manually_approved_at' => now(), 'email_verified_at' => null]);
        $workspace = SaasWorkspace::create(['name' => 'My store workspace']);
        $workspace->forceFill(['status' => 'active'])->save();
        $workspace->members()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        $workspace->products()->create([
            'cj_product_id' => 'CJ-ITEM-01', 'title' => 'Linen tablecloth',
            'sku' => 'CJ-TABLE-01', 'status' => 'draft',
            'source_snapshot' => [
                'source' => 'cj-catalog-selected',
                'sell_price_usd' => '8.50',
                'image_url' => 'https://example.test/table.jpg',
                'category' => 'Table linens',
            ],
        ]);
        $grant = new SaasAdminPlanGrant([
            'plan_code' => 'agency', 'starts_at' => now()->subMinute(), 'expires_at' => now()->addDays(14),
            'reason' => 'Internal customer catalog test', 'granted_by' => 'unit-test-admin',
        ]);
        $grant->workspace_id = $workspace->id;
        $grant->save();

        return [$owner, $workspace, $workspace->products()->firstOrFail()];
    }

    public function test_navigation_separates_import_search_from_saved_products(): void
    {
        [$owner, $workspace] = $this->workspace();

        $this->actingAs($owner)->get(route('saas.workspace.products', $workspace->public_id))
            ->assertOk()->assertSee('My Products')->assertSee('Import from CJ')
            ->assertSee('Linen tablecloth')->assertSee('Edit')
            ->assertSee('Not published')
            ->assertDontSee('Search CJ Products')
            ->assertDontSee('CJ search results');

        $this->actingAs($owner)->get(route('saas.workspace.products.cj', $workspace->public_id))
            ->assertOk()->assertSee('Import from CJ')
            ->assertSee('Search CJ Products')
            ->assertSee('Import selected as drafts')
            ->assertDontSee('Imported Products');
    }

    public function test_saving_draft_edits_changes_only_owner_workspace_and_preserves_cj_supplier_snapshot(): void
    {
        [$owner, $workspace, $product] = $this->workspace();
        $url = route('saas.workspace.products.update', [
            'workspace' => $workspace->public_id, 'product' => $product->id,
        ]);

        $this->actingAs($owner)->get(route('saas.workspace.products.edit', [
            'workspace' => $workspace->public_id, 'product' => $product->id,
        ]))->assertOk()->assertSee('Edit product')->assertSee('Save product')
            ->assertSee('Push to Botble');

        $this->actingAs($owner)->patch($url, [
            'title' => 'Premium linen tablecloth',
            'sku' => 'MY-TABLECLOTH-01',
            'selling_price_usd' => '29.99',
            'category_name' => 'Home and Dining',
            'description' => 'Carefully selected cotton-look table linen.',
        ])->assertRedirect()->assertSessionHas('status');

        $product->refresh();
        $this->assertSame('Premium linen tablecloth', $product->title);
        $this->assertSame('MY-TABLECLOTH-01', $product->sku);
        $this->assertSame('29.99', $product->selling_price_usd);
        $this->assertSame('Home and Dining', $product->category_name);
        $this->assertSame('Carefully selected cotton-look table linen.', $product->description);
        $this->assertNotNull($product->merchant_edited_at);
        $this->assertSame('8.50', $product->source_snapshot['sell_price_usd']);
        $this->assertSame('CJ-ITEM-01', $product->cj_product_id);
        $this->assertSame('draft', $product->status);
        Http::assertNothingSent();

        $this->actingAs($owner)->get(route('saas.workspace.products', $workspace->public_id))
            ->assertOk()->assertSee('Premium linen tablecloth')->assertSee('29.99');
    }

    public function test_other_tenant_cannot_read_or_write_product_using_guessed_id(): void
    {
        [$owner, $workspace, $product] = $this->workspace();
        [$other, $different] = $this->workspace();
        $url = route('saas.workspace.products.edit', [
            'workspace' => $different->public_id, 'product' => $product->id,
        ]);
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->actingAs($other)->patch(route('saas.workspace.products.update', [
            'workspace' => $different->public_id, 'product' => $product->id,
        ]), ['title' => 'Hacked title', 'sku' => 'HACK'])->assertNotFound();
        $this->actingAs($other)->get(route('saas.workspace.products', $workspace->public_id))
            ->assertNotFound();
        $this->assertSame('Linen tablecloth', $product->fresh()->title);
    }

    public function test_viewer_and_inactive_or_reviewed_draft_cannot_modify(): void
    {
        [$owner, $workspace, $product] = $this->workspace();
        $viewer = User::factory()->create(['email_verified_at' => now()]);
        $workspace->members()->attach($viewer->id, ['role' => 'viewer', 'status' => 'active']);
        $edit = route('saas.workspace.products.edit', [
            'workspace' => $workspace->public_id, 'product' => $product->id,
        ]);
        $this->actingAs($viewer)->get($edit)->assertForbidden();

        $product->forceFill(['status' => 'approved'])->save();
        $this->actingAs($owner)->get($edit)->assertForbidden();
        $product->forceFill(['status' => 'draft'])->save();

        $workspace->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($owner)->get($edit)->assertNotFound();
    }

    public function test_invalid_price_or_unreasonably_long_fields_never_change_product(): void
    {
        [$owner, $workspace, $product] = $this->workspace();
        $url = route('saas.workspace.products.update', [
            'workspace' => $workspace->public_id, 'product' => $product->id,
        ]);
        $this->actingAs($owner)->patch($url, [
            'title' => 'Changed title', 'selling_price_usd' => '-10', 'sku' => 'VALID',
        ])->assertSessionHasErrors('selling_price_usd');
        $this->assertSame('Linen tablecloth', $product->fresh()->title);

        $this->actingAs($owner)->patch($url, [
            'title' => str_repeat('X', 251), 'selling_price_usd' => '12.00',
        ])->assertSessionHasErrors('title');
        $this->assertSame('Linen tablecloth', $product->fresh()->title);
    }

    public function test_lapsed_grant_disables_product_editing_without_erasing_existing_products(): void
    {
        [$owner, $workspace, $product] = $this->workspace();
        SaasAdminPlanGrant::where('workspace_id', $workspace->id)->update([
            'expires_at' => now()->subMinute(),
        ]);
        $this->actingAs($owner)->get(route('saas.workspace.products.edit', [
            'workspace' => $workspace->public_id, 'product' => $product->id,
        ]))->assertForbidden();
        $this->assertSame(1, $workspace->products()->count());
    }
}
