<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\User;
use App\Services\ClosingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CashierSessionCloseTest extends TestCase
{
    use RefreshDatabase;

    private function kasir(): User
    {
        return User::factory()->create(['role' => 'kasir']);
    }

    private function openSession(User $user, float $openingCash = 100000): CashierSession
    {
        return CashierSession::create([
            'user_id' => $user->id,
            'opening_cash' => $openingCash,
            'opened_at' => now(),
        ]);
    }

    public function test_closing_a_session_returns_a_localized_report(): void
    {
        $user = $this->kasir();
        $session = $this->openSession($user);

        $response = $this->actingAs($user)->postJson(route('transactions.session.close'), [
            'closing_cash_physical' => 100000,
        ]);

        $response->assertOk();
        $response->assertJsonPath('closingData.totalTransactions', 0);
        $response->assertJsonPath('closingData.settlementStatus', 'balance');
        $this->assertSame(now()->locale('id')->translatedFormat('j F Y'), $response->json('closingData.date'));

        $this->assertNotNull($session->fresh()->closed_at);
    }

    public function test_a_report_failure_rolls_back_the_session_close(): void
    {
        $user = $this->kasir();
        $session = $this->openSession($user);

        $this->mock(ClosingReportService::class, function ($mock) {
            $mock->shouldReceive('buildReport')->andThrow(new RuntimeException('report exploded'));
        });

        $this->actingAs($user)
            ->postJson(route('transactions.session.close'), [
                'closing_cash_physical' => 100000,
            ])
            ->assertStatus(500);

        $this->assertNull($session->fresh()->closed_at);
    }

    public function test_closing_a_session_without_an_open_session_is_rejected(): void
    {
        $user = $this->kasir();

        $this->actingAs($user)
            ->postJson(route('transactions.session.close'), [
                'closing_cash_physical' => 100000,
            ])
            ->assertStatus(422);
    }

    public function test_report_date_uses_indonesian_month_names(): void
    {
        $user = $this->kasir();
        $this->openSession($user);

        $report = app(ClosingReportService::class)
            ->buildReport($user->cashierSessions()->firstOrFail());

        $this->assertSame(now()->locale('id')->translatedFormat('j F Y'), $report['date']);
    }
}
