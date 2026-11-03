<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Packagist serves the archive that `git archive` writes under `.gitattributes`, and `composer
 * require` unpacks it into vendor/somework/lockrot. A file at the root of the repository ships
 * there unless `.gitattributes` export-ignores it, so a new maintainer file fails here.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class DistArchiveTest extends TestCase
{
    private const SHIPPED = [
        '.gitignore',
        'CHANGELOG.md',
        'CONTRIBUTING.md',
        'LICENSE',
        'README.md',
        'SECURITY.md',
        'bin/lockrot',
        'composer.json',
        'resources',
        'src',
    ];

    public function testTheDistArchiveHoldsOnlyWhatAnInstallRuns(): void
    {
        $archive = new Process(['git', 'archive', '--worktree-attributes', '--format=tar', 'HEAD'], __DIR__.'/../..');
        $archive->mustRun();
        $list = new Process(['tar', '-t'], null, null, $archive->getOutput());
        $list->mustRun();

        $roots = [];
        foreach (preg_split('/\n/', trim($list->getOutput())) ?: [] as $path) {
            if ($path === 'bin/') {
                continue;
            }
            $parts = explode('/', rtrim($path, '/'));
            $roots[$parts[0] === 'bin' ? 'bin/'.$parts[1] : $parts[0]] = true;
        }
        $roots = array_keys($roots);
        sort($roots);

        self::assertSame(self::SHIPPED, $roots, 'export-ignore each maintainer file in .gitattributes');
    }
}
