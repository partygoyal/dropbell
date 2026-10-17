<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProductTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();

        return $user;
    }

    private function productData(): array
    {
        return ['name' => 'Test essential', 'category' => 'Fresh', 'pack_size' => '500 g', 'price' => '49.95', 'stock_quantity' => 8, 'active' => 1, 'description' => 'Fresh daily pick'];
    }

    public function test_admin_can_create_edit_and_hide_products_with_uploaded_images(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin())->post('/admin/products', [...$this->productData(), 'image' => UploadedFile::fake()->image('essential.jpg')])->assertRedirect('/admin/products');
        $product = DB::table('products')->first();
        $this->assertEquals(4995, $product->price_paise);
        Storage::disk('public')->assertExists($product->image_path);
        $this->getJson('/api/products/'.$product->id)->assertNotFound();
        $this->publishProduct($product->id);
        $this->getJson('/api/products/'.$product->id)->assertOk()->assertJsonPath('data.price_paise', 4995);
        $this->get('/admin/products/'.$product->id.'/edit')->assertOk()->assertSee('Test essential');
        $this->put('/admin/products/'.$product->id, [...$this->productData(), 'price' => '25.50', 'active' => 0, 'remove_image' => 1])->assertRedirect('/admin/products');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'price_paise' => 2550, 'active' => false, 'image_path' => null]);
        Storage::disk('public')->assertMissing($product->image_path);
        $this->getJson('/api/products/'.$product->id)->assertNotFound();
        $this->getJson('/api/products')->assertJsonCount(0, 'data');
    }

    public function test_guests_and_customers_cannot_manage_catalogue(): void
    {
        $this->post('/admin/products', $this->productData())->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->post('/admin/products', $this->productData())->assertForbidden();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_invalid_fields_and_non_image_uploads_are_rejected(): void
    {
        $this->actingAs($this->admin())->post('/admin/products', [...$this->productData(), 'price' => '1.005', 'stock_quantity' => -1, 'category' => 'Unknown', 'image' => UploadedFile::fake()->create('script.txt', 1, 'text/plain')])->assertSessionHasErrors(['price', 'category', 'image']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_templates_need_vendor_availability_and_admin_search_works(): void
    {
        $this->actingAs($this->admin())->post('/admin/products', [...$this->productData(), 'stock_quantity' => 0])->assertRedirect();
        $this->getJson('/api/products')->assertJsonCount(0, 'data');
        $this->publishProduct(DB::table('products')->value('id'));
        $this->getJson('/api/products')->assertJsonCount(1, 'data');
        $this->get('/admin/products?q=unmatched')->assertOk()->assertDontSee('Test essential');
    }
}
