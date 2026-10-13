<?php

namespace Tests\Feature;

use App\Filament\Concerns\ResolvesMonthlyAnalyticsFilter;
use App\Models\CashierSession;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionDateReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_recap_without_open_session_includes_only_paid_transactions_created_today(): void
    {
        $user = User::factory()->create(['role' => 'kasir']);
        $today = now()->startOfDay();

        $todayTransaction = $this->transaction($user, $today->copy()->addHours(12), 'paid', 100);
        $this->transaction($user, $today->copy()->subSecond(), 'paid', 200);
        $this->transaction($user, $today->copy()->addHours(13), 'voided', 300);

        $recap = app(TransactionService::class)->getRecap($user->id, null);

        $this->assertSame(1, $recap['summary']['total_transactions']);
        $this->assertSame(100.0, $recap['summary']['revenue_total']);
        $this->assertEquals([$todayTransaction->id], $recap['transactions']->pluck('id')->all());
    }

    public function test_recap_with_open_session_uses_session_instead_of_date_and_user_scope(): void
    {
        $user = User::factory()->create(['role' => 'kasir']);
        $otherUser = User::factory()->create(['role' => 'kasir']);
        $session = CashierSession::create([
            'user_id' => $user->id,
            'opening_cash' => 0,
            'opened_at' => now()->subDays(2),
        ]);

        $inSession = $this->transaction($user, now()->subDay(), 'paid', 125, $session);
        $this->transaction($user, now(), 'paid', 250);
        $this->transaction($otherUser, now(), 'paid', 500, $session);
        $this->transaction($user, now()->subDay(), 'voided', 750, $session);

        $recap = app(TransactionService::class)->getRecap($user->id, $session);

        $this->assertSame(1, $recap['summary']['total_transactions']);
        $this->assertSame(125.0, $recap['summary']['revenue_total']);
        $this->assertEquals([$inSession->id], $recap['transactions']->pluck('id')->all());
    }

    public function test_history_includes_paid_and_voided_transactions_across_dates_but_not_drafts(): void
    {
        $user = User::factory()->create(['role' => 'kasir']);
        $oldPaid = $this->transaction($user, now()->subMonths(3), 'paid', 100);
        $recentVoided = $this->transaction($user, now(), 'voided', 200);
        $this->transaction($user, now(), 'draft', 300);
        $this->transaction(User::factory()->create(['role' => 'kasir']), now(), 'paid', 400);

        $history = app(TransactionService::class)->getHistory($user->id);

        $this->assertSame(2, $history->total());
        $this->assertEquals([$recentVoided->id, $oldPaid->id], $history->getCollection()->pluck('id')->all());
    }

    public function test_monthly_analytics_filter_resolves_available_selected_and_invalid_months(): void
    {
        $user = User::factory()->create(['role' => 'kasir']);
        $this->transaction($user, Carbon::parse('2025-02-10 12:00:00'), 'paid', 100);
        $this->transaction($user, Carbon::parse('2025-04-10 12:00:00'), 'paid', 100);

        $filter = new class
        {
            use ResolvesMonthlyAnalyticsFilter;

            public function options(): array
            {
                return $this->getMonthlyAnalyticsMonthOptions();
            }

            public function selected(?array $filters = null): Carbon
            {
                return $this->getSelectedAnalyticsMonth($filters);
            }
        };

        $this->assertSame(['2025-04', '2025-02'], array_keys($filter->options()));
        $this->assertSame('2025-02-01', $filter->selected(['month' => '2025-02'])->toDateString());
        $this->assertSame('2025-04-01', $filter->selected(['month' => '2025-03'])->toDateString());
        $this->assertSame('2025-04-01', $filter->selected(['month' => ['2025-02']])->toDateString());
    }

    private function transaction(User $user, Carbon $createdAt, string $status, float $amount, ?CashierSession $session = null): Transaction
    {
        $transaction = Transaction::create([
            'invoice_number' => 'TEST-'.uniqid(),
            'user_id' => $user->id,
            'cashier_session_id' => $session?->id,
            'total_amount' => $amount,
            'total_profit' => $amount / 2,
            'customer_type' => 'general',
            'status' => $status,
            'payment_method' => 'cash',
            'amount_paid' => $amount,
            'change_amount' => 0,
        ]);

        $transaction->timestamps = false;
        $transaction->created_at = $createdAt;
        $transaction->saveQuietly();
        $transaction->timestamps = true;

        return $transaction;
    }
}
