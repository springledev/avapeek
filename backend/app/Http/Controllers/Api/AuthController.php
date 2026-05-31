<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', Password::defaults()],
            'display_name' => ['required', 'string', 'max:100'],
            'master_key_salt' => ['required', 'string', 'max:64'],
            'public_key' => ['required', 'string'],
        ]);

        $user = User::create([
            'email' => $validated['email'],
            'password_hash' => Hash::make($validated['password']),
            'display_name' => $validated['display_name'],
            'master_key_salt' => $validated['master_key_salt'],
            'public_key' => $validated['public_key'],
        ]);

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

        // Load the user's encryption keys for all families they belong to
        $encryptionKeys = $user->encryptionKeys()
            ->with('family')
            ->get()
            ->map(function ($key) {
                return [
                    'family_id' => $key->family_id,
                    'family_name' => $key->family->name ?? null,
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
}
