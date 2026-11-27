<?php

declare(strict_types=1);

use App\Models\RegistrationOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

test('POST /api/auth/register sends OTP and stores payload in database', function () {
    Mail::fake();

    $response = $this->postJson('/api/auth/register', [
        'name' => 'Budi Santoso',
        'email' => 'budi@example.com',
        'password' => 'Password123',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'message' => 'OTP telah dikirim ke email kamu.',
            'data' => [
                'email' => 'budi@example.com',
            ],
        ]);

    $this->assertDatabaseHas('registration_otps', [
        'email' => 'budi@example.com',
    ]);
});

test('POST /api/auth/register returns 422 on invalid password or duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->postJson('/api/auth/register', [
        'name' => 'Budi',
        'email' => 'taken@example.com',
        'password' => 'weak',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'status' => 'error',
            'message' => 'Validation failed.',
        ]);
});

test('POST /api/auth/register/verify creates user, deletes OTP, and returns token', function () {
    RegistrationOtp::create([
        'email' => 'verify@example.com',
        'otp' => '123456',
        'payload' => [
            'name' => 'Verify User',
            'email' => 'verify@example.com',
            'password' => 'Password123',
        ],
        'expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->postJson('/api/auth/register/verify', [
        'email' => 'verify@example.com',
        'otp' => '123456',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'user' => ['id', 'name', 'email'],
                'token',
                'token_type',
            ],
        ])
        ->assertJson([
            'status' => 'success',
            'message' => 'Register berhasil.',
            'data' => [
                'token_type' => 'Bearer',
            ],
        ]);

    $this->assertDatabaseHas('users', ['email' => 'verify@example.com']);
    $this->assertDatabaseMissing('registration_otps', ['email' => 'verify@example.com']);
});

test('POST /api/auth/register/verify rejects invalid or expired OTP with 422', function () {
    RegistrationOtp::create([
        'email' => 'expired@example.com',
        'otp' => '654321',
        'payload' => ['name' => 'Expired', 'email' => 'expired@example.com', 'password' => 'Password123'],
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson('/api/auth/register/verify', [
        'email' => 'expired@example.com',
        'otp' => '000000',
    ])->assertStatus(422)->assertJson(['message' => 'OTP tidak valid.']);

    $this->postJson('/api/auth/register/verify', [
        'email' => 'expired@example.com',
        'otp' => '654321',
    ])->assertStatus(422)->assertJson(['message' => 'OTP sudah kedaluwarsa.']);

    $this->postJson('/api/auth/register/verify', [
        'email' => 'expired@example.com',
        'otp' => 'abc',
    ])->assertStatus(422)->assertJson(['message' => 'Validation failed.']);
});

test('POST /api/auth/login authenticates user and rejects invalid credentials', function () {
    User::factory()->create([
        'email' => 'login@example.com',
        'password' => Hash::make('Password123'),
    ]);

    $success = $this->postJson('/api/auth/login', [
        'email' => 'login@example.com',
        'password' => 'Password123',
    ]);

    $success->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => ['user', 'token', 'token_type'],
        ])
        ->assertJson([
            'status' => 'success',
            'message' => 'Login berhasil.',
            'data' => ['token_type' => 'Bearer'],
        ]);

    $this->postJson('/api/auth/login', [
        'email' => 'login@example.com',
        'password' => 'WrongPass123',
    ])->assertStatus(401)->assertJson(['message' => 'Email atau password salah.']);

    $this->postJson('/api/auth/login', [])->assertStatus(422);
});

test('POST /api/auth/logout revokes current sanctum token and requires auth', function () {
    $this->postJson('/api/auth/logout')->assertStatus(401);

    $user = User::factory()->create();
    $token = $user->createToken('auth_token')->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/auth/logout');

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'message' => 'Logout berhasil.',
        ]);

    expect($user->tokens()->count())->toBe(0);
});

test('POST /api/auth/forgot-password sends reset OTP for existing user and validates edge cases', function () {
    Mail::fake();
    User::factory()->create(['email' => 'reset@example.com']);

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'reset@example.com',
    ])->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'data' => ['email' => 'reset@example.com'],
        ]);

    $this->assertDatabaseHas('registration_otps', ['email' => 'reset@example.com']);

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'missing@example.com',
    ])->assertStatus(404)->assertJson(['message' => 'Email tidak terdaftar.']);

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'not-an-email',
    ])->assertStatus(422);
});

test('POST /api/auth/forgot-password/reset updates password, clears tokens, and validates OTP', function () {
    $user = User::factory()->create([
        'email' => 'forgot@example.com',
        'password' => Hash::make('OldPassword1'),
    ]);
    $user->createToken('old_token');

    RegistrationOtp::create([
        'email' => 'forgot@example.com',
        'otp' => '987654',
        'payload' => ['type' => 'password_reset'],
        'expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->postJson('/api/auth/forgot-password/reset', [
        'email' => 'forgot@example.com',
        'otp' => '987654',
        'password' => 'NewPassword1',
        'password_confirmation' => 'NewPassword1',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'message' => 'Password berhasil direset.',
        ]);

    expect(Hash::check('NewPassword1', $user->fresh()->password))->toBeTrue();
    expect($user->tokens()->count())->toBe(0);
    $this->assertDatabaseMissing('registration_otps', ['email' => 'forgot@example.com']);

    $this->postJson('/api/auth/forgot-password/reset', [
        'email' => 'forgot@example.com',
        'otp' => '111111',
        'password' => 'NewPassword1',
        'password_confirmation' => 'NewPassword1',
    ])->assertStatus(422);
});

test('GET /api/auth/google redirects to Google OAuth consent screen', function () {
    $provider = Mockery::mock();
    $provider->shouldReceive('stateless')->once()->andReturnSelf();
    $provider->shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));

    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider);

    $response = $this->get('/api/auth/google');

    $response->assertStatus(302)
        ->assertRedirect('https://accounts.google.com/o/oauth2/auth');
});

test('GET /api/auth/google/callback logs in user or returns 401 on provider failure', function () {
    $googleUser = Mockery::mock(SocialiteUser::class);
    $googleUser->shouldReceive('getEmail')->andReturn('oauth@example.com');
    $googleUser->shouldReceive('getName')->andReturn('Google Traveler');
    $googleUser->shouldReceive('getId')->andReturn('google-uid-123');

    $provider = Mockery::mock();
    $provider->shouldReceive('stateless')->once()->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($googleUser);

    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider);

    $response = $this->getJson('/api/auth/google/callback');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'message',
            'data' => ['user', 'token', 'token_type'],
        ])
        ->assertJson([
            'status' => 'success',
            'message' => 'Login dengan Google berhasil.',
        ]);

    $this->assertDatabaseHas('users', [
        'email' => 'oauth@example.com',
        'google_id' => 'google-uid-123',
    ]);

    $failingProvider = Mockery::mock();
    $failingProvider->shouldReceive('stateless')->once()->andReturnSelf();
    $failingProvider->shouldReceive('user')->once()->andThrow(new RuntimeException('OAuth failed'));

    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($failingProvider);

    $this->getJson('/api/auth/google/callback')
        ->assertStatus(401)
        ->assertJson([
            'status' => 'error',
            'message' => 'Gagal autentikasi dengan Google.',
        ]);
});
