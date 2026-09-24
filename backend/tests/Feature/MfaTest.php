<?php

use App\Http\Controllers\Api\MfaController;
use App\Models\User;
use App\Models\WebAuthnCredential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

describe('MFA login flow', function () {
    it('returns mfa_required when logging in with TOTP enabled', function () {
        $user = User::factory()->create([
            'totp_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('mfa_required', true)
            ->assertJsonPath('mfa_methods', ['totp'])
            ->assertJsonStructure(['mfa_token'])
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('encryption_keys');
    });

    it('returns mfa_required when logging in with a passkey', function () {
        $user = User::factory()->create();
        WebAuthnCredential::factory()->create(['user_id' => $user->id]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('mfa_required', true)
            ->assertJsonPath('mfa_methods', ['passkey'])
            ->assertJsonStructure(['mfa_token']);
    });

    it('completes login with a valid TOTP code', function () {
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey(32);
        $user = User::factory()->create([
            'totp_secret_encrypted' => $secret,
            'totp_confirmed_at' => now(),
        ]);

        $mfaToken = app(MfaController::class)->createMfaToken($user);
        $code = $google2fa->getCurrentOtp($secret);

        $response = $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $mfaToken,
            'method' => 'totp',
            'code' => $code,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['user', 'token', 'encryption_keys']);
    });

    it('rejects an invalid TOTP code', function () {
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey(32);
        $user = User::factory()->create([
            'totp_secret_encrypted' => $secret,
            'totp_confirmed_at' => now(),
        ]);

        $mfaToken = app(MfaController::class)->createMfaToken($user);

        $response = $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $mfaToken,
            'method' => 'totp',
            'code' => '000000',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    });

    it('rejects a replayed TOTP timestep', function () {
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey(32);
        $user = User::factory()->create([
            'totp_secret_encrypted' => $secret,
            'totp_confirmed_at' => now(),
        ]);

        $code = $google2fa->getCurrentOtp($secret);

        $firstToken = app(MfaController::class)->createMfaToken($user);
        $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $firstToken,
            'method' => 'totp',
            'code' => $code,
        ])->assertStatus(200);

        $secondToken = app(MfaController::class)->createMfaToken($user);
        $response = $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $secondToken,
            'method' => 'totp',
            'code' => $code,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    });

    it('completes login with a valid recovery code', function () {
        $user = User::factory()->create([
            'totp_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
            'mfa_recovery_codes_hash' => json_encode([Hash::make('abcdef1234567890')]),
        ]);

        $mfaToken = app(MfaController::class)->createMfaToken($user);

        $response = $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $mfaToken,
            'method' => 'recovery',
            'code' => 'abcdef1234567890',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['user', 'token', 'encryption_keys']);

        $freshUser = $user->fresh();
        $hashes = json_decode($freshUser->mfa_recovery_codes_hash, true);
        expect($hashes[0])->toBeNull();
    });

    it('rejects an invalid recovery code', function () {
        $user = User::factory()->create([
            'totp_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
            'mfa_recovery_codes_hash' => json_encode([Hash::make('abcdef1234567890')]),
        ]);

        $mfaToken = app(MfaController::class)->createMfaToken($user);

        $response = $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $mfaToken,
            'method' => 'recovery',
            'code' => '0000000000000000',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    });

    it('locks recovery code attempts after 10 failures', function () {
        $user = User::factory()->create([
            'totp_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
            'mfa_recovery_codes_hash' => json_encode([Hash::make('abcdef1234567890')]),
        ]);

        $mfaToken = app(MfaController::class)->createMfaToken($user);
        $payload = (new ReflectionClass(MfaController::class))
            ->getMethod('parseMfaToken')
            ->invoke(app(MfaController::class), $mfaToken);

        Cache::put('mfa_recovery_attempts:'.$payload['nonce'], 10, now()->addMinutes(5));

        $response = $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $mfaToken,
            'method' => 'recovery',
            'code' => 'abcdef1234567890',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    });

    it('rejects an expired or used mfa_token', function () {
        $user = User::factory()->create([
            'totp_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
        ]);

        $payload = [
            'user_id' => $user->id,
            'expires_at' => now()->subMinute()->getTimestamp(),
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $token = Crypt::encryptString(json_encode($payload));

        $response = $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $token,
            'method' => 'totp',
            'code' => '000000',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mfa_token']);
    });
});

