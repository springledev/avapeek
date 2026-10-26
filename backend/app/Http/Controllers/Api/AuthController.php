<?php

namespace App\Http\Controllers\Api;

use App\Contracts\RegistrationServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\EncryptionKey;
use App\Models\Invite;
use App\Models\User;
use App\Services\AuthSessionService;
use App\Services\MfaTokenService;
use App\Services\PasswordProofService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(
        private AuthSessionService $authSession,
        private MfaTokenService $mfaTokens,
        private PasswordProofService $passwordProofs,
    ) {}

    /**
     * Register a new user account.
     *
     * Creates a new user with email, auth hash, and display name. The password
     * never leaves the browser: `auth_hash` is derived from the key that the
     * password produces, and only a bcrypt hash of it is stored.
     * Also stores encryption metadata (master_key_salt, public_key and the
     * KEK-wrapped private key) needed for the zero-knowledge encryption system.
     *
     * When a valid invite_token is provided, the invite is auto-accepted
     * during registration and the user is added as a member of the child.
     */
    public function register(Request $request): JsonResponse
    {
        $inviteToken = $request->input('invite_token');

        if (! app(RegistrationServiceInterface::class)->isRegistrationAllowed($inviteToken)) {
            return response()->json([
                'message' => 'Registration is by invitation only.',
            ], 403);
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'auth_hash' => ['required', 'string', 'regex:'.PasswordProofService::AUTH_HASH_REGEX],
            'display_name' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN\s\-.,\']+$/u'],
            'master_key_salt' => ['required', 'string', 'max:64', 'regex:/^[a-f0-9]+$/i'],
            'public_key' => ['required', 'string', 'max:2000'],
            'encrypted_private_key' => ['required', 'string', 'max:4000'],
            'invite_token' => ['sometimes', 'string', 'max:64'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        [$user, $acceptedChild] = DB::transaction(function () use ($validated, $inviteToken): array {
            $user = User::create([
                'email' => $validated['email'],
                'password_hash' => Hash::make($validated['auth_hash']),
                'display_name' => $validated['display_name'],
                'master_key_salt' => $validated['master_key_salt'],
                'public_key' => $validated['public_key'],
                'encrypted_private_key' => $validated['encrypted_private_key'],
            ]);

            if (! $inviteToken) {
                return [$user, null];
            }

            $invite = Invite::where('token', $inviteToken)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $invite) {
                return [$user, null];
            }

            $invite->accepted_at = now();
            $invite->accepted_by = $user->id;
            $invite->save();

            DB::table('child_users')->insert([
                'child_id' => $invite->child_id,
                'user_id' => $user->id,
                'role' => $invite->role,
                'relationship' => $invite->relationship ?? '',
                'can_upload' => $invite->can_upload,
                'joined_at' => now(),
            ]);

            return [$user, [
                'child_id' => $invite->child_id,
                'child_name' => $invite->child->name,
                'child_slug' => $invite->child->slug,
            ]];
        });

        return response()->json([
            'user' => $this->authSession->userPayload($user),
            'token' => $this->authSession->issueToken($user, $validated['remember'] ?? true),
            'accepted_child' => $acceptedChild,
        ], 201);
    }

    /**
     * Tell the client how to derive its auth hash before it can log in.
     *
     * Returns the account's key-derivation salt. Unknown emails get a stable fake
     * salt, so the answer does not show whether an account exists.
     */
    public function prelogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        return response()->json([
            'data' => [
                'master_key_salt' => $user !== null
                    ? $user->master_key_salt
                    : hash_hmac('sha256', strtolower(trim($validated['email'])), (string) config('app.key')),
            ],
        ]);
    }

    /**
     * Login an existing user.
     *
     * The client sends `auth_hash`, never the password. Authenticates the user and returns their wrapped DEKs
     * for all families they belong to, allowing client-side decryption.
     * Accounts with MFA get a short-lived challenge token instead.
     *
     * @throws ValidationException
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'auth_hash' => ['required', 'string', 'regex:'.PasswordProofService::AUTH_HASH_REGEX],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $remember = $validated['remember'] ?? true;
        $user = User::where('email', $validated['email'])->first();

        if (! $this->passwordProofs->matches($user, $validated['auth_hash'])) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->hasMfaEnabled()) {
            return response()->json([
                'mfa_required' => true,
                'mfa_token' => $this->mfaTokens->create($user, $remember),
                'mfa_methods' => $user->enabledMfaMethods(),
            ]);
        }

        return $this->authSession->loginResponse($user, $remember);
    }

    /**
     * Logout the authenticated user.
     *
     * Deletes the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }

    /**
     * Refresh the user's access token.
     *
     * Deletes the old token and issues a new one.
     */
    public function refresh(Request $request): JsonResponse
    {
        return response()->json([
            'token' => $this->authSession->refreshToken($request->user()),
        ]);
    }

    /**
     * Get the current registration mode.
     *
     * Returns 'open' when registration is freely available, 'invite_only'
     * when registration requires a valid invitation token.
     */
    public function registrationMode(): JsonResponse
    {
        $mode = app(RegistrationServiceInterface::class)->getRegistrationMode();

        return response()->json([
            'data' => ['mode' => $mode],
        ]);
    }

    /**
     * Update the authenticated user's profile.
     *
     * Allows updating display_name and/or email. Password changes go through
     * changePassword() because they must re-wrap the user's keys.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:100', 'regex:/^[\pL\pN\s\-.,\']+$/u'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        if (array_key_exists('display_name', $validated)) {
            $user->display_name = $validated['display_name'];
        }

        if (array_key_exists('email', $validated)) {
            $user->email = $validated['email'];
        }

        $user->save();

        return response()->json([
            'user' => $this->authSession->userPayload($user),
        ]);
    }

    /**
     * Change the user's password.
     *
     * The KEK is derived from the password, so the client re-wraps every
     * KEK-wrapped DEK and the private key with the new KEK and sends them
     * here with the auth hash of the new password. They are saved together with
     * it, or not at all. Other sessions are signed out because they hold the old KEK.
     *
     * @throws ValidationException
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            ...PasswordProofService::rules(changingPassword: true),
            'encryption_keys' => ['present', 'array'],
            'encryption_keys.*.child_id' => ['required', 'integer'],
            'encryption_keys.*.key_version' => ['required', 'integer'],
            'encryption_keys.*.encrypted_dek' => ['required', 'string', 'max:2000'],
            'encrypted_private_key' => [Rule::requiredIf($user->encrypted_private_key !== null), 'nullable', 'string', 'max:4000'],
        ]);

        $this->passwordProofs->assertValid($user, $validated);

        if (hash_equals($validated['current_auth_hash'], $validated['new_auth_hash'])) {
            throw ValidationException::withMessages([
                'new_auth_hash' => ['The new password must be different from the current password.'],
            ]);
        }

        $storedKeys = $user->encryptionKeys()
            ->where('wrap_type', EncryptionKey::WRAP_KEK)
            ->get()
            ->keyBy(fn (EncryptionKey $key): string => $key->child_id.':'.$key->key_version);

        $submittedKeys = collect($validated['encryption_keys'])
            ->keyBy(fn (array $key): string => $key['child_id'].':'.$key['key_version']);

        if ($submittedKeys->count() !== count($validated['encryption_keys'])
            || $storedKeys->keys()->sort()->values()->all() !== $submittedKeys->keys()->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'encryption_keys' => ['Your album keys changed while you were doing this. Reload the page and try again.'],
            ]);
        }

        DB::transaction(function () use ($user, $validated, $storedKeys, $submittedKeys): void {
            foreach ($storedKeys as $index => $key) {
                $key->encrypted_dek = $submittedKeys[$index]['encrypted_dek'];
                $key->save();
            }

            $user->password_hash = Hash::make($validated['new_auth_hash']);

            if ($user->encrypted_private_key !== null) {
                $user->encrypted_private_key = $validated['encrypted_private_key'];
            }

            $user->save();

            $currentToken = $user->currentAccessToken();
            $user->tokens()
                ->when($currentToken instanceof PersonalAccessToken, fn ($query) => $query->whereKeyNot($currentToken->getKey()))
                ->delete();
        });

        return response()->json([
            'user' => $this->authSession->userPayload($user),
            'encryption_keys' => $this->authSession->encryptionKeysPayload($user),
        ]);
    }

    /**
     * Set the key pair for an account created before private keys were stored.
     *
     * Only allowed once: replacing an existing key pair would let a stolen
     * session redirect future key shares to an attacker's key. Shares already
     * wrapped for the old public key can't be opened and are dropped so an
     * admin re-shares them.
     *
     * @throws ValidationException
     */
    public function setKeyPair(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            ...PasswordProofService::rules(),
            'public_key' => ['required', 'string', 'max:2000'],
            'encrypted_private_key' => ['required', 'string', 'max:4000'],
        ]);

        $this->passwordProofs->assertValid($user, $validated);

        if ($user->encrypted_private_key !== null) {
            return response()->json([
                'message' => 'This account already has a key pair.',
            ], 409);
        }

        DB::transaction(function () use ($user, $validated): void {
            $user->public_key = $validated['public_key'];
            $user->encrypted_private_key = $validated['encrypted_private_key'];
            $user->save();

            $user->encryptionKeys()
                ->where('wrap_type', EncryptionKey::WRAP_PUBLIC_KEY)
                ->delete();
        });

        return response()->json([
            'user' => $this->authSession->userPayload($user),
        ]);
    }
}
