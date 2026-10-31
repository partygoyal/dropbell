<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminVendorToolsTest extends TestCase
{
    use RefreshDatabase;

    private function vendor(string $name = 'Corner Shop'): int
    {
        return DB::table('vendors')->insertGetId(['user_id' => User::factory()->create(['is_vendor' => true])->id, 'business_name' => $name, 'pickup_address' => '12 Market Road', 'city' => 'Bengaluru', 'postal_code' => '560001', 'service_postal_codes' => json_encode(['560001']), 'status' => 'approved', 'revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_vendor_tools_and_reports_are_admin_only(): void
    {
        $id = $this->vendor();
        foreach (['/admin/vendors/'.$id.'/edit', '/admin/reports', '/admin/reports/export'] as $path) {
            $this->get($path)->assertRedirect('/admin/login');
        }
        $this->put('/admin/vendors/'.$id, [])->assertRedirect('/admin/login');
        $this->delete('/admin/vendors/'.$id, [])->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        $this->get('/admin/reports/export')->assertForbidden();
        $this->get('/admin/vendors/'.$id.'/edit')->assertForbidden();
        $this->put('/admin/vendors/'.$id, [])->assertForbidden();
        $this->delete('/admin/vendors/'.$id, [])->assertForbidden();
    }

    public function test_vendor_directory_search_sort_and_validation(): void
    {
        $this->vendor('Alpha Shop');
        $this->vendor('Zebra Shop');
        $this->admin();
        $this->get('/admin/vendors?q=Alpha&sort=name&per_page=10')->assertOk()->assertSee('Alpha Shop')->assertDontSee('Zebra Shop')->assertSee('More');
        $this->getJson('/admin/vendors?sort=invalid')->assertUnprocessable();
        $this->getJson('/admin/vendors?per_page=9999')->assertUnprocessable();
    }

    public function test_vendor_edit_updates_business_only_and_rejects_stale_edits(): void
    {
        $id = $this->vendor();
        $this->admin();
        $payload = ['revision' => 0, 'business_name' => 'Updated Shop', 'pickup_address' => 'New Road', 'city' => 'New City', 'postal_code' => '560001', 'service_postal_codes' => '560001, 560002, 560001'];
        $this->get('/admin/vendors/'.$id.'/edit')->assertOk();
        $this->put('/admin/vendors/'.$id, $payload)->assertRedirect('/admin/vendors/'.$id);
        $this->assertDatabaseHas('vendors', ['id' => $id, 'business_name' => 'Updated Shop', 'revision' => 1, 'status' => 'approved', 'service_postal_codes' => json_encode(['560001', '560002'])]);
        $this->putJson('/admin/vendors/'.$id, $payload)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $payload['revision'] = 1;
        $payload['service_postal_codes'] = '123';
        $this->putJson('/admin/vendors/'.$id, $payload)->assertUnprocessable()->assertJsonValidationErrors('service_postal_codes');
    }

    public function test_hide_and_delete_retain_history_and_disable_vendor_access(): void
    {
        $id = $this->vendor();
        $owner = User::find(DB::table('vendors')->where('id', $id)->value('user_id'));
        $this->admin();
        $this->patch('/admin/vendors/'.$id, ['revision' => 0, 'status' => 'suspended', 'review_note' => 'Hidden by admin'])->assertRedirect();
        $this->assertDatabaseHas('vendors', ['id' => $id, 'status' => 'suspended']);
        $this->delete('/admin/vendors/'.$id, ['revision' => 1])->assertRedirect();
        $this->assertNotNull(DB::table('vendors')->where('id', $id)->value('archived_at'));
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->get('/admin/vendors')->assertOk()->assertDontSee('Corner Shop');
        $this->get('/admin/vendors?archived=1')->assertOk()->assertSee('Corner Shop');
        $this->get('/admin/vendors/'.$id)->assertOk()->assertSee('Historical details');
        $this->patchJson('/admin/vendors/'.$id, ['revision' => 2, 'status' => 'approved'])->assertUnprocessable();
        $this->actingAs($owner)->get('/vendor')->assertForbidden();
    }

    public function test_open_orders_block_deletion_and_completed_order_history_is_retained(): void
    {
        $id = $this->vendor();
        $order = DB::table('orders')->insertGetId(['reference' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id, 'delivery_address' => '{}', 'subtotal_paise' => 12345, 'total_paise' => 12345, 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_items')->insert(['order_id' => $order, 'vendor_id' => $id, 'product_name' => 'Milk', 'pack_size' => '1 litre', 'quantity' => 1, 'unit_price_paise' => 12345]);
        $this->admin();
        $this->deleteJson('/admin/vendors/'.$id, ['revision' => 0])->assertUnprocessable()->assertJsonValidationErrors('vendor');
        DB::table('orders')->where('id', $order)->update(['status' => 'delivered']);
        $this->delete('/admin/vendors/'.$id, ['revision' => 0])->assertRedirect();
        $this->assertDatabaseHas('order_items', ['order_id' => $order, 'vendor_id' => $id]);
        $response = $this->get('/admin/reports/export?type=orders');
        $response->assertOk()->assertDownload();
        $this->assertStringContainsString('123.45', $response->streamedContent());
    }

    public function test_report_filters_export_all_rows_and_escape_spreadsheet_formulas(): void
    {
        $this->vendor('=DANGEROUS()');
        $this->vendor('Other Shop');
        $this->admin();
        $this->get('/admin/reports?type=vendors&q=DANGEROUS')->assertOk()->assertSee('=DANGEROUS()')->assertDontSee('Other Shop');
        $response = $this->get('/admin/reports/export?type=vendors&q=DANGEROUS');
        $response->assertOk()->assertDownload();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=DANGEROUS()", $csv);
        $this->assertStringNotContainsString('Other Shop', $csv);
        $this->get('/admin/reports?to='.now()->toDateString())->assertOk();
        $this->getJson('/admin/reports/export?from=2026-10-31&to=2026-01-01')->assertUnprocessable();
        $this->getJson('/admin/reports/export?type=invalid')->assertUnprocessable();
    }
}
