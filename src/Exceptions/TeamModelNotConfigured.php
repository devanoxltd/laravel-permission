<?php

namespace Spatie\Permission\Exceptions;

use RuntimeException;

class TeamModelNotConfigured extends RuntimeException
{
    public static function create(): static
    {
        return new static(__('permission::exception.team_model_not_configured'));
    }
}
