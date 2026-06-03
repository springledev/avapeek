<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureChildMember
{
    /**
     * Ensure the authenticated user is a member of the given child.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $child = $request->route('child');

        if (! $child) {
            return $next($request);
        }

        $isMember = $request->user()
            ?->children()
            ->where('child_id', $child->id)
            ->exists();

        if (! $isMember) {
            return response()->json([
                'message' => 'You are not a member of this child.',
            ], 403);
        }

        return $next($request);
    }
}
