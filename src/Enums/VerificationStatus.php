<?php

declare(strict_types=1);

namespace Hookbox\Enums;

enum VerificationStatus: string
{
    case VALID = 'valid';
    case INVALID = 'invalid';
    case SKIPPED = 'skipped';
}
