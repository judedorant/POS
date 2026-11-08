<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class SessionTest extends TestCase {
    use RefreshDatabase;
    public function test_login_and_logout(): void {
        User::create(['name'=>'User','email'=>'user@example.com','password'=>'long-password']);
        $this->withHeader('Origin','http://localhost')->postJson('/api/v1/login',['email'=>'user@example.com','password'=>'long-password'])->assertOk()->assertJsonMissingPath('user.password');
        $this->getJson('/api/v1/me')->assertOk();
        $this->deleteJson('/api/v1/session')->assertNoContent();
    }
    public function test_bad_password_is_rejected(): void {
        $this->withHeader('Origin','http://localhost')->postJson('/api/v1/login',['email'=>'missing@example.com','password'=>'wrong'])->assertUnprocessable();
    }
}
