<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_demo_user_can_log_in_after_running_the_seeder_twice(): void
    {
        $this->seed();
        $this->seed(UserSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $demo = User::query()->where('email', 'demo@gmail.com')->sole();
        $this->assertTrue($demo->hasVerifiedEmail());

        $this->post(route('login.store'), [
            'email' => 'demo@gmail.com',
            'password' => 'Merdeka1945!!!',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($demo);
        $this->get(route('dashboard'))->assertOk();
    }
}
