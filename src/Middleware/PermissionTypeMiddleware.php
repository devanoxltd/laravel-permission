<?php

namespace Spatie\Permission\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Guard;
use Spatie\Permission\Support\Config;

class PermissionTypeMiddleware
{
    public function handle(Request $request, Closure $next, $permission, $type, ?string $guard = null)
    {
        $authGuard = Auth::guard($guard);

        $user = $authGuard->user();

        // For machine-to-machine Passport clients
        if (! $user && $request->bearerToken() && Config::usePassportClientCredentials()) {
            $user = Guard::getPassportClient($guard);
        }

        if (! $user) {
            throw UnauthorizedException::notLoggedIn();
        }

        if (! method_exists($user, 'hasAnyPermission')) {
            throw UnauthorizedException::missingTraitHasRoles($user);
        }

        if (! $user->hasPermissionWithType($permission, $type, $guard)) {
            throw UnauthorizedException::forPermissions([$permission]);
        }

        return $next($request);
    }
}
