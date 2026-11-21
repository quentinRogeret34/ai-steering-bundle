<?php

namespace AiSteering\Tests;

use AiSteering\Experiment\Experiment;
use AiSteering\Steering\SteeringConfig;
use PHPUnit\Framework\TestCase;

final class ExperimentTest extends TestCase
{
    public function testSameKeyAlwaysGetsTheSameVariant(): void
    {
        $experiment = $this->experiment(['a' => 1, 'b' => 1]);

        self::assertSame($experiment->assign('conversation-1'), $experiment->assign('conversation-1'));
    }

    public function testVariantsAreSplitByWeight(): void
    {
        $experiment = $this->experiment(['a' => 3, 'b' => 1]);
        $counts = ['a' => 0, 'b' => 0];

        for ($i = 0; $i < 4000; ++$i) {
            ++$counts[$experiment->assign('conversation-'.$i)];
        }

        self::assertEqualsWithDelta(3000, $counts['a'], 150);
    }

    public function testVariantWithoutWeightIsNeverAssigned(): void
    {
        $experiment = $this->experiment(['a' => 0, 'b' => 1]);

        for ($i = 0; $i < 100; ++$i) {
            self::assertSame('b', $experiment->assign('conversation-'.$i));
        }
    }

    public function testTotalWeightOfZeroIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->experiment(['a' => 0]);
    }

    public function testOneExperimentPerAgent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SteeringConfig::fromArray(['experiments' => [
            'first' => ['agent' => 'booking', 'variants' => ['a' => ['system_prompt' => 'A', 'weight' => 1]]],
            'second' => ['agent' => 'booking', 'variants' => ['a' => ['system_prompt' => 'A', 'weight' => 1]]],
        ]]);
    }

    /**
     * @param array<string, int> $weights
     */
    private function experiment(array $weights): Experiment
    {
        return Experiment::fromArray('booking_prompt', ['agent' => 'booking', 'variants' => array_map(static fn (int $weight): array => ['system_prompt' => 'Prompt', 'weight' => $weight], $weights)]);
    }
}
