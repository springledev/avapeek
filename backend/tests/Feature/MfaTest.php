<?php

use App\Models\User;
use App\Models\WebAuthnCredential;
use App\Services\MfaTokenService;
use App\Services\MfaWebAuthnService;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

describe('MFA login flow', function () {
    it('returns mfa_required when logging in with TOTP enabled', function () {
        $user = User::factory()->create([
            'totp_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'auth_hash' => UserFactory::AUTH_HASH,
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
            'auth_hash' => UserFactory::AUTH_HASH,
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

        $mfaToken = app(MfaTokenService::class)->create($user, true);
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

        $mfaToken = app(MfaTokenService::class)->create($user, true);

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

        $firstToken = app(MfaTokenService::class)->create($user, true);
        $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $firstToken,
            'method' => 'totp',
            'code' => $code,
        ])->assertStatus(200);

        $secondToken = app(MfaTokenService::class)->create($user, true);
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

        $mfaToken = app(MfaTokenService::class)->create($user, true);

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

        $mfaToken = app(MfaTokenService::class)->create($user, true);

        $response = $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $mfaToken,
            'method' => 'recovery',
            'code' => '0000000000000000',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    });

    it('locks code attempts for the account after 10 failures, across fresh tokens', function () {
        $user = User::factory()->create([
            'totp_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
            'mfa_recovery_codes_hash' => json_encode([Hash::make('abcdef1234567890')]),
        ]);

        foreach (range(1, 10) as $attempt) {
            RateLimiter::hit('mfa_code_failures:'.$user->id, 900);
        }

        $response = $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => app(MfaTokenService::class)->create($user, true),
            'method' => 'recovery',
            'code' => 'abcdef1234567890',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
        expect($response->json('errors.code.0'))->toContain('Too many incorrect codes');
        expect(json_decode($user->fresh()->mfa_recovery_codes_hash, true)[0])->not->toBeNull();
    });

    it('counts wrong codes against the account and clears the count on success', function () {
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey(32);
        $user = User::factory()->create([
            'totp_secret_encrypted' => $secret,
            'totp_confirmed_at' => now(),
        ]);
        $key = 'mfa_code_failures:'.$user->id;

        $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => app(MfaTokenService::class)->create($user, true),
            'method' => 'totp',
            'code' => '000000',
        ])->assertStatus(422);

        expect(RateLimiter::attempts($key))->toBe(1);

        $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => app(MfaTokenService::class)->create($user, true),
            'method' => 'totp',
            'code' => $google2fa->getCurrentOtp($secret),
        ])->assertStatus(200);

        expect(RateLimiter::attempts($key))->toBe(0);
    });

    it('accepts recovery codes typed with dashes, spaces and capitals', function () {
        $user = User::factory()->create([
            'totp_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
            'mfa_recovery_codes_hash' => json_encode([Hash::make('abcdef1234567890')]),
        ]);

        $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => app(MfaTokenService::class)->create($user, true),
            'method' => 'recovery',
            'code' => ' ABCD-EF12 3456-7890 ',
        ])->assertStatus(200);
    });

    it('accepts an authenticator code with a space in the middle', function () {
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey(32);
        $user = User::factory()->create([
            'totp_secret_encrypted' => $secret,
            'totp_confirmed_at' => now(),
        ]);
        $code = $google2fa->getCurrentOtp($secret);

        $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => app(MfaTokenService::class)->create($user, true),
            'method' => 'totp',
            'code' => substr($code, 0, 3).' '.substr($code, 3),
        ])->assertStatus(200);
    });

    it('refuses a challenge while another request is redeeming the same token', function () {
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey(32);
        $user = User::factory()->create([
            'totp_secret_encrypted' => $secret,
            'totp_confirmed_at' => now(),
        ]);
        $mfaTokens = app(MfaTokenService::class);
        $mfaToken = $mfaTokens->create($user, true);
        $nonce = $mfaTokens->resolve($mfaToken)['nonce'];

        $lock = $mfaTokens->lock($nonce);
        expect($lock->get())->toBeTrue();

        $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $mfaToken,
            'method' => 'totp',
            'code' => $google2fa->getCurrentOtp($secret),
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);

        $lock->release();
    });

    it('issues an expiring token when the user did not ask to stay signed in', function () {
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey(32);
        $user = User::factory()->create([
            'totp_secret_encrypted' => $secret,
            'totp_confirmed_at' => now(),
        ]);

        $mfaToken = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'auth_hash' => UserFactory::AUTH_HASH,
            'remember' => false,
        ])->json('mfa_token');

        $token = $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $mfaToken,
            'method' => 'totp',
            'code' => $google2fa->getCurrentOtp($secret),
        ])->assertStatus(200)->json('token');

        expect(PersonalAccessToken::findToken($token)->expires_at)->not->toBeNull();
    });

    it('matches a passkey sent with a base64url credential id', function () {
        $rawCredentialId = str_repeat("\xfb\xff", 16);
        $user = User::factory()->create();
        WebAuthnCredential::factory()->create([
            'user_id' => $user->id,
            'credential_id' => base64_encode($rawCredentialId),
        ]);

        $mfaToken = app(MfaTokenService::class)->create($user, true);
        $this->postJson('/api/auth/mfa/passkey/options', ['mfa_token' => $mfaToken])
            ->assertStatus(200);

        $base64UrlId = rtrim(strtr(base64_encode($rawCredentialId), '+/', '-_'), '=');

        $response = $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $mfaToken,
            'method' => 'passkey',
            'passkey_response' => ['id' => $base64UrlId, 'rawId' => $base64UrlId, 'type' => 'public-key'],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.passkey_response.0', 'The passkey verification failed.');
    });

    it('does not throttle MFA attempts with the registration limit', function () {
        $google2fa = app(Google2FA::class);
        $user = User::factory()->create([
            'totp_secret_encrypted' => $google2fa->generateSecretKey(32),
            'totp_confirmed_at' => now(),
        ]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'auth_hash' => UserFactory::AUTH_HASH])
            ->assertStatus(200);

        $mfaToken = app(MfaTokenService::class)->create($user, true);

        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/auth/mfa/challenge', [
                'mfa_token' => $mfaToken,
                'method' => 'totp',
                'code' => '000000',
            ])->assertStatus(422);
        }
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
            ->postJson('/api/auth/mfa/totp/setup', ['current_auth_hash' => UserFactory::AUTH_HASH]);

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

    it('refuses to restart setup while an authenticator is enabled', function () {
        $secret = app(Google2FA::class)->generateSecretKey(32);
        $user = User::factory()->create([
            'totp_secret_encrypted' => $secret,
            'totp_confirmed_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/totp/setup', ['current_auth_hash' => UserFactory::AUTH_HASH]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['totp']);

        $freshUser = $user->fresh();
        expect($freshUser->totp_confirmed_at)->not->toBeNull();
        expect($freshUser->totp_secret_encrypted)->toBe($secret);
    });

    it('requires the current password to start TOTP setup', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/totp/setup', ['current_auth_hash' => str_repeat('f', 64)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_auth_hash']);

        expect($user->fresh()->totp_secret_encrypted)->toBeNull();
    });

    it('keeps existing recovery codes when TOTP is added after a passkey', function () {
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey(32);
        $existingHashes = json_encode([Hash::make('abcdef1234567890')]);
        $user = User::factory()->create([
            'totp_secret_encrypted' => $secret,
            'mfa_recovery_codes_hash' => $existingHashes,
        ]);
        WebAuthnCredential::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/totp/confirm', ['code' => $google2fa->getCurrentOtp($secret)])
            ->assertStatus(200)
            ->assertJsonPath('recovery_codes', null);

        expect($user->fresh()->mfa_recovery_codes_hash)->toBe($existingHashes);
    });

    it('does not let the setup code be replayed to sign in', function () {
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey(32);
        $user = User::factory()->create(['totp_secret_encrypted' => $secret]);
        $code = $google2fa->getCurrentOtp($secret);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/totp/confirm', ['code' => $code])
            ->assertStatus(200);

        $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => app(MfaTokenService::class)->create($user->fresh(), true),
            'method' => 'totp',
            'code' => $code,
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);
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
                'current_auth_hash' => UserFactory::AUTH_HASH,
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
                'current_auth_hash' => str_repeat('f', 64),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_auth_hash']);
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

    it('reports how many recovery codes are left', function () {
        $user = User::factory()->create([
            'totp_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
            'mfa_recovery_codes_hash' => json_encode([Hash::make('a'), null, Hash::make('b')]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/mfa/methods')
            ->assertStatus(200)
            ->assertJsonPath('recovery_codes_remaining', 2);
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
                'current_auth_hash' => UserFactory::AUTH_HASH,
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(10, 'recovery_codes');

        $hashes = json_decode($user->fresh()->mfa_recovery_codes_hash, true);
        expect(Hash::check($response->json('recovery_codes')[0], $hashes[0]))->toBeTrue();
    });
});

/**
 * Build a "none" attestation the way a software authenticator such as Bitwarden does.
 *
 * @return array<string, mixed>
 */
function fakeNoneAttestation(string $challengeB64Url, string $origin = 'http://localhost:5173', int $flags = 0x45, bool $emptyExtensions = true): array
{
    $b64 = fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $cborBytes = fn (string $raw): string => (strlen($raw) < 24 ? chr(0x40 + strlen($raw)) : chr(0x58).chr(strlen($raw))).$raw;
    $cborInt = fn (int $n): string => $n >= 0 ? chr($n) : chr(0x20 + (-1 - $n));
    $cborText = fn (string $t): string => chr(0x60 + strlen($t)).$t;

    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $details = openssl_pkey_get_details($key)['ec'];
    $cose = chr(0xA5).$cborInt(1).$cborInt(2).$cborInt(3).$cborInt(-7).$cborInt(-1).$cborInt(1)
        .$cborInt(-2).$cborBytes(str_pad($details['x'], 32, "\0", STR_PAD_LEFT))
        .$cborInt(-3).$cborBytes(str_pad($details['y'], 32, "\0", STR_PAD_LEFT));

    $credentialId = random_bytes(32);
    $authData = hash('sha256', parse_url($origin, PHP_URL_HOST), true)
        .chr($flags).pack('N', 0).str_repeat("\0", 16).pack('n', strlen($credentialId)).$credentialId.$cose;
    $attestationObject = chr(0xA3).$cborText('fmt').$cborText('none').$cborText('attStmt').chr(0xA0)
        .$cborText('authData').chr(0x58).chr(strlen($authData)).$authData;
    $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => $challengeB64Url, 'origin' => $origin, 'crossOrigin' => false]);

    return [
        'id' => $b64($credentialId),
        'rawId' => $b64($credentialId),
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => $b64($clientData),
            'attestationObject' => $b64($attestationObject),
            'transports' => ['internal'],
        ],
        'clientExtensionResults' => $emptyExtensions ? (object) [] : [],
        'authenticatorAttachment' => 'platform',
    ];
}

