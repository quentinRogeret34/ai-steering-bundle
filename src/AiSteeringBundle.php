<?php

namespace AiSteering;

use AiSteering\DependencyInjection\ProfilerPass;
use AiSteering\Profiler\SteeringDataCollector;
use AiSteering\Steering\HubConfigLoader;
use AiSteering\Steering\SteeringInputProcessor;
use AiSteering\Steering\SteeringProvider;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class AiSteeringBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->canBeDisabled()
            ->children()
                ->arrayNode('agents')
                    ->info('Model, system prompt and options imposed on an agent, keyed by agent name (AgentInterface::getName()). The hub overrides them when connected.')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('model')->defaultNull()->end()
                            ->scalarNode('system_prompt')->defaultNull()->end()
                            ->scalarNode('prompt_version')->defaultNull()->end()
                            ->arrayNode('options')
                                ->info('Options merged over the ones of the call, e.g. temperature or max_tokens.')
                                ->normalizeKeys(false)
                                ->variablePrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('experiments')
                    ->info('A/B tests keyed by experiment id. Each conversation gets one variant, which may change the system prompt, the model and the options.')
                    ->useAttributeAsKey('id')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('agent')
                                ->info('Name of the agent, as returned by AgentInterface::getName().')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->arrayNode('variants')
                                ->isRequired()
                                ->requiresAtLeastOneElement()
                                ->useAttributeAsKey('name')
                                ->arrayPrototype()
                                    ->children()
                                        ->integerNode('weight')->defaultValue(1)->min(0)->end()
                                        ->scalarNode('model')->defaultNull()->end()
                                        ->scalarNode('system_prompt')->defaultNull()->end()
                                        ->scalarNode('prompt_version')->defaultNull()->end()
                                        ->arrayNode('options')
                                            ->info('Options merged over the ones of the call, e.g. temperature or max_tokens.')
                                            ->normalizeKeys(false)
                                            ->variablePrototype()->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('hub')
                    ->info('Reads the agent settings and experiments managed in the hub. Without a url, only the ones above apply.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('url')->info('Base url of the hub, e.g. "%env(AI_HUB_URL)%".')->defaultNull()->end()
                        ->scalarNode('token')->info('Bearer token of the hub API (HUB_STEERING_TOKEN on the hub side).')->defaultNull()->end()
                        ->integerNode('cache_ttl')->info('Seconds a configuration read from the hub is kept before asking again.')->defaultValue(60)->min(1)->end()
                        ->floatNode('timeout')->info('Seconds to wait for the hub, the last configuration received is used beyond.')->defaultValue(2.0)->min(0.1)->end()
                        ->scalarNode('http_client')->defaultValue('http_client')->end()
                        ->scalarNode('cache')->defaultValue('cache.app')->end()
                    ->end()
                ->end()
            ->end();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new ProfilerPass());
    }

    /**
     * @param array{enabled: bool, agents: array<string, array<string, mixed>>, experiments: array<string, array<string, mixed>>, hub: array{url: ?string, token: ?string, cache_ttl: int, timeout: float, http_client: string, cache: string}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$config['enabled']) {
            return;
        }

        $services = $container->services();

        $hub = null;
        if (null !== $config['hub']['url']) {
            $services->set('ai_steering.hub_config_loader', HubConfigLoader::class)
                ->args([
                    service($config['hub']['http_client']),
                    service($config['hub']['cache']),
                    $config['hub']['url'],
                    $config['hub']['token'],
                    $config['hub']['cache_ttl'],
                    $config['hub']['timeout'],
                    service('logger')->nullOnInvalid(),
                ])
                ->tag('monolog.logger', ['channel' => 'ai_steering'])
                ->tag('kernel.reset', ['method' => 'reset']);
            $hub = service('ai_steering.hub_config_loader');
        }

        $services->set('ai_steering.provider', SteeringProvider::class)
            ->args([['agents' => $config['agents'], 'experiments' => $config['experiments']], $hub]);

        // Before SystemPromptInputProcessor (-30) and MemoryInputProcessor (-40) of symfony/ai-bundle, on every agent
        $services->set('ai_steering.input_processor', SteeringInputProcessor::class)
            ->args([service('ai_steering.provider'), service('ai_observability.span_stack')->nullOnInvalid(), null])
            ->tag('ai.agent.input_processor', ['priority' => 0]);
        $services->alias(SteeringInputProcessor::class, 'ai_steering.input_processor');

        $services->set('ai_steering.data_collector', SteeringDataCollector::class)
            ->args([$hub])
            ->tag('data_collector', ['id' => SteeringDataCollector::NAME, 'template' => SteeringDataCollector::TEMPLATE]);
    }
}
