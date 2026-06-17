<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChildController;
use App\Http\Controllers\Api\EncryptionKeysController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\PhotoController;
use App\Http\Controllers\Api\QuotaController;
use App\Http\Controllers\Api\StorageInfoController;
use App\Http\Controllers\Api\TagController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Authentication routes (no auth required)
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');

// Invite preview (no auth required — preview before login)
Route::get('/invites/{token}', [InviteController::class, 'show'])->middleware('throttle:10,1');

// Protected routes
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // User public key (for DEK re-wrapping during invites)
    Route::get('/users/{user}/public-key', function (User $user) {
        return response()->json([
            'data' => [
                'id' => $user->id,
                'public_key' => $user->public_key,
            ],
        ]);
    });

    // Children
    Route::get('/children', [ChildController::class, 'index']);
    Route::post('/children', [ChildController::class, 'store']);

    // Storage info
    Route::get('/storage/disk-info', [StorageInfoController::class, 'diskInfo']);

    // System-wide quota
    Route::get('/quota', [QuotaController::class, 'show']);

    // User
    Route::put('/user/seen', [PhotoController::class, 'markSeen']);

    // Unified photo feed (across all children)
    Route::get('/photos', [PhotoController::class, 'feed']);
    Route::post('/photos/upload', [PhotoController::class, 'upload'])->middleware('throttle:upload');
    Route::get('/photos/{photo}', [PhotoController::class, 'show']);
    Route::get('/photos/{photo}/thumbnail', [PhotoController::class, 'thumbnail']);
    Route::delete('/photos/{photo}', [PhotoController::class, 'destroy']);

    // Recents feed
    Route::get('/recents', [PhotoController::class, 'recents']);
    Route::get('/recents/unseen-count', [PhotoController::class, 'unseenCount']);

    // Global tags
    Route::get('/tags', [TagController::class, 'globalIndex']);
    Route::post('/tags', [TagController::class, 'store']);
    Route::delete('/tags/{tag}', [TagController::class, 'destroy']);

    // Photo-Tag management
    Route::post('/photos/{photo}/tags', [TagController::class, 'attach']);
    Route::delete('/photos/{photo}/tags/{tag}', [TagController::class, 'detach']);

    // Child-scoped routes
    Route::middleware('child.member')->group(function () {
        Route::get('/children/{child}', [ChildController::class, 'show']);

        // Invites (admin only)
        Route::middleware('child.admin')->group(function () {
            Route::post('/children/{child}/invite', [InviteController::class, 'store'])->middleware('throttle:invite');
        });
    });

    // Invite acceptance
    Route::post('/invites/{token}/accept', [InviteController::class, 'accept']);

    // Encryption Keys
    Route::post('/encryption-keys', [EncryptionKeysController::class, 'store']);
    Route::post('/encryption-keys/wrap-for-invitee', [EncryptionKeysController::class, 'wrapForInvitee']);
});
