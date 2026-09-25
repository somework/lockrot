<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * A console formatter that fails the moment it is handed a given piece of text.
 *
 * Some symfony/console 5.4 releases inside Composer's PHARs throw on a `<<fg=red>>` that
 * OutputFormatter::escape() left half-live, and the one in this repository's vendor does not: a
 * test cannot reproduce the crash with the real formatter. What it can prove is that the text never
 * reaches a formatter at all, which is the fix, and this is how.
 */
final class MarkupRefusingFormatter extends OutputFormatter
{
    private string $refused;

    public function __construct(string $refused, bool $decorated = false)
    {
        parent::__construct($decorated);
        $this->refused = $refused;
    }

    public function format(?string $message): ?string
    {
        if ($message !== null && strpos($message, $this->refused) !== false) {
            throw new \LogicException('the formatter was handed '.$this->refused);
        }

        return parent::format($message);
    }
}
