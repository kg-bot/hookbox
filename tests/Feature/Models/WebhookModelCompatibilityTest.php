<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Models;

use Hookbox\Models\WebhookAttempt;
use Hookbox\Models\WebhookMessage;
use Hookbox\Models\WebhookMessageReceipt;
use Hookbox\Models\WebhookSource;
use Hookbox\Tests\TestCase;
use ReflectionClass;

final class WebhookModelCompatibilityTest extends TestCase
{
    public function test_models_define_local_casts_properties_for_laravel_9_and_10_compatibility(): void
    {
        $this->assertLocalCastsProperty(WebhookAttempt::class, [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'int',
        ]);

        $this->assertLocalCastsProperty(WebhookMessage::class, [
            'headers' => 'array',
            'received_at' => 'datetime',
            'redacted_at' => 'datetime',
        ]);

        $this->assertLocalCastsProperty(WebhookMessageReceipt::class, [
            'headers' => 'array',
        ]);

        $this->assertLocalCastsProperty(WebhookSource::class, [
            'config' => 'array',
            'is_active' => 'bool',
        ]);
    }

    /**
     * @param  class-string  $modelClass
     * @param  array<string, string>  $expectedCasts
     */
    private function assertLocalCastsProperty(string $modelClass, array $expectedCasts): void
    {
        $reflection = new ReflectionClass($modelClass);

        $this->assertTrue($reflection->hasProperty('casts'));

        $property = $reflection->getProperty('casts');

        $this->assertSame($modelClass, $property->getDeclaringClass()->getName());

        if ($reflection->hasMethod('casts')) {
            $this->assertNotSame($modelClass, $reflection->getMethod('casts')->getDeclaringClass()->getName());
        }

        $this->assertSame($expectedCasts, $property->getDefaultValue());
    }
}
