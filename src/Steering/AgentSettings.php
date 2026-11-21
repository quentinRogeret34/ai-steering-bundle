<?php

namespace AiSteering\Steering;

/**
 * What the bundle imposes on an agent run: every null field leaves the agent's own value.
 */
final readonly class AgentSettings
{
    /**
     * @param array<string, mixed> $options merged over the options of the call, e.g. temperature or max_tokens
     */
    public function __construct(
        public ?string $model = null,
        public ?string $systemPrompt = null,
        public ?string $promptVersion = null,
        public array $options = [],
    ) {
    }

    /**
     * @param array<mixed> $settings the shape of the bundle configuration and of the hub API: model, system_prompt, prompt_version, options
     */
    public static function fromArray(array $settings, string $path): self
    {
        foreach (['model', 'system_prompt', 'prompt_version'] as $key) {
            if (null !== ($settings[$key] ?? null) && (!\is_string($settings[$key]) || '' === $settings[$key])) {
                throw new \InvalidArgumentException(\sprintf('"%s.%s" must be a non-empty string or null.', $path, $key));
            }
        }

        $options = $settings['options'] ?? [];
        if (!\is_array($options) || ([] !== $options && array_is_list($options))) {
            throw new \InvalidArgumentException(\sprintf('"%s.options" must be a map of option names to values.', $path));
        }

        return new self($settings['model'] ?? null, $settings['system_prompt'] ?? null, $settings['prompt_version'] ?? null, $options);
    }

    /**
     * The fields set in $override win. A prompt brings its own version, so the version of this prompt is not kept for another one.
     */
    public function with(self $override): self
    {
        return new self(
            $override->model ?? $this->model,
            $override->systemPrompt ?? $this->systemPrompt,
            null !== $override->systemPrompt ? $override->promptVersion : $override->promptVersion ?? $this->promptVersion,
            array_replace($this->options, $override->options),
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->model && null === $this->systemPrompt && [] === $this->options;
    }
}
