<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_filters_and_product_lookup(): void
    {
        $this->seed();
        foreach (DB::table('products')->pluck('id') as $id) {
            $this->publishProduct($id);
        }
        $this->getJson('/api/products')->assertOk()->assertJsonCount(9, 'data');
        $this->getJson('/api/products?category=Fresh')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/products?q=milk')->assertOk()->assertJsonPath('data.0.price_paise', 3000);
        $this->getJson('/api/products/1')->assertOk()->assertJsonPath('data.name', 'Sweet ripe bananas');
        $this->getJson('/api/products/999')->assertNotFound();
        $this->getJson('/api/health')->assertOk()->assertJsonPath('database', 'connected');
    }
}
