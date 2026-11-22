<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\PurchaseResource\Pages\CreatePurchase;
use App\Filament\Resources\StockAdjustmentResource\Pages\CreateStockAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminInventoryFormValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_purchase_quantity_and_buy_price_must_be_present_and_numeric(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'date' => now()->toDateString(),
                'product_id' => 1,
                'quantity' => 'not-a-number',
                'buy_price_per_unit' => 'not-a-number',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'quantity' => 'numeric',
                'buy_price_per_unit' => 'numeric',
            ]);

        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'date' => now()->toDateString(),
                'product_id' => 1,
                'quantity' => null,
                'buy_price_per_unit' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'quantity' => 'required',
                'buy_price_per_unit' => 'required',
            ]);
    }

    public function test_stock_adjustment_physical_stock_must_be_present_and_numeric(): void
    {
        Livewire::test(CreateStockAdjustment::class)
            ->fillForm([
                'product_id' => 1,
                'system_stock' => 5,
                'physical_stock' => 'not-a-number',
            ])
            ->call('create')
            ->assertHasFormErrors(['physical_stock' => 'numeric']);

        Livewire::test(CreateStockAdjustment::class)
            ->fillForm([
                'product_id' => 1,
                'system_stock' => 5,
                'physical_stock' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['physical_stock' => 'required']);
    }

    public function test_admin_product_stock_and_sell_price_must_be_present_and_numeric(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'sku' => 'TEST-001',
                'name' => 'Test Product',
                'stock' => 'not-a-number',
                'sell_price' => 'not-a-number',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'stock' => 'numeric',
                'sell_price' => 'numeric',
            ]);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'sku' => 'TEST-002',
                'name' => 'Test Product',
                'stock' => null,
                'sell_price' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'stock' => 'required',
                'sell_price' => 'required',
            ]);
    }

    public function test_owner_product_cost_price_must_be_present_and_numeric(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'sku' => 'OWNER-001',
                'name' => 'Owner Product',
                'stock' => 5,
                'cost_price' => 'not-a-number',
                'sell_price' => 100,
            ])
            ->call('create')
            ->assertHasFormErrors(['cost_price' => 'numeric']);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'sku' => 'OWNER-002',
                'name' => 'Owner Product',
                'stock' => 5,
                'cost_price' => null,
                'sell_price' => 100,
            ])
            ->call('create')
            ->assertHasFormErrors(['cost_price' => 'required']);
    }
}
