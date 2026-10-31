<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $token = 'notification-customer'): User
    {
        $user = User::factory()->create();
        DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken($token);

        return $user;
    }

    private function order(User $user): object
    {
        $id = DB::table('orders')->insertGetId(['reference' => (string) Str::uuid(), 'user_id' => $user->id, 'delivery_address' => json_encode(['name' => 'Customer', 'phone' => '9123456780', 'street' => 'Road', 'city' => 'Bengaluru', 'pin' => '560001']), 'subtotal_paise' => 10000, 'delivery_paise' => 2000, 'total_paise' => 12000, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('orders')->find($id);
    }

    private function device(string $token = 'a-very-long-test-device-token-1234567890'): array
    {
        $input = ['installation_id' => (string) Str::uuid(), 'token' => $token, 'platform' => 'android'];
        $this->postJson('/api/customer/notification-devices', $input)->assertOk();

        return $input;
    }

    private function credentials(): void
    {
        Storage::fake('local');
        $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($private, $key);
        Storage::disk('local')->put('firebase-service-account.json', json_encode(['project_id' => 'dropbell-test', 'client_email' => 'test@dropbell-test.iam.gserviceaccount.com', 'private_key' => $key]));
        config(['services.firebase.credentials' => Storage::disk('local')->path('firebase-service-account.json')]);
        Http::preventStrayRequests();
    }

    public function test_notifications_are_owned_readable_and_deduplicated(): void
    {
        $this->getJson('/api/customer/notifications')->assertUnauthorized();
        $owner = $this->customer();
        $order = $this->order($owner);
        AppNotifications::status($order);
        AppNotifications::status($order);
        $id = DB::table('app_notifications')->value('id');
        $this->getJson('/api/customer/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('unread_count', 1)->assertJsonPath('data.0.order_reference', $order->reference);
        $this->patchJson('/api/customer/notifications/'.$id.'/read')->assertOk();
        $this->getJson('/api/customer/notifications')->assertJsonPath('unread_count', 0)->assertJsonPath('data.0.read', true);
        $this->customer('other-customer');
        $this->getJson('/api/customer/notifications')->assertJsonCount(0, 'data');
        $this->patchJson('/api/customer/notifications/'.$id.'/read')->assertNotFound();
        $this->assertDatabaseCount('app_notifications', 1);
    }

    public function test_device_registration_encrypts_tokens_and_logout_removes_device(): void
    {
        $this->customer();
        $input = $this->device();
        $device = DB::table('app_devices')->first();
        $this->assertNotSame($input['token'], $device->token);
        $this->assertSame($input['token'], Crypt::decryptString($device->token));
        $this->assertDatabaseHas('app_devices', ['token_hash' => hash('sha256', $input['token'])]);
        $this->postJson('/api/customer/notification-devices', $input)->assertOk();
        $this->assertDatabaseCount('app_devices', 1);
        $this->postJson('/api/customer/logout')->assertOk();
        $this->assertDatabaseCount('app_devices', 0);
    }

    public function test_device_and_notification_endpoints_cannot_cross_customer_partner_roles(): void
    {
        $user = $this->customer();
        $order = $this->order($user);
        AppNotifications::status($order);
        $notification = DB::table('app_notifications')->value('id');
        $this->getJson('/api/partner/notifications')->assertUnauthorized();
        $partner = DB::table('delivery_partners')->insertGetId(['name' => 'Rider', 'phone' => '9234567890', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('delivery_partner_kycs')->insert(['delivery_partner_id' => $partner, 'legal_name' => 'Rider', 'address' => 'Road', 'city' => 'City', 'postal_code' => '560001', 'document_type' => 'passport', 'identity_path' => 'i.jpg', 'address_path' => 'a.jpg', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('partner_tokens')->insert(['delivery_partner_id' => $partner, 'phone' => '9234567890', 'token_hash' => hash('sha256', 'notify-rider'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken('notify-rider')->getJson('/api/partner/notifications')->assertJsonCount(0, 'data');
        $this->patchJson('/api/partner/notifications/'.$notification.'/read')->assertNotFound();
        $this->getJson('/api/customer/notifications')->assertUnauthorized();
    }

    public function test_push_sender_delivers_after_commit_without_financial_or_address_details(): void
    {
        $this->freezeTime();
        $this->credentials();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-test']), 'https://fcm.googleapis.com/v1/projects/dropbell-test/messages:send' => Http::response(['name' => 'sent-1'])]);
        $customer = $this->customer();
        $device = $this->device();
        $order = $this->order($customer);
        AppNotifications::status($order);
        Http::assertNothingSent();
        $this->assertSame(1, AppNotifications::deliver());
        $this->assertSame(0, AppNotifications::deliver());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com') && $request['message']['token'] === $device['token'] && $request['message']['data']['order_reference'] === $order->reference && $request['message']['data']['audience'] === 'customer' && ! str_contains(json_encode($request['message']), 'rate_paise_per_km') && ! str_contains(json_encode($request['message']), '9123456780'));
        $this->assertDatabaseHas('app_notification_deliveries', ['status' => 'sent', 'attempts' => 0]);
    }

    public function test_push_failures_back_off_and_retry_without_duplicating_inbox_items(): void
    {
        $this->freezeTime();
        $this->credentials();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-test']), 'https://fcm.googleapis.com/v1/projects/dropbell-test/messages:send' => Http::sequence()->push(['error' => ['status' => 'UNAVAILABLE']], 503)->push(['name' => 'sent-2'])]);
        $customer = $this->customer();
        $this->device();
        AppNotifications::status($this->order($customer));
        $this->assertSame(0, AppNotifications::deliver());
        $this->assertDatabaseHas('app_notification_deliveries', ['status' => 'pending', 'attempts' => 1, 'lease_until' => null]);
        $this->assertSame(0, AppNotifications::deliver());
        $this->travel(61)->seconds();
        $this->assertSame(1, AppNotifications::deliver());
        $this->assertDatabaseCount('app_notifications', 1);
        Http::assertSentCount(3);
    }

    public function test_rotated_or_expired_session_devices_never_receive_old_pushes(): void
    {
        $this->credentials();
        $customer = $this->customer();
        $device = $this->device();
        AppNotifications::status($this->order($customer));
        $device['token'] = 'new-device-token-after-rotation-1234567890';
        $this->postJson('/api/customer/notification-devices', $device)->assertOk();
        $this->assertSame(0, AppNotifications::deliver());
        $this->assertDatabaseHas('app_notification_deliveries', ['status' => 'cancelled']);
        $order = $this->order($customer);
        AppNotifications::status($order);
        DB::table('customer_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(0, AppNotifications::deliver());
        Http::assertNothingSent();
    }

    public function test_unregistered_device_is_removed_and_missing_configuration_does_not_claim_successful_push(): void
    {
        $this->credentials();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-test']), 'https://fcm.googleapis.com/v1/projects/dropbell-test/messages:send' => Http::response(['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]], 404)]);
        $customer = $this->customer();
        $this->device();
        AppNotifications::status($this->order($customer));
        $this->assertSame(0, AppNotifications::deliver());
        $this->assertDatabaseCount('app_devices', 0);
        $this->assertDatabaseCount('app_notifications', 1);
        config(['services.firebase.credentials' => null]);
        $this->artisan('app:send-app-notifications')->expectsOutput('Firebase is not configured. In-app notifications remain available.')->assertSuccessful();
    }

    public function test_transaction_rollback_creates_neither_inbox_item_nor_push_delivery(): void
    {
        $customer = $this->customer();
        $this->device();
        $order = $this->order($customer);
        DB::beginTransaction();
        AppNotifications::status($order);
        DB::rollBack();
        $this->assertDatabaseCount('app_notifications', 0);
        $this->assertDatabaseCount('app_notification_deliveries', 0);
    }
}
