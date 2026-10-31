<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerSupportTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $user = User::factory()->create();
        DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', 'support-'.$user->id), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken('support-'.$user->id);

        return $user;
    }

    private function concern(): array
    {
        return ['request_key' => (string) Str::uuid(), 'subject' => 'Missing grocery item', 'body' => 'One item from my bag was not delivered.'];
    }

    public function test_support_is_private_and_creation_retries_are_idempotent(): void
    {
        $this->getJson('/api/customer/support/tickets')->assertUnauthorized();
        $this->customer();
        $input = $this->concern();
        $id = $this->postJson('/api/customer/support/tickets', $input)->assertCreated()->json('data.id');
        $this->postJson('/api/customer/support/tickets', $input)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('support_messages', 1);
        $this->customer();
        $this->getJson('/api/customer/support/tickets')->assertJsonCount(0, 'data');
        $this->getJson('/api/customer/support/tickets/'.$id)->assertNotFound();
        $this->postJson('/api/customer/support/tickets/'.$id.'/messages', ['request_key' => (string) Str::uuid(), 'body' => 'Wrong account'])->assertNotFound();
        $this->postJson('/api/customer/support/tickets/'.$id.'/read', ['message_id' => DB::table('support_messages')->value('id')])->assertNotFound();
    }

    public function test_admin_replies_resolves_and_customer_reopens_with_unread_counts(): void
    {
        $user = $this->customer();
        $id = $this->postJson('/api/customer/support/tickets', $this->concern())->assertCreated()->json('data.id');
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/support')->assertOk()->assertSee('Missing grocery item');
        $this->get('/admin/support/'.$id)->assertOk()->assertSee('One item from my bag');
        $this->getJson('/admin/support')->assertJsonPath('data.0.unread', 1);
        $input = ['request_key' => (string) Str::uuid(), 'body' => 'We will deliver the missing item.', 'status' => 'resolved'];
        $this->postJson('/admin/support/'.$id.'/messages', $input)->assertOk();
        $this->postJson('/admin/support/'.$id.'/messages', $input)->assertOk();
        $this->assertDatabaseCount('support_messages', 2);
        $this->withToken('support-'.$user->id)->getJson('/api/customer/support/tickets')->assertJsonPath('data.0.status', 'resolved')->assertJsonPath('data.0.unread', 1);
        $last = DB::table('support_messages')->max('id');
        $this->postJson('/api/customer/support/tickets/'.$id.'/read', ['message_id' => $last])->assertOk();
        $this->getJson('/api/customer/support/tickets')->assertJsonPath('data.0.unread', 0);
        $this->postJson('/api/customer/support/tickets/'.$id.'/messages', ['request_key' => (string) Str::uuid(), 'body' => 'I still need help please.', 'status' => 'resolved'])->assertOk();
        $this->getJson('/api/customer/support/tickets/'.$id)->assertJsonPath('data.ticket.status', 'open');
        $this->getJson('/admin/support')->assertJsonPath('data.0.unread', 1);
    }

    public function test_non_admin_cannot_use_support_dashboard_and_unknown_order_is_rejected(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->get('/admin/support')->assertForbidden();
        $this->postJson('/api/customer/support/tickets', $this->concern() + ['order_reference' => 'not-my-order'])->assertUnprocessable();
        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertDatabaseCount('support_messages', 0);
    }

    public function test_messages_are_paginated_and_read_cursor_cannot_cross_tickets(): void
    {
        $user = $this->customer();
        $id = $this->postJson('/api/customer/support/tickets', $this->concern())->json('data.id');
        for ($i = 0; $i < 55; $i++) {
            DB::table('support_messages')->insert(['ticket_id' => $id, 'sender_id' => $user->id, 'sender_role' => 'customer', 'request_key' => (string) Str::uuid(), 'body' => 'Message '.$i, 'created_at' => now(), 'updated_at' => now()]);
        }
        $before = $this->getJson('/api/customer/support/tickets/'.$id)->assertOk()->assertJsonCount(50, 'data.messages')->json('data.next_before');
        $this->getJson('/api/customer/support/tickets/'.$id.'?before='.$before)->assertJsonCount(6, 'data.messages');
        $other = $this->postJson('/api/customer/support/tickets', $this->concern())->json('data.id');
        $message = DB::table('support_messages')->where('ticket_id', $other)->value('id');
        $this->postJson('/api/customer/support/tickets/'.$id.'/read', ['message_id' => $message])->assertUnprocessable();
    }
}
