<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChildController;
use App\Http\Controllers\Api\EncryptionKeysController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\PhotoController;
use App\Http\Controllers\Api\StorageInfoController;
use App\Http\Controllers\Api\TagController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Authentication routes (no auth required)
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Invite preview (no auth required — preview before login)
Route::get('/invites/{token}', [InviteController::class, 'show']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
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

    Route::middleware('child.member')->group(function () {
        Route::get('/children/{child}', [ChildController::class, 'show']);

        // Photos
        Route::get('/children/{child}/photos', [PhotoController::class, 'index']);
        Route::post('/children/{child}/photos/upload', [PhotoController::class, 'upload']);
        Route::get('/children/{child}/photos/{photo}', [PhotoController::class, 'show']);
        Route::get('/children/{child}/photos/{photo}/thumbnail/{size}', [PhotoController::class, 'thumbnail']);

        // Tags
        Route::get('/children/{child}/tags', [TagController::class, 'index']);
        Route::post('/children/{child}/tags', [TagController::class, 'store']);
        Route::post('/children/{child}/photos/{photo}/tags', [TagController::class, 'attach']);
        Route::delete('/children/{child}/photos/{photo}/tags/{tag}', [TagController::class, 'detach']);
    });

    Route::middleware(['child.member', 'child.admin'])->group(function () {
        Route::post('/children/{child}/invite', [InviteController::class, 'store']);
        Route::delete('/children/{child}/tags/{tag}', [TagController::class, 'destroy']);
    });

    // Invite acceptance
    Route::post('/invites/{token}/accept', [InviteController::class, 'accept']);

    // Encryption Keys
    Route::post('/encryption-keys', [EncryptionKeysController::class, 'store']);
    Route::post('/encryption-keys/wrap-for-invitee', [EncryptionKeysController::class, 'wrapForInvitee']);
});
