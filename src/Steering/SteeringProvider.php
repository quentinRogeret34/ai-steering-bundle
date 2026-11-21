<?php

namespace AiSteering\Steering;

/**
 * The configuration in force: the one of the bundle, overridden agent by agent by the hub when it is connected.
 */
final readonly class SteeringProvider
{
    private SteeringConfig $static;

    /**
     * @param array<mixed> $config agents and experiments of the bundle configuration
     */
    public function __construct(
        array $config,
        private ?HubConfigLoader $hub = null,
    ) {
        $this->static = SteeringConfig::fromArray($config);
    }

    public function resolve(string $agent, string $conversationId): ?Resolution
    {
        $hub = $this->hub?->load();

        if (null === $hub || !$hub->defines($agent)) {
            return $this->static->resolve($agent, $conversationId);
        }

        $resolution = $this->static->merge($hub)->resolve($agent, $conversationId);

        return null === $resolution ? null : new Resolution($resolution->settings, $resolution->experiment, $resolution->variant, Resolution::SOURCE_HUB);
    }
}
