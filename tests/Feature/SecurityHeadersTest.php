<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_pages_use_a_per_response_nonce_without_script_eval(): void
    {
        $firstResponse = $this->get('/login')->assertOk();
        $firstPolicy = $firstResponse->headers->get('Content-Security-Policy');
        preg_match("/script-src 'self' 'nonce-([^']+)'/", $firstPolicy, $firstNonce);

        $this->assertNotEmpty($firstNonce[1] ?? null);
        $this->assertDoesNotMatchRegularExpression('/script-src[^;]*unsafe-inline/i', $firstPolicy);
        $this->assertDoesNotMatchRegularExpression('/style-src\s+[^;]*unsafe-inline/i', $firstPolicy);
        $this->assertStringNotContainsString("'unsafe-eval'", $firstPolicy);
        $this->assertStringNotContainsString('192.168.1.16', $firstPolicy);
        $this->assertStringContainsString(
            "'sha256-JPiV+zbElb6F8GfxmDt1fq5wNR89mwpjxdpPeFaGQ1M='",
            $firstPolicy,
        );
        $this->assertStringContainsString(
            "'sha256-j7MDc5w84jijhNNXv1VRK3APJe7+vzJgvb28436L3ec='",
            $firstPolicy,
        );
        $this->assertStringContainsString(
            'meta property="csp-nonce" nonce="'.$firstNonce[1].'"',
            $firstResponse->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/<script\b[^>]*\bnonce="'.preg_quote($firstNonce[1], '/').'"[^>]*>(?:const Ziggy=|Object\.assign\(Ziggy\.routes,)/',
            $firstResponse->getContent(),
        );

        $secondPolicy = $this->get('/login')->headers->get('Content-Security-Policy');
        preg_match("/script-src 'self' 'nonce-([^']+)'/", $secondPolicy, $secondNonce);

        $this->assertNotSame($firstNonce[1], $secondNonce[1] ?? null);
    }

    public function test_local_policy_allows_only_loopback_vite_hmr_without_eval(): void
    {
        config(['app.env' => 'local']);

        $policy = $this->get('/login')
            ->headers
            ->get('Content-Security-Policy');

        $this->assertStringContainsString('http://localhost:5173', $policy);
        $this->assertStringContainsString('http://127.0.0.1:5173', $policy);
        $this->assertStringContainsString('ws://localhost:5173', $policy);
        $this->assertStringContainsString('ws://127.0.0.1:5173', $policy);
        $this->assertStringNotContainsString('192.168.1.16', $policy);
        $this->assertStringNotContainsString("'unsafe-eval'", $policy);
    }

    public function test_filament_legacy_allowances_are_scoped_to_admin_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $policy = $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->headers
            ->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression(
            '/script-src[^;]*\'unsafe-inline\'[^;]*\'unsafe-eval\'/',
            $policy,
        );
        $this->assertStringContainsString(
            'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
            $policy,
        );
        $this->assertStringNotContainsString('192.168.1.16', $policy);
        $this->assertStringNotContainsString('http://localhost:5173', $policy);
    }

    public function test_admin_scanner_uses_the_pinned_integrity_protected_asset(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)
            ->get('/admin/products/create')
            ->assertOk();

        $this->assertStringContainsString(
            'src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"',
            $response->getContent(),
        );
        $this->assertStringContainsString(
            'integrity="sha384-c9d8RFSL+u3exBOJ4Yp3HUJXS4znl9f+z66d1y54ig+ea249SpqR+w1wyvXz/lk+"',
            $response->getContent(),
        );
    }
}
