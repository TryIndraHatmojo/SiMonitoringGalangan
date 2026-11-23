<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['/dashboard'])]
    #[TestWith(['/monitoring/ringkasan'])]
    public function test_guests_are_redirected_to_the_login_page(string $path): void
    {
        $response = $this->get($path);

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }
}
