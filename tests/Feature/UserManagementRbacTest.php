<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\User;
use App\Models\UserManagementAudit;
use App\Services\UserManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UserManagementRbacTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(string $pin = '4826'): User
    {
        return User::factory()->create([
            'role' => 'kasir',
            'pin' => $pin,
        ]);
    }

    public function test_admin_can_create_and_reset_only_cashier_accounts_with_audit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(UserManagementService::class);
        $cashier = $service->create($admin, [
            'name' => 'Kasir Baru',
            'email' => null,
            'pin' => '4826',
            'role' => 'kasir',
        ]);

        $this->assertSame('kasir', $cashier->role);
        $this->assertTrue($cashier->is_active);
        $this->assertTrue(Hash::check('4826', $cashier->pin));
        $this->assertDatabaseHas('user_management_audits', [
            'actor_id' => $admin->id,
            'target_user_id' => $cashier->id,
            'action' => 'created',
        ]);

        $service->update($admin, $cashier, [
            'role' => 'kasir',
            'pin' => '5937',
        ]);

        $this->assertTrue(Hash::check('5937', $cashier->fresh()->pin));
        $this->assertDatabaseHas('user_management_audits', [
            'actor_id' => $admin->id,
            'target_user_id' => $cashier->id,
            'action' => 'pin_reset',
            'metadata' => null,
        ]);
        $this->assertSame(2, UserManagementAudit::count());
        $this->assertNotNull(UserManagementAudit::oldest('id')->firstOrFail()->created_at);
    }

    public function test_admin_cannot_create_or_manage_admin_or_owner_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'owner']);
        $service = app(UserManagementService::class);

        try {
            $service->create($admin, [
                'name' => 'Injected Admin',
                'email' => 'injected@example.test',
                'password' => 'long-enough-password',
                'role' => 'admin',
            ]);
            $this->fail('Expected Admin role creation to be forbidden.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        foreach ([$otherAdmin, $owner] as $target) {
            try {
                $service->deactivate($admin, $target);
                $this->fail('Expected Admin/Owner account management to be forbidden.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->assertFalse(User::where('name', 'Injected Admin')->exists());
        $this->assertSame(0, UserManagementAudit::count());

        $this->actingAs($admin)
            ->get("/admin/users/{$otherAdmin->id}/edit")
            ->assertNotFound();
        $this->get("/admin/users/{$owner->id}/edit")->assertNotFound();
    }

    public function test_admin_cannot_assign_an_existing_cashier_pin_to_another_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->cashier('4826');
        $second = $this->cashier('5937');

        try {
            app(UserManagementService::class)->update($admin, $second, [
                'role' => 'kasir',
                'pin' => '4826',
            ]);
            $this->fail('Expected duplicate cashier PIN to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.pin', $exception->errors());
        }

        $this->assertTrue(Hash::check('5937', $second->fresh()->pin));
        $this->assertSame(0, UserManagementAudit::count());
        $this->assertSame($first->id, User::findCashierForPin('4826')?->id);
    }

    public function test_admin_can_deactivate_cashier_but_not_with_an_open_shift(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = $this->cashier();
        CashierSession::create([
            'user_id' => $cashier->id,
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);

        try {
            app(UserManagementService::class)->deactivate($admin, $cashier);
            $this->fail('Expected active shift to block deactivation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user', $exception->errors());
        }

        $cashier->openCashierSession()->update(['closed_at' => now()]);
        app(UserManagementService::class)->deactivate($admin, $cashier);

        $this->assertFalse($cashier->fresh()->is_active);
        $this->assertNull(User::findCashierForPin('4826'));
        $this->assertDatabaseHas('user_management_audits', [
            'actor_id' => $admin->id,
            'target_user_id' => $cashier->id,
            'action' => 'deactivated',
        ]);

        $this->actingAs($cashier->fresh())
            ->get('/dashboard')
            ->assertRedirect(route('pin.login'));

        try {
            app(UserManagementService::class)->activate($admin, $cashier);
            $this->fail('Expected cashier activation to be Owner-only.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $owner = User::factory()->create(['role' => 'owner']);
        app(UserManagementService::class)->activate($owner, $cashier);

        $this->assertTrue($cashier->fresh()->is_active);
        $this->assertDatabaseHas('user_management_audits', [
            'actor_id' => $owner->id,
            'target_user_id' => $cashier->id,
            'action' => 'activated',
        ]);
    }

    public function test_admin_cannot_deactivate_a_cashier_that_is_themselves(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = $this->cashier();
        $this->expectException(HttpException::class);

        app(UserManagementService::class)->deactivate($cashier, $cashier);
    }
}
