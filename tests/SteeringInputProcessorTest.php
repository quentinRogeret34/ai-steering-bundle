<?php

namespace AiSteering\Tests;

use AiSteering\Tests\Fixtures\TestKernel;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class SteeringInputProcessorTest extends KernelTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['config'] ?? []);
    }

    public function testExperimentReplacesTheSystemPromptAndTagsTheAgentSpan(): void
    {
        $kernel = $this->boot(['experiments' => ['booking_prompt' => [
            'agent' => 'booking',
            'variants' => [
                'control' => ['system_prompt' => 'You book hotel rooms.', 'weight' => 0],
                'concise' => ['system_prompt' => 'You book hotel rooms. Answer in one sentence.', 'weight' => 1],
            ],
        ]]]);
        $messages = new MessageBag(Message::forSystem('Prompt of the app.'), Message::ofUser('A room for tonight?'));

        $kernel->agent($kernel->platform('Room 12 is free.'))->call($messages)->getContent();

        [$chat, $agentSpan] = $this->exportedSpans();
        self::assertSame('You book hotel rooms. Answer in one sentence.', $this->systemPrompt($chat));
        self::assertSame('booking_prompt', $agentSpan->getAttributes()->get('ai_observability.experiment.id'));
        self::assertSame('concise', $agentSpan->getAttributes()->get('ai_observability.experiment.variant'));
        self::assertSame($messages->getId()->toRfc4122(), $agentSpan->getAttributes()->get('gen_ai.conversation.id'));
    }

    public function testStringInputGetsAVariantToo(): void
    {
        $kernel = $this->boot(['experiments' => ['booking_prompt' => [
            'agent' => 'booking',
            'variants' => ['concise' => ['system_prompt' => 'Answer in one sentence.']],
        ]]]);

        $kernel->agent($kernel->platform('Room 12 is free.'))->call('A room for tonight?')->getContent();

        [, $agentSpan] = $this->exportedSpans();
        self::assertSame('concise', $agentSpan->getAttributes()->get('ai_observability.experiment.variant'));
    }

    public function testAgentWithoutSettingsKeepsItsPrompt(): void
    {
        $kernel = $this->boot();

        $kernel->agent($kernel->platform('Room 12 is free.'))->call(new MessageBag(Message::forSystem('Prompt of the app.'), Message::ofUser('A room?')))->getContent();

        [$chat, $agentSpan] = $this->exportedSpans();
        self::assertSame('Prompt of the app.', $this->systemPrompt($chat));
        self::assertNull($agentSpan->getAttributes()->get('ai_observability.experiment.id'));
    }

    public function testAgentSettingsImposeModelPromptAndOptions(): void
    {
        $kernel = $this->boot(['agents' => ['booking' => [
            'model' => 'gpt-steered',
            'system_prompt' => 'You book hotel rooms in French.',
            'prompt_version' => 'v3',
            'options' => ['temperature' => 0.1],
        ]]]);

        $kernel->agent($kernel->platform('Chambre 12.'), appPrompt: 'Prompt of the app.')->call('A room?')->getContent();

        [$chat, $agentSpan] = $this->exportedSpans();
        self::assertSame('chat gpt-steered', $chat->getName());
        self::assertSame(0.1, $chat->getAttributes()->get('gen_ai.request.temperature'));
        self::assertSame('You book hotel rooms in French.', $this->systemPrompt($chat));
        self::assertSame('v3', $agentSpan->getAttributes()->get('ai_observability.prompt.version'));
        self::assertNull($agentSpan->getAttributes()->get('ai_observability.experiment.id'));
    }

    public function testVariantOverridesTheAgentSettings(): void
    {
        $kernel = $this->boot([
            'agents' => ['booking' => ['model' => 'gpt-steered', 'system_prompt' => 'Base prompt.', 'prompt_version' => 'v3']],
            'experiments' => ['booking_model' => ['agent' => 'booking', 'variants' => [
                'control' => ['weight' => 0],
                'small' => ['model' => 'gpt-small'],
            ]]],
        ]);

        $kernel->agent($kernel->platform('Room 12.'))->call('A room?')->getContent();

        [$chat, $agentSpan] = $this->exportedSpans();
        self::assertSame('gpt-small', $chat->getAttributes()->get('gen_ai.request.model'));
        self::assertSame('Base prompt.', $this->systemPrompt($chat));
        self::assertSame('v3', $agentSpan->getAttributes()->get('ai_observability.prompt.version'));
        self::assertSame('small', $agentSpan->getAttributes()->get('ai_observability.experiment.variant'));
    }

    public function testImposedPromptIsNotSavedInTheMessageStore(): void
    {
        $kernel = $this->boot(['agents' => ['booking' => ['system_prompt' => 'Steered prompt.']]]);
        $store = new InMemoryMessageStore();
        $agent = $kernel->agent($kernel->platform('Room 12 is free.'), $store);
        $messages = new MessageBag(Message::ofUser('A room for tonight?'));

        $agent->call($messages)->getContent();
        $agent->call(MessageBag::forId($messages->getId(), Message::ofUser('And tomorrow?')))->getContent();

        $stored = $store->load($messages->getId());
        self::assertCount(4, $stored->getMessages());
        self::assertSame([], array_filter($stored->getMessages(), static fn ($message): bool => $message instanceof SystemMessage));
    }

    public function testProcessorIsRegisteredForEveryAgent(): void
    {
        $this->boot();

        self::assertSame(
            ['ai_steering.input_processor' => [['priority' => 0]]],
            self::getContainer()->getParameter('test.input_processors'),
        );
    }

    public function testNothingIsRegisteredWhenDisabled(): void
    {
        $this->boot(['enabled' => false]);

        self::assertFalse(self::getContainer()->has('ai_steering.input_processor'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config = []): TestKernel
    {
        $kernel = self::bootKernel(['config' => $config]);
        \assert($kernel instanceof TestKernel);

        return $kernel;
    }

    private function systemPrompt(ImmutableSpan $chat): ?string
    {
        return json_decode($chat->getAttributes()->get('gen_ai.system_instructions') ?? 'null', true)[0]['content'] ?? null;
    }

    /**
     * @return list<ImmutableSpan>
     */
    private function exportedSpans(): array
    {
        return array_values(self::getContainer()->get('test.span_exporter')->getSpans());
    }
}
