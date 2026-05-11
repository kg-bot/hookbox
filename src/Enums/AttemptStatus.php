<?php

declare(strict_types=1);

namespace Hookbox\Enums;

enum AttemptStatus: string
{
    case PENDING = 'pending';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}
