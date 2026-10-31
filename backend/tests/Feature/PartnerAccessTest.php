<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerAccessTest extends TestCase
{
    use RefreshDatabase;

    private function partner(): int
    {
        $id = DB::table('delivery_partners')->insertGetId(['name' => 'Asha', 'phone' => '9123456780', 'password' => Hash::make('rider-secure-password'), 'vehicle' => 'Scooter', 'active' => true, 'is_online' => true]);
        DB::table('delivery_partner_kycs')->insert(['delivery_partner_id' => $id, 'legal_name' => 'Asha Kumar', 'address' => '12 Road', 'city' => 'Dehradun', 'postal_code' => '248001', 'document_type' => 'passport', 'identity_path' => 'identity.jpg', 'address_path' => 'address.jpg', 'status' => 'approved']);

        return $id;
    }

    private function signIn(): string
    {
        return $this->postJson('/api/partner/auth/login', ['phone' => '9123456780', 'password' => 'rider-secure-password'])->assertOk()->json('token');
    }

    public function test_password_sign_in_works_in_production_and_only_exposes_a_session_token(): void
    {
        $id = $this->partner();
        $this->app['env'] = 'production';
        config(['partner.local_otp' => false]);

        $token = $this->signIn();

        $this->assertDatabaseHas('partner_tokens', ['delivery_partner_id' => $id, 'token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('partner_tokens', ['token_hash' => $token]);
        $this->withToken($token)->getJson('/api/partner/me')->assertOk()->assertJsonPath('data.name', 'Asha')->assertJsonMissingPath('data.password');
    }

    public function test_wrong_password_and_unapproved_or_inactive_accounts_cannot_sign_in(): void
    {
        $id = $this->partner();
        $this->postJson('/api/partner/auth/login', ['phone' => '9123456780', 'password' => 'wrong-password'])->assertUnprocessable();
        DB::table('delivery_partner_kycs')->where('delivery_partner_id', $id)->update(['status' => 'pending']);
        $this->postJson('/api/partner/auth/login', ['phone' => '9123456780', 'password' => 'rider-secure-password'])->assertUnprocessable();
        DB::table('delivery_partner_kycs')->where('delivery_partner_id', $id)->update(['status' => 'approved']);
        DB::table('delivery_partners')->where('id', $id)->update(['active' => false]);
        $this->postJson('/api/partner/auth/login', ['phone' => '9123456780', 'password' => 'rider-secure-password'])->assertUnprocessable();
        $this->assertDatabaseCount('partner_tokens', 0);
    }

    public function test_password_attempts_are_limited_per_phone(): void
    {
        $this->partner();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/partner/auth/login', ['phone' => '9123456780', 'password' => 'wrong-password'])->assertUnprocessable();
        }
        $this->postJson('/api/partner/auth/login', ['phone' => '9123456780', 'password' => 'rider-secure-password'])->assertTooManyRequests();
        $this->assertDatabaseCount('partner_tokens', 0);
    }

    public function test_admin_sets_a_hashed_password_and_reset_revokes_old_sessions(): void
    {
        $id = $this->partner();
        $oldToken = $this->signIn();
        DB::table('partner_otps')->insert(['id' => (string) Str::uuid(), 'delivery_partner_id' => $id, 'phone' => '9123456780', 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(5)]);
        $admin = User::factory()->create(['is_admin' => true]);
        $data = ['name' => 'Asha', 'phone' => '9123456780', 'vehicle' => 'Scooter', 'active' => true, 'password' => 'new-secure-rider-password', 'password_confirmation' => 'new-secure-rider-password'];

        $this->actingAs($admin)->put('/admin/delivery-partners/'.$id, $data)->assertRedirect();

        $this->assertTrue(Hash::check('new-secure-rider-password', DB::table('delivery_partners')->where('id', $id)->value('password')));
        $this->assertDatabaseCount('partner_tokens', 0);
        $this->assertDatabaseCount('partner_otps', 0);
        $this->assertDatabaseHas('delivery_partners', ['id' => $id, 'is_online' => false]);
        $this->assertDatabaseHas('delivery_partner_kycs', ['delivery_partner_id' => $id, 'status' => 'approved']);
        $this->withToken($oldToken)->getJson('/api/partner/me')->assertUnauthorized();
        $this->postJson('/api/partner/auth/login', ['phone' => '9123456780', 'password' => 'new-secure-rider-password'])->assertOk();
        $this->get('/admin/delivery-partners/'.$id.'/edit')->assertOk()->assertDontSee('new-secure-rider-password');
    }

    public function test_non_admins_cannot_provision_access_and_weak_passwords_are_rejected(): void
    {
        $id = $this->partner();
        $data = ['name' => 'Asha', 'phone' => '9123456780', 'vehicle' => 'Scooter', 'active' => true, 'password' => 'short', 'password_confirmation' => 'short'];
        $this->actingAs(User::factory()->create())->put('/admin/delivery-partners/'.$id, $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->putJson('/admin/delivery-partners/'.$id, $data)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('rider-secure-password', DB::table('delivery_partners')->where('id', $id)->value('password')));
    }

    public function test_oversized_unicode_password_returns_validation_instead_of_a_hashing_error(): void
    {
        $id = $this->partner();
        $value = str_repeat('🙂', 20);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->putJson('/admin/delivery-partners/'.$id, ['name' => 'Asha', 'phone' => '9123456780', 'active' => true, 'password' => $value, 'password_confirmation' => $value])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/partner/auth/login', ['phone' => '9123456780', 'password' => $value])->assertUnprocessable();
        $this->assertDatabaseCount('partner_tokens', 0);
    }

    public function test_history_is_paginated_separately_from_active_deliveries(): void
    {
        $id = $this->partner();
        $user = User::factory()->create();
        $rows = [];
        for ($index = 0; $index < 32; $index++) {
            $rows[] = ['reference' => (string) Str::uuid(), 'user_id' => $user->id, 'delivery_partner_id' => $id, 'delivery_address' => json_encode(['name' => 'Customer']), 'subtotal_paise' => 10000, 'total_paise' => 10000, 'status' => $index === 0 ? 'delivered' : 'received'];
        }
        DB::table('orders')->insert($rows);
        $token = $this->signIn();

        $this->withToken($token)->getJson('/api/partner/orders?scope=history')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $rows[0]['reference'])->assertJsonPath('next_page', null);
        $this->getJson('/api/partner/orders?scope=active')->assertJsonCount(30, 'data')->assertJsonPath('next_page', 2);
        $this->getJson('/api/partner/orders?scope=active&page=2')->assertJsonCount(1, 'data')->assertJsonPath('next_page', null);
        $this->getJson('/api/partner/orders?scope=invalid')->assertUnprocessable();
    }

    public function test_readiness_reports_missing_rider_coverage_without_printing_passwords(): void
    {
        $this->partner();
        $this->serviceLocality('248001');
        config(['services.firebase.credentials' => null]);

        $this->artisan('app:partner-readiness')
            ->expectsOutput('Approved riders with app passwords: 1')
            ->expectsOutput('Active delivery areas: 1')
            ->assertExitCode(1);
    }

    public function test_sign_out_marks_the_partner_offline_without_deleting_deliveries(): void
    {
        $id = $this->partner();
        $token = $this->signIn();

        $this->withToken($token)->postJson('/api/partner/logout')->assertOk();

        $this->assertDatabaseHas('delivery_partners', ['id' => $id, 'is_online' => false]);
        $this->getJson('/api/partner/me')->assertUnauthorized();
    }
}
