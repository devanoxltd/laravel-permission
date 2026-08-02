<?php

namespace Spatie\Permission\Exceptions;

use InvalidArgumentException;

class WildcardPermissionNotImplementsContract extends InvalidArgumentException
{
    public static function create(): static
    {
        return new static(__('permission::exception.wildcard_permission_not_implements_contract'));
    }
}
