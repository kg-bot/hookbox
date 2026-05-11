<?php

declare(strict_types=1);

namespace Hookbox\Verifiers;

use Hookbox\Contracts\Verifier;
use Hookbox\Contracts\VerifierTransport;
use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\VerificationResult;
use Hookbox\Verifiers\Concerns\DecodesJsonPayload;
use Hookbox\Verifiers\Support\VerifierFailurePolicy;
use Hookbox\Verifiers\Support\VerifierTransportException;
use Illuminate\Http\Request;
use JsonException;

final class PayPalVerifier implements Verifier
{
    use DecodesJsonPayload;

    public function __construct(
        private readonly VerifierTransport $transport,
    ) {}

    public function verify(Request $request, SourceDefinition $source): VerificationResult
    {
        $verificationInput = $this->verificationInput($request, $source);

        if ($verificationInput === null) {
            return new VerificationResult(
                VerificationStatus::INVALID,
                $this->missingRequirementsReason($request, $source),
            );
        }

        $event = $this->webhookEvent($request);

        if ($event === null) {
            return new VerificationResult(VerificationStatus::INVALID, 'Malformed PayPal webhook payload.');
        }

        $accessToken = $this->accessToken($source, $verificationInput);

        if ($accessToken instanceof VerificationResult) {
            return $accessToken;
        }

        try {
            $response = $this->transport->send('POST', $verificationInput['verify_url'], [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$accessToken,
                ],
                'json' => [
                    'auth_algo' => $verificationInput['auth_algo'],
                    'cert_url' => $verificationInput['cert_url'],
                    'transmission_id' => $verificationInput['transmission_id'],
                    'transmission_sig' => $verificationInput['transmission_sig'],
                    'transmission_time' => $verificationInput['transmission_time'],
                    'webhook_id' => $verificationInput['webhook_id'],
                    'webhook_event' => $event,
                ],
            ]);
        } catch (VerifierTransportException $exception) {
            return VerifierFailurePolicy::forTransportFailure($source, $exception);
        }

        if ($response->status < 200 || $response->status >= 300) {
            return VerifierFailurePolicy::forProviderFailure(
                $source,
                sprintf('PayPal verification request failed with HTTP %d.', $response->status),
            );
        }

        $payload = $response->json();
        $status = is_array($payload) ? $payload['verification_status'] ?? null : null;

        if ($status === 'SUCCESS') {
            return new VerificationResult(VerificationStatus::VALID);
        }

        if ($status === 'FAILURE') {
            return new VerificationResult(VerificationStatus::INVALID, 'PayPal reported an invalid webhook signature.');
        }

        return VerifierFailurePolicy::forProviderFailure($source, 'PayPal verification response was malformed.');
    }

    public function idempotencyKey(Request $request, SourceDefinition $source): ?string
    {
        return $this->stringFromPayloadPath($request, 'id');
    }

    public function eventType(Request $request, SourceDefinition $source): ?string
    {
        return $this->stringFromPayloadPath($request, 'event_type');
    }

    /**
     * @return array{
     *     token_url: string,
     *     verify_url: string,
     *     client_id: string,
     *     client_secret: string,
     *     webhook_id: string,
     *     auth_algo: string,
     *     cert_url: string,
     *     transmission_id: string,
     *     transmission_sig: string,
     *     transmission_time: string
     * }|null
     */
    private function verificationInput(Request $request, SourceDefinition $source): ?array
    {
        $baseUrl = $this->stringConfig($source, 'base_url');
        $clientId = $this->stringConfig($source, 'client_id');
        $clientSecret = $this->stringConfig($source, 'client_secret');
        $webhookId = $this->stringConfig($source, 'webhook_id');
        $authAlgo = $this->requiredHeader($request, 'PAYPAL-AUTH-ALGO');
        $certUrl = $this->requiredHeader($request, 'PAYPAL-CERT-URL');
        $transmissionId = $this->requiredHeader($request, 'PAYPAL-TRANSMISSION-ID');
        $transmissionSig = $this->requiredHeader($request, 'PAYPAL-TRANSMISSION-SIG');
        $transmissionTime = $this->requiredHeader($request, 'PAYPAL-TRANSMISSION-TIME');

        if ($baseUrl === null || $clientId === null || $clientSecret === null || $webhookId === null) {
            return null;
        }

        if ($authAlgo === null || $certUrl === null || $transmissionId === null || $transmissionSig === null || $transmissionTime === null) {
            return null;
        }

        return [
            'token_url' => rtrim($baseUrl, '/').'/v1/oauth2/token',
            'verify_url' => rtrim($baseUrl, '/').'/v1/notifications/verify-webhook-signature',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'webhook_id' => $webhookId,
            'auth_algo' => $authAlgo,
            'cert_url' => $certUrl,
            'transmission_id' => $transmissionId,
            'transmission_sig' => $transmissionSig,
            'transmission_time' => $transmissionTime,
        ];
    }

    private function missingRequirementsReason(Request $request, SourceDefinition $source): string
    {
        $missing = [];

        foreach (['PAYPAL-AUTH-ALGO', 'PAYPAL-CERT-URL', 'PAYPAL-TRANSMISSION-ID', 'PAYPAL-TRANSMISSION-SIG', 'PAYPAL-TRANSMISSION-TIME'] as $header) {
            if ($this->requiredHeader($request, $header) === null) {
                $missing[] = $header;
            }
        }

        foreach (['base_url', 'client_id', 'client_secret', 'webhook_id'] as $configKey) {
            if ($this->stringConfig($source, $configKey) === null) {
                $missing[] = $configKey;
            }
        }

        return 'Missing required PayPal verification values: '.implode(', ', $missing).'.';
    }

    /**
     * @param  array{token_url: string, verify_url: string, client_id: string, client_secret: string, webhook_id: string, auth_algo: string, cert_url: string, transmission_id: string, transmission_sig: string, transmission_time: string}  $verificationInput
     */
    private function accessToken(SourceDefinition $source, array $verificationInput): string|VerificationResult
    {
        try {
            $response = $this->transport->send('POST', $verificationInput['token_url'], [
                'auth' => [$verificationInput['client_id'], $verificationInput['client_secret']],
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                ],
            ]);
        } catch (VerifierTransportException $exception) {
            return VerifierFailurePolicy::forTransportFailure($source, $exception);
        }

        if ($response->status < 200 || $response->status >= 300) {
            return VerifierFailurePolicy::forProviderFailure(
                $source,
                sprintf('PayPal token request failed with HTTP %d.', $response->status),
            );
        }

        $payload = $response->json();
        $accessToken = is_array($payload) ? $payload['access_token'] ?? null : null;

        if (! is_string($accessToken) || $accessToken === '') {
            return VerifierFailurePolicy::forProviderFailure($source, 'PayPal token response was malformed.');
        }

        return $accessToken;
    }

    private function requiredHeader(Request $request, string $name): ?string
    {
        $value = $request->headers->get($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function stringConfig(SourceDefinition $source, string $key): ?string
    {
        $value = $source->config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function webhookEvent(Request $request): ?array
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }
}
