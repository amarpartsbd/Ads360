<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Services;

use App\Domains\Assistant\Contracts\AssistantProvider;
use App\Domains\Assistant\Providers\MockAssistantProvider;
use RuntimeException;

/**
 * Resolves the assistant adapter (spec §45, §95).
 *
 * The same shape as ProviderManager, and for the same reasons — which
 * implementation answers is a configuration decision, and a driver with no live
 * adapter is refused rather than quietly falling back to a mock.
 *
 * That refusal is the important line here. A mock advertising adapter answering
 * in production reports campaigns as published when nothing was sent, and a
 * client eventually asks why. A mock assistant answering in production writes
 * ad copy that gets published to real audiences under a client's name, and
 * nobody may ever notice.
 */
final class AssistantManager
{
    private ?AssistantProvider $resolved = null;

    /** @var (callable(): AssistantProvider)|null */
    private $custom = null;

    /** Whether an assistant is available at all right now. */
    public function isAvailable(): bool
    {
        return (bool) config('platform.features.ai_assistant')
            && (string) config('platform.assistant.driver', 'none') !== 'none';
    }

    /**
     * @throws RuntimeException when no assistant is configured
     */
    public function provider(): AssistantProvider
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException(
                'No assistant is configured. Enable FEATURE_AI_ASSISTANT and set ASSISTANT_DRIVER.'
            );
        }

        return $this->resolved ??= $this->build();
    }

    /** Register an adapter directly. Used by tests and by a future live driver. */
    public function extend(callable $factory): void
    {
        $this->custom = $factory;
        $this->resolved = null;
    }

    private function build(): AssistantProvider
    {
        if ($this->custom !== null) {
            return ($this->custom)();
        }

        $driver = (string) config('platform.assistant.driver', 'none');

        return match ($driver) {
            'mock' => new MockAssistantProvider,
            default => throw new RuntimeException(
                "No assistant adapter exists for [{$driver}] yet. "
                .'It is set in configuration but not implemented.'
            ),
        };
    }
}
