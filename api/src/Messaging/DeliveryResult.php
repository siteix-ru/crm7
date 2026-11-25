<?php

declare(strict_types=1);

namespace App\Messaging;

/** Normalized delivery outcome shared by every channel provider. */
final class DeliveryResult
{
    public const DELIVERED = 'delivered';
    public const RETRY = 'retry';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly ?string $errorCode,
        public readonly ?string $providerId,
        public readonly ?int $retryAfterSeconds
    ) {
    }

    public static function delivered(?string $providerId = null): self
    {
        return new self(self::DELIVERED, null, $providerId, null);
    }

    public static function retry(string $errorCode, ?int $retryAfterSeconds = null): self
    {
        return new self(self::RETRY, $errorCode, null, $retryAfterSeconds);
    }

    public static function failed(string $errorCode): self
    {
        return new self(self::FAILED, $errorCode, null, null);
    }

    public function ok(): bool
    {
        return $this->status === self::DELIVERED;
    }
}
