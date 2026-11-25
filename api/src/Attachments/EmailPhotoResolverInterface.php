<?php

declare(strict_types=1);

namespace App\Attachments;

/** Small seam so notification preparation can be tested without file I/O. */
interface EmailPhotoResolverInterface
{
    /**
     * @param int[] $ids Empty means "all eligible photos of the request".
     * @return string JSON payload for the mail queue, or '' when nothing is safe to attach.
     */
    public function resolve(int $requestId, array $ids = []): string;
}
