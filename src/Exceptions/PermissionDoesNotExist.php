<?php

namespace Spatie\Permission\Exceptions;

use InvalidArgumentException;

class PermissionDoesNotExist extends InvalidArgumentException
{
    public static function create(string $permissionName, ?string $guardName): static
    {
        return new static(__('permission::exception.permission_does_not_exist', [
            'permission' => $permissionName,
            'guard' => $guardName,
        ]));
    }

    public static function withId(int|string $permissionId, ?string $guardName): static
    {
        return new static(__('permission::exception.permission_does_not_exist_with_id', [
            'id' => $permissionId,
            'guard' => $guardName,
        ]));
    }
}
