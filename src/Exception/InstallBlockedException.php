<?php

declare(strict_types=1);

namespace Lockrot\Exception;

/**
 * Thrown from the PRE_OPERATIONS_EXEC listener when `install-time-strict` is on and the transaction
 * carries findings at or above `fail-on`. It is the one exception the install-time path deliberately
 * lets escape: Composer prints its message and exits non-zero, so the operations never run.
 *
 * The message is built by the caller, because Composer shows it verbatim to the user.
 *
 * @internal
 */
final class InstallBlockedException extends \RuntimeException
{
}
