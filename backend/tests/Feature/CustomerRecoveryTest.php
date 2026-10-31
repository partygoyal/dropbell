<?php

namespace Tests\Feature;

use App\Mail\CustomerPasswordReset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class CustomerRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_reset_once_and_all_existing_sessions_are_revoked(): void
    {
        Mail::fake();
        config(['customer.web_url' => 'https://shop.example.test']);
        $user = User::factory()->create(['email' => 'customer@example.test', 'password' => 'old-password']);
        $token = $this->postJson('/api/customer/auth/login', ['email' => $user->email, 'password' => 'old-password'])->assertOk()->json('token');
        $this->postJson('/api/customer/auth/forgot-password', ['email' => 'CUSTOMER@example.test'])->assertOk()->assertJsonMissingPath('local_reset_url');
        $resetToken = '';
        Mail::assertSent(CustomerPasswordReset::class, function ($mail) use (&$resetToken): bool {
            parse_str(parse_url($mail->resetUrl, PHP_URL_QUERY), $query);
            $resetToken = $query['token'];

            return str_starts_with($mail->resetUrl, 'https://shop.example.test/reset-password?');
        });
        $this->assertNotSame($resetToken, DB::table('password_reset_tokens')->value('token'));
        $input = ['email' => $user->email, 'token' => $resetToken, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
        $this->postJson('/api/customer/auth/reset-password', $input)->assertOk();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->withToken($token)->getJson('/api/customer/me')->assertUnauthorized();
        $this->postJson('/api/customer/auth/reset-password', $input)->assertUnprocessable();
        $this->postJson('/api/customer/auth/login', ['email' => $user->email, 'password' => 'old-password'])->assertUnprocessable();
        $this->postJson('/api/customer/auth/login', ['email' => $user->email, 'password' => 'new-password'])->assertOk();
    }

    public function test_unknown_and_non_customer_accounts_receive_identical_responses_without_email(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'admin@example.test', 'is_admin' => true]);
        User::factory()->create(['email' => 'vendor@example.test', 'is_vendor' => true]);
        $response = $this->postJson('/api/customer/auth/forgot-password', ['email' => 'absent@example.test'])->assertOk()->json();
        foreach (['admin@example.test', 'vendor@example.test'] as $email) {
            $this->assertSame($response, $this->postJson('/api/customer/auth/forgot-password', ['email' => $email])->assertOk()->json());
        }
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_expired_token_wrong_email_and_mismatched_confirmation_cannot_reset(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $input = ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'mismatch'];
        $this->postJson('/api/customer/auth/reset-password', $input)->assertUnprocessable()->assertJsonValidationErrors('password');
        $input['password_confirmation'] = 'new-password';
        $this->postJson('/api/customer/auth/reset-password', array_replace($input, ['email' => 'different@example.test']))->assertUnprocessable();
        $this->travel(61)->minutes();
        $this->postJson('/api/customer/auth/reset-password', $input)->assertUnprocessable();
    }

    public function test_customer_endpoint_cannot_reset_admin_and_local_links_never_escape_in_production(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $token = Password::broker()->createToken($admin);
        $this->postJson('/api/customer/auth/reset-password', ['email' => $admin->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnprocessable();
        $this->app['env'] = 'production';
        config(['customer.web_url' => 'https://shop.example.test', 'mail.default' => 'log']);
        $this->postJson('/api/customer/auth/forgot-password', ['email' => 'customer@example.test'])->assertStatus(503)->assertJsonMissingPath('local_reset_url');
        Mail::assertNothingSent();
    }

    public function test_repeated_requests_are_throttled_and_local_reset_can_be_tested_without_smtp(): void
    {
        Mail::fake();
        $this->app['env'] = 'local';
        config(['customer.web_url' => 'http://localhost:3000', 'mail.default' => 'log']);
        $user = User::factory()->create();
        $this->postJson('/api/customer/auth/forgot-password', ['email' => $user->email])->assertOk()->assertJsonStructure(['local_reset_url']);
        $this->postJson('/api/customer/auth/forgot-password', ['email' => $user->email])->assertOk()->assertJsonMissingPath('local_reset_url');
        Mail::assertSentCount(1);
    }
}
