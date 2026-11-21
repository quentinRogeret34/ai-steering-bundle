# AI Steering Bundle

Steering for [Symfony AI](https://github.com/symfony/ai) agents: imposes their model, system prompt and options, and runs
A/B tests of prompts and models, from the bundle configuration or from the
[hub](https://github.com/quentinRogeret34/ai-observability-hub). The agent code does not change.

With [ai-observability-bundle](https://github.com/quentinRogeret34/ai-observability-bundle), the `invoke_agent` span of
each run carries the experiment, the variant and the prompt version, so the traces tell which settings produced an answer.

## Installation

```bash
composer require quentinrogeret34/ai-steering-bundle
```

Register the bundle in `config/bundles.php` if Flex did not:

```php
AiSteering\AiSteeringBundle::class => ['all' => true],
```

## Agent settings

Model, system prompt and options of an agent, imposed on every run:

```yaml
# config/packages/ai_steering.yaml
ai_steering:
    agents:
        booking:                      # AgentInterface::getName()
            model: gpt-4o-mini
            system_prompt: 'You book hotel rooms.'
            prompt_version: 'v3'      # optional, recorded on the span
            options: { temperature: 0.2, max_tokens: 400 }
```

Every field is optional: a missing one leaves the value of the agent. The options are merged over the ones of the call.

## Experiments

A/B tests on an agent. Each conversation (the id of the `MessageBag`) gets one variant, picked by weight and kept from
one turn to the next. A variant may change the system prompt, the model and the options; a variant that changes nothing
keeps the settings of the agent.

```yaml
ai_steering:
    experiments:
        booking_tone:
            agent: booking
            variants:
                control: { }
                concise: { system_prompt: 'You book hotel rooms. Answer in one sentence.' }
                small: { model: gpt-4o-mini, weight: 2 }
```

The variant applies over the agent settings. A variant that brings its own system prompt drops the `prompt_version` of the
agent one. The hub takes the variant named `control` as reference. One experiment per agent at a time.

## From the hub

```yaml
ai_steering:
    hub:
        url: '%env(AI_HUB_URL)%'
        token: '%env(AI_HUB_STEERING_TOKEN)%'   # HUB_STEERING_TOKEN of the hub
        # cache_ttl: 60                         # seconds between two reads
        # timeout: 2                            # seconds, beyond which the last configuration received is used
        # http_client: http_client
        # cache: cache.app
```

The bundle reads `GET /api/steering` at most once per `cache_ttl` and never fails an agent run because of the hub: when it
is down, the last configuration received keeps applying, and the hub is asked again after 10 seconds. The settings and
experiments of the hub replace the ones of the configuration, agent by agent. An empty `url` turns the hub off.

## How it is applied

`SteeringInputProcessor` is registered as an input processor of every agent of symfony/ai-bundle, before
`SystemPromptInputProcessor`. It runs after the agent saved the input in its message store, so the imposed system prompt is
sent to the model without being stored: agents with a message store keep a clean conversation. It replaces the system
prompt of the application, tool definitions included (`include_tools`).

An agent built by hand needs it in its input processors:

```php
new Agent($platform, 'gpt-4o', inputProcessors: [$steeringInputProcessor, ...], name: 'booking');
```

## Traces

With ai-observability-bundle installed and enabled, the `invoke_agent` span gets:

| Attribute | Value |
|---|---|
| `ai_observability.experiment.id` | id of the experiment the run belongs to |
| `ai_observability.experiment.variant` | variant of the conversation |
| `ai_observability.prompt.version` | `prompt_version` of the system prompt applied |

The `ai_observability.` prefix is the one the hub reads.

## Profiler

With WebProfilerBundle, the "AI steering" panel lists the agent runs of the request: the source of their settings
(`config` or `hub`), the model requested by the agent and the one imposed, the experiment and variant, the system prompt
with its version, and the options. It also shows how the hub answered: from the cache, fetched, not modified, or failed
with the error and whether the last configuration received was used. Nothing is recorded when the profiler is off.

## Limits

- The steering applies to agents through their input processors: an agent outside symfony/ai-bundle needs
  `SteeringInputProcessor` in its list.
- Variants are assigned per conversation, not per user: a user may see several variants across conversations. The hub
  counts a user in the variant of their first conversation for the retention.

## Tests

```bash
vendor/bin/phpunit
```

`quentinrogeret34/ai-observability-bundle` is a dev dependency read from its GitHub repository.