describe('Passkey setup', function () {
    it('returns registration options', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/passkeys/register/options', [
                'current_auth_hash' => UserFactory::AUTH_HASH,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['options'])
            ->assertJsonPath('options.rp.id', 'localhost');
    });

    it('requires the current password to start passkey registration', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/passkeys/register/options', [
                'current_auth_hash' => str_repeat('f', 64),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_auth_hash']);
    });

    it('binds passkeys to the frontend origin rather than the API url', function () {
        config([
            'app.url' => 'https://api.avapeek.test',
            'app.frontend_url' => 'https://photos.avapeek.test/',
        ]);

        $service = app(MfaWebAuthnService::class);

        expect($service->getOrigin())->toBe('https://photos.avapeek.test');
        expect($service->getRpId())->toBe('photos.avapeek.test');
    });

    it('refuses to guess the passkey origin in production', function () {
        config(['app.frontend_url' => null]);
        app()->detectEnvironment(fn (): string => 'production');

        expect(fn () => app(MfaWebAuthnService::class)->getOrigin())
            ->toThrow(RuntimeException::class, 'FRONTEND_URL must be set');
    });

    it('stores a passkey from a software authenticator and returns its id and recovery codes', function () {
        $user = User::factory()->create();

        $options = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/passkeys/register/options', ['current_auth_hash' => UserFactory::AUTH_HASH])
            ->json('options');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/passkeys/register/verify', [
                'response' => fakeNoneAttestation($options['challenge']),
                'user_chosen_name' => 'Bitwarden',
            ])
            ->assertOk()
            ->assertJsonStructure(['passkey_id', 'recovery_codes']);

        expect($user->webauthnCredentials()->where('user_chosen_name', 'Bitwarden')->exists())->toBeTrue();
    });

    it('keeps registration options across requests on a cache store that serializes values', function () {
        config(['cache.stores.array.serialize' => true]);
        app('cache')->forgetDriver('array');
        $user = User::factory()->create();

        $options = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/passkeys/register/options', ['current_auth_hash' => UserFactory::AUTH_HASH])
            ->json('options');

        $this->app['auth']->forgetGuards();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/passkeys/register/verify', [
                'response' => fakeNoneAttestation($options['challenge']),
            ])
            ->assertOk()
            ->assertJsonStructure(['passkey_id']);
    });

    it('keeps passkey login options across requests on a cache store that serializes values', function () {
        config(['cache.stores.array.serialize' => true]);
        app('cache')->forgetDriver('array');
        $user = User::factory()->create();
        $credential = WebAuthnCredential::factory()->create(['user_id' => $user->id]);
        $mfaToken = app(MfaTokenService::class)->create($user, true);

        $this->postJson('/api/auth/mfa/passkey/options', ['mfa_token' => $mfaToken])->assertOk();

        $this->postJson('/api/auth/mfa/challenge', [
            'mfa_token' => $mfaToken,
            'method' => 'passkey',
            'passkey_response' => ['id' => $credential->credential_id, 'rawId' => $credential->credential_id, 'type' => 'public-key'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.passkey_response.0', 'The passkey verification failed.');
    });

    it('accepts a passkey from an authenticator that did not report user verification', function () {
        $user = User::factory()->create();

        $options = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/passkeys/register/options', ['current_auth_hash' => UserFactory::AUTH_HASH])
            ->json('options');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/passkeys/register/verify', [
                'response' => fakeNoneAttestation($options['challenge'], flags: 0x41),
            ])
            ->assertOk();
    });

    it('rejects a passkey created on a different origin with a readable message', function () {
        $user = User::factory()->create();

        $options = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/passkeys/register/options', ['current_auth_hash' => UserFactory::AUTH_HASH])
            ->json('options');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/passkeys/register/verify', [
                'response' => fakeNoneAttestation($options['challenge'], 'https://evil.example'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['response']);
    });

    it('rejects an invalid registration response', function () {
        $user = User::factory()->create();

        // Prime cache with options so validation proceeds to WebAuthn verification.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/mfa/passkeys/register/options', [
                'current_auth_hash' => UserFactory::AUTH_HASH,
            ]);

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
                'current_auth_hash' => UserFactory::AUTH_HASH,
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
                'current_auth_hash' => UserFactory::AUTH_HASH,
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

        $mfaToken = app(MfaTokenService::class)->create($user, true);
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
