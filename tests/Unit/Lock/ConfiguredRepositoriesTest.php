<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Lock;

use Lockrot\Lock\ConfiguredRepositories;
use Lockrot\Lock\OriginFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfiguredRepositoriesTest extends TestCase
{
    private const GITHUB_SOURCE = 'https://github.com/acme/lib.git';
    private const VCS = ['type' => 'vcs', 'url' => 'https://github.com/acme/lib'];

    /** @return iterable<string, array{mixed}> a `repositories` that configures nothing */
    public static function nothing(): iterable
    {
        yield 'absent' => [null];
        yield 'a string' => ['https://github.com/acme/lib'];
        yield 'a number' => [42];
        yield 'empty' => [[]];
        yield 'packagist.org disabled' => [['packagist.org' => false]];
        yield 'packagist.org disabled in list form' => [[['packagist.org' => false]]];
    }

    /**
     * @param mixed $repositories
     *
     * @dataProvider nothing
     */
    #[DataProvider('nothing')]
    public function testARepositoriesThatConfiguresNothingServesNothing($repositories): void
    {
        $configured = ConfiguredRepositories::fromManifest($repositories);

        self::assertNull($configured->kindServing('acme/lib', '1.0.0', self::fromGithub()));
        self::assertSame('path', $configured->kindServing('acme/lib', '1.0.0', self::pathDist()));
    }

    /**
     * A manifest Composer would refuse could not have produced the lock: reading it neither throws
     * nor warns, and it serves nothing lockrot can name, while a path dist is still a path.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function refused(): iterable
    {
        yield 'an entry that is not an object' => [['https://github.com/acme/lib', self::VCS]];
        yield 'an entry without a type' => [[['url' => 'https://github.com/acme/lib'], self::VCS]];
        yield 'a type that is not a string' => [[['type' => ['vcs'], 'url' => 'https://github.com/acme/lib']]];
        yield 'a url that is not a string' => [[['type' => 'vcs', 'url' => ['https://github.com/acme/lib']]]];
        yield 'only that is not a list' => [[['type' => 'vcs', 'url' => 'https://github.com/acme/lib', 'only' => 'acme/*']]];
        yield 'only with a name that is not a string' => [[['type' => 'vcs', 'url' => 'https://github.com/acme/lib', 'only' => ['acme/*', 7]]]];
        yield 'only and exclude together' => [[['type' => 'vcs', 'url' => 'https://github.com/acme/lib', 'only' => ['acme/*'], 'exclude' => ['other/*']]]];
        yield 'canonical that is not a flag' => [[['type' => 'vcs', 'url' => 'https://github.com/acme/lib', 'canonical' => 'no']]];
        yield 'an inline package that is a name' => [[['type' => 'package', 'package' => 'acme/lib'], self::VCS]];
        yield 'an inline package without a version' => [[['type' => 'package', 'package' => ['name' => 'acme/lib']], self::VCS]];
        yield 'an inline package whose name is not a string' => [[['type' => 'package', 'package' => [['name' => 7, 'version' => '1.0.0']]], self::VCS]];
        yield 'an inline repository without its package' => [[['type' => 'package'], self::VCS]];
        yield 'a vcs repository without a url' => [[self::VCS, ['type' => 'vcs']]];
        yield 'a type Composer does not register' => [[self::VCS, ['type' => 'acme-plugin', 'url' => 'https://acme.test']]];
        yield 'a type in capitals' => [[self::VCS, ['type' => 'VCS', 'url' => 'https://github.com/acme/lib']]];
    }

    /**
     * @param mixed $repositories
     *
     * @dataProvider refused
     */
    #[DataProvider('refused')]
    public function testAManifestComposerWouldRefuseServesNothing($repositories): void
    {
        $configured = ConfiguredRepositories::fromManifest($repositories);

        self::assertSame('unknown', $configured->kindServing('acme/lib', '1.0.0', self::fromGithub()));
        self::assertSame('path', $configured->kindServing('acme/lib', '1.0.0', self::pathDist()));
    }

    /** @return iterable<string, array{string, string}> the configured url and the lock's source url, naming the same repository */
    public static function sameRepository(): iterable
    {
        yield 'with and without .git' => ['https://github.com/acme/lib', self::GITHUB_SOURCE];
        yield 'in another case, as GitHub answers renames' => ['https://github.com/getgrav/twig', 'https://github.com/getgrav/Twig.git'];
        yield 'with a trailing slash' => ['https://github.com/acme/lib/', self::GITHUB_SOURCE];
        yield 'over ssh' => ['git@github.com:acme/lib.git', self::GITHUB_SOURCE];
        yield 'over ssh with a port' => ['ssh://git@git.acme.test:2222/team/lib.git', 'https://git.acme.test/team/lib.git'];
        yield 'with credentials' => ['https://ci:token@git.acme.test/team/lib', 'https://git.acme.test/team/lib.git'];
        yield 'www.github.com, as Composer reads it' => ['https://www.github.com/acme/lib', self::GITHUB_SOURCE];
        yield 'a GitLab subgroup' => ['https://gitlab.acme.test/group/sub/lib.git', 'git@gitlab.acme.test:group/sub/lib.git'];
        yield 'a host in capitals' => ['https://GitHub.com/acme/lib', self::GITHUB_SOURCE];
        yield 'a local repository' => ['/srv/git/lib/.git', '/srv/git/lib'];
        yield 'a local repository with a trailing slash' => ['/srv/git/lib/', '/srv/git/lib'];
        yield 'a Windows repository' => ['C:\\repos\\lib\\.git', 'C:\\repos\\lib'];
        yield 'a relative local repository' => ['../lib', '../lib'];
        yield 'a file URL' => ['file:///srv/git/lib', 'file:///srv/git/lib'];
    }

    /** @dataProvider sameRepository */
    #[DataProvider('sameRepository')]
    public function testAVcsRepositoryServedTheEntryWhoseSourceItIs(string $configured, string $source): void
    {
        $repositories = ConfiguredRepositories::fromManifest([['type' => 'vcs', 'url' => $configured]]);

        self::assertSame('vcs', $repositories->kindServing('acme/lib', '1.0.0', new OriginFacts(null, 'zip', null, 'git', $source)));
    }

    public function testEveryVcsTypeIsAVcsRepository(): void
    {
        foreach (['vcs', 'git', 'github', 'gitlab', 'bitbucket', 'git-bitbucket', 'hg', 'svn', 'fossil', 'perforce'] as $type) {
            self::assertSame('vcs', ConfiguredRepositories::fromManifest([['type' => $type, 'url' => 'https://github.com/acme/lib']])->kindServing('acme/lib', '1.0.0', self::fromGithub()), $type);
        }
    }

    public function testAnotherRepositoryIsNotTheSource(): void
    {
        $repositories = ConfiguredRepositories::fromManifest([
            ['type' => 'vcs', 'url' => 'https://github.com/acme/lib-fork'],
            ['type' => 'vcs', 'url' => 'https://gitlab.com/acme/lib'],
            ['type' => 'vcs', 'url' => 'https://github.com/acme'],
            ['type' => 'vcs', 'url' => '~/src/lib'],
            ['type' => 'vcs', 'url' => 'github.com/acme/lib'],
        ]);

        self::assertNull($repositories->kindServing('acme/lib', '1.0.0', self::fromGithub()));
        self::assertNull($repositories->kindServing('acme/lib', '1.0.0', new OriginFacts(null, 'zip', null, 'git', '~/src/lib')), 'a home directory lockrot does not expand');
        self::assertNull($repositories->kindServing('acme/lib', '1.0.0', new OriginFacts(null, 'zip', null, null, null)), 'no source, nothing to match');
        self::assertNull(ConfiguredRepositories::fromManifest([['type' => 'vcs', 'url' => '../other']])->kindServing('acme/lib', '1.0.0', new OriginFacts(null, 'zip', null, 'git', '../lib')), 'another local repository');
    }

    /** Composer takes a name from the first repository that has it: one that could have, and left no trace, makes the entry unknown. */
    public function testARepositoryListedFirstThatCouldHaveServedTheNameMakesItUnknown(): void
    {
        self::assertSame('unknown', ConfiguredRepositories::fromManifest([['type' => 'composer', 'url' => 'https://satis.acme.test'], self::VCS])->kindServing('acme/lib', '1.0.0', self::fromGithub()));
        self::assertSame('unknown', ConfiguredRepositories::fromManifest([['type' => 'acme-plugin', 'url' => 'https://acme.test'], self::VCS])->kindServing('acme/lib', '1.0.0', self::fromGithub()));
        self::assertSame('unknown', ConfiguredRepositories::fromManifest([['type' => 'composer', 'url' => 'https://asset-packagist.org']])->kindServing('bower-asset/jquery', '3.7.1', self::fromGithub()));
        self::assertSame('unknown', ConfiguredRepositories::fromManifest([['type' => 'artifact', 'url' => '~/artifacts'], self::VCS])->kindServing('acme/lib', '1.0.0', self::fromGithub()), 'an artifact directory lockrot does not expand');
        self::assertSame('vcs', ConfiguredRepositories::fromManifest([self::VCS, ['type' => 'composer', 'url' => 'https://satis.acme.test']])->kindServing('acme/lib', '1.0.0', self::fromGithub()), 'listed after the match');
    }

    /** packagist.org would have written its notification-url; an entry without one did not come from it. */
    public function testPackagistListedFirstIsPassedOver(): void
    {
        foreach (['https://repo.packagist.org', 'https://packagist.org', 'http://packagist.org/', 'https://Repo.Packagist.org/', 'https://u:p@packagist.org:443'] as $url) {
            $repositories = ConfiguredRepositories::fromManifest([['type' => 'composer', 'url' => $url], self::VCS]);

            self::assertSame('vcs', $repositories->kindServing('acme/lib', '1.0.0', self::fromGithub()), $url);
        }
        foreach (['https://packagist.org.evil.example', 'https://notpackagist.org', 'https://repo.packagist.com/acme', 'packagist.org'] as $url) {
            $repositories = ConfiguredRepositories::fromManifest([['type' => 'composer', 'url' => $url], self::VCS]);

            self::assertSame('unknown', $repositories->kindServing('acme/lib', '1.0.0', self::fromGithub()), $url);
        }
    }

    /** A mirror configured under the name packagist keeps the default's place, after every other repository. */
    public function testARepositoryNamedPackagistIsAskedLast(): void
    {
        foreach (['packagist', 'packagist.org'] as $name) {
            $repositories = ConfiguredRepositories::fromManifest([$name => ['type' => 'composer', 'url' => 'https://mirrors.tencent.com/composer/'], 'mine' => self::VCS]);

            self::assertSame('vcs', $repositories->kindServing('acme/lib', '1.0.0', self::fromGithub()), $name);
        }
    }

    public function testARepositoryThatDoesNotAdmitTheNameIsPassedOver(): void
    {
        self::assertSame('vcs', ConfiguredRepositories::fromManifest([['type' => 'composer', 'url' => 'https://satis.acme.test', 'only' => ['other/*']], self::VCS])->kindServing('acme/lib', '1.0.0', self::fromGithub()));
        self::assertSame('vcs', ConfiguredRepositories::fromManifest([self::VCS + ['only' => null], ['type' => 'composer', 'url' => 'https://satis.acme.test', 'exclude' => null]])->kindServing('acme/lib', '1.0.0', self::fromGithub()), 'a null filter is no filter');
        self::assertSame('vcs', ConfiguredRepositories::fromManifest([['type' => 'composer', 'url' => 'https://satis.acme.test', 'only' => []], self::VCS])->kindServing('acme/lib', '1.0.0', self::fromGithub()), 'admits nothing');
        self::assertSame('vcs', ConfiguredRepositories::fromManifest([['type' => 'composer', 'url' => 'https://satis.acme.test', 'exclude' => ['acme/*']], self::VCS])->kindServing('acme/lib', '1.0.0', self::fromGithub()));
        self::assertSame('unknown', ConfiguredRepositories::fromManifest([['type' => 'composer', 'url' => 'https://satis.acme.test', 'only' => ['acme/*']], self::VCS])->kindServing('acme/lib', '1.0.0', self::fromGithub()));
        self::assertNull(ConfiguredRepositories::fromManifest([['type' => 'vcs', 'url' => 'https://github.com/acme/lib', 'exclude' => ['acme/lib']]])->kindServing('acme/lib', '1.0.0', self::fromGithub()));
    }

    public function testDisabledAndPathRepositoriesAreStepsOnTheWay(): void
    {
        $repositories = ConfiguredRepositories::fromManifest([
            'packagist.org' => false,
            'local' => ['type' => 'path', 'url' => 'packages/*'],
            'lib' => self::VCS,
        ]);

        self::assertSame('vcs', $repositories->kindServing('acme/lib', '1.0.0', self::fromGithub()));
    }

    public function testAnInlineDefinitionServesTheEntryItDefines(): void
    {
        $dist = ['type' => 'zip', 'url' => 'https://example.test/lib-1.0.0.zip'];
        $facts = new OriginFacts(null, 'zip', 'https://example.test/lib-1.0.0.zip', null, null);
        $one = ConfiguredRepositories::fromManifest([['type' => 'package', 'package' => ['name' => 'Acme/Lib', 'version' => '1.0.0', 'dist' => $dist]]]);
        $list = ConfiguredRepositories::fromManifest([['type' => 'package', 'package' => [['name' => 'acme/lib', 'version' => '0.9.0', 'dist' => $dist], ['name' => 'acme/lib', 'version' => '1.0.0', 'dist' => $dist]]]]);

        self::assertSame('package', $one->kindServing('acme/lib', '1.0.0', $facts));
        self::assertSame('package', $list->kindServing('acme/lib', '1.0.0', $facts));
        self::assertSame('package', $one->kindServing('acme/lib', 'v1.0.0', $facts), 'the same version, spelt otherwise');
        self::assertSame('package', ConfiguredRepositories::fromManifest([['type' => 'package', 'package' => ['name' => 'acme/lib', 'version' => 3, 'dist' => $dist]]])->kindServing('acme/lib', '3', $facts), 'a version written as a number');
        $unparsable = ConfiguredRepositories::fromManifest([['type' => 'package', 'package' => ['name' => 'acme/lib', 'version' => 'not a version!', 'dist' => $dist]]]);
        self::assertSame('package', $unparsable->kindServing('acme/lib', 'not a version!', $facts), 'a version Composer cannot read, as written');
        self::assertSame('unknown', $unparsable->kindServing('acme/lib', 'not a version?', $facts));
    }

    /** A canonical inline repository that has the name is the only one Composer asks for it: an entry it does not give came from nowhere lockrot can name. */
    public function testAnInlineDefinitionOfTheNameThatDoesNotMatchMakesItUnknown(): void
    {
        $dist = ['type' => 'zip', 'url' => 'https://example.test/lib-1.0.0.zip'];
        $other = new OriginFacts(null, 'zip', 'https://example.test/lib-1.0.1.zip', 'git', self::GITHUB_SOURCE);
        $inline = ['type' => 'package', 'package' => ['name' => 'acme/lib', 'version' => '1.0.0', 'dist' => $dist]];

        self::assertSame('unknown', ConfiguredRepositories::fromManifest([$inline, self::VCS])->kindServing('acme/lib', '1.0.0', $other));
        self::assertSame('unknown', ConfiguredRepositories::fromManifest([$inline])->kindServing('acme/lib', '2.0.0', $other));
        self::assertSame('vcs', ConfiguredRepositories::fromManifest([$inline + ['canonical' => false], self::VCS])->kindServing('acme/lib', '1.0.0', $other));
        self::assertSame('vcs', ConfiguredRepositories::fromManifest([['type' => 'package', 'package' => ['name' => 'acme/other', 'version' => '1.0.0']], self::VCS])->kindServing('acme/lib', '1.0.0', $other));
    }

    public function testAnInlineDefinitionWithASourceServesTheEntryWithThatSource(): void
    {
        $repositories = ConfiguredRepositories::fromManifest([['type' => 'package', 'package' => ['name' => 'acme/meta', 'version' => '2.0.0', 'source' => ['type' => 'git', 'url' => 'https://github.com/acme/meta.git', 'reference' => 'abc']]]]);

        self::assertSame('package', $repositories->kindServing('acme/meta', '2.0.0', new OriginFacts(null, null, null, 'git', 'https://github.com/acme/meta.git')));
        self::assertSame('unknown', $repositories->kindServing('acme/meta', '2.0.0', new OriginFacts(null, 'zip', 'https://example.test/meta.zip', 'git', 'https://github.com/acme/meta.git')), 'a dist the definition does not give');
    }

    /** A path dist is a path, unless the first repository that admits it is an inline definition that gives exactly that dist. */
    public function testAPathDistIsWalkedToItsPathRepositoryOrItsDefinition(): void
    {
        $inline = ['type' => 'package', 'package' => ['name' => 'acme/lib', 'version' => '1.0.0', 'dist' => ['type' => 'path', 'url' => 'packages/lib']]];
        $path = ['type' => 'path', 'url' => 'packages/*'];

        self::assertSame('package', ConfiguredRepositories::fromManifest([$inline, $path])->kindServing('acme/lib', '1.0.0', self::pathDist()));
        self::assertSame('path', ConfiguredRepositories::fromManifest([$path, $inline])->kindServing('acme/lib', '1.0.0', self::pathDist()));
        self::assertSame('path', ConfiguredRepositories::fromManifest([$inline + ['exclude' => ['acme/*']], $path])->kindServing('acme/lib', '1.0.0', self::pathDist()));
        self::assertSame('path', ConfiguredRepositories::fromManifest([['type' => 'composer', 'url' => 'https://satis.acme.test'], $inline])->kindServing('acme/lib', '2.0.0', self::pathDist()));
    }

    /** @return iterable<string, array{string, string, ?string}> the artifact repository's url, the entry's dist url, the kind */
    public static function artifacts(): iterable
    {
        yield 'relative' => ['artifacts', 'artifacts/lib-1.0.0.zip', 'artifact'];
        yield 'relative with ./' => ['./artifacts/', 'artifacts/nested/lib-1.0.0.zip', 'artifact'];
        yield 'absolute' => ['/srv/app/artifacts', '/srv/app/artifacts/lib-1.0.0.zip', 'artifact'];
        yield 'on Windows' => ['C:\\app\\artifacts', 'C:/app/artifacts/lib-1.0.0.zip', 'artifact'];
        yield 'a directory that only starts the same' => ['art', 'artifacts/lib-1.0.0.zip', null];
        yield 'a home directory lockrot does not expand' => ['~/artifacts', '/home/me/artifacts/lib-1.0.0.zip', 'unknown'];
        yield 'a variable lockrot does not expand' => ['$ARTIFACTS', '/srv/artifacts/lib-1.0.0.zip', 'unknown'];
        yield 'a URL, not a file' => ['artifacts', 'https://example.test/artifacts/lib-1.0.0.zip', null];
        yield 'a file URL' => ['/srv/artifacts', 'file:///srv/artifacts/lib-1.0.0.zip', null];
        yield 'the project root' => ['./', './lib-1.0.0.zip', 'artifact'];
        yield 'the project root as a dot' => ['.', './nested/lib-1.0.0.zip', 'artifact'];
        yield 'outside the project root' => ['.', '../lib-1.0.0.zip', null];
        yield 'an absolute file, not under the root' => ['.', '/srv/lib-1.0.0.zip', null];
        yield 'no url, which Composer refuses' => ['', 'artifacts/lib-1.0.0.zip', 'unknown'];
    }

    /** @dataProvider artifacts */
    #[DataProvider('artifacts')]
    public function testAnArtifactRepositoryServedTheArchivesInsideIt(string $configured, string $dist, ?string $kind): void
    {
        $repositories = ConfiguredRepositories::fromManifest([$configured === '' ? ['type' => 'artifact'] : ['type' => 'artifact', 'url' => $configured]]);

        self::assertSame($kind, $repositories->kindServing('acme/lib', '1.0.0', new OriginFacts(null, 'zip', $dist, null, null)));
    }

    private static function fromGithub(): OriginFacts
    {
        return new OriginFacts(null, 'zip', 'https://api.github.com/repos/acme/lib/zipball/abc', 'git', self::GITHUB_SOURCE);
    }

    private static function pathDist(): OriginFacts
    {
        return new OriginFacts(null, 'path', 'packages/lib', null, null);
    }
}
