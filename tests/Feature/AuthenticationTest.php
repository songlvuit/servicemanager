<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();
    }

    public function test_home_redirects_to_the_admin_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_login_page_can_be_rendered(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_users_can_log_in_with_valid_credentials(): void
    {
        $user = $this->createUserWithRole(SystemRole::Employee);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_users_cannot_log_in_with_a_wrong_password(): void
    {
        $user = $this->createUserWithRole(SystemRole::Employee);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_deactivated_users_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_deactivated_users_cannot_open_the_panel(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_authenticated_users_can_open_the_dashboard(): void
    {
        $user = $this->createUserWithRole(SystemRole::Employee);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee($user->name);
    }
}
