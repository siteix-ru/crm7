<?php

declare(strict_types=1);

namespace App\Messaging;

/** Normalized result of a provider configuration/connectivity check. */
final class ChannelStatus
{
    public function __construct(
        public readonly bool $connected,
        public readonly ?string $botId = null,
        public readonly ?string $botName = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
        public readonly ?string $checkedAt = null
    ) {
    }

    /** Safe for API responses: never carries a token. */
    public function toArray(): array
    {
        return [
            'connected' => $this->connected,
            'bot_id' => $this->botId,
            'bot_name' => $this->botName,
            'error_code' => $this->errorCode,
            'message' => $this->message,
            'checked_at' => $this->checkedAt,
        ];
    }
}
