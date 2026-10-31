<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['is_admin' => true, 'password' => 'CurrentAdmin@123']);
        $admin->username = 'originaladmin';
        $admin->save();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_profile_routes_are_admin_only(): void
    {
        $this->get('/admin/profile')->assertRedirect('/admin/login');
        $this->put('/admin/profile', [])->assertRedirect('/admin/login');
        $this->put('/admin/profile/password', [])->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/profile')->assertForbidden();
        $this->put('/admin/profile', [])->assertForbidden();
        $this->put('/admin/profile/password', [])->assertForbidden();
    }

    public function test_admin_updates_own_profile_and_can_login_with_username_or_email(): void
    {
        $admin = $this->admin();
        $this->get('/admin/profile')->assertOk()->assertSee('Personal details')->assertSee('Change password');
        $this->put('/admin/profile', ['name' => 'Updated Admin', 'username' => 'New.Admin', 'email' => 'newadmin@example.test', 'phone' => '9876543210', 'current_password' => 'CurrentAdmin@123', 'is_admin' => false])->assertRedirect('/admin/profile');
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'name' => 'Updated Admin', 'username' => 'new.admin', 'email' => 'newadmin@example.test', 'is_admin' => true]);
        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => 'new.admin', 'password' => 'CurrentAdmin@123'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => 'newadmin@example.test', 'password' => 'CurrentAdmin@123'])->assertRedirect('/admin');
    }

    public function test_profile_rejects_wrong_password_and_duplicate_identifiers(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create(['phone' => '9876543210']);
        $other->username = 'taken';
        $other->save();
        $payload = ['name' => 'Changed', 'username' => 'taken', 'email' => $other->email, 'phone' => $other->phone, 'current_password' => 'wrong'];
        $this->putJson('/admin/profile', $payload)->assertUnprocessable()->assertJsonValidationErrors(['current_password', 'username', 'email', 'phone']);
        $this->assertSame('originaladmin', $admin->fresh()->username);
    }

    public function test_password_change_requires_current_password_confirmation_and_minimum_length(): void
    {
        $admin = $this->admin();
        $this->putJson('/admin/profile/password', ['current_password' => 'wrong', 'password' => 'short', 'password_confirmation' => 'different'])->assertUnprocessable()->assertJsonValidationErrors(['current_password', 'password']);
        $this->assertTrue(Hash::check('CurrentAdmin@123', $admin->fresh()->password));
        $this->putJson('/admin/profile/password', ['current_password' => 'CurrentAdmin@123', 'password' => 'CurrentAdmin@123', 'password_confirmation' => 'CurrentAdmin@123'])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_password_changes_and_sessions_using_the_old_hash_must_sign_in_again(): void
    {
        $admin = $this->admin();
        $oldHash = $admin->password;
        $this->put('/admin/profile/password', ['current_password' => 'CurrentAdmin@123', 'password' => 'UpdatedAdmin@456', 'password_confirmation' => 'UpdatedAdmin@456'])->assertRedirect('/admin/profile');
        $this->assertTrue(Hash::check('UpdatedAdmin@456', $admin->fresh()->password));
        $this->assertFalse(Hash::check('CurrentAdmin@123', $admin->fresh()->password));
        $this->get('/admin/profile')->assertOk();
        $this->withSession(['password_hash_web' => $oldHash])->get('/admin/profile')->assertRedirect();
        $this->assertGuest();
    }
}
