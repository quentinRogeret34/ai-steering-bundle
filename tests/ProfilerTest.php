<?php

namespace AiSteering\Tests;

use AiSteering\Profiler\SteeringDataCollector;
use AiSteering\Steering\Resolution;
use AiSteering\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class ProfilerTest extends WebTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel([
            'agents' => ['booking' => ['model' => 'gpt-steered', 'system_prompt' => 'You book hotel rooms.', 'prompt_version' => 'v3']],
            'experiments' => ['booking_tone' => ['agent' => 'booking', 'variants' => ['concise' => ['options' => ['max_tokens' => 50]]]]],
            'hub' => ['url' => 'http://127.0.0.1:9/', 'timeout' => 0.1],
        ], profiler: true);
    }

    public function testRunsAndHubAreShownInTheProfiler(): void
    {
        $client = self::createClient();
        $client->enableProfiler();

        $client->request('GET', '/chat');

        self::assertResponseIsSuccessful();
        /** @var SteeringDataCollector $collector */
        $collector = $client->getProfile()->getCollector(SteeringDataCollector::NAME);
        [$run] = $collector->getRuns();
        self::assertSame('booking', $run['agent']);
        self::assertSame('gpt-test', $run['requested_model']);
        self::assertSame('gpt-steered', $run['model']);
        self::assertSame(Resolution::SOURCE_CONFIG, $run['source']);
        self::assertSame(['booking_tone', 'concise'], [$run['experiment'], $run['variant']]);
        self::assertSame(['max_tokens' => 50], $run['options']);
        self::assertSame('failed', $collector->getHub()['state']);
        self::assertFalse($collector->getHub()['fallback']);

        $token = $client->getProfile()->getToken();
        $client->request('GET', '/_wdt/'.$token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sf-toolbar-block-'.SteeringDataCollector::NAME, '1 steered');

        $client->request('GET', '/_profiler/'.$token.'?panel='.SteeringDataCollector::NAME);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#collector-content h2', 'AI steering');
        self::assertSelectorTextContains('#collector-content', 'booking_tone / concise');
        self::assertSelectorTextContains('#collector-content', 'version v3');
    }
}
