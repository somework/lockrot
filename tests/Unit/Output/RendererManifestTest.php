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
