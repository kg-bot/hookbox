<?php

declare(strict_types=1);

namespace Hookbox\Contracts;

use Hookbox\Verifiers\Support\VerifierTransportResponse;

interface VerifierTransport
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function send(string $method, string $url, array $options = []): VerifierTransportResponse;
}
