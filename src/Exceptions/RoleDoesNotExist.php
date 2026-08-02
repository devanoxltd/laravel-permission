<?php

namespace Spatie\Permission\Exceptions;

use InvalidArgumentException;

class RoleDoesNotExist extends InvalidArgumentException
{
    public static function named(string $roleName, ?string $guardName): static
    {
        return new static(__('permission::exception.role_does_not_exist', [
            'role' => $roleName,
            'guard' => $guardName,
        ]));
    }

    public static function withId(int|string $roleId, ?string $guardName): static
    {
        return new static(__('permission::exception.role_does_not_exist_with_id', [
            'id' => $roleId,
            'guard' => $guardName,
        ]));
    }
}
