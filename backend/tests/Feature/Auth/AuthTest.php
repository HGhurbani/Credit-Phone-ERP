<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_succeeds_with_valid_credentials(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create([
            'email' => 'user@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'user@example.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user', 'token'])
            ->assertJsonPath('user.email', 'user@example.com');
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        User::factory()->forTenant($tenant->id)->create([
            'email' => 'user@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'user@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(422);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_protected_endpoint_returns_401_without_token(): void
    {
        $response = $this->getJson('/api/dashboard');

        $response->assertUnauthorized();
    }
}
