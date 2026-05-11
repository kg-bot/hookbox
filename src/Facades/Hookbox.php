<?php

declare(strict_types=1);

namespace Hookbox\Facades;

use Hookbox\HookboxActionRegistrar;
use Illuminate\Support\Facades\Facade;

final class Hookbox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HookboxActionRegistrar::class;
    }
}
