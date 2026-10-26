<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\EncryptionKey;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @return array{user: User, child: Child, key: EncryptionKey}
 */
function userWithOneAlbumKey(): array
{
    $user = User::factory()->create(['encrypted_private_key' => 'old-private']);
    $child = Child::factory()->create(['created_by' => $user->id]);
    ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);
    $key = EncryptionKey::create([
        'child_id' => $child->id,
        'user_id' => $user->id,
        'encrypted_dek' => 'old-dek',
        'key_version' => 1,
    ]);

    return ['user' => $user, 'child' => $child, 'key' => $key];
}

describe('PUT /api/user/password', function () {
    it('saves the new auth hash together with the re-wrapped keys', function () {
        ['user' => $user, 'child' => $child] = userWithOneAlbumKey();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/password', [
                'current_auth_hash' => UserFactory::AUTH_HASH,
                'new_auth_hash' => str_repeat('b', 64),
                'encryption_keys' => [
                    ['child_id' => $child->id, 'key_version' => 1, 'encrypted_dek' => 'new-dek'],
                ],
                'encrypted_private_key' => 'new-private',
            ])
            ->assertStatus(200)
            ->assertJsonPath('user.encrypted_private_key', 'new-private')
            ->assertJsonPath('encryption_keys.0.encrypted_dek', 'new-dek');

        $fresh = $user->fresh();
        expect(Hash::check(str_repeat('b', 64), $fresh->password_hash))->toBeTrue();
        expect($fresh->encrypted_private_key)->toBe('new-private');
        $this->assertDatabaseHas('encryption_keys', ['user_id' => $user->id, 'encrypted_dek' => 'new-dek']);
    });

    it('changes nothing when a key is missing from the request', function () {
        ['user' => $user] = userWithOneAlbumKey();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/password', [
                'current_auth_hash' => UserFactory::AUTH_HASH,
                'new_auth_hash' => str_repeat('b', 64),
                'encryption_keys' => [],
                'encrypted_private_key' => 'new-private',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['encryption_keys']);

        $fresh = $user->fresh();
        expect(Hash::check(UserFactory::AUTH_HASH, $fresh->password_hash))->toBeTrue();
        expect($fresh->encrypted_private_key)->toBe('old-private');
    });

    it('rejects keys for albums the user has no key for', function () {
        ['user' => $user, 'child' => $child] = userWithOneAlbumKey();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/password', [
                'current_auth_hash' => UserFactory::AUTH_HASH,
                'new_auth_hash' => str_repeat('b', 64),
                'encryption_keys' => [
                    ['child_id' => $child->id, 'key_version' => 1, 'encrypted_dek' => 'new-dek'],
                    ['child_id' => 9999, 'key_version' => 1, 'encrypted_dek' => 'other'],
                ],
                'encrypted_private_key' => 'new-private',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['encryption_keys']);
    });

    it('requires the re-wrapped private key when the account has one', function () {
        ['user' => $user, 'child' => $child] = userWithOneAlbumKey();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/password', [
                'current_auth_hash' => UserFactory::AUTH_HASH,
                'new_auth_hash' => str_repeat('b', 64),
                'encryption_keys' => [
                    ['child_id' => $child->id, 'key_version' => 1, 'encrypted_dek' => 'new-dek'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['encrypted_private_key']);
    });

    it('rejects a wrong current password', function () {
        ['user' => $user, 'child' => $child] = userWithOneAlbumKey();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/password', [
                'current_auth_hash' => str_repeat('f', 64),
                'new_auth_hash' => str_repeat('b', 64),
                'encryption_keys' => [
                    ['child_id' => $child->id, 'key_version' => 1, 'encrypted_dek' => 'new-dek'],
                ],
                'encrypted_private_key' => 'new-private',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertDatabaseHas('encryption_keys', ['user_id' => $user->id, 'encrypted_dek' => 'old-dek']);
    });

    it('marks the account as v2 and stops accepting the old auth hash', function () {
        ['user' => $user, 'child' => $child] = userWithOneAlbumKey();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/password', [
                'current_auth_hash' => UserFactory::AUTH_HASH,
                'new_auth_hash' => str_repeat('b', 64),
                'encryption_keys' => [
                    ['child_id' => $child->id, 'key_version' => 1, 'encrypted_dek' => 'new-dek'],
                ],
                'encrypted_private_key' => 'new-private',
            ])
            ->assertOk()
            ->assertJsonPath('user.auth_scheme', 'v2');

        $this->postJson('/api/auth/login', ['email' => $user->email, 'auth_hash' => UserFactory::AUTH_HASH])
            ->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'auth_hash' => str_repeat('b', 64)])
            ->assertOk();
    });

    it('rejects a new auth hash equal to the current one', function () {
        ['user' => $user, 'child' => $child] = userWithOneAlbumKey();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/password', [
                'current_auth_hash' => UserFactory::AUTH_HASH,
                'new_auth_hash' => UserFactory::AUTH_HASH,
                'encryption_keys' => [
                    ['child_id' => $child->id, 'key_version' => 1, 'encrypted_dek' => 'new-dek'],
                ],
                'encrypted_private_key' => 'new-private',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['new_auth_hash']);
    });

    it('requires a well-formed new auth hash and never accepts a plaintext new password', function () {
        ['user' => $user, 'child' => $child] = userWithOneAlbumKey();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/password', [
                'current_auth_hash' => UserFactory::AUTH_HASH,
                'new_password' => 'New-password-1',
                'encryption_keys' => [
                    ['child_id' => $child->id, 'key_version' => 1, 'encrypted_dek' => 'new-dek'],
                ],
                'encrypted_private_key' => 'new-private',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['new_auth_hash']);

        expect(Hash::check(UserFactory::AUTH_HASH, $user->fresh()->password_hash))->toBeTrue();
    });

    it('lets a legacy account change its password with the plaintext current password', function () {
        $user = User::factory()->legacy()->create(['encrypted_private_key' => 'old-private']);
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);
        EncryptionKey::create(['child_id' => $child->id, 'user_id' => $user->id, 'encrypted_dek' => 'old-dek', 'key_version' => 1]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/password', [
                'current_password' => UserFactory::LEGACY_PASSWORD,
                'new_auth_hash' => str_repeat('b', 64),
                'encryption_keys' => [
                    ['child_id' => $child->id, 'key_version' => 1, 'encrypted_dek' => 'new-dek'],
                ],
                'encrypted_private_key' => 'new-private',
            ])
            ->assertOk()
            ->assertJsonPath('user.auth_scheme', 'v2');

        $fresh = $user->fresh();
        expect($fresh->auth_scheme)->toBe('v2');
        expect(Hash::check(str_repeat('b', 64), $fresh->password_hash))->toBeTrue();
    });

    it('rejects a wrong plaintext current password for a legacy account', function () {
        $user = User::factory()->legacy()->create(['encrypted_private_key' => 'old-private']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/password', [
                'current_password' => 'wrong',
                'new_auth_hash' => str_repeat('b', 64),
                'encryption_keys' => [],
                'encrypted_private_key' => 'new-private',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        expect($user->fresh()->auth_scheme)->toBe('legacy');
    });

    it('signs out other sessions but keeps the current one', function () {
        ['user' => $user, 'child' => $child] = userWithOneAlbumKey();
        $current = $user->createToken('auth-token')->plainTextToken;
        $user->createToken('auth-token');

        $this->withToken($current)
            ->putJson('/api/user/password', [
                'current_auth_hash' => UserFactory::AUTH_HASH,
                'new_auth_hash' => str_repeat('b', 64),
                'encryption_keys' => [
                    ['child_id' => $child->id, 'key_version' => 1, 'encrypted_dek' => 'new-dek'],
                ],
                'encrypted_private_key' => 'new-private',
            ])
            ->assertStatus(200);

        expect($user->tokens()->count())->toBe(1);
        expect(PersonalAccessToken::findToken($current))->not->toBeNull();
    });
});

