<?php

declare(strict_types=1);

namespace Lockrot\Config;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * The {@see UnknownKeys} warnings as console lines, each printed at most once per guard.
 *
 * `composer lockrot` reads composer.json twice (once to catch a bad manifest before Composer does,
 * once to run), and the install-time summary and the command share a Composer process in the
 * plugin. Both printers ask {@see self::process()}, so however many times the config is read, a
 * process says each warning once. Tests hand in their own guard instead.
 *
 * @internal
 */
final class UnknownKeyWarnings
{
    private static ?self $process = null;

    /** @var array<string, string> the lines this guard has already handed out, keyed by themselves */
    private array $printed = [];

    public static function process(): self
    {
        if (self::$process === null) {
            self::$process = new self();
        }

        return self::$process;
    }

    /**
     * The `<warning>lockrot: …</warning>` lines for $lockrotExtra that this guard has not handed out
     * yet, in document order. The key is escaped, so it prints as written and never as a style tag.
     *
     * @param array<string, mixed> $lockrotExtra contents of composer.json extra.lockrot
     *
     * @return list<string>
     */
    public function lines(array $lockrotExtra): array
    {
        $lines = [];
        foreach (UnknownKeys::warnings($lockrotExtra) as $warning) {
            $line = '<warning>lockrot: '.OutputFormatter::escape($warning).'</warning>';
            if (!isset($this->printed[$line])) {
                $this->printed[$line] = $line;
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
