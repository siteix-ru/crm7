<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Mail\MailService;

/**
 * Email stays on its existing queue. This adapter only makes the channel
 * visible to the common registry; NotificationService keeps its current email
 * code path unchanged.
 */
final class EmailProvider implements ChannelProvider
{
    public function __construct(private readonly MailService $mail = new MailService())
    {
    }

    public function code(): string
    {
        return 'email';
    }

    public function isConfigured(): bool
    {
        return $this->mail->mailEnabled();
    }

    public function checkConnection(): ChannelStatus
    {
        $enabled = $this->isConfigured();

        return new ChannelStatus(
            $enabled,
            null,
            null,
            $enabled ? null : 'mail_not_configured',
            $enabled ? 'Почта настроена' : 'Почта не настроена'
        );
    }

    public function send(string $recipient, NotificationMessage $message): DeliveryResult
    {
        $this->mail->enqueue($recipient, $message->title, $message->text());

        return DeliveryResult::delivered();
    }
}
