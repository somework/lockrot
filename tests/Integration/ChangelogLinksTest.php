<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * CHANGELOG.md is read on github.com as well as on lockrot.dev. `mkdocs build --strict` checks its
 * anchors against the site's heading ids. GitHub ignores a `{#id}` and builds its own slug from the
 * heading text, so an anchor that differs from that slug needs an `<a name="…">` in the heading.
 */
final class ChangelogLinksTest extends TestCase
{
    private const ROOT = __DIR__.'/../../';

    public function testEveryDocsAnchorOfTheChangelogResolvesOnGitHub(): void
    {
        $changelog = (string) file_get_contents(self::ROOT.'CHANGELOG.md');
        preg_match_all('/\]\((docs\/[^)#\s]+\.md)#([^)\s]+)\)/', $changelog, $links, \PREG_SET_ORDER);
        self::assertNotSame([], $links, 'CHANGELOG.md links docs anchors');

        $unresolved = [];
        foreach ($links as [, $page, $anchor]) {
            if (!\in_array($anchor, self::gitHubAnchors($page), true)) {
                $unresolved[] = $page.'#'.$anchor;
            }
        }
        self::assertSame([], array_values(array_unique($unresolved)), 'add <a name="<anchor>"></a> at the start of the heading text');
    }

    public function testAHeadingWithAnExplicitIdGetsNoGitHubSlugOfThatId(): void
    {
        self::assertSame(['score', 'the-score-score', 'what---generate-baseline-does', 'score'], self::gitHubAnchors('x.md', "## The score {#score}\n\n## What `--generate-baseline` does\n\n### <a name=\"score\"></a>Score\n"));
    }

    /**
     * The fragments github.com scrolls to: each heading's slug (lower case, punctuation other than
     * `-` and `_` dropped, spaces as `-`), with `-1`, `-2` on a repeat, and each `<a name>`.
     *
     * @return list<string>
     */
    private static function gitHubAnchors(string $page, ?string $text = null): array
    {
        $text ??= (string) file_get_contents(self::ROOT.$page);
        $text = (string) preg_replace('/^```.*?^```/ms', '', $text);
        preg_match_all('/<a name="([^"]+)"><\/a>/', $text, $names);
        preg_match_all('/^#{1,6} +(.+?) *$/m', $text, $headings);

        $anchors = $names[1];
        $seen = [];
        foreach ($headings[1] as $heading) {
            $plain = (string) preg_replace(['/<[^>]+>/', '/\[([^\]]*)\]\([^)]*\)/'], ['', '$1'], $heading);
            $slug = str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N}_\- ]/u', '', strtolower($plain)));
            $count = $seen[$slug] ?? 0;
            $seen[$slug] = $count + 1;
            $anchors[] = $count === 0 ? $slug : $slug.'-'.$count;
        }

        return $anchors;
    }
}
