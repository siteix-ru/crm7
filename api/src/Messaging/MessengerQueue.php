<?php

declare(strict_types=1);

namespace App\Messaging;

/** Seam for callers that must distinguish duplicates from real queue failures. */
interface MessengerQueue
{
    /** true = queued, false = duplicate; real failures throw. */
    public function enqueue(int $userId, string $channel, NotificationMessage $message): bool;

    public function processQueue(int $limit = 20): array;
}
