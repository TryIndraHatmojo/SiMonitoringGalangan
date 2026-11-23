<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_login_screen_loads_assets_over_https_behind_the_local_proxy(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://galangan.test/login');

        $response->assertOk()
            ->assertHeaderContains('Link', '<https://galangan.test/build/assets/')
            ->assertDontSee('http://galangan.test/build/assets/');
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    #[TestWith(['127.0.0.1', 'https://galangan.test/dashboard'])]
    #[TestWith(['::1', 'https://galangan.test/dashboard'])]
    #[TestWith(['192.0.2.10', 'http://galangan.test/dashboard'])]
    public function test_login_redirect_uses_the_forwarded_scheme_from_the_local_proxy(
        string $remoteAddress,
        string $expectedDashboardUrl,
    ): void {
        $user = User::factory()->create();

        $response = $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Inertia' => 'true'])
            ->post('http://galangan.test/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect($expectedDashboardUrl);
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_users_are_rate_limited()
    {
        $user = User::factory()->create();

        RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }
}
