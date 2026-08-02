<?php

namespace App\Http\Middleware;

use App\Models\LampyrisUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLampyrisUser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() instanceof LampyrisUser, 403, 'This token does not belong to Lampyris.');

        return $next($request);
    }
}
