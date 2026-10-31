<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminTablePagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_table_sizes_and_pages_are_applied_to_the_database_query(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $rows = [];
        for ($index = 0; $index < 205; $index++) {
            $rows[] = ['name' => sprintf('Rider %03d', $index), 'phone' => (string) (9000000000 + $index), 'password' => 'DO-NOT-EXPORT-HASH', 'active' => true, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('delivery_partners')->insert($rows);
        foreach ([10, 20, 50, 100, 200] as $size) {
            $this->get('/admin/delivery-partners?per_page='.$size)->assertOk()->assertViewHas('rows', fn ($page) => $page->count() === $size && $page->total() === 205 && str_contains($page->nextPageUrl(), 'per_page='.$size));
        }
        $this->get('/admin/delivery-partners?per_page=200&page=2')->assertOk()->assertViewHas('rows', fn ($page) => $page->count() === 5);
        $this->getJson('/admin/delivery-partners?per_page=201')->assertUnprocessable();
        $response = $this->get('/admin/delivery-partners?per_page=10&page=2&export=all&export_table=partners');
        $response->assertOk()->assertDownload();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Rider 000', $csv);
        $this->assertStringContainsString('Rider 204', $csv);
        $this->assertStringNotContainsString('DO-NOT-EXPORT-HASH', $csv);
        $this->assertStringNotContainsString('Password', $csv);
    }

    public function test_full_dataset_export_preserves_server_filters_and_protects_formula_cells(): void
    {
        foreach (['=Alpha Shop', 'Beta Shop'] as $name) {
            DB::table('vendors')->insert(['user_id' => User::factory()->create(['is_vendor' => true])->id, 'business_name' => $name, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $response = $this->get('/admin/vendors?q=Alpha&export=all&export_table=vendors');
        $response->assertOk()->assertDownload();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=Alpha Shop", $csv);
        $this->assertStringNotContainsString('Beta Shop', $csv);
        $this->get('/admin/vendors?export=all&export_table=vendors')->assertOk();
    }

    public function test_full_exports_are_admin_only_and_report_page_size_is_validated(): void
    {
        $this->get('/admin/customers?export=all&export_table=customers')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/customers?export=all&export_table=customers')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/reports?type=orders&per_page=200')->assertOk()->assertViewHas('rows', fn ($page) => $page->perPage() === 200);
        $this->getJson('/admin/reports?per_page=5000')->assertUnprocessable();
        $this->get('/admin/reports?type=orders&export=all&export_table=report-orders')->assertOk()->assertDownload();
    }
}
