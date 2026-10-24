<?php

namespace Tests\Feature;

use Botble\DatapilotConnector\Services\NativeDraftPreflight;
use Tests\TestCase;

final class BotbleNativeDraftPreflightTest extends TestCase
{
    private NativeDraftPreflight $preflight;

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('integrations/botble-connector/src/Services/NativeDraftPreflight.php');
        $this->preflight = new NativeDraftPreflight();
    }

    private function draft(int $count = 40): array
    {
        $variants = [];
        for ($i = 1; $i <= $count; $i++) {
            $variants[] = [
                'source_variant_id' => 'CJ-V'.$i,
                'sku' => 'CJ-BED-'.$i,
                'attributes' => ['CJ Option' => 'Silk bedding - option '.$i],
                'regular_price' => '120.00',
                'sale_price' => '80.00',
                'image' => 'https://img.example.test/bed-'.$i.'.jpg',
            ];
        }

        return [
            'schema_version' => 3,
            'source' => 'cj',
            'source_product_id' => '2505090210081629800',
            'title' => 'Silk Bed Sheet Silk Bed Sheet',
            'sku' => 'CJ-PARENT',
            'currency_code' => 'USD',
            'regular_price' => '120.00',
            'sale_price' => '80.00',
            'variant_count' => $count,
            'variants' => $variants,
            'images' => ['https://img.example.test/gallery.jpg'],
            'seo_title' => 'Silk Bedding',
            'seo_description' => 'Silk bedding for home',
        ];
    }

    public function test_complete_forty_variant_v3_contract_is_ready_for_a_separate_runtime_audit(): void
    {
        $check = $this->preflight->inspect($this->draft());
        $this->assertTrue($check['ready_for_runtime_audit']);
        $this->assertSame([], $check['errors']);
        $this->assertSame(40, $check['variation_count']);
        $this->assertSame(1, $check['gallery_count']);
    }

    public function test_existing_successfully_staged_v2_draft_is_blocked_from_native_import(): void
    {
        $draft = $this->draft();
        $draft['schema_version'] = 2;
        $draft['price'] = '80.00';
        unset($draft['regular_price'], $draft['sale_price']);
        $result = $this->preflight->inspect($draft);
        $this->assertFalse($result['ready_for_runtime_audit']);
        $this->assertContains('requires_v3_regular_and_sale_price_contract', $result['errors']);
        $this->assertContains('missing_regular_retail_price', $result['errors']);
    }

    public function test_sale_price_must_be_below_regular_price_for_parent_and_variations(): void
    {
        $draft = $this->draft();
        $draft['sale_price'] = '150.00';
        $draft['variants'][39]['regular_price'] = '40.00';
        $result = $this->preflight->inspect($draft);
        $this->assertContains('invalid_sale_retail_price', $result['errors']);
        $this->assertContains('invalid_variant_retail_price_39', $result['errors']);
    }

    public function test_duplicate_option_or_source_identifier_is_rejected(): void
    {
        $draft = $this->draft();
        $draft['variants'][15]['source_variant_id'] = $draft['variants'][1]['source_variant_id'];
        $draft['variants'][20]['attributes'] = $draft['variants'][0]['attributes'];
        $result = $this->preflight->inspect($draft);
        $this->assertContains('invalid_variant_mapping_15', $result['errors']);
        $this->assertContains('invalid_variant_mapping_20', $result['errors']);
    }

    public function test_incomplete_or_oversized_variation_sets_fail_closed(): void
    {
        $draft = $this->draft();
        array_pop($draft['variants']);
        $result = $this->preflight->inspect($draft);
        $this->assertContains('incomplete_variants', $result['errors']);
        $result = $this->preflight->inspect($this->draft(101));
        $this->assertContains('incomplete_variants', $result['errors']);
    }

    public function test_missing_variant_image_and_malformed_gallery_are_rejected(): void
    {
        $draft = $this->draft();
        $draft['variants'][2]['image'] = 'http://localhost/file.jpg';
        $draft['images'] = [['url' => 'file:///etc/passwd']];
        $result = $this->preflight->inspect($draft);
        $this->assertContains('missing_or_unsafe_variant_image_2', $result['errors']);
        $this->assertContains('invalid_gallery', $result['errors']);
    }

    public function test_unapproved_inventory_cost_and_publish_fields_are_rejected(): void
    {
        $draft = $this->draft();
        $draft['publish'] = true;
        $draft['variants'][3]['quantity'] = 100;
        $draft['variants'][5]['supplier_price'] = '4.99';
        $result = $this->preflight->inspect($draft);
        $this->assertContains('unapproved_catalog_fields', $result['errors']);
        $this->assertContains('unapproved_inventory_or_supplier_cost_3', $result['errors']);
        $this->assertContains('unapproved_inventory_or_supplier_cost_5', $result['errors']);
    }

    public function test_blank_parent_price_and_wrong_currency_are_rejected(): void
    {
        $draft = $this->draft();
        $draft['currency_code'] = 'GBP';
        $draft['regular_price'] = null;
        $result = $this->preflight->inspect($draft);
        $this->assertContains('currency_requires_review', $result['errors']);
        $this->assertContains('missing_regular_retail_price', $result['errors']);
    }
}
