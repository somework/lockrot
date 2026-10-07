<?php

declare(strict_types=1);

namespace Lockrot\Exception;

/**
 * The exception that the install-time path throws under `install-time-strict`: Composer prints its
 * message verbatim and exits non-zero, so the caller builds the message.
 * See docs/install-time.md#install-time-strict.
 *
 * @internal
 */
final class InstallBlockedException extends \RuntimeException
{
}
