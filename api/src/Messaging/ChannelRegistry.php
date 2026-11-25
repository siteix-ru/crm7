<?php

declare(strict_types=1);

namespace App\Messaging;

/** Single place where new channels are registered; no business code changes. */
final class ChannelRegistry
{
    /** @var array<string, ChannelProvider> */
    private array $providers = [];

    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            if ($provider instanceof ChannelProvider) {
                $this->providers[$provider->code()] = $provider;
            }
        }
    }

    public function get(string $code): ?ChannelProvider
    {
        return $this->providers[$code] ?? null;
    }

    /** @return array<string, ChannelProvider> */
    public function all(): array
    {
        return $this->providers;
    }

    public static function default(): self
    {
        return new self([
            new EmailProvider(),
            new MaxProvider(),
            new TelegramProvider(),
        ]);
    }
}
