<?php

namespace AiSteering\Tests;

use AiSteering\Steering\HubConfigLoader;
use AiSteering\Steering\Resolution;
use AiSteering\Steering\SteeringConfig;
use AiSteering\Steering\SteeringProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SteeringTest extends TestCase
{
    private const HUB_CONFIG = [
        'agents' => ['booking' => ['model' => 'gpt-hub', 'system_prompt' => 'Hub prompt.', 'prompt_version' => '4', 'options' => ['temperature' => 0.3]]],
        'experiments' => [],
    ];

    public function testHubSettingsReplaceTheConfiguredOnesAgentByAgent(): void
    {
        $static = SteeringConfig::fromArray(['agents' => [
            'booking' => ['model' => 'gpt-static'],
            'support' => ['model' => 'gpt-support'],
        ]]);

        $merged = $static->merge(SteeringConfig::fromArray(self::HUB_CONFIG));

        self::assertSame('gpt-hub', $merged->resolve('booking', 'c1')->settings->model);
        self::assertSame('gpt-support', $merged->resolve('support', 'c1')->settings->model);
        self::assertNull($merged->resolve('unknown', 'c1'));
    }

    public function testVariantPromptDropsTheVersionOfTheAgentPrompt(): void
    {
        $config = SteeringConfig::fromArray([
            'agents' => ['booking' => ['system_prompt' => 'Base.', 'prompt_version' => '3']],
            'experiments' => ['tone' => ['agent' => 'booking', 'variants' => ['warm' => ['system_prompt' => 'Warm.']]]],
        ]);

        $resolution = $config->resolve('booking', 'c1');

        self::assertSame('Warm.', $resolution->settings->systemPrompt);
        self::assertNull($resolution->settings->promptVersion);
        self::assertSame('tone', $resolution->experiment);
    }

    public function testResolutionTellsWhetherTheHubSetTheAgent(): void
    {
        $provider = new SteeringProvider(['agents' => [
            'booking' => ['model' => 'gpt-static'],
            'support' => ['model' => 'gpt-support'],
        ]], $this->loader(static fn (): JsonMockResponse => new JsonMockResponse(self::HUB_CONFIG)));

        self::assertSame(Resolution::SOURCE_HUB, $provider->resolve('booking', 'c1')->source);
        self::assertSame('gpt-hub', $provider->resolve('booking', 'c1')->settings->model);
        self::assertSame(Resolution::SOURCE_CONFIG, $provider->resolve('support', 'c1')->source);
    }

    public function testInvalidSettingsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SteeringConfig::fromArray(['agents' => ['booking' => ['model' => 42]]]);
    }

    public function testHubConfigurationIsCached(): void
    {
        $requests = 0;
        $loader = $this->loader(static function (string $method, string $url, array $options) use (&$requests): JsonMockResponse {
            ++$requests;
            self::assertSame('https://hub.test/api/steering', $url);
            self::assertContains('Authorization: Bearer secret', $options['headers']);

            return new JsonMockResponse(self::HUB_CONFIG, ['response_headers' => ['ETag' => '"v1"']]);
        });

        self::assertSame('gpt-hub', $loader->load()->resolve('booking', 'c1')->settings->model);
        self::assertSame(HubConfigLoader::STATE_FETCHED, $loader->status()['state']);
        self::assertSame('gpt-hub', $loader->load()->resolve('booking', 'c1')->settings->model);
        self::assertSame(HubConfigLoader::STATE_CACHED, $loader->status()['state']);
        self::assertSame(1, $requests);
    }

    public function testLastConfigurationIsKeptWhenTheHubIsDown(): void
    {
        $cache = new ArrayAdapter();
        $responses = [
            new JsonMockResponse(self::HUB_CONFIG),
            static fn (): never => throw new TransportException('Connection refused.'),
        ];
        $loader = $this->loader(static function () use (&$responses) {
            $response = array_shift($responses);

            return $response instanceof \Closure ? $response() : $response;
        }, $cache);

        $loader->load();
        $cache->deleteItem('ai_steering.hub_config');

        self::assertSame('gpt-hub', $loader->load()->resolve('booking', 'c1')->settings->model);
        self::assertSame(['state' => HubConfigLoader::STATE_FAILED, 'error' => 'Connection refused.', 'fallback' => true], $loader->status());
        self::assertSame([], $responses);
    }

    public function testNothingIsAppliedWhenTheHubWasNeverReached(): void
    {
        $loader = $this->loader(static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));

        self::assertNull($loader->load());
        self::assertNull((new SteeringProvider([], $loader))->resolve('booking', 'c1'));
    }

    public function testInvalidHubPayloadIsIgnored(): void
    {
        $loader = $this->loader(static fn (): JsonMockResponse => new JsonMockResponse(['agents' => ['booking' => ['model' => ['not', 'a', 'string']]]]));

        self::assertNull($loader->load());
    }

    public function testNotModifiedReusesTheLastConfiguration(): void
    {
        $cache = new ArrayAdapter();
        $responses = [
            new JsonMockResponse(self::HUB_CONFIG, ['response_headers' => ['ETag' => '"v1"']]),
            new MockResponse('', ['http_code' => 304]),
        ];
        $loader = $this->loader(static function (string $method, string $url, array $options) use (&$responses): MockResponse {
            if (1 === \count($responses)) {
                self::assertContains('If-None-Match: "v1"', $options['headers']);
            }

            return array_shift($responses);
        }, $cache);

        $loader->load();
        $cache->deleteItem('ai_steering.hub_config');

        self::assertSame('gpt-hub', $loader->load()->resolve('booking', 'c1')->settings->model);
        self::assertSame(HubConfigLoader::STATE_NOT_MODIFIED, $loader->status()['state']);
    }

    public function testEmptyHubUrlDisablesTheHub(): void
    {
        $loader = new HubConfigLoader(new MockHttpClient(static fn () => self::fail('The hub should not be called.')), new ArrayAdapter(), '', null);

        self::assertNull($loader->load());
    }

    private function loader(callable $responseFactory, ArrayAdapter $cache = new ArrayAdapter()): HubConfigLoader
    {
        return new HubConfigLoader(new MockHttpClient($responseFactory), $cache, 'https://hub.test/', 'secret');
    }
}
