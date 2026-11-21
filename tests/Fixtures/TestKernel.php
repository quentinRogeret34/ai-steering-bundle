<?php

namespace AiSteering\Tests\Fixtures;

use AiObservability\AiObservabilityBundle;
use AiObservability\Agent\TracedAgent;
use AiSteering\AiSteeringBundle;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig
     */
    public function __construct(
        private readonly array $bundleConfig = [],
        private readonly bool $profiler = false,
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new AiObservabilityBundle();
        yield new AiSteeringBundle();

        if ($this->profiler) {
            yield new TwigBundle();
            yield new WebProfilerBundle();
        }
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/ai-steering-bundle/'.md5(serialize([$this->bundleConfig, $this->profiler]));
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir().'/log';
    }

    /**
     * The agent as symfony/ai-bundle builds it: the steering processor first, then the system prompt of the app.
     */
    public function agent(Platform $platform, ?MessageStoreInterface $messageStore = null, ?string $appPrompt = null): TracedAgent
    {
        $container = $this->getContainer();
        $processors = [$container->get('test.input_processor')];
        if (null !== $appPrompt) {
            $processors[] = new SystemPromptInputProcessor($appPrompt);
        }

        $agent = new Agent($platform, 'gpt-test', inputProcessors: $processors, name: 'booking', eventDispatcher: $container->get('event_dispatcher'), messageStore: $messageStore);

        return new TracedAgent($agent, $container->get('test.tracer'), $container->get('test.span_stack'));
    }

    public function platform(string $answer): Platform
    {
        return MockPlatformFactory::createPlatform($answer, eventDispatcher: $this->getContainer()->get('test.service_container')->get('event_dispatcher'));
    }

    public function chat(): Response
    {
        return new Response($this->agent($this->platform('Room 12 is free.'))->call('A room?')->getContent());
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->setParameter('test.input_processors', $container->findTaggedServiceIds('ai.agent.input_processor'));
            }
        });
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['test' => true, 'http_method_override' => false, 'http_client' => true, 'profiler' => ['enabled' => $this->profiler, 'collect' => false]]);
        $container->extension('ai_observability', ['tracer_provider' => 'test.tracer_provider', 'capture_content' => true]);
        $container->extension('ai_steering', $this->bundleConfig);

        if ($this->profiler) {
            $container->extension('web_profiler', ['toolbar' => false]);
        }

        $services = $container->services();

        if ($this->bundleConfig['enabled'] ?? true) {
            $services->alias('test.input_processor', 'ai_steering.input_processor')->public();
        }

        $services
            ->alias('test.tracer', 'ai_observability.tracer')->public()
            ->alias('test.span_stack', 'ai_observability.span_stack')->public()
            ->set('test.span_exporter', InMemoryExporter::class)->public()
            ->set('test.tracer_provider', TracerProvider::class)->public()
                ->args([inline_service(SimpleSpanProcessor::class)->args([service('test.span_exporter')])]);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        if ($this->profiler) {
            $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.php')->prefix('/_profiler');
            $routes->import('@WebProfilerBundle/Resources/config/routing/wdt.php')->prefix('/_wdt');
            $routes->add('chat', '/chat')->controller('kernel::chat');
        }
    }
}
