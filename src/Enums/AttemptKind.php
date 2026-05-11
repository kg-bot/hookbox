<?php

declare(strict_types=1);

namespace Hookbox\Enums;

enum AttemptKind: string
{
    case INITIAL = 'initial';
    case REPLAY = 'replay';
    case DRY_RUN = 'dry_run';
}
