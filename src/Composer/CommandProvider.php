<?php

declare(strict_types=1);

namespace Lockrot\Composer;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

final class CommandProvider implements CommandProviderCapability
{
    /** @return list<\Composer\Command\BaseCommand> */
    public function getCommands(): array
    {
        return [new LockrotCommand()];
    }
}
