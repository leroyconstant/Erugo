<?php

namespace Tests\Feature;

use App\AuthProviders\AuthProviderUser;
use App\Http\Controllers\ExternalAuthController;
use App\Models\AuthProvider;
use App\Models\Setting;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): string
    {
        $this->app['auth']->forgetGuards();
        $token = app(TokenService::class)->issue($user);
        $this->withToken($token);
        return $token;
    }

    public function test_smtp_settings_require_an_administrator_for_both_read_routes(): void
    {
        Setting::create(['key' => 'smtp_password', 'group' => 'system.smtp', 'value' => 'dummy-secret']);
        $this->bearer(User::factory()->create(['admin' => false]));
        $this->getJson('/api/settings/smtp_password')->assertForbidden();
        $this->getJson('/api/settings/group/system.*')->assertForbidden();
        $this->bearer(User::factory()->create(['admin' => true]));
        $this->getJson('/api/settings/smtp_password')->assertOk();
        $this->getJson('/api/settings/group/system.smtp')->assertOk();
    }

    public function test_inactive_and_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create(['active' => false, 'password' => 'review-password']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'review-password'])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertUnauthorized();
    }

    public function test_password_change_revokes_both_existing_access_and_refresh_tokens(): void
    {
        $user = User::factory()->create();
        $access = app(TokenService::class)->issue($user);
        $refresh = app(TokenService::class)->issue($user, 'refresh', 1440);
        $user->update(['password' => 'changed-review-password']);
        $this->withToken($access)->getJson('/api/users/me')->assertUnauthorized();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withCredentials()->withUnencryptedCookie('refresh_token', $refresh)->postJson('/api/auth/refresh')->assertUnauthorized();
    }

    public function test_disabling_account_revokes_existing_token_and_refresh(): void
    {
        $user = User::factory()->create();
        $access = app(TokenService::class)->issue($user);
        $refresh = app(TokenService::class)->issue($user, 'refresh');
        $user->update(['active' => false]);
        $this->withToken($access)->getJson('/api/users/me')->assertUnauthorized();
        $this->assertNull(app(TokenService::class)->authenticate($refresh, ['refresh']));
    }

    public function test_refresh_cookie_is_secure_lasts_one_day_and_cannot_be_used_as_access_token(): void
    {
        $user = User::factory()->create(['password' => 'review-password']);
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'review-password'])->assertOk();
        $cookies = array_values(array_filter($response->headers->getCookies(), fn ($c) => $c->getName() === 'refresh_token'));
        $this->assertCount(1, $cookies);
        $this->assertTrue($cookies[0]->isSecure());
        $this->assertTrue($cookies[0]->isHttpOnly());
        $this->assertEqualsWithDelta(86400, $cookies[0]->getExpiresTime() - time(), 5);
        $this->assertSame(3600, $response->json('data.expires_in'));
        $refresh = $cookies[0]->getValue();
        $payload = app('tymon.jwt')->setToken($refresh)->getPayload();
        $this->assertSame(86400, $payload->get('exp') - $payload->get('iat'));
        $this->withToken($refresh)->getJson('/api/users/me')->assertUnauthorized();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withCredentials()->withUnencryptedCookie('refresh_token', $refresh)->postJson('/api/auth/refresh')
            ->assertOk()->assertJsonPath('data.expires_in', 3600);
    }

    public function test_logout_invalidates_the_access_and_refresh_token(): void
    {
        $user = User::factory()->create();
        $access = $this->bearer($user);
        $refresh = app(TokenService::class)->issue($user, 'refresh');
        $this->withCredentials()->withUnencryptedCookie('refresh_token', $refresh)->postJson('/api/auth/logout')->assertOk();
        $this->assertNull(app(TokenService::class)->authenticate($access));
        $this->assertNull(app(TokenService::class)->authenticate($refresh, ['refresh']));
    }

    public function test_upload_authorization_checks_owner_for_head_patch_and_delete(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $id = str_repeat('a', 32);
        UploadSession::create(['upload_id' => $id, 'user_id' => $owner->id, 'filename' => 'test.txt', 'filesize' => 4,
            'filetype' => 'text/plain', 'total_chunks' => 1, 'chunks_received' => 0, 'status' => 'pending']);
        foreach (['HEAD', 'PATCH', 'DELETE'] as $method) {
            $this->bearer($other);
            $this->withHeaders(['X-Forwarded-Method' => $method, 'X-Forwarded-Uri' => '/files/' . $id])
                ->getJson('/api/uploads/authorize')->assertNotFound();
            $this->bearer($owner);
            $this->getJson('/api/uploads/authorize')->assertNoContent();
        }
        $this->withHeader('X-Forwarded-Method', 'GET')->getJson('/api/uploads/authorize')->assertStatus(405);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/uploads/authorize')->assertUnauthorized();
    }

    public function test_hook_rejects_private_and_public_prefix_ips_without_secret(): void
    {
        config(['erugo.tusd_hook_secret' => str_repeat('s', 64)]);
        $user = User::factory()->create();
        $id = str_repeat('b', 32);
        UploadSession::create(['upload_id' => $id, 'user_id' => $user->id, 'filename' => 'test.txt', 'filesize' => 1,
            'filetype' => 'text/plain', 'total_chunks' => 1, 'chunks_received' => 0, 'status' => 'pending']);
        $payload = ['Type' => 'post-terminate', 'Event' => ['Upload' => ['ID' => $id]]];
        foreach (['192.168.25.10', '172.200.25.10', '127.0.0.1'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/tusd-hooks', $payload)->assertForbidden();
        }
        $this->assertDatabaseHas('upload_sessions', ['upload_id' => $id]);
        $this->withHeader('X-Erugo-Hook-Secret', str_repeat('s', 64))->postJson('/api/tusd-hooks', $payload)->assertOk();
        $this->assertDatabaseMissing('upload_sessions', ['upload_id' => $id]);
    }

    public function test_sso_email_trust_requires_a_verified_identity(): void
    {
        $admin = User::factory()->create(['admin' => true]);
        $provider = AuthProvider::create(['name' => 'Test provider', 'provider_class' => 'OIDC', 'enabled' => true,
            'uuid' => 'test-oidc', 'allow_registration' => false, 'provider_config' => []]);
        $provider->trust_email = true;
        $provider->save();
        $identity = new AuthProviderUser(['sub' => 'test-identity', 'name' => 'Test identity', 'email' => $admin->email,
            'avatar' => null, 'verified' => false]);
        $controller = app(ExternalAuthController::class);
        $method = new \ReflectionMethod($controller, 'loginWithEmail');
        $method->invoke($controller, $provider, $identity);
        $this->assertFalse(auth('api')->check());
        $this->assertDatabaseMissing('user_auth_provider', ['user_id' => $admin->id]);
        $identity->verified = true;
        $method->invoke($controller, $provider, $identity);
        $this->assertDatabaseHas('user_auth_provider', ['user_id' => $admin->id]);
    }

    public function test_login_and_verification_attempts_are_throttled(): void
    {
        foreach (['login', 'verify-email'] as $route) {
            for ($i = 0; $i < 5; $i++) {
                $this->postJson('/api/auth/' . $route, ['email' => 'missing@example.test', 'password' => 'wrong-password', 'code' => '123456']);
            }
            $this->postJson('/api/auth/' . $route, ['email' => 'missing@example.test', 'password' => 'wrong-password', 'code' => '123456'])->assertStatus(429);
        }
    }

    public function test_guest_invitation_still_issues_a_usable_session(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        Setting::create(['key' => 'allow_reverse_shares', 'group' => 'system.shares', 'value' => 'true']);
        $this->bearer(User::factory()->create());
        $this->postJson('/api/reverse-shares/invite', ['recipient_name' => 'Guest', 'recipient_email' => 'guest@example.test'])->assertOk();
        $job = \Illuminate\Support\Facades\Queue::pushed(\App\Jobs\sendEmail::class)->first();
        $this->assertNotNull($job);
        $encrypted = $job->mailableData['token'];
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $response = $this->getJson('/api/reverse-shares/accept?token=' . urlencode($encrypted))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('data.access_token'))->getJson('/api/users/me')->assertOk();
    }

    public function test_required_password_change_blocks_uploads_but_allows_profile(): void
    {
        $this->bearer(User::factory()->create(['must_change_password' => true]));
        $this->getJson('/api/users/me')->assertOk();
        $this->withHeaders(['X-Forwarded-Method' => 'POST', 'X-Forwarded-Uri' => '/files/'])
            ->getJson('/api/uploads/authorize')->assertForbidden();
    }
}
