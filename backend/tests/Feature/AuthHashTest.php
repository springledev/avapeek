<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\User;
use App\Models\WebAuthnCredential;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * The server must never see a password: it only gets an auth hash derived from the key.
 */
function newAuthHash(): string
{
    return str_repeat('d', 64);
}

/**
 * @return array{0: string, 1: string, 2: array<string, mixed>}
 */
function proofEndpoint(string $name, User $user): array
{
    return match ($name) {
        'totp setup' => ['POST', '/api/auth/mfa/totp/setup', []],
        'totp disable' => ['DELETE', '/api/auth/mfa/totp', []],
        'passkey options' => ['POST', '/api/auth/mfa/passkeys/register/options', []],
        'passkey remove' => [
            'DELETE',
            '/api/auth/mfa/passkeys/'.WebAuthnCredential::factory()->create(['user_id' => $user->id])->id,
            [],
        ],
        'recovery codes' => ['POST', '/api/auth/mfa/recovery-codes', []],
        'key pair' => ['PUT', '/api/user/keypair', ['public_key' => 'pub', 'encrypted_private_key' => 'priv']],
        'transfer ownership' => (function () use ($user): array {
            $child = Child::factory()->create(['created_by' => $user->id]);
            ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);
            $member = User::factory()->create();
            ChildUser::create(['child_id' => $child->id, 'user_id' => $member->id, 'role' => 'member']);

            return ['POST', "/api/children/{$child->id}/transfer-ownership", ['user_id' => $member->id]];
        })(),
    };
}

const PROOF_ENDPOINTS = ['totp setup', 'totp disable', 'passkey options', 'passkey remove', 'recovery codes', 'key pair', 'transfer ownership'];

