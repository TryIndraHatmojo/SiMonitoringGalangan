<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_registration_page_is_unavailable(): void
    {
        $response = $this->get('/register');

        $response->assertNotFound();
    }

    public function test_guests_cannot_create_an_account_through_public_registration(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'ExamplePassword123!',
            'password_confirmation' => 'ExamplePassword123!',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
