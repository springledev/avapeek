<?php

namespace App\Http\Controllers\Api;

use App\Contracts\RegistrationServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user account.
     *
     * Creates a new user with email, password, and display name.
     * Also stores encryption metadata (master_key_salt and public_key)
     * needed for the zero-knowledge encryption system.
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
            'password' => ['required', 'string', Password::defaults()],
            'display_name' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN\s\-.,\']+$/u'],
            'master_key_salt' => ['required', 'string', 'max:64', 'regex:/^[a-f0-9]+$/i'],
            'public_key' => ['required', 'string', 'max:2000'],
            'invite_token' => ['sometimes', 'string', 'max:64'],
        ]);

        $user = User::create([
            'email' => $validated['email'],
            'password_hash' => Hash::make($validated['password']),
            'display_name' => $validated['display_name'],
            'master_key_salt' => $validated['master_key_salt'],
            'public_key' => $validated['public_key'],
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        $acceptedChild = null;

        if ($inviteToken) {
            $invite = Invite::where('token', $inviteToken)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->first();

            if ($invite) {
                $invite->accepted_at = now();
                $invite->accepted_by = $user->id;
                $invite->save();

                DB::table('child_users')->insert([
                    'child_id' => $invite->child_id,
                    'user_id' => $user->id,
                    'role' => $invite->role,
                    'relationship' => $invite->relationship ?? '',
                    'joined_at' => now(),
                ]);

                $acceptedChild = [
                    'child_id' => $invite->child_id,
                    'child_name' => $invite->child->name,
                    'child_slug' => $invite->child->slug,
                ];
            }
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'display_name' => $user->display_name,
                'master_key_salt' => $user->master_key_salt,
                'public_key' => $user->public_key,
            ],
            'token' => $token,
            'accepted_child' => $acceptedChild,
        ], 201);
    }

    /**
     * Login an existing user.
     *
     * Authenticates the user and returns their wrapped DEKs
     * for all families they belong to, allowing client-side decryption.
     *
     *
     * @throws ValidationException
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->hasMfaEnabled()) {
            $mfaToken = app(MfaController::class)->createMfaToken($user);

            return response()->json([
                'mfa_required' => true,
                'mfa_token' => $mfaToken,
                'mfa_methods' => $user->enabledMfaMethods(),
            ]);
        }

        // Load the user's encryption keys for all children they belong to
        $encryptionKeys = $user->encryptionKeys()
            ->with('child')
            ->get()
            ->map(function ($key) {
                return [
                    'child_id' => $key->child_id,
                    'child_name' => $key->child->name ?? null,
                    'encrypted_dek' => $key->encrypted_dek,
                    'key_version' => $key->key_version,
                ];
            });

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'display_name' => $user->display_name,
                'master_key_salt' => $user->master_key_salt,
                'public_key' => $user->public_key,
            ],
            'token' => $token,
            'encryption_keys' => $encryptionKeys,
        ]);
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
        $user = $request->user();
        $user->currentAccessToken()->delete();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
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
     * Allows updating display_name, email, and/or password.
     * Password change requires current_password for verification.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:100', 'regex:/^[\pL\pN\s\-.,\']+$/u'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'current_password' => ['required_with:new_password', 'string'],
            'new_password' => ['nullable', 'string', Password::defaults(), 'confirmed'],
        ]);

        if ($request->filled('new_password')) {
            if (! Hash::check($validated['current_password'], $user->password_hash)) {
                throw ValidationException::withMessages([
                    'current_password' => ['The current password is incorrect.'],
                ]);
            }

            $user->password_hash = $validated['new_password'];
        }

        if ($request->has('display_name')) {
            $user->display_name = $validated['display_name'];
        }

        if ($request->has('email')) {
            $user->email = $validated['email'];
        }

        $user->save();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'display_name' => $user->display_name,
                'master_key_salt' => $user->master_key_salt,
                'public_key' => $user->public_key,
            ],
        ]);
    }
}
