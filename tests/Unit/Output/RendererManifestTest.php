<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Output\JsonFormatter;
use PHPUnit\Framework\TestCase;

/**
 * resources/report/report.html is built in another repository (somework/lockrot-report) and
 * vendored with the manifest its release published. tools/report/update-renderer verifies the
 * release's build provenance when it brings the two in; this test keeps them honest afterwards: a
 * hand edit to the page, or a page copied in without its manifest, fails here rather than shipping
 * inside a signed PHAR under another release's name.
 */
final class RendererManifestTest extends TestCase
{
    private const DIR = __DIR__.'/../../../resources/report';

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $json = file_get_contents(self::DIR.'/manifest.json');
        self::assertIsString($json, 'the renderer is vendored with its manifest');
        $manifest = json_decode($json, true);
        self::assertIsArray($manifest);

        /** @var array<string, mixed> $manifest */
        return $manifest;
    }

    public function testThePageIsTheOneTheManifestDescribes(): void
    {
        $files = self::manifest()['files'] ?? null;
        self::assertIsArray($files);

        self::assertSame($files['report.html'] ?? null, hash_file('sha256', self::DIR.'/report.html'));
    }

    /**
     * The page's policy pins its inline script and stylesheet by hash. The manifest records those
     * hashes too, and they must be the ones in the page: a policy that names another build's
     * script would refuse to run this one, and the reader would get a blank page.
     */
    public function testThePolicyInThePageIsTheOneTheManifestRecords(): void
    {
        $csp = self::manifest()['csp'] ?? null;
        self::assertIsArray($csp);
        $page = file_get_contents(self::DIR.'/report.html');
        self::assertIsString($page);

        foreach (['script', 'style'] as $kind) {
            self::assertIsString($csp[$kind] ?? null);
            self::assertStringContainsString($kind."-src '".$csp[$kind]."'", $page);
        }
    }

    /**
     * The hashes in the policy are what the browser checks the inline script and stylesheet
     * against, so they are checked here the same way: sha256 over the exact text between the tags.
     * A policy that does not match the code it guards renders a blank page, and a manifest written
     * by the same build would agree with it.
     */
    public function testThePolicyHashesAreTheHashesOfTheInlineCode(): void
    {
        $page = file_get_contents(self::DIR.'/report.html');
        self::assertIsString($page);
        $matched = preg_match('{<meta http-equiv="Content-Security-Policy" content="([^"]+)">}', $page, $policy);
        self::assertSame(1, $matched, 'the page carries one policy');

        foreach (['script', 'style'] as $kind) {
            self::assertSame(1, preg_match_all('{<'.$kind.'>(.*?)</'.$kind.'>}s', $page, $inline), 'one inline '.$kind);
            $hash = "'sha256-".base64_encode(hash('sha256', $inline[1][0], true))."'";
            self::assertStringContainsString($kind.'-src '.$hash, $policy[1]);
        }
    }

    public function testTheManifestReadsTheReportSchemaLockrotWrites(): void
    {
        $schema = self::manifest()['schema'] ?? null;
        self::assertIsArray($schema);
        $majors = $schema['report'] ?? null;
        self::assertIsArray($majors);

        self::assertContains(JsonFormatter::SCHEMA, $majors);
    }

    public function testNothingElseIsVendored(): void
    {
        $files = array_values(array_diff(scandir(self::DIR) ?: [], ['.', '..']));
        sort($files);

        self::assertSame(['manifest.json', 'report.html'], $files, 'the old hand-written sources are gone');
    }
}
