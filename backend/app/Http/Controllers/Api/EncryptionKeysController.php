<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EncryptionKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EncryptionKeysController extends Controller
{
    /**
     * Store a wrapped DEK for a child.
     *
     * The client generates and wraps the DEK client-side,
     * then uploads the wrapped version for storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'child_id' => ['required', 'integer', 'exists:children,id'],
            'wrapped_dek' => ['required', 'string'],
        ]);

        $user = $request->user();

        $isMember = $user->children()
            ->where('child_id', $validated['child_id'])
            ->exists();

        if (! $isMember) {
            return response()->json([
                'message' => 'You are not a member of this child.',
            ], 403);
        }

        EncryptionKey::create([
            'child_id' => $validated['child_id'],
            'user_id' => $user->id,
            'encrypted_dek' => $validated['wrapped_dek'],
            'key_version' => 1,
        ]);

        return response()->json([
            'data' => [
                'child_id' => $validated['child_id'],
                'key_version' => 1,
            ],
        ], 201);
    }
}
