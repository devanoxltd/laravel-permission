<?php

namespace Spatie\Permission\Exceptions;

use InvalidArgumentException;

class WildcardPermissionInvalidArgument extends InvalidArgumentException
{
    public static function create(): static
    {
        return new static(__('permission::exception.wildcard_permission_invalid_argument'));
    }
}
