<?php

namespace Spatie\Permission\Exceptions;

use InvalidArgumentException;

class RoleAlreadyExists extends InvalidArgumentException
{
    public static function create(string $roleName, string $guardName): static
    {
        return new static(__('permission::exception.role_already_exists', [
            'role' => $roleName,
            'guard' => $guardName,
        ]));
    }
}
