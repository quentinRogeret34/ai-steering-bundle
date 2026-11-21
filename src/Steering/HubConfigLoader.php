<?php

namespace AiSteering\Steering;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads the settings and experiments published by the hub, without ever failing an agent run.
 *
 * The answer is cached for $ttl seconds. When the hub cannot be reached or answers something unexpected, the last
 * configuration received keeps being used, and the hub is asked again after a shorter delay.
 */
final class HubConfigLoader implements ResetInterface
{
    public const STATE_CACHED = 'cached';
    public const STATE_FETCHED = 'fetched';
    public const STATE_NOT_MODIFIED = 'not_modified';
    public const STATE_FAILED = 'failed';

    private const CACHE_KEY = 'ai_steering.hub_config';
    private const LAST_KNOWN_KEY = 'ai_steering.hub_config.last_known';
    private const RETRY_AFTER = 10;

    /** @var array{state: self::STATE_*, error: ?string, fallback: bool}|null */
    private ?array $status = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $url,
        #[\SensitiveParameter]
        private readonly ?string $token,
        private readonly int $ttl = 60,
        private readonly float $timeout = 2.0,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function load(): ?SteeringConfig
    {
        if ('' === trim($this->url)) {
            return null;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);

        if ($item->isHit()) {
            $this->status = ['state' => self::STATE_CACHED, 'error' => null, 'fallback' => false];

            return $this->parse($item->get());
        }

        $lastKnown = $this->cache->getItem(self::LAST_KNOWN_KEY);
        /** @var array{etag: ?string, config: array<mixed>}|null $last */
        $last = $lastKnown->isHit() ? $lastKnown->get() : null;

        try {
            $config = $this->fetch($last);
            $this->status = ['state' => $config === $last ? self::STATE_NOT_MODIFIED : self::STATE_FETCHED, 'error' => null, 'fallback' => false];
            $lastKnown->set($config);
            $this->cache->save($lastKnown);
            $item->expiresAfter($this->ttl);
        } catch (\Throwable $exception) {
            $this->logger?->warning('The AI hub configuration could not be loaded, the last one received is kept: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
            $config = $last;
            $this->status = ['state' => self::STATE_FAILED, 'error' => $exception->getMessage(), 'fallback' => null !== $last];
            $item->expiresAfter(min($this->ttl, self::RETRY_AFTER));
        }

        $item->set($config);
        $this->cache->save($item);

        return $this->parse($config);
    }

    /**
     * How the last load() got its configuration: from the cache, from the hub, or from the last one received when the
     * hub failed (fallback).
     *
     * @return array{state: self::STATE_*, error: ?string, fallback: bool}|null null before the first load()
     */
    public function status(): ?array
    {
        return $this->status;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function reset(): void
    {
        $this->status = null;
    }

    /**
     * @param array{etag: ?string, config: array<mixed>}|null $last
     *
     * @return array{etag: ?string, config: array<mixed>}
     */
    private function fetch(?array $last): array
    {
        $headers = ['Accept' => 'application/json'];
        if (null !== $this->token && '' !== $this->token) {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }
        if (null !== $last && null !== $last['etag']) {
            $headers['If-None-Match'] = $last['etag'];
        }

        $response = $this->httpClient->request('GET', rtrim($this->url, '/').'/api/steering', [
            'headers' => $headers,
            'timeout' => $this->timeout,
            'max_duration' => $this->timeout * 2,
        ]);

        if (304 === $response->getStatusCode() && null !== $last) {
            return $last;
        }

        $config = $response->toArray();
        SteeringConfig::fromArray($config);

        return ['etag' => $response->getHeaders()['etag'][0] ?? null, 'config' => $config];
    }

    /**
     * @param array{etag: ?string, config: array<mixed>}|null $cached
     */
    private function parse(?array $cached): ?SteeringConfig
    {
        return null === $cached ? null : SteeringConfig::fromArray($cached['config']);
    }
}
