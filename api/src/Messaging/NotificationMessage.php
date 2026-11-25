<?php

declare(strict_types=1);

namespace App\Messaging;

/** Provider-agnostic notification payload. No attachments are sent to messengers. */
final class NotificationMessage
{
    public function __construct(
        public readonly int $userId,
        public readonly string $eventCode,
        public readonly string $title,
        public readonly string $body,
        public readonly string $link,
        public readonly string $eventKey
    ) {
    }

    /** Plain text safe for messengers; secrets and storage details are never included. */
    public function text(): string
    {
        $text = trim($this->title . ($this->body !== '' ? "\n" . $this->body : ''));

        if ($this->link !== '') {
            $text .= "\n" . $this->link;
        }

        return mb_substr($text, 0, 3800);
    }
}
