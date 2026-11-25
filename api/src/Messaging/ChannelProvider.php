<?php

declare(strict_types=1);

namespace App\Messaging;

/**
 * Common contract for all notification channels. Business services only talk
 * to this interface, never to a messenger API directly.
 */
interface ChannelProvider
{
    public function code(): string;

    public function isConfigured(): bool;

    /** Real API connectivity/auth check. Must not send messages to users. */
    public function checkConnection(): ChannelStatus;

    /** Send one text notification to a linked recipient. */
    public function send(string $recipient, NotificationMessage $message): DeliveryResult;
}
