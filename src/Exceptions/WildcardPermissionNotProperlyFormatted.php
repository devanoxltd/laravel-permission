<?php

namespace Spatie\Permission\Exceptions;

use InvalidArgumentException;

class WildcardPermissionNotProperlyFormatted extends InvalidArgumentException
{
    public static function create(string $permission): static
    {
        return new static(__('permission::exception.wildcard_permission_not_properly_formatted', [
            'permission' => $permission,
        ]));
    }
}
