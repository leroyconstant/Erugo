<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Loader\ArrayLoader;

class SecurityUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_and_protected_upload_routes_boot_after_upgrade(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'success');
        $this->postJson('/api/uploads/create-share-from-uploads', [])->assertUnauthorized();
    }

    public function test_jwt_login_still_issues_a_usable_access_token(): void
    {
        $user = User::factory()->create(['password' => 'security-test-password']);
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'security-test-password',
        ])->assertOk();

        $token = $response->json('data.access_token');
        $this->assertIsString($token);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/users/me')
            ->assertOk()->assertJsonFragment(['email' => $user->email]);
    }

    public function test_password_reset_email_renders_and_escapes_user_content(): void
    {
        $html = view('emails.passwordResetMail', [
            'user' => (object) ['name' => '<script>alert(1)</script>'],
            'token' => 'test-reset-token',
        ])->render();

        $this->assertStringContainsString('test-reset-token', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_twig_treats_quoted_macro_names_as_data(): void
    {
        // CVE-2026-46640: an arbitrary literal must never become raw PHP source.
        $twig = new Environment(new ArrayLoader([
            'invalid' => "{{ _self.(\"missing'macro\")() }}",
        ]));

        $this->expectException(RuntimeError::class);
        $twig->render('invalid');
    }
}
