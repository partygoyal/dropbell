<?php

namespace Tests\Feature;

use Database\Seeders\FmcgProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportProductPhotosTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_read_only_and_import_preserves_product_identity_price_stock_and_custom_uploads(): void
    {
        Storage::fake('public');
        $this->seed(FmcgProductSeeder::class);
        $rows = json_decode(file_get_contents(database_path('data/product-photos.json')), true);
        $first = $rows[0];
        $second = $rows[1];
        $id = DB::table('products')->where('name', $first['original_name'])->value('id');
        DB::table('products')->where('id', $id)->update(['price_paise' => 12500, 'stock_quantity' => 17]);
        DB::table('products')->where('name', $second['original_name'])->where('pack_size', $second['pack_size'])->update(['image_path' => 'products/admin-upload.jpg']);

        $this->artisan('catalogue:import-photos')->assertSuccessful();
        $this->assertDatabaseHas('products', ['id' => $id, 'name' => $first['original_name'], 'image_path' => null]);
        Storage::disk('public')->assertMissing('products/'.$first['asset']);

        $this->artisan('catalogue:import-photos', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseHas('products', ['id' => $id, 'name' => $first['product_name'], 'pack_size' => $first['pack_size'], 'price_paise' => 12500, 'stock_quantity' => 17, 'image_path' => 'products/'.$first['asset']]);
        $this->assertDatabaseHas('products', ['name' => $second['original_name'], 'image_path' => 'products/admin-upload.jpg']);
        Storage::disk('public')->assertExists('products/'.$first['asset']);
        $this->assertSame($first['sha256'], hash('sha256', Storage::disk('public')->get('products/'.$first['asset'])));

        $count = DB::table('products')->count();
        $this->artisan('catalogue:import-photos', ['--apply' => true])->assertSuccessful();
        $this->seed(FmcgProductSeeder::class);
        $this->assertDatabaseCount('products', $count);
        $this->assertDatabaseHas('products', ['id' => $id, 'name' => $first['product_name']]);
    }

    public function test_import_in_production_requires_explicit_authorization(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('catalogue:import-photos', ['--apply' => true])->assertFailed();
        Storage::fake('public');
        $this->artisan('catalogue:import-photos', ['--production' => true])->assertSuccessful();
    }

    public function test_an_existing_branded_name_does_not_hide_an_unprocessed_template(): void
    {
        Storage::fake('public');
        $this->seed(FmcgProductSeeder::class);
        $rows = json_decode(file_get_contents(database_path('data/product-photos.json')), true);
        $first = collect($rows)->firstWhere('original_name', 'Toilet cleaning liquid');
        $second = collect($rows)->firstWhere('original_name', 'Toilet cleaner citrus');
        $second['product_name'] = $first['product_name'];
        $firstId = DB::table('products')->where('name', $first['original_name'])->value('id');
        $secondId = DB::table('products')->where('name', $second['original_name'])->value('id');
        $manifest = tempnam(sys_get_temp_dir(), 'dropbell-photos-');
        try {
            file_put_contents($manifest, json_encode([$first, $second]));
            $this->artisan('catalogue:import-photos', ['--apply' => true, '--manifest' => $manifest])->assertSuccessful();
            $this->assertDatabaseHas('products', ['id' => $firstId, 'name' => $first['product_name'], 'image_path' => 'products/'.$first['asset']]);
            $this->assertDatabaseHas('products', ['id' => $secondId, 'name' => $second['product_name'], 'image_path' => 'products/'.$second['asset']]);
        } finally {
            unlink($manifest);
        }
    }

    public function test_damaged_image_fails_preflight_before_any_database_changes(): void
    {
        $this->seed(FmcgProductSeeder::class);
        $rows = json_decode(file_get_contents(database_path('data/product-photos.json')), true);
        $rows[0]['sha256'] = str_repeat('0', 64);
        $manifest = tempnam(sys_get_temp_dir(), 'dropbell-photos-');
        try {
            file_put_contents($manifest, json_encode($rows));
            $this->artisan('catalogue:import-photos', ['--apply' => true, '--manifest' => $manifest])->assertFailed();
            $this->assertSame(0, DB::table('products')->whereNotNull('image_path')->count());
        } finally {
            unlink($manifest);
        }
    }
}
