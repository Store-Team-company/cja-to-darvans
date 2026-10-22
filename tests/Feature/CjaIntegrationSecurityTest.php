<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CjaIntegrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_import_and_management_routes_are_registered(): void
    {
        foreach ([
            'bdp.cj.import.bulk',
            'bdp.settings',
            'bdp.settings.update',
            'bdp.profile',
            'bdp.categories.mapping.store',
            'bdp.products.images.zip',
            'bdp.products.labels.assign',
            'bdp.products.reviews.fetch.cj',
            'bdp.bulk.optimization.export',
            'bdp.sync.queue.index',
            'bdp.sync.queue.enqueue',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Missing route: {$name}");
        }
    }

    public function test_unauthenticated_user_cannot_open_woocommerce_connection_approval(): void
    {
        $this->get('/wc-auth/v1/authorize?callback_url=https%3A%2F%2Fwww.cjdropshipping.com%2Fcallback')
            ->assertRedirect(route('bdp.login.form'));
    }

    public function test_unauthenticated_user_cannot_approve_connection(): void
    {
        $this->post('/wc-auth/v1/authorize/approve', [
            'callback_url' => 'https://www.cjdropshipping.com/callback',
        ])->assertStatus(403);
    }

    public function test_unverified_oauth_consumer_key_is_not_sufficient(): void
    {
        config([
            'balenco-data-pilot.cja_consumer_key' => 'ck_for_tests',
            'balenco-data-pilot.cja_consumer_secret' => 'cs_for_tests',
        ]);

        // Invalid authentication must be rejected before the API touches the database.
        $this->get('/wp-json/wc/v3/system_status?oauth_consumer_key=ck_for_tests&oauth_signature=forged')
            ->assertStatus(401);
    }

    public function test_unconfigured_woocommerce_credentials_are_denied(): void
    {
        config([
            'balenco-data-pilot.cja_consumer_key' => '',
            'balenco-data-pilot.cja_consumer_secret' => '',
        ]);

        $this->get('/wp-json/wc/v3/system_status')
            ->assertStatus(401);
    }

    public function test_webhook_routes_keep_csrf_exemption_scoped_to_api(): void
    {
        $middleware = new \App\Http\Middleware\VerifyCsrfToken(app(), app('encrypter'));
        $reflection = new \ReflectionProperty($middleware, 'except');
        $reflection->setAccessible(true);
        $except = $reflection->getValue($middleware);

        $this->assertContains('wp-json/wc/*', $except);
        $this->assertNotContains('wc-auth/v1/authorize/approve', $except);
        $this->assertNotContains('login', $except);
    }
}
