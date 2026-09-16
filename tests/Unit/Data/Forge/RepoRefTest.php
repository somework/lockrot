<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Data\Forge;

use Lockrot\Data\Forge\RepoRef;
use PHPUnit\Framework\TestCase;

final class RepoRefTest extends TestCase
{
    public function testLabelsAndWording(): void
    {
        self::assertSame(['github', 'gitlab', 'bitbucket'], RepoRef::FORGES);
        self::assertSame('GitHub', (new RepoRef(RepoRef::GITHUB, 'github.com', 'o/r'))->forgeLabel());
        self::assertSame('GitLab', RepoRef::label(RepoRef::GITLAB));
        self::assertSame('Bitbucket', RepoRef::label(RepoRef::BITBUCKET));
        self::assertSame('last push', (new RepoRef(RepoRef::GITHUB, 'github.com', 'o/r'))->activityWording());
        self::assertSame('last commit', (new RepoRef(RepoRef::GITLAB, 'gitlab.com', 'g/s/p'))->activityWording());
        self::assertSame('last commit', (new RepoRef(RepoRef::BITBUCKET, 'bitbucket.org', 'w/r'))->activityWording());
        self::assertSame('gitlab.example.com/gitlab/g/p', (new RepoRef(RepoRef::GITLAB, 'gitlab.example.com/gitlab', 'g/p'))->key());
    }
}
