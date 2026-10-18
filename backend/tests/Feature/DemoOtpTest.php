<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_demo_otp_is_displayed_once_and_cannot_open_an_existing_customer(): void
    {
        $this->app['env'] = 'production';
        $existing = User::factory()->create(['phone' => '9876543210']);
        $challenge = $this->postJson('/api/customer/auth/demo/request', ['phone' => $existing->phone])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $challenge['demo_code']);
        $payload = ['challenge_id' => $challenge['challenge_id'], 'code' => $challenge['demo_code']];
        $this->postJson('/api/customer/auth/verify', $payload)->assertUnprocessable();

        $token = $this->postJson('/api/customer/auth/demo/verify', $payload)->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/customer/me')->assertOk()->assertJsonPath('data.isDemo', true)->assertJsonPath('data.phone', null)->assertJsonPath('data.name', 'Demo customer');
        $this->assertDatabaseHas('users', ['id' => $existing->id, 'phone' => '9876543210', 'is_sample_guest' => false]);
        $this->assertDatabaseCount('users', 2);
        $this->postJson('/api/customer/auth/demo/verify', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('customer_tokens', 1);
        $this->assertDatabaseCount('customer_otps', 0);
    }

    public function test_demo_otp_expires_after_five_minutes_and_locks_after_five_wrong_attempts(): void
    {
        $this->freezeTime();
        $challenge = $this->postJson('/api/customer/auth/demo/request', ['phone' => '9876543210'])->assertOk()->json();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/customer/auth/demo/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => '000000'])->assertUnprocessable();
        }
        $this->postJson('/api/customer/auth/demo/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => $challenge['demo_code']])->assertUnprocessable();
        $second = $this->postJson('/api/customer/auth/demo/request', ['phone' => '9123456789'])->assertOk()->json();
        $this->travel(5)->minutes();
        $this->postJson('/api/customer/auth/demo/verify', ['challenge_id' => $second['challenge_id'], 'code' => $second['demo_code']])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customer_tokens', 0);
    }

    public function test_demo_routes_cannot_consume_real_challenges_and_resend_limit_has_a_clear_message(): void
    {
        $this->app['env'] = 'local';
        config(['customer.local_otp' => true]);
        $real = $this->postJson('/api/customer/auth/request', ['phone' => '9876543210'])->assertOk()->json();
        $this->postJson('/api/customer/auth/demo/verify', ['challenge_id' => $real['challenge_id'], 'code' => $real['local_code']])->assertUnprocessable();
        $this->assertDatabaseHas('customer_otps', ['id' => $real['challenge_id'], 'is_demo' => false, 'attempts' => 0]);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/customer/auth/demo/request', ['phone' => '9876543210'])->assertOk();
        }
        $response = $this->postJson('/api/customer/auth/demo/request', ['phone' => '9876543210'])->assertStatus(429);
        $this->assertStringContainsString('seconds before generating another temporary code', $response->json('message'));
        $this->assertSame(1, DB::table('customer_otps')->where('is_demo', false)->count());
        $this->postJson('/api/customer/auth/verify', ['challenge_id' => $real['challenge_id'], 'code' => $real['local_code']])->assertOk();
    }
}
