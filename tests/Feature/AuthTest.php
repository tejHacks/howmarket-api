<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_a_token_and_a_profile_without_the_database_id(): void
    {
        $res = $this->postJson('/api/register', ['name' => 'Ada', 'email' => 'Ada@Example.com', 'password' => 'secret123', 'area' => 'Lagos'])
            ->assertCreated()
            ->assertJsonPath('user.area', 'Lagos')
            ->assertJsonPath('user.email', 'ada@example.com')
            ->assertJsonPath('user.trust', 1)
            ->assertJsonPath('user.dailyLimit', 20);

        $this->assertNotEmpty($res->json('token'));
        $this->assertSame(16, strlen($res->json('user.id')));
        $this->assertNotSame((string) User::first()->id, $res->json('user.id'));
    }

    public function test_register_rejects_bad_input(): void
    {
        $this->postJson('/api/register', ['name' => 'A', 'email' => 'nope', 'password' => 'short', 'area' => 'Kano'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'area']);
    }

    public function test_login_checks_the_password(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'secret123']);

        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong-one'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => 'ADA@example.com', 'password' => 'secret123'])->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_signed_out_requests_get_a_json_401_even_without_accept_header(): void
    {
        $this->get('/api/me')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
        $this->post('/api/reports')->assertUnauthorized();
    }

    public function test_profile_update_cannot_change_trust(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/me', ['area' => 'Abuja', 'trust' => 2])
            ->assertOk()
            ->assertJsonPath('area', 'Abuja')
            ->assertJsonPath('trust', 1);
        $this->assertEquals(1, $user->fresh()->trust);
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = $this->postJson('/api/register', ['name' => 'Ada', 'email' => 'a@b.co', 'password' => 'secret123', 'area' => 'Ibadan'])->json('token');

        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 10) as $_) {
            $this->postJson('/api/login', ['email' => 'x@y.co', 'password' => 'nope']);
        }
        $this->postJson('/api/login', ['email' => 'x@y.co', 'password' => 'nope'])->assertTooManyRequests();
    }
}
