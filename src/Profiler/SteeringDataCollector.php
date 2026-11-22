<?php

namespace AiSteering\Profiler;

use AiSteering\Steering\HubConfigLoader;
use AiSteering\Steering\Resolution;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

/**
 * What the steering changed on each agent run of the request, and how the hub answered. Read once the response is sent,
 * so the runs of a streamed answer are there.
 */
final class SteeringDataCollector extends DataCollector implements LateDataCollectorInterface
{
    public const NAME = 'ai_steering';
    public const TEMPLATE = '@AiSteering/Collector/steering.html.twig';

    /** @var list<array{agent: string, conversation_id: string, requested_model: string, resolution: ?Resolution}> */
    private array $runs = [];

    public function __construct(
        private readonly ?HubConfigLoader $hub = null,
    ) {
    }

    public function addRun(string $agent, string $conversationId, string $requestedModel, ?Resolution $resolution): void
    {
        $this->runs[] = ['agent' => $agent, 'conversation_id' => $conversationId, 'requested_model' => $requestedModel, 'resolution' => $resolution];
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
    }

    public function lateCollect(): void
    {
        $this->data = [
            'runs' => array_map(static fn (array $run): array => [
                'agent' => $run['agent'],
                'conversation_id' => $run['conversation_id'],
                'requested_model' => $run['requested_model'],
                'source' => $run['resolution']?->source,
                'model' => $run['resolution']?->settings->model,
                'system_prompt' => $run['resolution']?->settings->systemPrompt,
                'prompt_version' => $run['resolution']?->settings->promptVersion,
                'options' => $run['resolution']->settings->options ?? [],
                'experiment' => $run['resolution']?->experiment,
                'variant' => $run['resolution']?->variant,
            ], $this->runs),
            'hub' => null === $this->hub ? null : ['url' => self::redactUrl($this->hub->getUrl())] + ($this->hub->status() ?? ['state' => null, 'error' => null, 'fallback' => false]),
        ];
    }

    private static function redactUrl(string $url): string
    {
        return (string) preg_replace('#^(https?://)[^/@]+@#i', '$1***@', $url);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function reset(): void
    {
        parent::reset();
        $this->runs = [];
    }

    /**
     * @return list<array{agent: string, conversation_id: string, requested_model: string, source: ?string, model: ?string, system_prompt: ?string, prompt_version: ?string, options: array<string, mixed>, experiment: ?string, variant: ?string}>
     */
    public function getRuns(): array
    {
        return $this->data['runs'] ?? [];
    }

    public function getSteeredCount(): int
    {
        return \count(array_filter($this->getRuns(), static fn (array $run): bool => null !== $run['source']));
    }

    /**
     * @return array{url: string, state: ?string, error: ?string, fallback: bool}|null null without a hub
     */
    public function getHub(): ?array
    {
        return $this->data['hub'] ?? null;
    }
}
