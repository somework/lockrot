<?php

declare(strict_types=1);

namespace Lockrot\Exception;

/**
 * The one exception the install-time path lets escape: Composer prints its message verbatim and exits
 * non-zero, so the caller builds the message. See docs/install-time.md#install-time-strict.
 *
 * @internal
 */
final class InstallBlockedException extends \RuntimeException
{
}
