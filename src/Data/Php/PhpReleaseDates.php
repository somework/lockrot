<?php

declare(strict_types=1);

namespace Lockrot\Data\Php;

use Lockrot\Clock;
use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;

/** @internal */
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

    /** @return list<string> the minors whose GA is on or before the clock's day, as the report dates it, oldest first */
    public function minors(Clock $clock): array
    {
        $today = $clock->now()->format('Y-m-d');
        $minors = array_keys(array_filter($this->dates, static fn (\DateTimeImmutable $ga): bool => $ga->format('Y-m-d') <= $today));
        usort($minors, 'version_compare');

        return array_map('strval', $minors);
    }

    /**
     * `8.4.25` gives `8.4` and `8` gives `8.0`. Input that does not start with digits comes back
     * unchanged.
     */
    public static function minorOf(string $version): string
    {
        if (preg_match('{^(\d+)(?:\.(\d+))?}', $version, $m) !== 1) {
            return $version;
        }

        return $m[1].'.'.($m[2] ?? '0');
    }
}
