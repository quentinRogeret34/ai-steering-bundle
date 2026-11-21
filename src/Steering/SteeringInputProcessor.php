<?php

namespace AiSteering\Steering;

use AiObservability\Tracing\SpanStack;
use AiSteering\Profiler\SteeringDataCollector;
use Symfony\AI\Agent\AgentAwareInterface;
use Symfony\AI\Agent\AgentAwareTrait;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Message\Message;

/**
 * Applies the model, system prompt and options set for the agent, from its experiment variant when it has one.
 *
 * It runs as an input processor, after the agent saved the input in its message store: the imposed system prompt is
 * sent to the model but never stored, so a conversation does not pile it up turn after turn. Registered before
 * SystemPromptInputProcessor, which leaves a message bag that already holds a system message as is.
 *
 * With ai-observability-bundle, the experiment, variant and prompt version go on the invoke_agent span.
 */
final class SteeringInputProcessor implements InputProcessorInterface, AgentAwareInterface
{
    use AgentAwareTrait;

    public function __construct(
        private readonly SteeringProvider $provider,
        private readonly ?SpanStack $stack = null,
        private readonly ?SteeringDataCollector $collector = null,
    ) {
    }

    public function processInput(Input $input): void
    {
        if (!isset($this->agent)) {
            return;
        }

        $name = $this->agent->getName();
        $messages = $input->getMessageBag();
        $conversationId = $messages->getId()->toRfc4122();
        $resolution = $this->provider->resolve($name, $conversationId);
        $this->collector?->addRun($name, $conversationId, $input->getModel(), $resolution);

        if (null === $resolution) {
            return;
        }

        $settings = $resolution->settings;

        if (null !== $settings->model) {
            $input->setModel($settings->model);
        }

        if (null !== $settings->systemPrompt) {
            $input->setMessageBag($messages->withSystemMessage(Message::forSystem($settings->systemPrompt)));
        }

        if ([] !== $settings->options) {
            $input->setOptions(array_replace($input->getOptions(), $settings->options));
        }

        $span = $this->stack?->agentSpan($name);
        if (null === $span) {
            return;
        }

        // Names kept from the time steering lived in ai-observability-bundle: the hub reads them
        if (null !== $settings->promptVersion) {
            $span->setAttribute('ai_observability.prompt.version', $settings->promptVersion);
        }

        if (null !== $resolution->experiment) {
            $span->setAttribute('ai_observability.experiment.id', $resolution->experiment);
            $span->setAttribute('ai_observability.experiment.variant', $resolution->variant);
        }
    }
}
