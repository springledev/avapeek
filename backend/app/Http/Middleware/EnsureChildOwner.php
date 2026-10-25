<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureChildOwner
{
    /**
     * Ensure the authenticated user is the owner of the given child.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $child = $request->route('child');

        if (! $child) {
            return $next($request);
        }

        $pivot = $request->user()
            ?->children()
            ->where('child_id', $child->id)
            ->first()?->pivot;

        if (! $pivot || $pivot->role !== 'owner') {
            return response()->json([
                'message' => 'Only the owner can do this.',
            ], 403);
        }

        return $next($request);
    }
}
