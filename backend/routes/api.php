<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChildController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\EncryptionKeysController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\MemoryController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\PhotoController;
use App\Http\Controllers\Api\QuotaController;
use App\Http\Controllers\Api\StorageInfoController;
use App\Http\Controllers\Api\TagController;
use Illuminate\Support\Facades\Route;

// Authentication routes (no auth required)
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::get('/auth/registration-mode', [AuthController::class, 'registrationMode']);

// MFA challenge routes (no auth required, bound to mfa_token)
Route::post('/auth/mfa/challenge', [MfaController::class, 'challenge'])->middleware('throttle:mfa');
Route::post('/auth/mfa/passkey/options', [MfaController::class, 'passkeyOptions'])->middleware('throttle:mfa');

// Invite preview (no auth required — preview before login)
Route::get('/invites/{token}', [InviteController::class, 'show'])
    ->middleware('throttle:10,1')
    ->where('token', '[A-Za-z0-9]{32,}');

// Protected routes
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // MFA setup routes
    Route::get('/auth/mfa/methods', [MfaController::class, 'methods']);
    Route::post('/auth/mfa/totp/confirm', [MfaController::class, 'confirmTotp'])->middleware('throttle:mfa');
    Route::post('/auth/mfa/passkeys/register/verify', [MfaController::class, 'registerPasskeyVerify']);

    // Password-confirmed security changes
    Route::middleware('throttle:password-confirm')->group(function () {
        Route::post('/auth/mfa/totp/setup', [MfaController::class, 'setupTotp']);
        Route::delete('/auth/mfa/totp', [MfaController::class, 'disableTotp']);
        Route::post('/auth/mfa/passkeys/register/options', [MfaController::class, 'registerPasskeyOptions']);
        Route::delete('/auth/mfa/passkeys/{credential}', [MfaController::class, 'removePasskey']);
        Route::post('/auth/mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes']);
    });

    // User public key (scoped to users sharing a child with the requester)
    Route::get('/users/{user}/public-key', [EncryptionKeysController::class, 'publicKey']);

    // Children
    Route::get('/children', [ChildController::class, 'index']);
    Route::post('/children', [ChildController::class, 'store']);

    // Storage info
    Route::get('/storage/disk-info', [StorageInfoController::class, 'diskInfo']);

    // System-wide quota
    Route::get('/quota', [QuotaController::class, 'show']);

    // User
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::put('/user/password', [AuthController::class, 'changePassword'])->middleware('throttle:password-confirm');
    Route::put('/user/keypair', [AuthController::class, 'setKeyPair'])->middleware('throttle:password-confirm');
    Route::put('/user/seen', [PhotoController::class, 'markSeen']);

    // Unified photo feed (across all children)
    Route::get('/photos', [PhotoController::class, 'feed']);
    Route::post('/photos/upload', [PhotoController::class, 'upload'])->middleware('throttle:upload');
    Route::get('/photos/{photo}', [PhotoController::class, 'show']);
    Route::get('/photos/{photo}/thumbnail', [PhotoController::class, 'thumbnail']);
    Route::patch('/photos/{photo}', [PhotoController::class, 'update']);
    Route::delete('/photos/{photo}', [PhotoController::class, 'destroy']);

    // Recents feed
    Route::get('/recents', [PhotoController::class, 'recents']);
    Route::get('/recents/unseen-count', [PhotoController::class, 'unseenCount']);

    // Memories ("on this day")
    Route::get('/memories', [MemoryController::class, 'index']);

    // Global tags
    Route::get('/tags', [TagController::class, 'globalIndex']);
    Route::post('/tags', [TagController::class, 'store']);
    Route::delete('/tags/{tag}', [TagController::class, 'destroy']);

    // Photo-Tag management
    Route::post('/photos/{photo}/tags', [TagController::class, 'attach']);
    Route::delete('/photos/{photo}/tags/{tag}', [TagController::class, 'detach']);

    // Photo comments (E2E-encrypted ciphertext only)
    Route::post('/photos/{photo}/comments', [CommentController::class, 'store']);
    Route::get('/photos/{photo}/comments', [CommentController::class, 'index']);
    Route::delete('/photos/{photo}/comments/{comment}', [CommentController::class, 'destroy']);

    // Child-scoped routes
    Route::middleware('child.member')->group(function () {
        Route::get('/children/{child}', [ChildController::class, 'show']);
        Route::put('/children/{child}', [ChildController::class, 'update']);
        Route::delete('/children/{child}', [ChildController::class, 'destroy']);

        // Invites (admin only)
        Route::middleware('child.admin')->group(function () {
            Route::post('/children/{child}/invite', [InviteController::class, 'store'])->middleware('throttle:invite');
            Route::delete('/children/{child}/members/{user}', [ChildController::class, 'removeMember']);
        });
    });

    // Invite acceptance
    Route::post('/invites/{token}/accept', [InviteController::class, 'accept']);

    // Pending invites (invites the current user has sent that haven't been accepted)
    Route::get('/invites/pending', [InviteController::class, 'pending']);
    Route::delete('/invites/{invite}', [InviteController::class, 'destroy']);

    // Encryption Keys
    Route::get('/encryption-keys', [EncryptionKeysController::class, 'index']);
    Route::post('/encryption-keys', [EncryptionKeysController::class, 'store']);
    Route::get('/encryption-keys/pending-grants', [EncryptionKeysController::class, 'pendingGrants']);
    Route::post('/encryption-keys/wrap-for-invitee', [EncryptionKeysController::class, 'wrapForInvitee']);
});
