<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebAuthnCredential;
use App\Services\AuthSessionService;
use App\Services\MfaTokenService;
use App\Services\MfaWebAuthnService;
use App\Services\PasswordProofService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Throwable;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

class MfaController extends Controller
{
    /**
     * Wrong TOTP or recovery codes allowed per account before a lockout.
     */
    private const CODE_MAX_FAILURES = 10;

    private const CODE_LOCKOUT_SECONDS = 900;

    private const OPTIONS_TTL_MINUTES = 5;

    public function __construct(
        private MfaWebAuthnService $webAuthnService,
        private MfaTokenService $mfaTokens,
        private AuthSessionService $authSession,
        private Google2FA $google2fa,
        private PasswordProofService $passwordProofs,
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
            'code' => ['required_if:method,totp,recovery', 'string', 'max:64'],
            'passkey_response' => ['required_if:method,passkey', 'array'],
        ]);

        $session = $this->mfaTokens->resolve($validated['mfa_token']);
        $user = $session['user'];
        $method = $validated['method'];
        $usesCode = $method !== 'passkey';

        if ($usesCode) {
            $this->ensureNotLockedOut($user);
        }

        $lock = $this->mfaTokens->lock($session['nonce']);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'code' => ['Verification is already in progress. Please wait a moment.'],
            ]);
        }

        try {
            $this->mfaTokens->ensureUnused($session['nonce']);

            try {
                match ($method) {
                    'totp' => $this->verifyTotpChallenge($user, $validated['code']),
                    'recovery' => $this->verifyRecoveryChallenge($user, $validated['code']),
                    'passkey' => $this->verifyPasskeyChallenge($user, $validated['passkey_response'], $session['nonce']),
                };
            } catch (ValidationException $e) {
                if ($usesCode) {
                    RateLimiter::hit($this->codeFailuresKey($user), self::CODE_LOCKOUT_SECONDS);
                }

                throw $e;
            }

            $this->mfaTokens->consume($session['nonce']);
        } finally {
            $lock->release();
        }

        RateLimiter::clear($this->codeFailuresKey($user));

        return $this->authSession->loginResponse($user, $session['remember']);
    }

    /**
     * Return WebAuthn assertion options for the MFA challenge.
     *
     * @throws ValidationException
     */
    public function passkeyOptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mfa_token' => ['required', 'string'],
        ]);

        $session = $this->mfaTokens->resolve($validated['mfa_token']);
        $user = $session['user'];

        if (! in_array('passkey', $user->enabledMfaMethods(), true)) {
            throw ValidationException::withMessages([
                'passkey_response' => ['Passkey is not enabled for this account.'],
            ]);
        }

        $options = $this->webAuthnService->buildAssertionOptions($user);
        Cache::put(
            $this->passkeyOptionsCacheKey($session['nonce']),
            json_encode($options, JSON_THROW_ON_ERROR),
            now()->addMinutes(self::OPTIONS_TTL_MINUTES)
        );

        return response()->json([
            'options' => $options,
        ]);
    }

    /**
     * Start TOTP setup by generating a new unconfirmed secret.
     *
     * @throws ValidationException
     */
    public function setupTotp(Request $request): JsonResponse
    {
        $validated = $request->validate(PasswordProofService::rules());

        $user = $request->user();

        $this->passwordProofs->assertValid($user, $validated);

        if ($user->totp_confirmed_at !== null) {
            throw ValidationException::withMessages([
                'totp' => ['An authenticator is already enabled. Disable it before setting up a new one.'],
            ]);
        }

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
     * Recovery codes are only issued when this is the account's first MFA
     * method; otherwise the codes the user already saved stay valid.
     *
     * @throws ValidationException
     */
    public function confirmTotp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if ($user->totp_confirmed_at !== null) {
            throw ValidationException::withMessages([
                'code' => ['An authenticator is already enabled.'],
            ]);
        }

        if ($user->totp_secret_encrypted === null) {
            throw ValidationException::withMessages([
                'code' => ['Authenticator setup has not been started.'],
            ]);
        }

        $timestep = $this->google2fa->verifyKeyNewer(
            $user->totp_secret_encrypted,
            $this->normalizeTotpCode($validated['code']),
            0,
            1
        );

        if ($timestep === false) {
            throw ValidationException::withMessages([
                'code' => ['That code didn\'t match. Check your authenticator app and try again.'],
            ]);
        }

        $isFirstMfaMethod = ! $user->hasMfaEnabled();

        $user->totp_confirmed_at = now();
        $user->totp_last_timestep = is_int($timestep) ? $timestep : null;
        $user->save();

        return response()->json([
            'recovery_codes' => $isFirstMfaMethod ? $this->generateRecoveryCodes($user) : null,
        ]);
    }

    /**
     * Disable TOTP for the authenticated user.
     *
     * @throws ValidationException
     */
    public function disableTotp(Request $request): JsonResponse
    {
        $validated = $request->validate(PasswordProofService::rules());

        $user = $request->user();

        $this->passwordProofs->assertValid($user, $validated);

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
            'recovery_codes_remaining' => $this->remainingRecoveryCodes($user),
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
     *
     * @throws ValidationException
     */
    public function registerPasskeyOptions(Request $request): JsonResponse
    {
        $validated = $request->validate(PasswordProofService::rules());

        $user = $request->user();

        $this->passwordProofs->assertValid($user, $validated);
        $options = $this->webAuthnService->buildRegistrationOptions($user);

        Cache::put(
            $this->registerOptionsCacheKey($user->id),
            json_encode($options, JSON_THROW_ON_ERROR),
            now()->addMinutes(self::OPTIONS_TTL_MINUTES)
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
        $cachedOptions = Cache::get($this->registerOptionsCacheKey($user->id));
        $options = is_string($cachedOptions) ? $this->restoreCachedOptions($cachedOptions, creation: true) : null;

        if (! $options instanceof PublicKeyCredentialCreationOptions) {
            throw ValidationException::withMessages([
                'response' => ['Registration options have expired. Please try again.'],
            ]);
        }

        try {
            $credentialSource = $this->webAuthnService->verifyRegistration($validated['response'], $options);
        } catch (AuthenticatorResponseVerificationException $e) {
            Log::warning('Passkey registration failed verification.', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
                'previous' => $e->getPrevious()?->getMessage(),
            ]);

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
        $validated = $request->validate(PasswordProofService::rules());

        $user = $request->user();

        if ($credential->user_id !== $user->id) {
            abort(403, 'This passkey does not belong to your account.');
        }

        $this->passwordProofs->assertValid($user, $validated);

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
        $validated = $request->validate(PasswordProofService::rules());

        $user = $request->user();

        $this->passwordProofs->assertValid($user, $validated);

        $recoveryCodes = $this->generateRecoveryCodes($user);

        return response()->json([
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Verify a TOTP challenge code. The user row is locked so the same
     * timestep cannot be redeemed by two concurrent logins.
     *
     * @throws ValidationException
     */
    private function verifyTotpChallenge(User $user, string $code): void
    {
        DB::transaction(function () use ($user, $code): void {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($lockedUser->totp_secret_encrypted === null || $lockedUser->totp_confirmed_at === null) {
                throw ValidationException::withMessages([
                    'code' => ['Authenticator codes are not enabled for this account.'],
                ]);
            }

            $result = $this->google2fa->verifyKeyNewer(
                $lockedUser->totp_secret_encrypted,
                $this->normalizeTotpCode($code),
                $lockedUser->totp_last_timestep,
                1
            );

            if ($result === false) {
                throw ValidationException::withMessages([
                    'code' => ['That code didn\'t work. Wait for a new code and try again.'],
                ]);
            }

            $lockedUser->totp_last_timestep = is_int($result) ? $result : $this->google2fa->getTimestamp();
            $lockedUser->save();
        });
    }

    /**
     * Verify and burn a recovery code.
     *
     * @throws ValidationException
     */
    private function verifyRecoveryChallenge(User $user, string $code): void
    {
        $normalizedCode = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $code));

        DB::transaction(function () use ($user, $normalizedCode): void {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            /** @var array<int, string|null> $hashes */
            $hashes = json_decode($lockedUser->mfa_recovery_codes_hash ?? '[]', true) ?? [];
            $matchedIndex = null;

            foreach ($hashes as $index => $hash) {
                if ($hash !== null && Hash::check($normalizedCode, $hash)) {
                    $matchedIndex = $index;
                    break;
                }
            }

            if ($matchedIndex === null) {
                throw ValidationException::withMessages([
                    'code' => ['That recovery code isn\'t valid or has already been used.'],
                ]);
            }

            $hashes[$matchedIndex] = null;
            $lockedUser->mfa_recovery_codes_hash = json_encode($hashes, JSON_THROW_ON_ERROR);
            $lockedUser->save();
        });
    }

    /**
     * Verify a WebAuthn assertion challenge.
     *
     * @param  array<string, mixed>  $passkeyResponse
     *
     * @throws ValidationException
     */
    private function verifyPasskeyChallenge(User $user, array $passkeyResponse, string $nonce): void
    {
        if (! in_array('passkey', $user->enabledMfaMethods(), true)) {
            throw ValidationException::withMessages([
                'passkey_response' => ['Passkey is not enabled for this account.'],
            ]);
        }

        $cachedOptions = Cache::get($this->passkeyOptionsCacheKey($nonce));
        $options = is_string($cachedOptions) ? $this->restoreCachedOptions($cachedOptions, creation: false) : null;

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

        Cache::forget($this->passkeyOptionsCacheKey($nonce));
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

        $base64 = strtr($rawId, '-_', '+/');
        $decoded = base64_decode(str_pad($base64, (int) ceil(strlen($base64) / 4) * 4, '=', STR_PAD_RIGHT), true);

        if ($decoded === false || $decoded === '') {
            return null;
        }

        $credentialId = base64_encode($decoded);

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
     * Count the recovery codes that have not been used yet.
     */
    private function remainingRecoveryCodes(User $user): int
    {
        if ($user->mfa_recovery_codes_hash === null) {
            return 0;
        }

        /** @var array<int, string|null> $hashes */
        $hashes = json_decode($user->mfa_recovery_codes_hash, true) ?? [];

        return count(array_filter($hashes, fn (?string $hash): bool => $hash !== null));
    }

    /**
     * Refuse code-based MFA while the account is locked out after repeated failures.
     *
     * @throws ValidationException
     */
    private function ensureNotLockedOut(User $user): void
    {
        $key = $this->codeFailuresKey($user);

        if (RateLimiter::tooManyAttempts($key, self::CODE_MAX_FAILURES)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
            $alternative = in_array('passkey', $user->enabledMfaMethods(), true) ? ' or use your passkey' : '';

            throw ValidationException::withMessages([
                'code' => ["Too many incorrect codes. Try again in {$minutes} ".Str::plural('minute', $minutes)."{$alternative}."],
            ]);
        }
    }

    /**
     * Strip spaces and separators people copy along with authenticator codes.
     */
    private function normalizeTotpCode(string $code): string
    {
        return (string) preg_replace('/\D/', '', $code);
    }

    private function codeFailuresKey(User $user): string
    {
        return 'mfa_code_failures:'.$user->id;
    }

    /**
     * Options are cached as JSON because cache.serializable_classes=false turns
     * cached objects into __PHP_Incomplete_Class on real (serializing) stores.
     */
    private function restoreCachedOptions(string $json, bool $creation): PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions|null
    {
        try {
            return $creation
                ? $this->webAuthnService->deserializeCreationOptions($json)
                : $this->webAuthnService->deserializeRequestOptions($json);
        } catch (Throwable) {
            return null;
        }
    }

    private function passkeyOptionsCacheKey(string $nonce): string
    {
        return 'mfa_passkey_options:'.$nonce;
    }

    private function registerOptionsCacheKey(int $userId): string
    {
        return 'webauthn_register_options:'.$userId;
    }
}