describe('PUT /api/user/profile', function () {
    it('no longer changes the password', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/profile', [
                'display_name' => 'New Name',
                'current_auth_hash' => UserFactory::AUTH_HASH,
                'new_auth_hash' => str_repeat('b', 64),
            ])
            ->assertStatus(200)
            ->assertJsonPath('user.display_name', 'New Name');

        expect(Hash::check(UserFactory::AUTH_HASH, $user->fresh()->password_hash))->toBeTrue();
    });
});

describe('PUT /api/user/keypair', function () {
    it('sets a key pair for an account that never stored its private key', function () {
        $user = User::factory()->create(['encrypted_private_key' => null]);
        $child = Child::factory()->create(['created_by' => $user->id]);
        EncryptionKey::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'encrypted_dek' => 'unopenable',
            'wrap_type' => EncryptionKey::WRAP_PUBLIC_KEY,
            'key_version' => 1,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/keypair', [
                'current_auth_hash' => UserFactory::AUTH_HASH,
                'public_key' => 'new-public',
                'encrypted_private_key' => 'new-private',
            ])
            ->assertStatus(200)
            ->assertJsonPath('user.public_key', 'new-public');

        expect($user->fresh()->encrypted_private_key)->toBe('new-private');
        expect(EncryptionKey::where('user_id', $user->id)->count())->toBe(0);
    });

    it('refuses to replace an existing key pair', function () {
        $user = User::factory()->create(['encrypted_private_key' => 'existing']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/keypair', [
                'current_auth_hash' => UserFactory::AUTH_HASH,
                'public_key' => 'attacker-public',
                'encrypted_private_key' => 'attacker-private',
            ])
            ->assertStatus(409);

        expect($user->fresh()->encrypted_private_key)->toBe('existing');
    });

    it('migrates a legacy account when it proves the plaintext password', function () {
        $user = User::factory()->legacy()->create(['encrypted_private_key' => null]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/keypair', [
                'current_password' => UserFactory::LEGACY_PASSWORD,
                'new_auth_hash' => str_repeat('c', 64),
                'public_key' => 'new-public',
                'encrypted_private_key' => 'new-private',
            ])
            ->assertOk()
            ->assertJsonPath('user.auth_scheme', 'v2');

        expect(Hash::check(str_repeat('c', 64), $user->fresh()->password_hash))->toBeTrue();
    });

    it('requires the current password', function () {
        $user = User::factory()->create(['encrypted_private_key' => null]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/keypair', [
                'current_auth_hash' => str_repeat('f', 64),
                'public_key' => 'new-public',
                'encrypted_private_key' => 'new-private',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    });
});

