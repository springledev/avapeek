<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebAuthnCredential;
use App\Services\MfaWebAuthnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Throwable;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

class MfaController extends Controller
{
    private const MFA_TOKEN_TTL_MINUTES = 5;

    private const RECOVERY_MAX_ATTEMPTS = 10;

    public function __construct(
        private MfaWebAuthnService $webAuthnService,
        private Google2FA $google2fa,
    ) {}

    /**
     * Validate an MFA challenge and complete login.
     *
     * @throws ValidationException
     */
    public function challenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mfa_token' => ['required', 'string'],
            'method' => ['required', 'string', 'in:totp,recovery,passkey'],
            'code' => ['required_if:method,totp,recovery', 'string'],
            'passkey_response' => ['required_if:method,passkey', 'array'],
        ]);

        $user = $this->validateMfaToken($validated['mfa_token']);
        $method = $validated['method'];

        $response = match ($method) {
            'totp' => $this->verifyTotpChallenge($user, $validated['code']),
            'recovery' => $this->verifyRecoveryChallenge($user, $validated['code'], $validated['mfa_token']),
            'passkey' => $this->verifyPasskeyChallenge($user, $validated['passkey_response'], $validated['mfa_token']),
            default => throw ValidationException::withMessages(['method' => ['Invalid MFA method.']]),
        };

        $this->consumeMfaToken($validated['mfa_token']);

        return $response;
    }

    /**
     * Return WebAuthn assertion options for the MFA challenge.
     */
    public function passkeyOptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mfa_token' => ['required', 'string'],
        ]);

        $user = $this->validateMfaToken($validated['mfa_token']);

        if (! in_array('passkey', $user->enabledMfaMethods(), true)) {
            throw ValidationException::withMessages([
                'mfa_token' => ['Passkey is not enabled for this account.'],
            ]);
        }

        $options = $this->webAuthnService->buildAssertionOptions($user);
        $payload = $this->parseMfaToken($validated['mfa_token']);
        Cache::put(
            $this->passkeyOptionsCacheKey($payload['nonce']),
            $this->webAuthnService->deserializeRequestOptions(json_encode($options, JSON_THROW_ON_ERROR)),
            now()->addMinutes(self::MFA_TOKEN_TTL_MINUTES)
        );

        return response()->json([
            'options' => $options,
        ]);
    }

    /**
     * Start TOTP setup by generating a new unconfirmed secret.
     */
    public function setupTotp(Request $request): JsonResponse
    {
        $user = $request->user();

        $secret = $this->google2fa->generateSecretKey(32);
        $user->totp_secret_encrypted = $secret;
        $user->totp_confirmed_at = null;
        $user->save();

        $otpauthUri = $this->google2fa->getQRCodeUrl(
            config('app.name', 'Avapeek'),
            $user->email,
            $secret
        );

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $otpauthUri,
        ]);
    }

    /**
     * Confirm TOTP setup with a verification code.
     *
     * @throws ValidationException
     */
    public function confirmTotp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $user = $request->user();

        if ($user->totp_secret_encrypted === null) {
            throw ValidationException::withMessages([
                'code' => ['TOTP setup has not been started.'],
            ]);
        }

        $verified = $this->google2fa->verifyKey(
            $user->totp_secret_encrypted,
            $validated['code'],
            1
        );

        if ($verified === false) {
            throw ValidationException::withMessages([
                'code' => ['The code is invalid.'],
            ]);
        }

        $user->totp_confirmed_at = now();
        $user->totp_last_timestep = null;
        $user->save();

        $recoveryCodes = $this->generateRecoveryCodes($user);

        return response()->json([
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Disable TOTP for the authenticated user.
     *
     * @throws ValidationException
     */
    public function disableTotp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->totp_secret_encrypted = null;
        $user->totp_confirmed_at = null;
        $user->totp_last_timestep = null;

        if ($user->webauthnCredentials()->count() === 0) {
            $user->mfa_recovery_codes_hash = null;
        }

        $user->save();

        return response()->json([
            'methods' => $user->enabledMfaMethods(),
        ]);
    }

    /**
     * List enabled MFA methods and registered passkeys.
     */
    public function methods(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'methods' => $user->enabledMfaMethods(),
            'passkeys' => $user->webauthnCredentials()
                ->get()
                ->map(fn (WebAuthnCredential $credential): array => [
                    'id' => $credential->id,
                    'name' => $credential->user_chosen_name,
                    'last_used_at' => $credential->last_used_at,
                ]),
        ]);
    }

    /**
     * Return WebAuthn registration options for adding a passkey.
     */
    public function registerPasskeyOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $options = $this->webAuthnService->buildRegistrationOptions($user);

        Cache::put(
            $this->registerOptionsCacheKey($user->id),
            $this->webAuthnService->deserializeCreationOptions(json_encode($options, JSON_THROW_ON_ERROR)),
            now()->addMinutes(self::MFA_TOKEN_TTL_MINUTES)
        );

        return response()->json([
            'options' => $options,
        ]);
    }

    /**
     * Verify a WebAuthn attestation and store the new passkey.
     *
     * @throws ValidationException
     */
    public function registerPasskeyVerify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'response' => ['required', 'array'],
            'user_chosen_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $options = Cache::get($this->registerOptionsCacheKey($user->id));

        if (! $options instanceof PublicKeyCredentialCreationOptions) {
            throw ValidationException::withMessages([
                'response' => ['Registration options have expired. Please try again.'],
            ]);
        }

        try {
            $credentialSource = $this->webAuthnService->verifyRegistration($validated['response'], $options);
        } catch (AuthenticatorResponseVerificationException $e) {
            throw ValidationException::withMessages([
                'response' => ['The passkey registration could not be verified.'],
            ]);
        }

        $isFirstMfaMethod = ! $user->hasMfaEnabled();

        $passkey = WebAuthnCredential::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'credential_id' => base64_encode($credentialSource->publicKeyCredentialId),
            'public_key' => base64_encode($credentialSource->credentialPublicKey),
            'sign_count' => $credentialSource->counter,
            'transports' => $credentialSource->transports,
            'user_chosen_name' => $validated['user_chosen_name'] ?? null,
            'last_used_at' => null,
        ]);

        $recoveryCodes = $isFirstMfaMethod ? $this->generateRecoveryCodes($user) : null;

        Cache::forget($this->registerOptionsCacheKey($user->id));

        return response()->json([
            'passkey_id' => $passkey->id,
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Remove a registered passkey.
     *
     * @throws ValidationException
     */
    public function removePasskey(Request $request, WebAuthnCredential $credential): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if ($credential->user_id !== $user->id) {
            abort(403, 'This passkey does not belong to your account.');
        }

        if (! Hash::check($validated['current_password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $credential->delete();

        if (! $user->hasMfaEnabled()) {
            $user->mfa_recovery_codes_hash = null;
            $user->save();
        }

        return response()->json([
            'methods' => $user->enabledMfaMethods(),
        ]);
    }

    /**
     * Regenerate MFA recovery codes.
     *
     * @throws ValidationException
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $recoveryCodes = $this->generateRecoveryCodes($user);

        return response()->json([
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Build the full login response for a user after MFA verification.
     */
    private function buildLoginResponse(User $user): JsonResponse
    {
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
     * Verify a TOTP challenge code.
     *
     * @throws ValidationException
     */
    private function verifyTotpChallenge(User $user, string $code): JsonResponse
    {
        if ($user->totp_secret_encrypted === null || $user->totp_confirmed_at === null) {
            throw ValidationException::withMessages([
                'code' => ['TOTP is not enabled for this account.'],
            ]);
        }

        $result = $this->google2fa->verifyKeyNewer(
            $user->totp_secret_encrypted,
            $code,
            $user->totp_last_timestep,
            1
        );

        if ($result === false) {
            throw ValidationException::withMessages([
                'code' => ['The code is invalid.'],
            ]);
        }

        $user->totp_last_timestep = is_int($result) ? $result : $this->google2fa->getTimestamp();
        $user->save();

        return $this->buildLoginResponse($user);
    }

    /**
     * Verify a recovery code challenge.
     *
     * @throws ValidationException
     */
    private function verifyRecoveryChallenge(User $user, string $code, string $mfaToken): JsonResponse
    {
        if ($user->mfa_recovery_codes_hash === null) {
            throw ValidationException::withMessages([
                'code' => ['No recovery codes are available for this account.'],
            ]);
        }

        $payload = $this->parseMfaToken($mfaToken);
        $attemptsKey = $this->recoveryAttemptsCacheKey($payload['nonce']);
        $attempts = (int) Cache::get($attemptsKey, 0);

        if ($attempts >= self::RECOVERY_MAX_ATTEMPTS) {
            throw ValidationException::withMessages([
                'code' => ['Too many recovery code attempts. Please request a new MFA challenge.'],
            ]);
        }

        Cache::put($attemptsKey, $attempts + 1, now()->addMinutes(self::MFA_TOKEN_TTL_MINUTES));

        /** @var array<int, string|null> $hashes */
        $hashes = json_decode($user->mfa_recovery_codes_hash, true) ?? [];
        $matchedIndex = null;

        foreach ($hashes as $index => $hash) {
            if ($hash !== null && Hash::check($code, $hash)) {
                $matchedIndex = $index;
                break;
            }
        }

        if ($matchedIndex === null) {
            throw ValidationException::withMessages([
                'code' => ['The recovery code is invalid.'],
            ]);
        }

        $hashes[$matchedIndex] = null;
        $user->mfa_recovery_codes_hash = json_encode($hashes, JSON_THROW_ON_ERROR);
        $user->save();

        return $this->buildLoginResponse($user);
    }

    /**
     * Verify a WebAuthn assertion challenge.
     *
     * @param  array<string, mixed>  $passkeyResponse
     *
     * @throws ValidationException
     */
    private function verifyPasskeyChallenge(User $user, array $passkeyResponse, string $mfaToken): JsonResponse
    {
        if (! in_array('passkey', $user->enabledMfaMethods(), true)) {
            throw ValidationException::withMessages([
                'passkey_response' => ['Passkey is not enabled for this account.'],
            ]);
        }

        $payload = $this->parseMfaToken($mfaToken);
        $options = Cache::get($this->passkeyOptionsCacheKey($payload['nonce']));

        if (! $options instanceof PublicKeyCredentialRequestOptions) {
            throw ValidationException::withMessages([
                'passkey_response' => ['Passkey challenge options have expired. Please try again.'],
            ]);
        }

        $credentialRecord = $this->findCredentialByResponse($user, $passkeyResponse);

        if ($credentialRecord === null) {
            throw ValidationException::withMessages([
                'passkey_response' => ['The passkey is not recognized.'],
            ]);
        }

        $credentialSource = $credentialRecord->toPublicKeyCredentialSource();

        try {
            $updatedSource = $this->webAuthnService->verifyAssertion($passkeyResponse, $options, $credentialSource);
        } catch (AuthenticatorResponseVerificationException $e) {
            throw ValidationException::withMessages([
                'passkey_response' => ['The passkey verification failed.'],
            ]);
        }

        $credentialRecord->sign_count = $updatedSource->counter;
        $credentialRecord->last_used_at = now();
        $credentialRecord->save();

        return $this->buildLoginResponse($user);
    }

    /**
     * Find the WebAuthn credential record matching the client response.
     *
     * @param  array<string, mixed>  $passkeyResponse
     */
    private function findCredentialByResponse(User $user, array $passkeyResponse): ?WebAuthnCredential
    {
        $rawId = $passkeyResponse['rawId'] ?? $passkeyResponse['id'] ?? null;

        if (! is_string($rawId)) {
            return null;
        }

        $credentialId = base64_encode(base64_decode($rawId, true));

        return $user->webauthnCredentials()
            ->where('credential_id', $credentialId)
            ->first();
    }

    /**
     * Generate and store recovery codes, returning the plaintext codes.
     *
     * @return array<int, string>
     */
    private function generateRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = bin2hex(random_bytes(8));
        }

        $hashes = array_map(fn (string $code): string => Hash::make($code), $codes);
        $user->mfa_recovery_codes_hash = json_encode($hashes, JSON_THROW_ON_ERROR);
        $user->save();

        return $codes;
    }

    /**
     * Create a short-lived MFA challenge token for a user.
     */
    public function createMfaToken(User $user): string
    {
        $nonce = bin2hex(random_bytes(16));
        $payload = [
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(self::MFA_TOKEN_TTL_MINUTES)->getTimestamp(),
            'nonce' => $nonce,
        ];

        Cache::put(
            $this->tokenCacheKey($nonce),
            true,
            now()->addMinutes(self::MFA_TOKEN_TTL_MINUTES)
        );

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Validate and parse an MFA token, returning the bound user.
     *
     * @throws ValidationException
     */
    private function validateMfaToken(string $token): User
    {
        try {
            $payload = $this->parseMfaToken($token);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'mfa_token' => ['The MFA token is invalid or has expired.'],
            ]);
        }

        if ($payload['expires_at'] < now()->getTimestamp()) {
            throw ValidationException::withMessages([
                'mfa_token' => ['The MFA token has expired.'],
            ]);
        }

        if (! Cache::has($this->tokenCacheKey($payload['nonce']))) {
            throw ValidationException::withMessages([
                'mfa_token' => ['The MFA token has already been used or has expired.'],
            ]);
        }

        $user = User::find($payload['user_id']);

        if ($user === null) {
            throw ValidationException::withMessages([
                'mfa_token' => ['The MFA token is invalid.'],
            ]);
        }

        return $user;
    }

    /**
     * Parse the encrypted MFA token payload.
     *
     * @return array{user_id: int, expires_at: int, nonce: string}
     */
    private function parseMfaToken(string $token): array
    {
        $decrypted = Crypt::decryptString($token);
        /** @var array{user_id: int, expires_at: int, nonce: string} $payload */
        $payload = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);

        if (! isset($payload['user_id'], $payload['expires_at'], $payload['nonce'])) {
            throw new \RuntimeException('Invalid MFA token payload.');
        }

        return $payload;
    }

    /**
     * Mark an MFA token as used so it cannot be replayed.
     */
    private function consumeMfaToken(string $token): void
    {
        $payload = $this->parseMfaToken($token);
        Cache::forget($this->tokenCacheKey($payload['nonce']));
    }

    private function tokenCacheKey(string $nonce): string
    {
        return 'mfa_token:'.$nonce;
    }

    private function passkeyOptionsCacheKey(string $nonce): string
    {
        return 'mfa_passkey_options:'.$nonce;
    }

    private function registerOptionsCacheKey(int $userId): string
    {
        return 'webauthn_register_options:'.$userId;
    }

    private function recoveryAttemptsCacheKey(string $nonce): string
    {
        return 'mfa_recovery_attempts:'.$nonce;
    }
}
