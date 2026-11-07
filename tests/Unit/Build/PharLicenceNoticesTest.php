<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Build;

use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * The MIT and BSD licences ask for their notice in every copy. The PHAR holds what the `finder` entries
 * of build/phar/box.json select, so each bundled package must keep one notice file there.
 * The finders run over the dev vendor, as no Composer installs build/phar here, so a package that only
 * the build lock holds is not checked. PharTest checks the built archive.
 */
final class PharLicenceNoticesTest extends TestCase
{
    public const NOTICE_NAME = '{^(LICEN[CS]E|COPYING)([.-][^/]*)?$}i';

    public function testTheBoxFindersKeepOneNoticeFileOfEveryNoticeDemandingPackage(): void
    {
        $root = \dirname(__DIR__, 3);
        $selected = $this->selectedUnderVendor($root);
        $missing = [];
        foreach (self::noticeDemandingPackages($root) as $package) {
            if (!is_dir($root.'/vendor/'.$package)) {
                continue;
            }
            $found = array_filter($selected, static fn (string $path): bool => \dirname($path) === $package && preg_match(self::NOTICE_NAME, basename($path)) === 1);
            if ($found === []) {
                $missing[] = $package;
            }
        }

        self::assertSame([], $missing, 'Packages whose notice file the PHAR drops.');
    }

    /** @return list<string> the packages of the build lock under the MIT or a BSD licence, minus lockrot itself */
    public static function noticeDemandingPackages(string $root): array
    {
        $lock = JsonPath::decodeFile($root.'/build/phar/composer.lock');
        $names = [];
        foreach (array_keys(JsonPath::arrayAt($lock, ['packages'])) as $index) {
            $name = JsonPath::stringAt($lock, ['packages', $index, 'name']);
            foreach (array_keys(JsonPath::arrayAt($lock, ['packages', $index, 'license'])) as $at) {
                $licence = JsonPath::stringAt($lock, ['packages', $index, 'license', $at]);
                if (($licence === 'MIT' || strncmp($licence, 'BSD', 3) === 0) && $name !== 'somework/lockrot') {
                    $names[] = $name;
                }
            }
        }
        self::assertNotSame([], $names);

        return array_values(array_unique($names));
    }

    /** @return list<string> paths under `vendor/` of the files that the `finder` entries select */
    private function selectedUnderVendor(string $root): array
    {
        $config = JsonPath::decodeFile($root.'/build/phar/box.json');
        $selected = [];
        foreach (array_keys(JsonPath::arrayAt($config, ['finder'])) as $index) {
            $entry = JsonPath::arrayAt($config, ['finder', $index]);
            $in = \is_array($entry['in']) ? JsonPath::arrayAt($entry, ['in']) : [JsonPath::stringAt($entry, ['in'])];
            $dirs = [];
            foreach (array_keys($in) as $at) {
                $dirs[] = JsonPath::stringAt($in, [$at]);
            }
            // The lockrot entries name a vendor copy of this repository, which only a build holds.
            if (strncmp($dirs[0], 'vendor', 6) !== 0 || !is_dir($root.'/'.$dirs[0]) || strpos($dirs[0], 'somework/lockrot') !== false) {
                continue;
            }
            $finder = Finder::create()->files()->in(array_map(static fn (string $dir): string => $root.'/'.$dir, $dirs))->ignoreDotFiles(false);
            foreach (['exclude' => 'exclude', 'name' => 'name', 'notName' => 'notName', 'notPath' => 'notPath'] as $key => $method) {
                if (!isset($entry[$key])) {
                    continue;
                }
                $values = [];
                foreach (array_keys(JsonPath::arrayAt($entry, [$key])) as $at) {
                    $values[] = JsonPath::stringAt($entry, [$key, $at]);
                }
                $finder->{$method}($values);
            }
            foreach ($finder as $file) {
                $selected[] = substr($file->getPathname(), \strlen($root.'/vendor/'));
            }
        }

        return $selected;
    }
}
