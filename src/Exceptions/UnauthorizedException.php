<?php

namespace Spatie\Permission\Exceptions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Spatie\Permission\Support\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UnauthorizedException extends HttpException
{
    private array $requiredRoles = [];

    private array $requiredPermissions = [];

    public static function forRoles(array $roles): static
    {
        $message = __('permission::exception.unauthorized');

        if (Config::displayRoleInException()) {
            $message .= ' '.__('permission::exception.necessary_roles_are', ['roles' => implode(', ', $roles)]);
        }

        $exception = new static(403, $message, null, []);
        $exception->requiredRoles = $roles;

        return $exception;
    }

    public static function forPermissions(array $permissions): static
    {
        $message = __('permission::exception.unauthorized_permissions');

        if (Config::displayPermissionInException()) {
            $message .= ' '.__('permission::exception.necessary_permissions_are', ['permissions' => implode(', ', $permissions)]);
        }

        $exception = new static(403, $message, null, []);
        $exception->requiredPermissions = $permissions;

        return $exception;
    }

    public static function forRolesOrPermissions(array $rolesOrPermissions): static
    {
        $message = __('permission::exception.unauthorized_roles_or_permissions');

        if (Config::displayPermissionInException() && Config::displayRoleInException()) {
            $message .= ' '.__('permission::exception.necessary_roles_or_permissions_are', ['values' => implode(', ', $rolesOrPermissions)]);
        }

        $exception = new static(403, $message, null, []);
        $exception->requiredPermissions = $rolesOrPermissions;

        return $exception;
    }

    public static function missingTraitHasRoles(Authorizable $user): static
    {
        return new static(403, __('permission::exception.authorizable_class_must_use_has_roles_trait', [
            'class' => $user::class,
        ]), null, []);
    }

    public static function notLoggedIn(): static
    {
        return new static(403, __('permission::exception.user_is_not_logged_in'), null, []);
    }

    public function getRequiredRoles(): array
    {
        return $this->requiredRoles;
    }

    public function getRequiredPermissions(): array
    {
        return $this->requiredPermissions;
    }
}