describe('session length', function () {
    it('issues a non-expiring token when the user stays signed in', function () {
        $user = User::factory()->create();

        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'auth_hash' => UserFactory::AUTH_HASH,
            'remember' => true,
        ])->assertStatus(200)->json('token');

        expect(PersonalAccessToken::findToken($token)->expires_at)->toBeNull();
    });

    it('issues an expiring token when the user does not stay signed in', function () {
        $user = User::factory()->create();

        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'auth_hash' => UserFactory::AUTH_HASH,
            'remember' => false,
        ])->assertStatus(200)->json('token');

        expect(PersonalAccessToken::findToken($token)->expires_at)->not->toBeNull();
    });

    it('keeps a short session short when the token is refreshed', function () {
        $user = User::factory()->create();
        $token = $user->createToken('auth-token', ['*'], now()->addHour())->plainTextToken;

        $newToken = $this->withToken($token)
            ->postJson('/api/auth/refresh')
            ->assertStatus(200)
            ->json('token');

        expect(PersonalAccessToken::findToken($newToken)->expires_at)->not->toBeNull();
        expect(PersonalAccessToken::findToken($token))->toBeNull();
    });

    it('returns the wrapped private key and key wrap types on login', function () {
        ['user' => $user] = userWithOneAlbumKey();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'auth_hash' => UserFactory::AUTH_HASH,
        ])
            ->assertStatus(200)
            ->assertJsonPath('user.encrypted_private_key', 'old-private')
            ->assertJsonPath('encryption_keys.0.wrap_type', EncryptionKey::WRAP_KEK);
    });
});
