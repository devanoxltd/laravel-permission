<?php

namespace Spatie\Permission\Exceptions;

use InvalidArgumentException;

class PermissionAlreadyExists extends InvalidArgumentException
{
    public static function create(string $permissionName, string $guardName): static
    {
        return new static(__('permission::exception.permission_already_exists', [
            'permission' => $permissionName,
            'guard' => $guardName,
        ]));
    }
}
