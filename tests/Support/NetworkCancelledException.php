<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Composer\Downloader\TransportException;

/** What Composer's HttpDownloader throws for a request it cancels because the network is disabled. */
final class NetworkCancelledException extends TransportException
{
    public function __construct(string $message = 'Network disabled, request canceled: https://repo.packagist.org/packages.json')
    {
        parent::__construct($message, 499);
        $this->setStatusCode(499);
    }
}
