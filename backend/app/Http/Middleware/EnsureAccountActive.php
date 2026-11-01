<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Blocks suspended or deleted accounts even if they still hold a token (spec 02 §2.3). */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && ! $user->status->canSignIn()) {
            throw new ApiException('account_suspended', 403);
        }

        return $next($request);
    }
}