describe('TOTP setup', function () {
    it('generates an unconfirmed TOTP secret', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/totp/setup');

        $response->assertStatus(200)
            ->assertJsonStructure(['secret', 'otpauth_uri']);

        $freshUser = $user->fresh();
        expect($freshUser->totp_secret_encrypted)->not->toBeNull();
        expect($freshUser->totp_confirmed_at)->toBeNull();
    });

    it('confirms TOTP and returns recovery codes', function () {
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey(32);
        $user = User::factory()->create(['totp_secret_encrypted' => $secret]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/totp/confirm', [
                'code' => $google2fa->getCurrentOtp($secret),
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(10, 'recovery_codes');

        $freshUser = $user->fresh();
        expect($freshUser->totp_confirmed_at)->not->toBeNull();
        expect($freshUser->mfa_recovery_codes_hash)->not->toBeNull();
    });

    it('rejects an invalid confirmation code', function () {
        $user = User::factory()->create([
            'totp_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/totp/confirm', [
                'code' => '000000',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    });

    it('disables TOTP when current password is correct', function () {
        $user = User::factory()->create([
            'totp_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/auth/mfa/totp', [
                'current_password' => 'password',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('methods', []);

        expect($user->fresh()->totp_confirmed_at)->toBeNull();
    });

    it('rejects disabling TOTP with wrong password', function () {
        $user = User::factory()->create([
            'totp_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/auth/mfa/totp', [
                'current_password' => 'wrong-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    });
});

describe('MFA methods', function () {
    it('lists enabled methods and passkeys', function () {
        $user = User::factory()->create([
            'totp_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
        ]);
        $passkey = WebAuthnCredential::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/mfa/methods');

        $response->assertStatus(200)
            ->assertJsonPath('methods', ['totp', 'passkey'])
            ->assertJsonPath('passkeys.0.id', $passkey->id);
    });
});

describe('Recovery codes', function () {
    it('regenerates recovery codes with correct password', function () {
        $user = User::factory()->create([
            'totp_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
            'mfa_recovery_codes_hash' => json_encode([Hash::make('oldcode123456789')]),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/recovery-codes', [
                'current_password' => 'password',
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(10, 'recovery_codes');

        $hashes = json_decode($user->fresh()->mfa_recovery_codes_hash, true);
        expect(Hash::check($response->json('recovery_codes')[0], $hashes[0]))->toBeTrue();
    });
});

describe('Passkey setup', function () {
    it('returns registration options', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/passkeys/register/options');

        $response->assertStatus(200)
            ->assertJsonStructure(['options']);
    });

    it('rejects an invalid registration response', function () {
        $user = User::factory()->create();

        // Prime cache with options so validation proceeds to WebAuthn verification.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/passkeys/register/options');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/passkeys/register/verify', [
                'response' => ['invalid' => 'data'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['response']);
    });

    it('removes a passkey with correct password', function () {
        $user = User::factory()->create();
        $passkey = WebAuthnCredential::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/auth/mfa/passkeys/{$passkey->id}", [
                'current_password' => 'password',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('methods', []);

        expect(WebAuthnCredential::find($passkey->id))->toBeNull();
    });

    it('prevents removing another users passkey', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $passkey = WebAuthnCredential::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/auth/mfa/passkeys/{$passkey->id}", [
                'current_password' => 'password',
            ]);

        $response->assertStatus(403);
    });
});

describe('MFA token design', function () {
    it('cannot reuse a consumed mfa_token', function () {
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey(32);
        $user = User::factory()->create([
            'totp_secret_encrypted' => $secret,
            'totp_confirmed_at' => now(),
        ]);

        $mfaToken = app(MfaController::class)->createMfaToken($user);
        $code = $google2fa->getCurrentOtp($secret);

        $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $mfaToken,
            'method' => 'totp',
            'code' => $code,
        ])->assertStatus(200);

        $response = $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $mfaToken,
            'method' => 'totp',
            'code' => $code,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mfa_token']);
    });
});
