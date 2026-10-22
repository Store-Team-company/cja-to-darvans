<?php

namespace Tests\Feature;

use App\Models\BdpAdminProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SiteGroundAdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_hashed_admin_profile_can_log_in(): void
    {
        BdpAdminProfile::query()->updateOrCreate(
            ['id' => 1],
            ['name' => 'SiteGround Admin', 'username' => 'site_admin',
             'password' => Hash::make('example-strong-password-2026')]
        );

        $this->post('/login', [
            'username' => 'site_admin',
            'password' => 'example-strong-password-2026',
        ])->assertRedirect(route('bdp.index'));

        $this->assertTrue((bool) session('bdp_logged_in'));
    }

    public function test_invalid_credentials_do_not_grant_dashboard_access(): void
    {
        BdpAdminProfile::query()->updateOrCreate(
            ['id' => 1],
            ['name' => 'SiteGround Admin', 'username' => 'site_admin',
             'password' => Hash::make('example-strong-password-2026')]
        );

        $this->post('/login', [
            'username' => 'site_admin',
            'password' => 'different-password',
        ])->assertRedirect();

        $this->get('/')->assertRedirect(route('bdp.login.form'));
    }
}
