<?php

namespace Tests\Feature;

use App\Models\BdpSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class BdpConnectedStoreSettingsRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_form_uses_real_named_post_routes_and_no_broken_attributes(): void
    {
        $this->withSession(['bdp_logged_in' => true])
            ->get(route('bdp.settings'))
            ->assertOk()
            ->assertSee(route('bdp.settings.store.save'), false)
            ->assertSee(route('bdp.settings.connection.test'), false)
            ->assertDontSee('/settings/connected-store/save')
            ->assertDontSee('"store_db_host\', $settings', false);
    }

    public function test_customer_cannot_save_legacy_global_store_connection(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->post(route('bdp.settings.store.save'), [
            'store_push_method' => 'database',
            'store_db_host' => 'localhost',
        ])->assertRedirect(route('bdp.login.form'));

        $this->assertDatabaseCount('bdp_settings', 0);
    }

    public function test_bad_website_url_cannot_be_saved_as_mysql_host(): void
    {
        $this->withSession(['bdp_logged_in' => true])
            ->post(route('bdp.settings.store.save'), [
                'store_push_method' => 'database',
                'store_db_host' => 'https://sandbox.daystart.pk',
                'store_db_port' => '3306',
                'store_db_database' => 'shop_db',
                'store_db_username' => 'shop_user',
            ])->assertSessionHasErrors('store_db_host');

        $this->assertNull(BdpSetting::getValue('store_db_host'));
        $this->assertNull(BdpSetting::getValue('store_db_username'));
    }

    public function test_test_connection_returns_safe_error_for_url_database_host(): void
    {
        $this->withSession(['bdp_logged_in' => true])
            ->post(route('bdp.settings.connection.test'), [
                'store_push_method' => 'database',
                'store_db_host' => 'https://sandbox.daystart.pk',
                'store_db_port' => '3306',
            ])->assertRedirect()
            ->assertSessionHas('error', function ($message): bool {
                return str_contains($message, 'MySQL hostname')
                    && !str_contains($message, 'SQLSTATE');
            });
    }

    public function test_valid_mysql_host_saves_and_secret_is_encrypted_not_returned(): void
    {
        $route = route('bdp.settings.store.save');
        $this->withSession(['bdp_logged_in' => true])
            ->post($route, [
                'store_push_method' => 'database',
                'connected_store_enabled' => '0',
                'connected_store_name' => 'Legacy test shop',
                'store_db_host' => 'localhost',
                'store_db_port' => '3306',
                'store_db_database' => 'private_store',
                'store_db_username' => 'private_db_user',
                'store_db_password' => 'test-password-123',
            ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('localhost', BdpSetting::getValue('store_db_host'));
        $this->assertSame('0', BdpSetting::getValue('connected_store_enabled'));
        $stored = BdpSetting::getValue('store_db_password');
        $this->assertNotSame('test-password-123', $stored);
        $this->assertSame('test-password-123', Crypt::decryptString($stored));

        $this->withSession(['bdp_logged_in' => true])->post($route, [
            'store_push_method' => 'database',
            'store_db_host' => 'localhost',
            'store_db_password' => '',
        ])->assertRedirect();
        $this->assertSame($stored, BdpSetting::getValue('store_db_password'));
    }

    public function test_api_mode_stores_only_legacy_endpoint_not_a_botble_pairing(): void
    {
        $this->withSession(['bdp_logged_in' => true])
            ->post(route('bdp.settings.store.save'), [
                'store_push_method' => 'api',
                'connected_store_enabled' => '0',
                'store_api_endpoint' => 'https://example.test/legacy-receiver',
                'store_api_secret' => 'test-secret-not-real',
            ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('api', BdpSetting::getValue('store_push_method'));
        $this->assertSame('https://example.test/legacy-receiver', BdpSetting::getValue('store_api_endpoint'));
        $this->assertSame('test-secret-not-real', Crypt::decryptString(BdpSetting::getValue('store_api_secret')));
        $this->assertDatabaseCount('saas_botble_stores', 0);
    }
}
