<?php

declare(strict_types=1);

namespace App\Messaging;

/** Injectable HTTP boundary; providers never open sockets themselves. */
interface HttpTransport
{
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 10
    ): HttpResponse;
}
