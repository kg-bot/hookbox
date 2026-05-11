<?php

declare(strict_types=1);

namespace Hookbox\Verifiers\Support;

use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\VerificationResult;

final class VerifierFailurePolicy
{
    public static function forTransportFailure(SourceDefinition $source, VerifierTransportException $exception): VerificationResult
    {
        return new VerificationResult(
            VerificationStatus::INVALID,
            $exception->getMessage(),
        );
    }

    public static function forProviderFailure(SourceDefinition $source, string $reason): VerificationResult
    {
        return new VerificationResult(VerificationStatus::INVALID, $reason);
    }
}
