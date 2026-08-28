<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiPinLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_pin_returns_authentication_error_instead_of_server_error(): void
    {
        User::factory()->create([
            'role' => 'kasir',
            'pin' => Hash::make('1234'),
        ]);

        $response = $this->postJson('/api/login', ['pin' => '9999']);

        $response->assertUnauthorized();
        $response->assertJson(['message' => 'PIN salah.']);
    }
}