describe('POST /api/auth/prelogin', function () {
    it('returns the salt and scheme of a known v2 account', function () {
        $user = User::factory()->create();

        $this->postJson('/api/auth/prelogin', ['email' => $user->email])
            ->assertOk()
            ->assertExactJson(['data' => ['master_key_salt' => $user->master_key_salt, 'auth_scheme' => 'v2']]);
    });

    it('reports legacy accounts', function () {
        $user = User::factory()->legacy()->create();

        $this->postJson('/api/auth/prelogin', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('data.auth_scheme', 'legacy')
            ->assertJsonPath('data.master_key_salt', $user->master_key_salt);
    });

    it('returns a stable fake salt in the same shape for unknown emails', function () {
        $first = $this->postJson('/api/auth/prelogin', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('data.auth_scheme', 'v2')
            ->json('data');
        $again = $this->postJson('/api/auth/prelogin', ['email' => ' NoBody@example.com'])->json('data');
        $other = $this->postJson('/api/auth/prelogin', ['email' => 'someone@example.com'])->json('data');

        expect($first['master_key_salt'])->toMatch('/^[0-9a-f]{64}$/');
        expect($again)->toBe($first);
        expect($other['master_key_salt'])->not->toBe($first['master_key_salt']);

        $known = User::factory()->create();
        $knownResponse = $this->postJson('/api/auth/prelogin', ['email' => $known->email])->json('data');
        expect(array_keys($knownResponse))->toBe(array_keys($first));
        expect($knownResponse['master_key_salt'])->toMatch('/^[0-9a-f]{64}$/');
    });

    it('requires an email', function () {
        $this->postJson('/api/auth/prelogin', [])->assertStatus(422)->assertJsonValidationErrors(['email']);
    });

    it('is throttled', function () {
        foreach (range(1, 10) as $ignored) {
            $this->postJson('/api/auth/prelogin', ['email' => 'nobody@example.com'])->assertOk();
        }

        $this->postJson('/api/auth/prelogin', ['email' => 'nobody@example.com'])->assertStatus(429);
    });
});

describe('POST /api/auth/register with an auth hash', function () {
    function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'first@example.com',
            'auth_hash' => str_repeat('a', 64),
            'display_name' => 'First User',
            'master_key_salt' => str_repeat('a', 64),
            'public_key' => 'test-public-key',
            'encrypted_private_key' => 'wrapped-private-key',
        ], $overrides);
    }

    it('stores a bcrypt hash of the auth hash and marks the account v2', function () {
        $this->postJson('/api/auth/register', registrationPayload())
            ->assertCreated()
            ->assertJsonPath('user.auth_scheme', 'v2')
            ->assertJsonMissingPath('user.password_hash');

        $user = User::where('email', 'first@example.com')->firstOrFail();
        expect($user->auth_scheme)->toBe('v2');
        expect($user->password_hash)->not->toBe(str_repeat('a', 64));
        expect(Hash::check(str_repeat('a', 64), $user->password_hash))->toBeTrue();
    });

    it('rejects a missing or malformed auth hash', function (mixed $value) {
        $payload = registrationPayload();
        unset($payload['auth_hash']);

        if ($value !== null) {
            $payload['auth_hash'] = $value;
        }

        $this->postJson('/api/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['auth_hash']);
    })->with([
        'missing' => [null],
        'too short' => ['abc123'],
        'uppercase' => [str_repeat('A', 64)],
        'not hex' => [str_repeat('z', 64)],
        'too long' => [str_repeat('a', 65)],
        'plaintext password' => ['Password1!'],
    ]);

    it('does not accept a plaintext password in place of the auth hash', function () {
        $payload = registrationPayload();
        unset($payload['auth_hash']);
        $payload['password'] = 'Password1!';

        $this->postJson('/api/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['auth_hash']);

        $this->assertDatabaseMissing('users', ['email' => 'first@example.com']);
    });

    it('lets the new account log in with the same auth hash', function () {
        $this->postJson('/api/auth/register', registrationPayload())->assertCreated();

        $this->postJson('/api/auth/login', ['email' => 'first@example.com', 'auth_hash' => str_repeat('a', 64)])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    });
});

describe('POST /api/auth/login', function () {
    it('logs a v2 account in with its auth hash', function () {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'auth_hash' => UserFactory::AUTH_HASH])
            ->assertOk()
            ->assertJsonPath('user.auth_scheme', 'v2')
            ->assertJsonStructure(['token', 'encryption_keys']);
    });

    it('rejects a wrong auth hash with the generic error', function () {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'auth_hash' => str_repeat('f', 64)])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');
    });

    it('rejects an unknown email with the same error as a wrong hash', function () {
        $unknown = $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'auth_hash' => UserFactory::AUTH_HASH]);
        $user = User::factory()->create();
        $wrong = $this->postJson('/api/auth/login', ['email' => $user->email, 'auth_hash' => str_repeat('f', 64)]);

        $unknown->assertStatus(422);
        expect($unknown->json())->toBe($wrong->json());
    });

    it('rejects a plaintext password from a v2 account without downgrading it', function () {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => UserFactory::AUTH_HASH,
            'new_auth_hash' => newAuthHash(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'new_auth_hash' => newAuthHash(),
        ])->assertStatus(422);

        $fresh = $user->fresh();
        expect($fresh->auth_scheme)->toBe('v2');
        expect(Hash::check(UserFactory::AUTH_HASH, $fresh->password_hash))->toBeTrue();
    });

    it('rejects an auth hash from a legacy account', function () {
        $user = User::factory()->legacy()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'auth_hash' => UserFactory::AUTH_HASH])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');
    });

    it('requires new_auth_hash alongside a plaintext password', function () {
        $user = User::factory()->legacy()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => UserFactory::LEGACY_PASSWORD])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['new_auth_hash']);

        expect($user->fresh()->auth_scheme)->toBe('legacy');
    });

    it('requires a credential', function () {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['auth_hash', 'password']);
    });

    it('migrates a legacy account on the first password login and then refuses the plaintext', function () {
        $user = User::factory()->legacy()->create();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => UserFactory::LEGACY_PASSWORD,
            'new_auth_hash' => newAuthHash(),
        ])
            ->assertOk()
            ->assertJsonPath('user.auth_scheme', 'v2')
            ->assertJsonStructure(['token']);

        $fresh = $user->fresh();
        expect($fresh->auth_scheme)->toBe('v2');
        expect(Hash::check(newAuthHash(), $fresh->password_hash))->toBeTrue();
        expect(Hash::check(UserFactory::LEGACY_PASSWORD, $fresh->password_hash))->toBeFalse();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => UserFactory::LEGACY_PASSWORD,
            'new_auth_hash' => newAuthHash(),
        ])->assertStatus(422);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'auth_hash' => newAuthHash()])->assertOk();
    });

    it('does not migrate a legacy account when the password is wrong', function () {
        $user = User::factory()->legacy()->create();
        $originalHash = $user->password_hash;

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
            'new_auth_hash' => newAuthHash(),
        ])->assertStatus(422);

        $fresh = $user->fresh();
        expect($fresh->auth_scheme)->toBe('legacy');
        expect($fresh->password_hash)->toBe($originalHash);
    });

    it('migrates a legacy account with MFA as soon as the password is proven', function () {
        $user = User::factory()->legacy()->create();
        WebAuthnCredential::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => UserFactory::LEGACY_PASSWORD,
            'new_auth_hash' => newAuthHash(),
        ])
            ->assertOk()
            ->assertJsonPath('mfa_required', true)
            ->assertJsonMissingPath('token');

        expect($user->fresh()->auth_scheme)->toBe('v2');
    });

    it('never returns or logs the auth hash or password', function () {
        Log::spy();
        $user = User::factory()->legacy()->create();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => UserFactory::LEGACY_PASSWORD,
            'new_auth_hash' => newAuthHash(),
        ])->assertOk();

        expect($response->getContent())->not->toContain(newAuthHash())
            ->not->toContain($user->fresh()->password_hash);
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    });
});

