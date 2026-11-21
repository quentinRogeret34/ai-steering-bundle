<?php

namespace AiSteering\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Hands the agent runs to the profiler only when it is on, so nothing is kept in memory otherwise.
 */
final class ProfilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('ai_steering.input_processor') && $container->hasDefinition('profiler')) {
            $container->getDefinition('ai_steering.input_processor')->replaceArgument(2, new Reference('ai_steering.data_collector'));
        }
    }
}
