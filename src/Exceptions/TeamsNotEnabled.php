<?php

namespace Spatie\Permission\Exceptions;

use BadMethodCallException;

class TeamsNotEnabled extends BadMethodCallException
{
    public static function create(): static
    {
        return new static(__('permission::exception.teams_not_enabled'));
    }
}