describe('password-confirmed endpoints', function () {
    it('accept the auth hash of a v2 account', function (string $endpoint) {
        $user = User::factory()->create(['encrypted_private_key' => null]);
        [$method, $url, $body] = proofEndpoint($endpoint, $user);

        $response = $this->actingAs($user, 'sanctum')
            ->json($method, $url, $body + ['current_auth_hash' => UserFactory::AUTH_HASH]);

        expect($response->status())->toBeIn([200, 201]);
        expect($user->fresh()->auth_scheme)->toBe('v2');
    })->with(PROOF_ENDPOINTS);

    it('reject a wrong auth hash with a current_password error', function (string $endpoint) {
        $user = User::factory()->create(['encrypted_private_key' => null]);
        [$method, $url, $body] = proofEndpoint($endpoint, $user);

        $this->actingAs($user, 'sanctum')
            ->json($method, $url, $body + ['current_auth_hash' => str_repeat('f', 64)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    })->with(PROOF_ENDPOINTS);

    it('reject a v2 account that sends a plaintext password', function (string $endpoint) {
        $user = User::factory()->create(['encrypted_private_key' => null]);
        [$method, $url, $body] = proofEndpoint($endpoint, $user);

        $this->actingAs($user, 'sanctum')
            ->json($method, $url, $body + ['current_password' => UserFactory::AUTH_HASH, 'new_auth_hash' => newAuthHash()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $fresh = $user->fresh();
        expect($fresh->auth_scheme)->toBe('v2');
        expect(Hash::check(UserFactory::AUTH_HASH, $fresh->password_hash))->toBeTrue();
    })->with(PROOF_ENDPOINTS);

    it('require a proof', function (string $endpoint) {
        $user = User::factory()->create(['encrypted_private_key' => null]);
        [$method, $url, $body] = proofEndpoint($endpoint, $user);

        $this->actingAs($user, 'sanctum')
            ->json($method, $url, $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_auth_hash', 'current_password']);
    })->with(PROOF_ENDPOINTS);

    it('migrate a legacy account that proves its plaintext password', function (string $endpoint) {
        $user = User::factory()->legacy()->create(['encrypted_private_key' => null]);
        [$method, $url, $body] = proofEndpoint($endpoint, $user);

        $response = $this->actingAs($user, 'sanctum')
            ->json($method, $url, $body + ['current_password' => UserFactory::LEGACY_PASSWORD, 'new_auth_hash' => newAuthHash()]);

        expect($response->status())->toBeIn([200, 201]);

        $fresh = $user->fresh();
        expect($fresh->auth_scheme)->toBe('v2');
        expect(Hash::check(newAuthHash(), $fresh->password_hash))->toBeTrue();
    })->with(PROOF_ENDPOINTS);

    it('leave a legacy account untouched when its plaintext password is wrong', function (string $endpoint) {
        $user = User::factory()->legacy()->create(['encrypted_private_key' => null]);
        [$method, $url, $body] = proofEndpoint($endpoint, $user);

        $this->actingAs($user, 'sanctum')
            ->json($method, $url, $body + ['current_password' => 'wrong', 'new_auth_hash' => newAuthHash()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        expect($user->fresh()->auth_scheme)->toBe('legacy');
    })->with(PROOF_ENDPOINTS);

    it('reject an auth hash from a legacy account', function (string $endpoint) {
        $user = User::factory()->legacy()->create(['encrypted_private_key' => null]);
        [$method, $url, $body] = proofEndpoint($endpoint, $user);

        $this->actingAs($user, 'sanctum')
            ->json($method, $url, $body + ['current_auth_hash' => UserFactory::AUTH_HASH])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        expect($user->fresh()->auth_scheme)->toBe('legacy');
    })->with(PROOF_ENDPOINTS);
});
