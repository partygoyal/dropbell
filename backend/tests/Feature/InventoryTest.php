<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_inventory_controls_are_removed(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/inventory')->assertNotFound();
        $this->post('/admin/inventory/1/adjust', [])->assertNotFound();
        $this->put('/admin/inventory/1/settings', [])->assertNotFound();
        $this->get('/admin')->assertOk()->assertSee('Manage vendors')->assertDontSee('Manage inventory');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_master_template_creation_and_edits_never_create_or_overwrite_stock(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $data = ['name' => 'New product', 'category' => 'Fresh', 'pack_size' => '500 g', 'active' => 1, 'stock_quantity' => 8];
        $this->post('/admin/products', $data)->assertRedirect();
        $id = DB::table('products')->value('id');
        $this->assertDatabaseHas('products', ['id' => $id, 'stock_quantity' => 0]);
        DB::table('products')->where('id', $id)->update(['stock_quantity' => 9]);
        $this->put('/admin/products/'.$id, $data + ['description' => 'Updated template'])->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $id, 'stock_quantity' => 9, 'description' => 'Updated template']);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->getJson('/api/products')->assertJsonCount(0, 'data');
    }
}
