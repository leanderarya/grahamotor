<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Product;
use App\Models\ShiftOverrideAudit;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashierSessionService;
use App\Services\DraftService;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ShiftOverdueProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function cashier(): User
    {
        return User::factory()->create(['role' => 'kasir']);
    }

    private function openSession(User $cashier, string $openedAt = '2026-11-16 12:00:00'): CashierSession
    {
        return CashierSession::create([
            'user_id' => $cashier->id,
            'opening_cash' => 100000,
            'opened_at' => Carbon::parse($openedAt, 'UTC'),
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'SHIFT-0001',
            'name' => 'Filter Oli',
            'stock' => 10,
            'cost_price' => 50000,
            'sell_price' => 80000,
        ]);
    }

    public function test_overdue_boundary_is_midnight_in_jakarta(): void
    {
        $session = $this->openSession($this->cashier(), '2026-11-16 16:59:59');
        $service = app(CashierSessionService::class);

        Carbon::setTestNow(Carbon::parse('2026-11-16 16:59:59', 'UTC'));
        $this->assertFalse($service->isOverdue($session));

        Carbon::setTestNow(Carbon::parse('2026-11-16 17:00:00', 'UTC'));
        $this->assertTrue($service->isOverdue($session->fresh()));
    }

    public function test_cashier_cannot_process_payment_after_cutoff(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);
        $product = $this->product();

        Carbon::setTestNow(Carbon::parse('2026-11-16 17:00:00', 'UTC'));

        try {
            app(TransactionService::class)->processPayment($cashier, [
                'cashier_session_id' => $session->id,
                'cart' => [['id' => $product->id, 'qty' => 1]],
                'payment_method' => 'cash',
                'amount_paid' => 80000,
                'change_amount' => 0,
                'customer_type' => 'general',
            ]);
            $this->fail('Expected the overdue shift to reject payment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('session', $exception->errors());
        }

        $this->assertSame(0, Transaction::where('status', 'paid')->count());
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_cashier_close_is_blocked_but_admin_override_closes_and_audits(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(CashierSessionService::class);

        Carbon::setTestNow(Carbon::parse('2026-11-16 17:00:00', 'UTC'));

        try {
            $service->closeSession($cashier->id, 100000);
            $this->fail('Expected cashier close to require an override.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('session', $exception->errors());
        }

        $service->closeOverdueSession(
            $session->id,
            $admin->id,
            95000,
            'Uang kas dihitung saat pergantian shift',
        );

        $session->refresh();
        $this->assertNotNull($session->closed_at);
        $this->assertSame('100000.00', (string) $session->expected_cash);
        $this->assertSame('-5000.00', (string) $session->cash_difference);
        $this->assertDatabaseHas('shift_override_audits', [
            'cashier_session_id' => $session->id,
            'actor_id' => $admin->id,
            'reason' => 'Uang kas dihitung saat pergantian shift',
            'closing_cash_physical' => 95000,
        ]);
        $this->assertSame(1, ShiftOverrideAudit::count());

        $newSession = $service->openSession($cashier->id, 0);
        $this->assertNotSame($session->id, $newSession->id);
    }

    public function test_owner_can_override_and_cashier_cannot(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);
        $service = app(CashierSessionService::class);
        $owner = User::factory()->create(['role' => 'owner']);

        Carbon::setTestNow(Carbon::parse('2026-11-16 17:00:00', 'UTC'));

        try {
            $service->closeOverdueSession(
                $session->id,
                $cashier->id,
                100000,
                'Kasir mencoba override shift sendiri',
            );
            $this->fail('Expected a cashier to be denied override access.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $service->closeOverdueSession($session->id, $owner->id, 100000, 'Owner menutup shift terlambat');

        $this->assertNotNull($session->fresh()->closed_at);
        $this->assertSame($owner->id, ShiftOverrideAudit::firstOrFail()->actor_id);
    }

    public function test_old_draft_cannot_be_reassigned_to_a_new_session(): void
    {
        $cashier = $this->cashier();
        $oldSession = $this->openSession($cashier);
        $oldDraft = Transaction::create([
            'invoice_number' => 'INV-OLD-SHIFT',
            'user_id' => $cashier->id,
            'cashier_session_id' => $oldSession->id,
            'total_amount' => 80000,
            'total_profit' => 30000,
            'status' => 'draft',
            'customer_type' => 'general',
        ]);
        $product = $this->product();
        $oldDraft->transactionItems()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price_at_time' => 80000,
            'cost_at_time' => 50000,
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(CashierSessionService::class);

        Carbon::setTestNow(Carbon::parse('2026-11-16 17:00:00', 'UTC'));
        $service->closeOverdueSession($oldSession->id, $admin->id, 100000, 'Menutup shift lama');
        $newSession = $service->openSession($cashier->id, 0);

        try {
            app(TransactionService::class)->processPayment($cashier, [
                'draft_id' => $oldDraft->id,
                'cashier_session_id' => $newSession->id,
                'cart' => [['id' => $product->id, 'qty' => 1]],
                'payment_method' => 'cash',
                'amount_paid' => 80000,
                'change_amount' => 0,
                'customer_type' => 'general',
            ]);
            $this->fail('Expected draft reassignment to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('draft_id', $exception->errors());
        }

        $this->assertSame('draft', $oldDraft->fresh()->status);
        $this->assertSame($oldSession->id, $oldDraft->fresh()->cashier_session_id);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_payment_requires_the_active_session_id_at_service_boundary(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier);
        $product = $this->product();

        try {
            app(TransactionService::class)->processPayment($cashier, [
                'cart' => [['id' => $product->id, 'qty' => 1]],
                'payment_method' => 'cash',
                'amount_paid' => 80000,
                'change_amount' => 0,
                'customer_type' => 'general',
            ]);
            $this->fail('Expected payment without a session ID to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('session', $exception->errors());
        }

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(0, Transaction::where('status', 'paid')->count());
    }

    public function test_payment_rejects_the_previous_session_after_shift_changes(): void
    {
        $cashier = $this->cashier();
        $oldSession = $this->openSession($cashier);
        $admin = User::factory()->create(['role' => 'admin']);
        $product = $this->product();
        $sessions = app(CashierSessionService::class);

        Carbon::setTestNow(Carbon::parse('2026-11-16 17:00:00', 'UTC'));
        $sessions->closeOverdueSession(
            $oldSession->id,
            $admin->id,
            100000,
            'Menutup shift sebelum pembayaran tertunda',
        );
        $sessions->openSession($cashier->id, 0);

        try {
            app(TransactionService::class)->processPayment($cashier, [
                'cashier_session_id' => $oldSession->id,
                'cart' => [['id' => $product->id, 'qty' => 1]],
                'payment_method' => 'cash',
                'amount_paid' => 80000,
                'change_amount' => 0,
                'customer_type' => 'general',
            ]);
            $this->fail('Expected payment from the previous session to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('session', $exception->errors());
        }

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(0, Transaction::where('status', 'paid')->count());
    }

    public function test_web_payment_requires_a_session_id(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier);
        $product = $this->product();

        $this->actingAs($cashier)
            ->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'cart' => [['id' => $product->id, 'qty' => 1]],
                'payment_method' => 'cash',
                'amount_paid' => 80000,
                'change_amount' => 0,
                'customer_type' => 'general',
            ])
            ->assertSessionHasErrors('cashier_session_id');

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(0, Transaction::where('status', 'paid')->count());
    }

    public function test_api_payment_requires_a_session_id(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier);
        $product = $this->product();
        Sanctum::actingAs($cashier);

        $this->postJson('/api/transactions', [
            'cart' => [['id' => $product->id, 'qty' => 1]],
            'payment_method' => 'cash',
            'amount_paid' => 80000,
            'change_amount' => 0,
            'customer_type' => 'general',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cashier_session_id');

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(0, Transaction::where('status', 'paid')->count());
    }

    public function test_clearing_a_new_session_does_not_delete_old_session_drafts(): void
    {
        $cashier = $this->cashier();
        $oldSession = $this->openSession($cashier);
        $draft = Transaction::create([
            'invoice_number' => 'INV-OLD-DRAFT',
            'user_id' => $cashier->id,
            'cashier_session_id' => $oldSession->id,
            'total_amount' => 0,
            'total_profit' => 0,
            'status' => 'draft',
            'customer_type' => 'general',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $sessions = app(CashierSessionService::class);

        Carbon::setTestNow(Carbon::parse('2026-11-16 17:00:00', 'UTC'));
        $sessions->closeOverdueSession($oldSession->id, $admin->id, 100000, 'Tutup shift lama');
        $newSession = $sessions->openSession($cashier->id, 0);

        app(DraftService::class)->clear($draft->id, $cashier->id, $newSession->id);

        $this->assertDatabaseHas('transactions', [
            'id' => $draft->id,
            'status' => 'draft',
            'cashier_session_id' => $oldSession->id,
        ]);
    }

    public function test_open_session_rejects_a_second_active_shift(): void
    {
        $cashier = $this->cashier();
        $service = app(CashierSessionService::class);
        $service->openSession($cashier->id, 100000);

        $this->expectException(ValidationException::class);
        $service->openSession($cashier->id, 0);
    }
}
