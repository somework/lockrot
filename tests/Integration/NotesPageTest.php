<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Analyzer\RunNote;
use PHPUnit\Framework\TestCase;

/**
 * Every `docs_url` a run note carries opens a page that explains it. The URLs lockrot has written
 * are frozen below and only grow: a code is retired, never removed, and a page that moves stays
 * behind as a stub keeping every id, so a link in a report written years ago still lands on its
 * section. The mkdocs build checks the page is in the nav; this checks each id is on it.
 */
final class NotesPageTest extends TestCase
{
    private const DOCS = __DIR__.'/../../docs/';

    /** Every note URL a lockrot release has written, from 0.13.0 on. Append; never edit or remove. */
    private const PUBLISHED_NOTE_URLS = [
        'https://lockrot.dev/notes/#offline',
        'https://lockrot.dev/notes/#metadata_unavailable',
        'https://lockrot.dev/notes/#monorepo_parent_unavailable',
        'https://lockrot.dev/notes/#advisory_ignore_unreadable',
        'https://lockrot.dev/notes/#advisories_unavailable',
        'https://lockrot.dev/notes/#advisories_not_checked',
        'https://lockrot.dev/notes/#repository_activity_not_checked',
        'https://lockrot.dev/notes/#repository_activity_anonymous_cap',
        'https://lockrot.dev/notes/#repository_activity_rate_limited',
        'https://lockrot.dev/notes/#repository_activity_unreachable',
        'https://lockrot.dev/notes/#repository_activity_not_found',
        'https://lockrot.dev/notes/#not_from_composer_repository',
    ];

    public function testEveryCodeLinksToAUrlAlreadyPublished(): void
    {
        foreach (RunNote::CODES as $code) {
            self::assertContains(RunNote::DOCS_URL.$code, self::PUBLISHED_NOTE_URLS, $code.': a new code adds its URL to the frozen list');
        }
        self::assertSame('https://lockrot.dev/notes/#', RunNote::DOCS_URL);
    }

    public function testEveryPublishedUrlLandsOnASectionOfItsPage(): void
    {
        foreach (self::PUBLISHED_NOTE_URLS as $url) {
            if (preg_match('{^https://lockrot\.dev/([a-z0-9-]+)/#([a-z][a-z0-9_]*)$}', $url, $parts) !== 1) {
                self::fail($url.' is not a section of a page on lockrot.dev');
            }
            [, $page, $id] = $parts;
            $markdown = self::DOCS.$page.'.md';
            self::assertFileExists($markdown, $url);
            self::assertMatchesRegularExpression('/^#{2,4} .+ \{#'.preg_quote($id, '/').'\}$/m', (string) file_get_contents($markdown), $url.': a heading with that id');
        }
    }

    public function testThePageIsInTheSiteNavigation(): void
    {
        self::assertMatchesRegularExpression('/^  - [^:\n]+: notes\.md$/m', (string) file_get_contents(self::DOCS.'../mkdocs.yml'));
    }

    /** Each section says whether its note sets `network_failures`, which is what `--strict-network` fails on. */
    public function testEachSectionSaysWhetherItsNoteSetsNetworkFailures(): void
    {
        $page = (string) file_get_contents(self::DOCS.'notes.md');
        foreach (RunNote::CODES as $code) {
            $start = strpos($page, '{#'.$code.'}');
            self::assertNotFalse($start, $code);
            $next = strpos($page, "\n#", $start);
            $section = $next === false ? substr($page, $start) : substr($page, $start, $next - $start);
            self::assertStringContainsString('`sets_network_failures`', $section, $code);
        }
    }
}
