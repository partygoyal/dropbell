<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_and_customers_are_forbidden(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_admin_login_dashboard_and_logout(): void
    {
        $this->seed();
        $admin = User::factory()->create(['password' => 'test-password-123']);
        $admin->is_admin = true;
        $admin->save();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'test-password-123'])->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertSee('Sweet ripe bananas')->assertSee('Your first order is still ahead.');
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_non_admin_credentials_cannot_log_in_as_admin(): void
    {
        $user = User::factory()->create(['password' => 'test-password-123']);
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'test-password-123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_sidebar_is_consistent_and_highlights_current_section(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (['/admin' => 'Overview', '/admin/orders' => 'Manage orders', '/admin/products' => 'Manage products'] as $url => $label) {
            $response = $this->get($url)->assertOk()->assertDontSee('Open storefront')->assertSee('Order notifications');
            preg_match('/<aside class="sidebar">(.*?)<\/aside>/s', $response->getContent(), $sidebar);
            $this->assertSame(1, substr_count($sidebar[1], 'aria-current="page"'));
            $this->assertMatchesRegularExpression('/<a[^>]*aria-current="page"[^>]*>.*?'.preg_quote($label, '/').'<\/a>/s', $sidebar[1]);
        }
    }
}
