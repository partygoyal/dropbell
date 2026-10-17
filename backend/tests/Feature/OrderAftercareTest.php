<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderAftercareTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $status = 'received', string $payment = 'pending'): object
    {
        $user = User::factory()->create();
        DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', 'aftercare-token'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken('aftercare-token');
        $id = DB::table('orders')->insertGetId(['reference' => (string) Str::uuid(), 'user_id' => $user->id, 'delivery_address' => json_encode(['name' => '<script>alert(1)</script>', 'phone' => '9876543210', 'street' => '12 Garden Road', 'city' => 'Bengaluru', 'pin' => '560001']), 'status' => $status, 'payment_status' => $payment, 'subtotal_paise' => 10000, 'discount_paise' => 0, 'delivery_paise' => 2500, 'total_paise' => 12500, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_items')->insert(['order_id' => $id, 'product_name' => 'Fresh paneer', 'pack_size' => '200 g', 'unit_price_paise' => 5000, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('orders')->find($id);
    }

    private function customerRequest(object $order, string $type): int
    {
        return $this->postJson('/api/customer/orders/'.$order->reference.'/requests', ['type' => $type, 'reason' => 'Customer needs help with this order'])->assertCreated()->json('data.id');
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    private function review(int $id, string $status, int $revision, array $extra = []): array
    {
        return ['status' => $status, 'revision' => $revision, 'review_note' => 'Request reviewed by support team'] + $extra;
    }

    public function test_request_ownership_and_admin_access_are_enforced(): void
    {
        $this->getJson('/api/customer/requests')->assertUnauthorized();
        $this->getJson('/api/customer/transactions')->assertUnauthorized();
        $order = $this->order();
        $this->postJson('/api/customer/orders/'.$order->reference.'/requests', ['type' => 'cancellation', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        DB::table('orders')->where('id', $order->id)->update(['user_id' => User::factory()->create()->id]);
        $this->postJson('/api/customer/orders/'.$order->reference.'/requests', ['type' => 'cancellation', 'reason' => 'Please cancel order'])->assertNotFound();
        $this->get('/api/customer/orders/'.$order->reference.'/invoice')->assertNotFound();
        $this->getJson('/api/customer/transactions')->assertJsonCount(0, 'data');
        $this->get('/admin/requests')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/requests')->assertForbidden();
        $this->post('/admin/orders/'.$order->id.'/payment', ['reference' => 'receipt-1'])->assertForbidden();
    }

    public function test_customer_cancellation_is_idempotent_and_requires_admin_review(): void
    {
        $order = $this->order();
        $id = $this->customerRequest($order, 'cancellation');
        $this->assertSame($id, $this->customerRequest($order, 'cancellation'));
        $this->assertDatabaseCount('customer_requests', 1);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'received']);
        $this->admin();
        $this->get('/admin/requests')->assertOk()->assertSee($order->reference);
        $this->get('/admin/requests/'.$id)->assertOk();
        $this->patch('/admin/requests/'.$id, $this->review($id, 'approved', 0))->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('customer_requests', ['id' => $id, 'status' => 'completed', 'revision' => 1]);
        $this->assertDatabaseHas('order_transactions', ['order_id' => $order->id, 'status' => 'voided']);
        $this->patchJson('/admin/requests/'.$id, $this->review($id, 'approved', 0))->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_cancellation_is_refused_after_dispatch_and_return_requires_delivery(): void
    {
        $order = $this->order('out_for_delivery');
        foreach (['cancellation', 'return', 'refund'] as $type) {
            $this->postJson('/api/customer/orders/'.$order->reference.'/requests', ['type' => $type, 'reason' => 'Please resolve my order'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('customer_requests', 0);
    }

    public function test_admin_rechecks_cancellation_status_and_can_reject_with_customer_visible_note(): void
    {
        $order = $this->order();
        $id = $this->customerRequest($order, 'cancellation');
        DB::table('orders')->where('id', $order->id)->update(['status' => 'out_for_delivery']);
        $this->admin();
        $this->patchJson('/admin/requests/'.$id, $this->review($id, 'approved', 0))->assertUnprocessable();
        $this->patch('/admin/requests/'.$id, $this->review($id, 'rejected', 0, ['amount' => 0]))->assertRedirect();
        $this->getJson('/api/customer/requests')->assertOk()->assertJsonPath('data.0.status', 'rejected')->assertJsonPath('data.0.reviewNote', 'Request reviewed by support team');
    }

    public function test_cod_collection_records_once_and_transactions_belong_to_customer(): void
    {
        $order = $this->order('delivered');
        $this->getJson('/api/customer/transactions')->assertJsonPath('data.0.status', 'pending');
        $admin = $this->admin();
        $this->post('/admin/orders/'.$order->id.'/payment', ['reference' => 'cash-receipt-101'])->assertRedirect();
        $this->post('/admin/orders/'.$order->id.'/payment', ['reference' => 'cash-receipt-101'])->assertRedirect();
        $this->assertDatabaseCount('order_transactions', 1);
        $this->assertDatabaseHas('order_transactions', ['order_id' => $order->id, 'amount_paise' => 12500, 'status' => 'completed', 'recorded_by' => $admin->id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'paid']);
        $this->getJson('/api/customer/transactions')->assertJsonPath('data.0.reference', 'cash-receipt-101');
        $this->get('/admin/transactions')->assertOk()->assertSee('cash-receipt-101');
    }

    public function test_collection_is_blocked_before_delivery(): void
    {
        $order = $this->order();
        $this->admin();
        $this->postJson('/admin/orders/'.$order->id.'/payment', ['reference' => 'cash-receipt'])->assertUnprocessable();
        $this->assertDatabaseCount('order_transactions', 0);
    }

    public function test_return_requires_receipt_and_refund_reference_before_completion(): void
    {
        $order = $this->order('delivered', 'paid');
        $id = $this->customerRequest($order, 'return');
        $this->admin();
        $this->patch('/admin/requests/'.$id, $this->review($id, 'approved', 0, ['amount' => '100.00']))->assertRedirect();
        $this->patchJson('/admin/requests/'.$id, $this->review($id, 'completed', 1, ['reference' => 'upi-123', 'method' => 'upi']))->assertUnprocessable();
        $this->patch('/admin/requests/'.$id, $this->review($id, 'returned', 1))->assertRedirect();
        $this->patchJson('/admin/requests/'.$id, $this->review($id, 'completed', 2))->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->patch('/admin/requests/'.$id, $this->review($id, 'completed', 2, ['reference' => 'upi-123', 'method' => 'upi']))->assertRedirect();
        $this->assertDatabaseHas('order_transactions', ['order_id' => $order->id, 'type' => 'refund', 'amount_paise' => 10000, 'reference' => 'upi-123']);
        $this->getJson('/api/customer/orders/'.$order->reference)->assertJsonPath('data.refunded', 10000)->assertJsonPath('data.requests.0.status', 'completed');
        $this->patchJson('/admin/requests/'.$id, $this->review($id, 'completed', 2, ['reference' => 'upi-123', 'method' => 'upi']))->assertUnprocessable();
        $this->assertDatabaseCount('order_transactions', 2);
    }

    public function test_approved_refunds_reserve_balance_and_cannot_exceed_collected_amount(): void
    {
        $order = $this->order('delivered', 'paid');
        $returnId = $this->customerRequest($order, 'return');
        $refundId = $this->customerRequest($order, 'refund');
        $this->admin();
        $this->patchJson('/admin/requests/'.$returnId, $this->review($returnId, 'approved', 0, ['amount' => '126.00']))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->patch('/admin/requests/'.$returnId, $this->review($returnId, 'approved', 0, ['amount' => '100.00']))->assertRedirect();
        $this->patchJson('/admin/requests/'.$refundId, $this->review($refundId, 'approved', 0, ['amount' => '26.00']))->assertUnprocessable();
        $this->patch('/admin/requests/'.$refundId, $this->review($refundId, 'approved', 0, ['amount' => '25.00']))->assertRedirect();
        $this->patch('/admin/requests/'.$refundId, $this->review($refundId, 'completed', 1, ['reference' => 'cash-25', 'method' => 'cash']))->assertRedirect();
        $this->assertDatabaseHas('order_transactions', ['customer_request_id' => $refundId, 'amount_paise' => 2500]);
    }

    public function test_unpaid_order_cannot_request_refund_and_return_can_finish_without_refund(): void
    {
        $order = $this->order('delivered');
        $this->postJson('/api/customer/orders/'.$order->reference.'/requests', ['type' => 'refund', 'reason' => 'Please refund my order'])->assertUnprocessable();
        $id = $this->customerRequest($order, 'return');
        $this->admin();
        $this->patch('/admin/requests/'.$id, $this->review($id, 'approved', 0, ['amount' => 0]))->assertRedirect();
        $this->postJson('/admin/orders/'.$order->id.'/payment', ['reference' => 'late-collection'])->assertUnprocessable();
        $this->patch('/admin/requests/'.$id, $this->review($id, 'returned', 1))->assertRedirect();
        $this->patch('/admin/requests/'.$id, $this->review($id, 'completed', 2))->assertRedirect();
        $this->assertDatabaseMissing('order_transactions', ['type' => 'refund']);
        $this->assertDatabaseHas('order_transactions', ['order_id' => $order->id, 'status' => 'voided']);
        $this->assertDatabaseHas('customer_requests', ['id' => $id, 'status' => 'completed', 'amount_paise' => 0]);
    }

    public function test_cancellation_request_can_be_resolved_if_admin_already_cancelled_order(): void
    {
        $order = $this->order();
        $id = $this->customerRequest($order, 'cancellation');
        $this->admin();
        $this->patch('/admin/orders/'.$order->id, ['status' => 'cancelled'])->assertRedirect();
        $this->patch('/admin/requests/'.$id, $this->review($id, 'approved', 0))->assertRedirect();
        $this->assertDatabaseHas('customer_requests', ['id' => $id, 'status' => 'completed']);
        $this->assertDatabaseCount('order_transactions', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_invoice_requires_delivery_and_is_stable_and_escapes_customer_input(): void
    {
        $order = $this->order();
        $url = '/api/customer/orders/'.$order->reference.'/invoice';
        $this->getJson($url)->assertUnprocessable();
        DB::table('orders')->where('id', $order->id)->update(['status' => 'delivered']);
        $response = $this->get($url)->assertOk()->assertSee('Fresh paneer')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>', false);
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $number = DB::table('order_invoices')->value('number');
        DB::table('order_items')->where('order_id', $order->id)->update(['product_name' => 'Changed after invoice']);
        $this->get($url)->assertOk()->assertSee('Fresh paneer')->assertDontSee('Changed after invoice')->assertSee($number);
        $this->assertDatabaseCount('order_invoices', 1);
    }
}
