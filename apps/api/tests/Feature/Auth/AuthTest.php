<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Cargar roles y permisos antes de cada test
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    // -------------------------------------------------------------------------
    // LOGIN
    // -------------------------------------------------------------------------

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'password'  => bcrypt('password123'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk()
                 ->assertJsonStructure([
                     'token',
                     'user' => ['id', 'name', 'email', 'roles'],
                 ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct')]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'wrong',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['email']);
    }

    public function test_login_fails_for_inactive_user(): void
    {
        $user = User::factory()->create([
            'password'  => bcrypt('password123'),
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ])->assertStatus(403)
          ->assertJson(['code' => 'ACCOUNT_DISABLED']);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/v1/auth/login', [])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['email', 'password']);
    }

    // -------------------------------------------------------------------------
    // ME
    // -------------------------------------------------------------------------

    public function test_authenticated_user_can_get_their_profile(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('postulante');

        $this->actingAs($user, 'sanctum')
             ->getJson('/api/v1/me')
             ->assertOk()
             ->assertJsonFragment([
                 'email' => $user->email,
             ])
             ->assertJsonPath('roles.0', 'postulante');
    }

    public function test_unauthenticated_user_cannot_access_me(): void
    {
        $this->getJson('/api/v1/me')
             ->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // LOGOUT
    // -------------------------------------------------------------------------

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'sanctum')
             ->postJson('/api/v1/auth/logout')
             ->assertOk()
             ->assertJsonFragment(['message' => 'Sesión cerrada correctamente.']);
    }

    public function test_unauthenticated_user_cannot_logout(): void
    {
        $this->postJson('/api/v1/auth/logout')
             ->assertStatus(401);
    }
}
