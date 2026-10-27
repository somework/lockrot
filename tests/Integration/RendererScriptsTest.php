<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * tools/report/update-renderer vendors the renderer, and tools/report/check-vendored is the check of
 * the release pull request and of phar.yml. Each runs in a copy of the repository layout, with a
 * stand-in `gh`. The stand-in serves the named assets of a lockrot-report release from a directory
 * and refuses any other argument. It answers each attestation with FAKE_ATTESTATION. A verified
 * attestation prints FAKE_DIGEST, the source commit that `--jq` selects.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class RendererScriptsTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';
    private const SCRIPTS = ['update-renderer', 'check-vendored'];
    private const COMMIT = 'e72b02bb9180858e5a84a119aad8466993b47627';
    private const FAKE_GH = <<<'BASH'
        #!/usr/bin/env bash
        echo "$*" >> "$FAKE_LOG"
        case "$1 $2" in
          "release download")
            shift 3
            patterns=()
            while [ $# -gt 0 ]; do
              case "$1" in
                --repo) [ "$2" = somework/lockrot-report ] || exit 96; shift 2 ;;
                --dir) dir=$2; shift 2 ;;
                --pattern=*) patterns+=("${1#--pattern=}"); shift ;;
                *) exit 98 ;;
              esac
            done
            [ ${#patterns[@]} -gt 0 ] || exit 97
            for pattern in "${patterns[@]}"; do cp "$FAKE_RELEASE/$pattern" "$dir"/ || exit 95; done ;;
          "attestation verify") [ "${FAKE_ATTESTATION:-0}" = 0 ] || exit "$FAKE_ATTESTATION"; printf '%s' "${FAKE_DIGEST:-}" ;;
          *) exit 99 ;;
        esac
        BASH;

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/lockrot-renderer-scripts-'.bin2hex(random_bytes(6));
        foreach (['tools/report', 'bin', 'release'] as $dir) {
            mkdir($this->root.'/'.$dir, 0777, true);
        }
        foreach (self::SCRIPTS as $script) {
            copy(self::ROOT.'/tools/report/'.$script, $this->root.'/tools/report/'.$script);
            chmod($this->root.'/tools/report/'.$script, 0755);
        }
        file_put_contents($this->root.'/bin/gh', self::FAKE_GH);
        chmod($this->root.'/bin/gh', 0755);
        touch($this->root.'/gh.log');
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->root]))->run();
    }

    public function testAnAttestedReleaseIsVendored(): void
    {
        $this->release('1.2.3', true);

        $process = $this->runScript('update-renderer', ['v1.2.3']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertFileEquals($this->root.'/release/report.html', $this->root.'/resources/report/report.html');
        self::assertFileEquals($this->root.'/release/manifest.json', $this->root.'/resources/report/manifest.json');
        self::assertStringContainsString('--signer-workflow somework/lockrot-report/.github/workflows/release.yml --source-ref refs/tags/v1.2.3', $this->log());
        self::assertSame(self::COMMIT."\n", file_get_contents($this->root.'/tools/report/renderer-commit'), 'the commit that the attestation names');
        self::assertSame(2, substr_count($this->log(), '--format json --jq .[].verificationResult.signature.certificate.sourceRepositoryDigest'), 'each file names its source commit');
    }

    /** A tag can move: each attested file must name one source commit, the same one. */
    public function testAnAttestationWithoutOneSourceCommitVendorsNothing(): void
    {
        $this->release('1.2.3', true);

        foreach (['', 'not-a-commit', self::COMMIT."\n".str_repeat('a', 40)] as $digest) {
            $process = $this->runScript('update-renderer', ['v1.2.3'], ['FAKE_DIGEST' => $digest]);

            self::assertSame(1, $process->getExitCode(), $digest.': '.$process->getErrorOutput());
            self::assertStringContainsString('one source commit', $process->getErrorOutput());
            self::assertDirectoryDoesNotExist($this->root.'/resources/report');
            self::assertFileDoesNotExist($this->root.'/tools/report/renderer-commit');
        }
    }

    public function testATagThatIsNoReleaseTagIsRefusedBeforeAnyDownload(): void
    {
        $this->release('1.2.3', true);

        $process = $this->runScript('update-renderer', ['1.2.3']);

        self::assertSame(2, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $this->log());
        self::assertDirectoryDoesNotExist($this->root.'/resources/report');
    }

    public function testAFailedAttestationVendorsNothing(): void
    {
        $this->release('1.2.3', true);

        $process = $this->runScript('update-renderer', ['v1.2.3'], ['FAKE_ATTESTATION' => '1']);

        self::assertNotSame(0, $process->getExitCode());
        self::assertDirectoryDoesNotExist($this->root.'/resources/report');
    }

    public function testAPageThatDiffersFromItsManifestVendorsNothing(): void
    {
        $this->release('1.2.3', false);

        $process = $this->runScript('update-renderer', ['v1.2.3']);

        self::assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('report.html does not match its manifest', $process->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->root.'/resources/report');
    }

    public function testAManifestThatNamesAnotherVersionVendorsNothing(): void
    {
        $this->release('1.2.3', true);

        $process = $this->runScript('update-renderer', ['v1.2.4']);

        self::assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('v1.2.4 carries a manifest for version 1.2.3', $process->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->root.'/resources/report');
    }

    public function testALocalBuildIsVendoredWithoutTheNetwork(): void
    {
        $this->release('1.2.3', true);

        file_put_contents($this->root.'/tools/report/renderer-commit', self::COMMIT."\n");
        $process = $this->runScript('update-renderer', ['--from-dir', $this->root.'/release']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertFileEquals($this->root.'/release/report.html', $this->root.'/resources/report/report.html');
        self::assertSame('', $this->log());
        self::assertFileDoesNotExist($this->root.'/tools/report/renderer-commit', 'a local build pins no commit');
    }

    public function testTheCheckPassesAnAttestedRelease(): void
    {
        $this->vendored('1.2.3');

        $process = $this->runScript('check-vendored', []);

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('attestation verify resources/report/report.html --repo somework/lockrot-report --signer-workflow somework/lockrot-report/.github/workflows/release.yml --source-ref refs/tags/v1.2.3 --source-digest '.self::COMMIT, $this->log());
        self::assertStringContainsString('attestation verify resources/report/manifest.json --repo somework/lockrot-report --signer-workflow somework/lockrot-report/.github/workflows/release.yml --source-ref refs/tags/v1.2.3 --source-digest '.self::COMMIT, $this->log());
    }

    public function testTheCheckRefusesAReleaseWithNoPinnedCommit(): void
    {
        $this->vendored('1.2.3');
        unlink($this->root.'/tools/report/renderer-commit');

        $process = $this->runScript('check-vendored', []);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('tools/report/renderer-commit names no commit', $process->getOutput());
        self::assertSame('', $this->log());
    }

    public function testTheCheckRefusesAPreRelease(): void
    {
        $this->vendored('1.2.3-rc.1');

        $process = $this->runScript('check-vendored', []);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('resources/report/ holds lockrot-report 1.2.3-rc.1, not a release', $process->getOutput());
        self::assertSame('', $this->log());
    }

    public function testTheCheckRefusesAPageWithoutAnAttestation(): void
    {
        $this->vendored('1.2.3');

        $process = $this->runScript('check-vendored', [], ['FAKE_ATTESTATION' => '1']);

        self::assertNotSame(0, $process->getExitCode());
    }

    /** Writes the assets of a renderer release whose manifest names $version and, when $matches, the page's sha256. */
    private function release(string $version, bool $matches): void
    {
        $page = '<!doctype html><title>lockrot report</title>';
        file_put_contents($this->root.'/release/report.html', $page);
        file_put_contents($this->root.'/release/manifest.json', (string) json_encode(['version' => $version, 'files' => ['report.html' => hash('sha256', $matches ? $page : 'another page')]]));
    }

    private function vendored(string $version): void
    {
        mkdir($this->root.'/resources/report', 0777, true);
        file_put_contents($this->root.'/resources/report/report.html', '<!doctype html>');
        file_put_contents($this->root.'/resources/report/manifest.json', (string) json_encode(['version' => $version]));
        file_put_contents($this->root.'/tools/report/renderer-commit', self::COMMIT."\n");
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     */
    private function runScript(string $script, array $arguments, array $env = []): Process
    {
        $process = new Process(array_merge([$this->root.'/tools/report/'.$script], $arguments), $this->root, $env + [
            'PATH' => $this->root.'/bin:'.\dirname(\PHP_BINARY).':'.(string) getenv('PATH'),
            'FAKE_LOG' => $this->root.'/gh.log',
            'FAKE_RELEASE' => $this->root.'/release',
            'FAKE_DIGEST' => self::COMMIT,
        ]);
        $process->setTimeout(60)->run();

        return $process;
    }

    private function log(): string
    {
        return (string) file_get_contents($this->root.'/gh.log');
    }
}
