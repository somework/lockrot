<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A job that installs or updates packages from Composer runs code that no lock pins. The checkout
 * token must then stay off disk, or that code can push with it.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class WorkflowCheckoutTokenTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /**
     * @dataProvider jobs
     */
    #[DataProvider('jobs')]
    public function testAJobThatRunsPackageCodeKeepsTheCheckoutTokenOffDisk(string $job): void
    {
        preg_match_all('/uses: actions\/checkout@[^\n]*\n((?:(?! *- )[^\n]*\n)*)/', $job, $checkouts, \PREG_SET_ORDER);
        self::assertNotSame([], $checkouts);
        foreach ($checkouts as $checkout) {
            self::assertMatchesRegularExpression('/persist-credentials: false/', $checkout[1], $checkout[0]);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function jobs(): iterable
    {
        foreach (glob(self::ROOT.'/.github/workflows/*.yml') ?: [] as $file) {
            $workflow = (string) file_get_contents($file);
            if (preg_match('/^jobs:\n(.*)\z/ms', $workflow, $body) !== 1) {
                continue;
            }
            preg_match_all('/^  ([\w-]+):\n((?:(?:    [^\n]*)?\n)*)/m', $body[1], $jobs, \PREG_SET_ORDER);
            foreach ($jobs as $job) {
                if (preg_match('/\bcomposer (?:global )?(?:install|update|require)\b/', self::withLocalActions($job[2])) === 1) {
                    yield basename($file).' '.$job[1] => [$job[2]];
                }
            }
        }
    }

    private static function withLocalActions(string $job): string
    {
        preg_match_all('/uses: \.\/(\.github\/actions\/[\w-]+)/', $job, $actions);

        return array_reduce($actions[1], static fn (string $text, string $action): string => $text.(string) file_get_contents(self::ROOT.'/'.$action.'/action.yml'), $job);
    }
}
