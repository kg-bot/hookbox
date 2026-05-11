<?php

declare(strict_types=1);

namespace Hookbox\Verifiers\Support;

use Hookbox\Contracts\VerifierTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class VerifierHttpClient implements VerifierTransport
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function send(string $method, string $url, array $options = []): VerifierTransportResponse
    {
        try {
            $response = Http::send($method, $url, $options);
        } catch (ConnectionException $exception) {
            throw new VerifierTransportException($exception->getMessage(), previous: $exception);
        }

        return new VerifierTransportResponse(
            status: $response->status(),
            body: $response->body(),
        );
    }
}
