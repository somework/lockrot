<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Exception;

use Lockrot\Exception\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigExceptionTest extends TestCase
{
    /** A configuration error carries no code, like one that `new ConfigException($message)` builds. */
    public function testAnErrorWithDetailsKeepsItsMessageDetailsAndCause(): void
    {
        $cause = new ConfigException('the schema refuses it');
        $error = ConfigException::withDetails('config is invalid', ['  - a: why'], $cause);

        self::assertSame(['config is invalid', ['  - a: why'], 0, $cause], [$error->getMessage(), $error->details(), $error->getCode(), $error->getPrevious()]);
        self::assertSame([], $cause->details());
    }
}
