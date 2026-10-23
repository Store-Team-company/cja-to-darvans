<?php

namespace Tests\Feature;

use App\Models\SaasAdminPlanGrant;
use App\Models\SaasWorkspace;
use App\Models\User;
use App\Services\Saas\SaasProductHtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SaasCjFullProductDetailsTest extends TestCase
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

    private function fixture(): array
    {
        $owner = User::factory()->create(['email_verified_at' => now(), 'saas_manually_approved_at' => now()]);
        $workspace = SaasWorkspace::create(['name' => 'CJ staging']);
        $workspace->forceFill(['status' => 'active'])->save();
        $workspace->members()->attach($owner->id, ['status' => 'active', 'role' => 'owner']);
        $grant = new SaasAdminPlanGrant([
            'plan_code' => 'agency', 'reason' => 'test', 'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(7), 'granted_by' => 'test-admin',
        ]);
        $grant->workspace_id = $workspace->id;
        $grant->save();
        $product = $workspace->products()->create([
            'title' => 'Card title', 'status' => 'draft', 'sku' => 'SKU-OLD',
            'cj_product_id' => 'CJ-EXAMPLE-ID-01',
            'source_snapshot' => ['source' => 'cj-catalog-selected', 'sell_price_usd' => null],
        ]);
        $connection = $workspace->cjConnections()->create(['status' => 'connected', 'last_verified_at' => now()]);
        $connection->access_token = 'CJ_PRIVATE_TOKEN_TEST';
        $connection->api_key = 'CJ_API_KEY_TEST_1234567';
        $connection->credential_revision = 3;
        $connection->access_token_expires_at = now()->addDays(10);
        $connection->save();
        return [$owner, $workspace, $product, $connection];
    }

    private function data(): array
    {
        return [
            'code' => 200, 'result' => true, 'data' => [
                'pid' => 'CJ-EXAMPLE-ID-01',
                'productNameEn' => 'Premium CJ Tablecloth',
                'productSku' => 'CJ-LINEN-123',
                'categoryName' => 'Home > Table Linens',
                'sellPrice' => '12.30',
                'bigImage' => 'https://example.test/image-main.jpg',
                'productImageSet' => ['https://example.test/image-gallery.jpg', 'javascript:alert(1)'],
                'productWeight' => 350,
                'description' => '<h2 onclick="alert(1)">Features</h2><p>Soft <strong>cotton</strong>.</p>'
                    .'<script>alert("XSS")</script><img src="javascript:alert(2)">'
                    .'<p><img src="https://example.test/size.jpg" onerror="alert(3)"></p>',
                'variants' => [
                    ['vid' => 'VID001', 'variantSku' => 'CJ-RED-L',
                        'variantKey' => 'Red-L', 'variantSellPrice' => '13.50',
                        'variantImage' => 'https://example.test/red.jpg',
                        'variantWeight' => 360, 'variantLength' => 30,
                        'variantWidth' => 20, 'variantHeight' => 2],
                ],
            ],
        ];
    }

    private function url(SaasWorkspace $workspace, int $productId): string
    {
        return route('saas.workspace.products.cj.details', [
            'workspace' => $workspace->public_id, 'product' => $productId,
        ]);
    }

    public function test_enrichment_fetches_full_cj_details_into_existing_draft_without_publishing(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response($this->data(), 200)]);
        $this->actingAs($owner)->post($this->url($workspace, $product->id))
            ->assertRedirect()->assertSessionHas('status');
        $product->refresh();
        $this->assertSame('Premium CJ Tablecloth', $product->title);
        $this->assertSame('CJ-LINEN-123', $product->sku);
        $this->assertSame('Home > Table Linens', $product->category_name);
        $this->assertSame('12.30', $product->source_snapshot['sell_price_usd']);
        $this->assertSame('350', $product->source_weight_g);
        $this->assertNotNull($product->cj_details_fetched_at);
        $this->assertCount(2, $product->gallery_images);
        $this->assertCount(1, $product->source_variants);
        $this->assertSame('CJ-RED-L', $product->source_variants[0]['sku']);
        $this->assertStringContainsString('<strong>cotton</strong>', $product->long_description_html);
        $this->assertStringNotContainsString('<script', $product->long_description_html);
        $this->assertStringNotContainsString('onclick', $product->long_description_html);
        $this->assertStringNotContainsString('javascript:', $product->long_description_html);
        $this->assertSame('draft', $product->status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET'
            && str_contains($r->url(), '/product/query?')
            && str_contains($r->url(), 'pid=CJ-EXAMPLE-ID-01')
            && $r->hasHeader('CJ-Access-Token', 'CJ_PRIVATE_TOKEN_TEST'));

        $this->actingAs($owner)->get(route('saas.workspace.products.edit', [
            'workspace' => $workspace->public_id, 'product' => $product->id,
        ]))->assertOk()->assertSee('Full CJ details fetched')->assertSee('Premium CJ Tablecloth')
            ->assertSee('Short description')->assertSee('Long description')
            ->assertSee('SEO details')->assertSee('CJ-RED-L')
            ->assertDontSee('CJ_PRIVATE_TOKEN_TEST')
            ->assertDontSee('onclick')->assertDontSee('alert("XSS")');
    }

    public function test_html_editor_sanitizes_merchant_html_and_saves_all_edit_sections(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        $this->actingAs($owner)->patch(route('saas.workspace.products.update', [
            'workspace' => $workspace->public_id, 'product' => $product->id,
        ]), [
            'title' => 'Merchant premium tablecloth', 'sku' => 'MERCHANT-SKU',
            'slug' => 'Merchant tablecloth', 'category_name' => 'Dining Linens',
            'tags' => 'linen, kitchen', 'short_description_html' => '<p onmouseover="alert(1)">Short <b>copy</b></p>',
            'long_description_html' => '<h2>Product benefits</h2><script>alert(2)</script><p>Comfortable <a href="javascript:alert(3)">link</a></p>',
            'seo_title' => 'Best Dining Linens', 'focus_keyword' => 'tablecloth',
            'seo_description' => 'Premium easy care tablecloth', 'selling_price_usd' => '30.00',
            'sale_price_usd' => '25.00', 'featured_image_url' => 'https://example.test/main.jpg',
            'gallery_images_text' => "https://example.test/main.jpg\nhttps://example.test/gallery.jpg",
        ])->assertRedirect()->assertSessionHas('status');

        $product->refresh();
        $this->assertSame('merchant-tablecloth', $product->slug);
        $this->assertSame('linen, kitchen', $product->tags);
        $this->assertSame('Best Dining Linens', $product->seo_title);
        $this->assertSame('30.00', $product->selling_price_usd);
        $this->assertSame('25.00', $product->sale_price_usd);
        $this->assertCount(2, $product->gallery_images);
        $this->assertStringContainsString('<b>copy</b>', $product->short_description_html);
        $this->assertStringNotContainsString('onmouseover', $product->short_description_html);
        $this->assertStringNotContainsString('<script', $product->long_description_html);
        $this->assertStringNotContainsString('javascript:', $product->long_description_html);
        Http::assertNothingSent();
    }

    public function test_refresh_never_overwrites_saved_merchant_fields(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        $product->forceFill([
            'merchant_edited_at' => now(), 'title' => 'Custom brand title',
            'sku' => 'MY-SKU', 'selling_price_usd' => '45.00',
            'long_description_html' => '<p>Custom description</p>',
            'featured_image_url' => 'https://example.test/my-image.jpg',
        ])->save();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response($this->data(), 200)]);
        $this->actingAs($owner)->post($this->url($workspace, $product->id))->assertSessionHas('status');
        $product->refresh();
        $this->assertSame('Custom brand title', $product->title);
        $this->assertSame('MY-SKU', $product->sku);
        $this->assertSame('45.00', $product->selling_price_usd);
        $this->assertSame('<p>Custom description</p>', $product->long_description_html);
        $this->assertSame('https://example.test/my-image.jpg', $product->featured_image_url);
        $this->assertCount(1, $product->source_variants);
        $this->assertSame('12.30', $product->source_snapshot['sell_price_usd']);
    }

    public function test_revoked_grants_cross_tenant_and_invalid_provider_identity_do_not_write(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        [$other, $different] = $this->fixture();
        $this->actingAs($other)->post($this->url($workspace, $product->id))->assertNotFound();
        $this->actingAs($other)->post($this->url($different, $product->id))->assertNotFound();
        Http::assertNothingSent();
        $bad = $this->data();
        $bad['data']['pid'] = 'ANOTHER-CJ-PRODUCT';
        Http::fake(['developers.cjdropshipping.com/*' => Http::response($bad, 200)]);
        $this->actingAs($owner)->post($this->url($workspace, $product->id))->assertSessionHasErrors('cj_details');
        $this->assertNull($product->fresh()->cj_details_fetched_at);
        Http::assertSentCount(1);
    }

    public function test_cooldown_limits_repeated_provider_details_queries(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response($this->data(), 200)]);
        $this->actingAs($owner)->post($this->url($workspace, $product->id))->assertSessionHas('status');
        $this->actingAs($owner)->post($this->url($workspace, $product->id))->assertSessionHasErrors('cj_details');
        Http::assertSentCount(1);
    }

    public function test_unavailable_provider_does_not_mark_draft_as_enriched(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response(['code' => 500, 'result' => false], 503)]);
        $this->actingAs($owner)->post($this->url($workspace, $product->id))->assertSessionHasErrors('cj_details');
        $this->assertNull($product->fresh()->cj_details_fetched_at);
        $this->assertSame('Card title', $product->fresh()->title);
    }

    public function test_html_sanitizer_never_keeps_scriptable_markup(): void
    {
        $safe = app(SaasProductHtmlSanitizer::class);
        $html = $safe->clean('<div><p class="x" onclick="evil()">OK <a href="javascript:evil()">bad</a>'
            .' <img src="https://example.test/g.jpg" onload="evil()">'
            .'</p><iframe src="https://evil.test"></iframe>'
            .'<svg onload="evil()"></svg><style>body{display:none}</style></div>');
        $this->assertStringContainsString('OK', $html);
        $this->assertStringContainsString('https://example.test/g.jpg', $html);
        foreach (['onclick', 'javascript:', '<iframe', '<svg', '<style', 'onload='] as $bad) {
            $this->assertStringNotContainsString($bad, $html);
        }
    }

    public function test_invalid_sale_price_and_non_https_image_are_rejected(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        $url = route('saas.workspace.products.update', ['workspace' => $workspace->public_id, 'product' => $product->id]);
        $this->actingAs($owner)->patch($url, [
            'title' => 'Example', 'selling_price_usd' => '30.00', 'sale_price_usd' => '40.00',
        ])->assertSessionHasErrors('sale_price_usd');
        $this->actingAs($owner)->patch($url, [
            'title' => 'Example', 'featured_image_url' => 'http://example.test/bad.jpg',
        ])->assertSessionHasErrors('featured_image_url');
        $this->assertSame('Card title', $product->fresh()->title);
        Http::assertNothingSent();
    }
}
