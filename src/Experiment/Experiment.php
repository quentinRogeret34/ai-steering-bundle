<?php

namespace AiSteering\Experiment;

use AiSteering\Steering\AgentSettings;

/**
 * A/B test on one agent: each conversation gets one variant, picked by weight. A variant may change the system prompt,
 * the model and the options; a variant that changes nothing keeps the settings of the agent, as a control.
 */
final readonly class Experiment
{
    private int $totalWeight;

    /**
     * @param non-empty-array<string, array{weight: int, settings: AgentSettings}> $variants
     */
    public function __construct(
        public string $id,
        public string $agent,
        private array $variants,
    ) {
        $this->totalWeight = array_sum(array_column($variants, 'weight'));
        if ($this->totalWeight <= 0) {
            throw new \InvalidArgumentException(\sprintf('The variants of experiment "%s" need a total weight above 0.', $id));
        }
    }

    /**
     * @param array<mixed> $experiment the shape of the bundle configuration and of the hub API
     */
    public static function fromArray(string $id, array $experiment): self
    {
        if (!\is_string($experiment['agent'] ?? null) || '' === $experiment['agent']) {
            throw new \InvalidArgumentException(\sprintf('Experiment "%s" needs an agent.', $id));
        }

        if (!\is_array($experiment['variants'] ?? null) || [] === $experiment['variants']) {
            throw new \InvalidArgumentException(\sprintf('Experiment "%s" needs at least one variant.', $id));
        }

        $variants = [];
        foreach ($experiment['variants'] as $name => $variant) {
            $weight = $variant['weight'] ?? 1;
            if (!\is_array($variant) || !\is_int($weight) || $weight < 0) {
                throw new \InvalidArgumentException(\sprintf('Variant "%s" of experiment "%s" needs a weight of 0 or more.', $name, $id));
            }

            $variants[(string) $name] = ['weight' => $weight, 'settings' => AgentSettings::fromArray($variant, $id.'.variants.'.$name)];
        }

        return new self($id, $experiment['agent'], $variants);
    }

    /**
     * The same key always gets the same variant, so a conversation keeps its variant from one turn to the next.
     */
    public function assign(string $key): string
    {
        $point = hexdec(substr(hash('xxh3', $this->id.'|'.$key), 0, 8)) % $this->totalWeight;

        foreach ($this->variants as $name => $variant) {
            if ($point < $variant['weight']) {
                return $name;
            }

            $point -= $variant['weight'];
        }

        return array_key_last($this->variants);
    }

    public function settings(string $variant): AgentSettings
    {
        return $this->variants[$variant]['settings'];
    }
}
