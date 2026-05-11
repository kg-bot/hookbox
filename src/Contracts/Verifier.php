<?php

declare(strict_types=1);

namespace Hookbox\Contracts;

use Hookbox\SourceDefinition;
use Hookbox\VerificationResult;
use Illuminate\Http\Request;

interface Verifier
{
    public function verify(Request $request, SourceDefinition $source): VerificationResult;

    /**
     * Extracts from the raw request body even when verification fails.
     */
    public function idempotencyKey(Request $request, SourceDefinition $source): ?string;

    /**
     * Extracts from the raw request body even when verification fails.
     */
    public function eventType(Request $request, SourceDefinition $source): ?string;
}
