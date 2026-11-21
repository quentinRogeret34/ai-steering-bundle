<?php

namespace AiSteering\Steering;

/**
 * The settings applied to one agent run, with the experiment variant they come from.
 */
final readonly class Resolution
{
    public const SOURCE_CONFIG = 'config';
    public const SOURCE_HUB = 'hub';

    /**
     * @param self::SOURCE_* $source hub when the hub sets the settings or the experiment of the agent
     */
    public function __construct(
        public AgentSettings $settings,
        public ?string $experiment = null,
        public ?string $variant = null,
        public string $source = self::SOURCE_CONFIG,
    ) {
    }
}
