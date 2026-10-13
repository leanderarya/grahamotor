<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CheckoutPaymentErrorTest extends TestCase
{
    use RefreshDatabase;

    private function kasir(): User
    {
        return User::factory()->create(['role' => 'kasir']);
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'A1-0007',
            'name' => 'Rack end Avanza',
            'stock' => 10,
            'cost_price' => 70000,
            'sell_price' => 105000,
        ]);
    }

    public function test_unexpected_payment_failure_surfaces_a_safe_error_message(): void
    {
        $user = $this->kasir();
        $product = $this->product();

        $this->mock(TransactionService::class, function ($mock) {
            $mock->shouldReceive('processPayment')->andThrow(
                new RuntimeException('SQLSTATE[HY000]: table is locked'),
            );
        });

        $response = $this->actingAs($user)
            ->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'cart' => [['id' => $product->id, 'qty' => 1]],
                'payment_method' => 'cash',
                'amount_paid' => 105000,
                'change_amount' => 0,
                'customer_type' => 'general',
            ]);

        $response->assertRedirect(route('transactions.create'));
        $response->assertSessionHasErrors('error');

        $message = session('errors')->first('error');
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('table is locked', $message);
    }

    public function test_validation_failure_keeps_its_own_error_key(): void
    {
        $user = $this->kasir();
        $product = $this->product();

        $this->actingAs($user)
            ->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'cart' => [['id' => $product->id, 'qty' => 99]],
                'payment_method' => 'cash',
                'amount_paid' => 105000,
                'change_amount' => 0,
                'customer_type' => 'general',
            ])
            ->assertSessionHasErrors('cart');
    }

    public function test_successful_payment_redirects_back_with_a_success_flash(): void
    {
        $user = $this->kasir();
        $product = $this->product();

        \App\Models\CashierSession::create([
            'user_id' => $user->id,
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'cart' => [['id' => $product->id, 'qty' => 1]],
                'payment_method' => 'cash',
                'amount_paid' => 105000,
                'change_amount' => 0,
                'customer_type' => 'general',
            ])
            ->assertRedirect(route('transactions.create'))
            ->assertSessionHas('success');

        $this->assertSame(9, $product->fresh()->stock);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_paying_a_draft_redirects_to_pos_instead_of_the_stale_checkout_page(): void
    {
        $user = $this->kasir();
        $product = $this->product();

        \App\Models\CashierSession::create([
            'user_id' => $user->id,
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $draft = \App\Models\Transaction::create([
            'invoice_number' => 'INV-DRAFT-1',
            'user_id' => $user->id,
            'total_amount' => 105000,
            'total_profit' => 0,
            'status' => 'draft',
            'customer_type' => 'general',
        ]);
        $draft->transactionItems()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price_at_time' => 105000,
            'cost_at_time' => 70000,
        ]);

        // The checkout page was the referrer; a redirect back() would land on
        // /pos/checkout/{id}, which no longer resolves once the draft is paid.
        $response = $this->actingAs($user)
            ->from(route('transactions.checkout', ['transaction' => $draft->id]))
            ->post(route('transactions.store'), [
                'draft_id' => $draft->id,
                'cart' => [['id' => $product->id, 'qty' => 1]],
                'payment_method' => 'cash',
                'amount_paid' => 105000,
                'change_amount' => 0,
                'customer_type' => 'general',
            ]);

        $response->assertRedirect(route('transactions.create'));
        $this->assertSame('paid', $draft->fresh()->status);
    }
}
