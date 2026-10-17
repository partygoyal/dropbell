<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoMarketplaceSeeder;
use Database\Seeders\FmcgProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DemoMarketplaceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_demo_publishes_products_and_preserves_vendor_changes_on_rerun(): void
    {
        $this->app['env'] = 'local';
        $this->seed(FmcgProductSeeder::class);
        $user = User::factory()->create(['email' => 'vendor.demo@dropbell.local', 'is_vendor' => true]);
        $vendorId = DB::table('vendors')->insertGetId(['user_id' => $user->id, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $this->seed(DemoMarketplaceSeeder::class);
        $this->assertDatabaseHas('vendors', ['id' => $vendorId, 'status' => 'approved', 'business_name' => 'Dropbell Demo Store']);
        $this->getJson('/api/products?postal_code=560001')->assertOk()->assertJsonCount(336, 'data');
        $subCategoryId = DB::table('sub_categories')->where('name', 'Paneer & Cheese')->value('id');
        $this->assertSame(3, DB::table('vendor_products')->join('products', 'products.id', '=', 'vendor_products.product_id')->where('sub_category_id', $subCategoryId)->count());
        $offerId = DB::table('vendor_products')->value('id');
        DB::table('vendor_products')->where('id', $offerId)->update(['price_paise' => 9999, 'available' => false]);
        $this->seed(DemoMarketplaceSeeder::class);
        $this->assertDatabaseCount('vendor_products', 336);
        $this->assertDatabaseHas('vendor_products', ['id' => $offerId, 'price_paise' => 9999, 'available' => false]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_demo_offers_cannot_be_seeded_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Demo offers can only be seeded in the local environment.');
        (new DemoMarketplaceSeeder)->run();
    }
}
