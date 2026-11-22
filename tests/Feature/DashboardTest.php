<?php

namespace Tests\Feature;

use App\Filament\Widgets\MonthlyAnalyticsStatsOverview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_admin_and_staff_cannot_access_cashier_routes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/pos')
            ->assertRedirect('/admin');

        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->get('/pos')
            ->assertRedirect(route('pin.login'));
    }

    public function test_admin_cannot_access_cashier_api_routes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/session')
            ->assertForbidden();
    }

    public function test_admin_can_view_omzet_without_financial_profit_or_margin(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => 'admin',
        ]));

        $this->get('/admin/monthly-analytics')
            ->assertOk()
            ->assertSee('Analitik Bulanan');

        Livewire::actingAs(auth()->user())
            ->test(MonthlyAnalyticsStatsOverview::class)
            ->assertSee('Omset')
            ->assertDontSee('Profit Bulanan')
            ->assertDontSee('Akumulasi margin bulan terpilih');
    }

    public function test_admin_users_cannot_view_the_monthly_reports_page()
    {
        $this->actingAs(User::factory()->create([
            'role' => 'admin',
        ]));

        $this->get('/admin/monthly-reports')
            ->assertForbidden();
    }

    public function test_admin_users_cannot_view_the_import_histories_page()
    {
        $this->actingAs(User::factory()->create([
            'role' => 'admin',
        ]));

        $this->get('/admin/import-histories')
            ->assertForbidden();
    }

    public function test_admin_can_view_purchase_invoice_prices(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => 'admin',
        ]));

        $this->get('/admin/purchases')->assertOk();
    }

    public function test_owner_can_view_financial_reports()
    {
        $this->actingAs(User::factory()->create([
            'role' => 'owner',
        ]));

        $this->get('/admin/monthly-analytics')
            ->assertOk()
            ->assertSee('Analitik Bulanan');

        Livewire::actingAs(auth()->user())
            ->test(MonthlyAnalyticsStatsOverview::class)
            ->assertSee('Profit Bulanan')
            ->assertSee('Akumulasi margin bulan terpilih');

        $this->get('/admin/monthly-reports')
            ->assertOk()
            ->assertSee('Finalisasi Bulan');

        $this->get('/admin/import-histories')
            ->assertOk()
            ->assertSee('Riwayat Impor');
    }

    public function test_admin_cannot_access_transaction_profit_resource()
    {
        $this->actingAs(User::factory()->create([
            'role' => 'admin',
        ]));

        $this->get('/admin/transactions')->assertForbidden();
    }

    public function test_owner_can_access_the_admin_panel()
    {
        $this->actingAs(User::factory()->create([
            'role' => 'owner',
        ]));

        $this->get('/admin')->assertOk();
    }

    public function test_owner_account_can_only_be_provisioned_from_the_cli()
    {
        $email = 'owner-provisioning@example.test';
        $existingAdmin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Existing Administrator',
        ]);
        $existingAdminPassword = $existingAdmin->password;

        $this->artisan('owner:create', [
            '--name' => 'Primary Owner',
            '--email' => $email,
        ])
            ->expectsQuestion('Password Owner', 'a-secure-test-password')
            ->expectsConfirmation("Buat akun Owner untuk {$email}?", 'yes')
            ->expectsOutput('Akun Owner berhasil dibuat.')
            ->assertExitCode(0);

        $owner = User::where('email', $email)->firstOrFail();
        $this->assertSame('owner', $owner->role);
        $this->assertNull($owner->pin);
        $this->assertSame('Existing Administrator', $existingAdmin->fresh()->name);
        $this->assertSame($existingAdminPassword, $existingAdmin->fresh()->password);
        $this->assertDatabaseHas('user_management_audits', [
            'actor_id' => null,
            'target_user_id' => $owner->id,
            'action' => 'owner_provisioned',
        ]);
        $this->actingAs($owner)->get('/admin')->assertOk();
    }
}
