<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_authenticated_staff_users_are_redirected_to_pos_from_dashboard()
    {
        $this->actingAs(User::factory()->create([
            'role' => 'kasir',
        ]));

        $this->get(route('dashboard'))
            ->assertRedirect(route('transactions.create'));
    }

    public function test_admin_users_can_view_the_admin_dashboard()
    {
        $this->actingAs(User::factory()->create([
            'role' => 'admin',
        ]));

        $this->get('/admin')->assertOk();
    }

    public function test_admin_users_can_view_the_monthly_analytics_page()
    {
        $this->actingAs(User::factory()->create([
            'role' => 'admin',
        ]));

        $this->get('/admin/monthly-analytics')
            ->assertOk()
            ->assertSee('Analitik Bulanan');
    }

    public function test_admin_users_can_view_the_monthly_reports_page()
    {
        $this->actingAs(User::factory()->create([
            'role' => 'admin',
        ]));

        $this->get('/admin/monthly-reports')
            ->assertOk()
            ->assertSee('Finalisasi Bulan');
    }

    public function test_admin_users_can_view_the_import_histories_page()
    {
        $this->actingAs(User::factory()->create([
            'role' => 'admin',
        ]));

        $this->get('/admin/import-histories')
            ->assertOk()
            ->assertSee('Riwayat Impor');
    }
}
