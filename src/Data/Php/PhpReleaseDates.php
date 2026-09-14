<?php

declare(strict_types=1);

namespace Lockrot\Data\Php;

use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;

final class PhpReleaseDates
{
    /** @var array<string, \DateTimeImmutable> */
    private array $dates;

    /** @param array<string, \DateTimeImmutable> $dates */
    private function __construct(array $dates)
    {
        $this->dates = $dates;
    }

    public static function load(?string $path = null): self
    {
        $path ??= __DIR__.'/../../../resources/php-ga-dates.json';
        $data = JsonReader::readObject($path);
        $dates = [];
        foreach ($data as $minor => $date) {
            if (\is_string($date) && preg_match('{^\d+\.\d+$}', (string) $minor) === 1) {
                try {
                    $dates[(string) $minor] = new \DateTimeImmutable($date.'T00:00:00+00:00');
                } catch (\Exception $e) {
                    throw new ConfigException('Invalid PHP GA date for "'.$minor.'" in '.$path, 0, $e);
                }
            }
        }

        return new self($dates);
    }

    public function gaDate(string $minor): ?\DateTimeImmutable
    {
        return $this->dates[$minor] ?? null;
    }

    /**
     * Extracts the "major.minor" prefix from a version string (e.g. "8.4.25" -> "8.4", "8" -> "8.0").
     * Input that is not shaped like a version (does not start with digits) is returned unchanged.
     */
    public static function minorOf(string $version): string
    {
        if (preg_match('{^(\d+)(?:\.(\d+))?}', $version, $m) !== 1) {
            return $version;
        }

        return $m[1].'.'.($m[2] ?? '0');
    }
}
