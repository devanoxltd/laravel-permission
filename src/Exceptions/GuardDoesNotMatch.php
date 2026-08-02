<?php

namespace Spatie\Permission\Exceptions;

use Illuminate\Support\Collection;
use InvalidArgumentException;

class GuardDoesNotMatch extends InvalidArgumentException
{
    public static function create(string $givenGuard, Collection $expectedGuards): static
    {
        return new static(__('permission::exception.guard_does_not_match', [
            'expected' => $expectedGuards->implode(', '),
            'given' => $givenGuard,
        ]));
    }
}
