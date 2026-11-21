<?php

namespace AiSteering\Steering;

use AiSteering\Experiment\Experiment;

/**
 * Settings and experiments of the agents, from the bundle configuration or from the hub.
 */
final readonly class SteeringConfig
{
    /**
     * @param array<string, AgentSettings> $agents      keyed by agent name
     * @param array<string, Experiment>    $experiments keyed by agent name
     */
    private function __construct(
        private array $agents = [],
        private array $experiments = [],
    ) {
    }

    /**
     * @param array<mixed> $config {agents: {name: settings}, experiments: {id: {agent, variants}}}
     */
    public static function fromArray(array $config): self
    {
        $agents = $experiments = [];

        foreach ($config['agents'] ?? [] as $name => $settings) {
            if (!\is_array($settings)) {
                throw new \InvalidArgumentException(\sprintf('The settings of agent "%s" must be a map.', $name));
            }

            $agents[(string) $name] = AgentSettings::fromArray($settings, 'agents.'.$name);
        }

        foreach ($config['experiments'] ?? [] as $id => $experiment) {
            $experiment = Experiment::fromArray((string) $id, \is_array($experiment) ? $experiment : []);

            if (isset($experiments[$experiment->agent])) {
                throw new \InvalidArgumentException(\sprintf('Experiments "%s" and "%s" both target agent "%s": run one at a time.', $experiments[$experiment->agent]->id, $id, $experiment->agent));
            }

            $experiments[$experiment->agent] = $experiment;
        }

        return new self($agents, $experiments);
    }

    /**
     * The hub wins agent by agent: its settings replace the configured ones, and so does its experiment.
     */
    public function merge(self $hub): self
    {
        return new self(array_replace($this->agents, $hub->agents), array_replace($this->experiments, $hub->experiments));
    }

    public function defines(string $agent): bool
    {
        return isset($this->agents[$agent]) || isset($this->experiments[$agent]);
    }

    public function resolve(string $agent, string $conversationId): ?Resolution
    {
        $settings = $this->agents[$agent] ?? new AgentSettings();
        $experiment = $this->experiments[$agent] ?? null;

        if (null === $experiment) {
            return $settings->isEmpty() ? null : new Resolution($settings);
        }

        $variant = $experiment->assign($conversationId);

        return new Resolution($settings->with($experiment->settings($variant)), $experiment->id, $variant);
    }
}
