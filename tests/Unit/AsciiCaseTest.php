<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit;

use Lockrot\AsciiCase;
use PHPUnit\Framework\TestCase;

final class AsciiCaseTest extends TestCase
{
    public function testLowerFoldsAsciiLettersAndKeepsEveryOtherByte(): void
    {
        self::assertSame("high-\xC4\xB0\xDD-9_z", AsciiCase::lower("HIGH-\xC4\xB0\xDD-9_Z"));
    }

    public function testLowerFoldsAnUpperCaseIUnderTheTurkishLocale(): void
    {
        $previous = setlocale(\LC_CTYPE, '0');
        if (setlocale(\LC_CTYPE, 'tr_TR.ISO8859-9', 'tr_TR.ISO-8859-9', 'tr_TR', 'tr_TR.UTF-8') === false) {
            self::markTestSkipped('No Turkish locale is installed.');
        }

        try {
            self::assertSame('github.com', AsciiCase::lower('GITHUB.COM'));
        } finally {
            setlocale(\LC_CTYPE, $previous === false ? 'C' : $previous);
        }
    }
}
