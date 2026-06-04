<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureChildAdmin
{
    /**
     * Ensure the authenticated user is an owner or admin of the given child.
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

        if (! $pivot || ! in_array($pivot->role, ['owner', 'admin'], true)) {
            return response()->json([
                'message' => 'You do not have admin access to this child.',
            ], 403);
        }

        return $next($request);
    }
}
