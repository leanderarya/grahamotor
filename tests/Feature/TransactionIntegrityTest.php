<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Characterization tests for the F-04 (payment amount integrity) and
 * F-05 (void status) risks recorded in qa-artifacts/QA-REPORT.html.
 *
 * These assert the CURRENT behaviour so the gap is visible and any future
 * fix makes them fail on purpose. They are not a statement that the
 * behaviour is correct.
 */
class TransactionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function kasir(): User
    {
        return User::factory()->create(['role' => 'kasir']);
    }

    private function openSession(User $user): CashierSession
    {
        return CashierSession::create([
            'user_id' => $user->id,
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);
    }

    private function product(int $stock = 10): Product
    {
        return Product::create([
            'sku' => 'A1-0007',
            'name' => 'Rack end Avanza',
            'stock' => $stock,
            'cost_price' => 70000,
            'sell_price' => 105000,
        ]);
    }

    public function test_f04_non_cash_payment_persists_client_amount_without_server_check(): void
    {
        $user = $this->kasir();
        $this->openSession($user);
        $product = $this->product();

        $transaction = app(TransactionService::class)->processPayment($user, [
            'cart' => [['id' => $product->id, 'qty' => 1]],
            'payment_method' => 'bank',
            'amount_paid' => 0,
            'change_amount' => -12345,
            'customer_type' => 'general',
        ]);

        // Current behaviour: the client-supplied figures are stored verbatim
        // while the server-computed total says Rp105.000.
        $this->assertSame('paid', $transaction->fresh()->status);
        $this->assertSame('105000.00', (string) $transaction->fresh()->total_amount);
        $this->assertSame('0.00', (string) $transaction->fresh()->amount_paid);
        $this->assertSame('-12345.00', (string) $transaction->fresh()->change_amount);
    }

    public function test_f04_cash_payment_accepts_underpayment(): void
    {
        $user = $this->kasir();
        $this->openSession($user);
        $product = $this->product();

        $transaction = app(TransactionService::class)->processPayment($user, [
            'cart' => [['id' => $product->id, 'qty' => 1]],
            'payment_method' => 'cash',
            'amount_paid' => 1,
            'change_amount' => 0,
            'customer_type' => 'general',
        ]);

        $this->assertSame('paid', $transaction->fresh()->status);
        $this->assertSame('1.00', (string) $transaction->fresh()->amount_paid);
    }

    public function test_f04_duplicate_cart_lines_do_not_bypass_stock_check(): void
    {
        $user = $this->kasir();
        $this->openSession($user);
        $product = $this->product(10);

        // Reported as a risk during audit; the lockForUpdate() re-read inside
        // the loop means the second line sees the already-decremented stock,
        // so the aggregate over-sell is rejected and the transaction rolls back.
        try {
            app(TransactionService::class)->processPayment($user, [
                'cart' => [
                    ['id' => $product->id, 'qty' => 6],
                    ['id' => $product->id, 'qty' => 6],
                ],
                'payment_method' => 'cash',
                'amount_paid' => 2000000,
                'change_amount' => 0,
                'customer_type' => 'general',
            ]);
            $this->fail('Expected ValidationException for aggregate stock over-sell.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(0, Transaction::where('status', 'paid')->count());
    }

    public function test_f04_single_line_over_stock_is_still_rejected(): void
    {
        $user = $this->kasir();
        $this->openSession($user);
        $product = $this->product(5);

        $this->expectException(ValidationException::class);

        app(TransactionService::class)->processPayment($user, [
            'cart' => [['id' => $product->id, 'qty' => 6]],
            'payment_method' => 'cash',
            'amount_paid' => 105000,
            'change_amount' => 0,
            'customer_type' => 'general',
        ]);
    }

    public function test_f05_draft_transaction_can_be_voided_and_restores_stock(): void
    {
        $user = $this->kasir();
        $session = $this->openSession($user);
        $product = $this->product(10);

        $draft = Transaction::create([
            'invoice_number' => 'INV-DRAFT-F05',
            'user_id' => $user->id,
            'cashier_session_id' => $session->id,
            'total_amount' => 105000,
            'total_profit' => 35000,
            'status' => 'draft',
            'customer_type' => 'general',
        ]);
        $draft->transactionItems()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'price_at_time' => 105000,
            'cost_at_time' => 70000,
        ]);

        app(TransactionService::class)->voidTransaction($draft, $user->id, 'uji draft');

        // The draft never decremented stock, yet void adds the quantity back.
        $this->assertSame('voided', $draft->fresh()->status);
        $this->assertSame(12, $product->fresh()->stock);
    }

    public function test_f05_second_void_is_rejected(): void
    {
        $user = $this->kasir();
        $this->openSession($user);
        $product = $this->product();

        $transaction = app(TransactionService::class)->processPayment($user, [
            'cart' => [['id' => $product->id, 'qty' => 1]],
            'payment_method' => 'cash',
            'amount_paid' => 105000,
            'change_amount' => 0,
            'customer_type' => 'general',
        ]);

        app(TransactionService::class)->voidTransaction($transaction, $user->id, 'pertama');
        $this->assertSame(10, $product->fresh()->stock);

        $this->expectException(ValidationException::class);
        app(TransactionService::class)->voidTransaction($transaction->fresh(), $user->id, 'kedua');
    }

    public function test_f05_void_by_another_user_is_rejected(): void
    {
        $user = $this->kasir();
        $this->openSession($user);
        $product = $this->product();

        $transaction = app(TransactionService::class)->processPayment($user, [
            'cart' => [['id' => $product->id, 'qty' => 1]],
            'payment_method' => 'cash',
            'amount_paid' => 105000,
            'change_amount' => 0,
            'customer_type' => 'general',
        ]);

        $other = $this->kasir();

        $this->expectException(ValidationException::class);
        app(TransactionService::class)->voidTransaction($transaction, $other->id, 'bukan milik saya');
    }
}
