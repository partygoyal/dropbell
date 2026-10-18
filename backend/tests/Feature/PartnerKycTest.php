<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerKycTest extends TestCase
{
    use RefreshDatabase;

    private function partner(): int
    {
        return DB::table('delivery_partners')->insertGetId(['name' => 'Asha', 'phone' => '9123456780', 'vehicle' => 'Scooter', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function admin(): void
    {
        Storage::fake('kyc');
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function details(int $revision = 0): array
    {
        return ['revision' => $revision, 'legal_name' => 'Asha Kumar', 'address' => '12 Garden Road', 'city' => 'Bengaluru', 'postal_code' => '560001', 'document_type' => 'passport', 'reference_last_four' => '1234'];
    }

    private function submit(int $id): void
    {
        $this->post('/admin/delivery-partners/'.$id.'/kyc', $this->details() + ['identity_document' => UploadedFile::fake()->image('identity.jpg'), 'address_document' => UploadedFile::fake()->image('address.png')])->assertRedirect('/admin/delivery-partners/'.$id.'/kyc');
    }

    public function test_kyc_details_uploads_reviews_and_documents_require_admin(): void
    {
        $id = $this->partner();
        $this->get('/admin/delivery-partners/'.$id.'/kyc')->assertRedirect('/admin/login');
        $this->get('/admin/delivery-partners/'.$id.'/kyc/documents/identity')->assertRedirect('/admin/login');
        $this->post('/admin/delivery-partners/'.$id.'/kyc', [])->assertRedirect('/admin/login');
        $this->patch('/admin/delivery-partners/'.$id.'/kyc/review', [])->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        $this->get('/admin/delivery-partners/'.$id.'/kyc')->assertForbidden();
        $this->get('/admin/delivery-partners/'.$id.'/kyc/documents/identity')->assertForbidden();
        $this->post('/admin/delivery-partners/'.$id.'/kyc', [])->assertForbidden();
        $this->patch('/admin/delivery-partners/'.$id.'/kyc/review', [])->assertForbidden();
    }

    public function test_partner_kyc_is_stored_privately_and_can_be_reviewed_and_downloaded(): void
    {
        $this->admin();
        $id = $this->partner();
        $this->submit($id);
        $kyc = DB::table('delivery_partner_kycs')->first();
        $this->assertSame('pending', $kyc->status);
        Storage::disk('kyc')->assertExists([$kyc->identity_path, $kyc->address_path]);
        Storage::disk('public')->assertMissing($kyc->identity_path);
        $this->get('/admin/delivery-partners/'.$id.'/kyc')->assertOk()->assertSee('Asha Kumar')->assertSee('Review submission');
        $this->get('/admin/delivery-partners/'.$id.'/kyc/documents/identity')->assertOk()->assertDownload('partner-'.$id.'-identity.jpg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->patch('/admin/delivery-partners/'.$id.'/kyc/review', ['revision' => 1, 'status' => 'approved', 'review_note' => 'Proofs matched'])->assertRedirect();
        $this->assertDatabaseHas('delivery_partner_kycs', ['delivery_partner_id' => $id, 'status' => 'approved', 'reviewed_by' => auth()->id()]);
        $this->assertDatabaseHas('partner_kyc_events', ['delivery_partner_id' => $id, 'action' => 'KYC approved']);
        $this->get('/admin/delivery-partners')->assertOk()->assertSee('Approved');
        $this->patchJson('/admin/delivery-partners/'.$id.'/kyc/review', ['revision' => 1, 'status' => 'approved'])->assertUnprocessable();
    }

    public function test_missing_oversized_and_unsupported_documents_are_rejected(): void
    {
        $this->admin();
        $id = $this->partner();
        $this->postJson('/admin/delivery-partners/'.$id.'/kyc', $this->details())->assertUnprocessable()->assertJsonValidationErrors('documents');
        $this->postJson('/admin/delivery-partners/'.$id.'/kyc', $this->details() + ['identity_document' => UploadedFile::fake()->create('identity.html', 1, 'text/html'), 'address_document' => UploadedFile::fake()->create('address.pdf', 5121, 'application/pdf')])->assertUnprocessable()->assertJsonValidationErrors(['identity_document', 'address_document']);
        $this->assertDatabaseCount('delivery_partner_kycs', 0);
        $this->assertSame([], Storage::disk('kyc')->allFiles());
    }

    public function test_rejection_needs_reason_and_resubmission_resets_review(): void
    {
        $this->admin();
        $id = $this->partner();
        $this->submit($id);
        $this->patchJson('/admin/delivery-partners/'.$id.'/kyc/review', ['revision' => 1, 'status' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('review_note');
        $this->patch('/admin/delivery-partners/'.$id.'/kyc/review', ['revision' => 1, 'status' => 'rejected', 'review_note' => 'Address is unreadable'])->assertRedirect();
        $this->assertDatabaseHas('delivery_partner_kycs', ['delivery_partner_id' => $id, 'status' => 'rejected']);
        $this->post('/admin/delivery-partners/'.$id.'/kyc', $this->details(1) + ['address_document' => UploadedFile::fake()->image('clear-address.jpg')])->assertRedirect();
        $this->assertDatabaseHas('delivery_partner_kycs', ['delivery_partner_id' => $id, 'status' => 'pending', 'revision' => 2, 'reviewed_by' => null, 'review_note' => null]);
        $this->patchJson('/admin/delivery-partners/'.$id.'/kyc/review', ['revision' => 1, 'status' => 'approved'])->assertUnprocessable();
    }

    public function test_replacing_documents_removes_old_files_and_stale_submissions_do_not_leak_files(): void
    {
        $this->admin();
        $id = $this->partner();
        $this->submit($id);
        $old = DB::table('delivery_partner_kycs')->value('identity_path');
        $this->post('/admin/delivery-partners/'.$id.'/kyc', $this->details(1) + ['identity_document' => UploadedFile::fake()->image('new-id.png')])->assertRedirect();
        Storage::disk('kyc')->assertMissing($old);
        $files = Storage::disk('kyc')->allFiles();
        $this->postJson('/admin/delivery-partners/'.$id.'/kyc', $this->details(1) + ['identity_document' => UploadedFile::fake()->image('stale.png')])->assertUnprocessable();
        $this->assertSame($files, Storage::disk('kyc')->allFiles());
        $this->assertDatabaseHas('delivery_partner_kycs', ['revision' => 2]);
    }

    public function test_missing_stored_documents_block_approval_and_unknown_document_is_not_served(): void
    {
        $this->admin();
        $id = $this->partner();
        $this->submit($id);
        Storage::disk('kyc')->delete(DB::table('delivery_partner_kycs')->value('identity_path'));
        $this->patchJson('/admin/delivery-partners/'.$id.'/kyc/review', ['revision' => 1, 'status' => 'approved'])->assertUnprocessable()->assertJsonValidationErrors('documents');
        $this->get('/admin/delivery-partners/'.$id.'/kyc/documents/identity')->assertNotFound();
        $this->get('/admin/delivery-partners/'.$id.'/kyc/documents/unknown')->assertNotFound();
        $this->assertDatabaseHas('delivery_partner_kycs', ['status' => 'pending']);
    }

    public function test_identity_contact_changes_reset_approved_kyc(): void
    {
        $this->admin();
        $id = $this->partner();
        $this->submit($id);
        $this->patch('/admin/delivery-partners/'.$id.'/kyc/review', ['revision' => 1, 'status' => 'approved'])->assertRedirect();
        $this->put('/admin/delivery-partners/'.$id, ['name' => 'Asha Updated', 'phone' => '9123456780', 'vehicle' => 'Scooter', 'active' => 1])->assertRedirect();
        $this->assertDatabaseHas('delivery_partner_kycs', ['delivery_partner_id' => $id, 'status' => 'pending', 'revision' => 2]);
        $this->assertDatabaseHas('partner_kyc_events', ['action' => 'KYC review reset']);
    }

    public function test_assignment_and_dispatch_require_active_approved_partner(): void
    {
        $this->admin();
        $id = $this->partner();
        $address = ['name' => 'Customer', 'phone' => '9876543210', 'street' => '12 Road', 'city' => 'Bengaluru', 'pin' => '560001'];
        $rules = ['id' => null, 'name' => 'Test area', 'postal_code' => '560001', 'fee_paise' => 2500, 'minimum_paise' => 0, 'free_above_paise' => 30000];
        $productId = DB::table('products')->insertGetId(['name' => 'Milk', 'category' => 'Fresh', 'pack_size' => '1 litre', 'price_paise' => 10000, 'active' => true, 'stock_quantity' => 4, 'created_at' => now(), 'updated_at' => now()]);
        $orderId = DB::table('orders')->insertGetId(['reference' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id, 'delivery_address' => json_encode($address), 'delivery_rules' => json_encode($rules), 'status' => 'picking', 'payment_status' => 'pending', 'subtotal_paise' => 10000, 'discount_paise' => 0, 'delivery_paise' => 2500, 'total_paise' => 12500, 'created_at' => now(), 'updated_at' => now()]);
        $itemId = DB::table('order_items')->insertGetId(['order_id' => $orderId, 'product_id' => $productId, 'product_name' => 'Milk', 'pack_size' => '1 litre', 'unit_price_paise' => 10000, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $edit = ['revision' => 0, 'address' => $address, 'quantities' => [$itemId => 1], 'delivery_preference' => 'standard', 'delivery_partner_id' => $id, 'reason' => 'Assign rider'];
        $this->putJson('/admin/orders/'.$orderId, $edit)->assertUnprocessable()->assertJsonValidationErrors('delivery_partner_id');
        $this->patchJson('/admin/orders/'.$orderId, ['status' => 'out_for_delivery', 'revision' => DB::table('orders')->where('id', $orderId)->value('revision'), 'reason' => 'Customer requested cancellation'])->assertUnprocessable();
        $this->submit($id);
        $this->patch('/admin/delivery-partners/'.$id.'/kyc/review', ['revision' => 1, 'status' => 'approved'])->assertRedirect();
        $this->put('/admin/orders/'.$orderId, $edit)->assertRedirect('/admin/orders/'.$orderId);
        DB::table('delivery_partners')->where('id', $id)->update(['active' => false]);
        $this->patchJson('/admin/orders/'.$orderId, ['status' => 'out_for_delivery', 'revision' => DB::table('orders')->where('id', $orderId)->value('revision'), 'reason' => 'Customer requested cancellation'])->assertUnprocessable();
        DB::table('delivery_partners')->where('id', $id)->update(['active' => true]);
        DB::table('products')->where('id', $productId)->update(['expires_on' => today('Asia/Kolkata')->subDay()->toDateString()]);
        $this->patchJson('/admin/orders/'.$orderId, ['status' => 'out_for_delivery', 'revision' => DB::table('orders')->where('id', $orderId)->value('revision'), 'reason' => 'Customer requested cancellation'])->assertUnprocessable()->assertJsonValidationErrors('status');
        DB::table('products')->where('id', $productId)->update(['expires_on' => null]);
        $this->patch('/admin/orders/'.$orderId, ['status' => 'out_for_delivery', 'revision' => DB::table('orders')->where('id', $orderId)->value('revision'), 'reason' => 'Customer requested cancellation'])->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'out_for_delivery']);
    }
}
