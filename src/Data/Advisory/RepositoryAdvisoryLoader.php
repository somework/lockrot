<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Composer\Downloader\TransportException;
use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Lockrot\Deadline;

/**
 * Security advisories through Composer's own repository layer — the same call `composer audit`
 * makes ({@see \Composer\Repository\RepositorySet::getMatchingSecurityAdvisories()}): every
 * repository that publishes advisories is asked for every name, with the installed version as the
 * constraint, and the answers are merged. Full records only, as audit's own report asks for them:
 * Packagist then answers one POST for the whole list, while the partial records Composer keeps in
 * its package-file cache — an id and a range, possibly withdrawn since — are never the answer. A
 * repository that carries advisories in its package files answers from Composer's cache, and one
 * whose inline records are partial is a note. Advisories the project ignores in Composer's own
 * configuration ({@see AdvisoryIgnore}) are dropped, as audit drops them.
 *
 * The POST goes through the repository's own HttpDownloader with the ten-second timeout Composer
 * hard-codes for it, which lockrot cannot shorten: under the install-time budget the deadline is
 * checked before each repository, not inside the call — the same bound the metadata pass has.
 *
 * Composer 2.2 has no advisory API at all: the run says so in a note and checks nothing else
 * differently. Offline, nothing is asked — the POST cannot be served from a cache — and the note
 * says so too.
 */
final class RepositoryAdvisoryLoader implements AdvisoryLoaderInterface
{
    public const NOTE_COMPOSER_TOO_OLD = 'security advisories not checked: needs Composer 2.4 or newer';
    public const NOTE_OFFLINE = 'offline: security advisories not checked';
    public const NOTE_BUDGET = 'security advisories not checked: install-time budget exhausted';

    /** @var list<RepositoryInterface> */
    private array $repositories;
    private bool $offline;
    private Deadline $deadline;
    private AdvisoryIgnore $ignore;
    private VersionParser $parser;

    /** @param list<RepositoryInterface> $repositories the project's Composer repositories, in configured order */
    public function __construct(array $repositories, bool $offline = false, ?Deadline $deadline = null, ?AdvisoryIgnore $ignore = null)
    {
        $this->repositories = $repositories;
        $this->offline = $offline;
        $this->deadline = $deadline ?? Deadline::never();
        $this->ignore = $ignore ?? AdvisoryIgnore::none();
        $this->parser = new VersionParser();
    }

    public function load(array $versionByName): AdvisoryBatch
    {
        $map = $this->constraints($versionByName);
        if ($map === []) {
            return AdvisoryBatch::empty();
        }
        if ($this->offline) {
            return AdvisoryBatch::unavailable(self::NOTE_OFFLINE);
        }
        // The interface arrived in Composer 2.4; the guard is load-bearing on the 2.2 LTS.
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            return AdvisoryBatch::unavailable(self::NOTE_COMPOSER_TOO_OLD);
        }

        /** @var array<string, array<string, Advisory>> $byName name => advisory id => advisory, so two repositories serving the same advisory count it once */
        $byName = [];
        $notes = $this->ignore->note() === null ? [] : [$this->ignore->note()];
        $failed = false;
        foreach ($this->repositories as $repository) {
            if (!$repository instanceof AdvisoryProviderInterface) {
                continue;
            }
            if ($this->deadline->isPast()) {
                return new AdvisoryBatch(self::lists($byName), array_merge($notes, [self::NOTE_BUDGET]), $failed);
            }

            try {
                if (!$repository->hasSecurityAdvisories()) {
                    continue;
                }
                $answer = $repository->getSecurityAdvisories($map, false)['advisories'];
            } catch (TransportException $e) {
                $notes[] = self::unavailableNote($repository->getRepoName(), $e);
                $failed = true;
                continue;
            } catch (\RuntimeException $e) {
                $notes[] = self::unavailableNote($repository->getRepoName(), $e);
                continue;
            }
            foreach ($answer as $name => $advisories) {
                foreach ($advisories as $advisory) {
                    if ($this->ignore->ignores($name, $advisory)) {
                        continue;
                    }
                    $byName[$name][$advisory->advisoryId] = Advisory::fromComposer($advisory);
                }
            }
        }

        return new AdvisoryBatch(self::lists($byName), $notes, $failed);
    }

    /**
     * One line: Composer's message for a partial record runs to a `var_export()` dump of it, and
     * every format prints a note on one line.
     */
    private static function unavailableNote(string $repository, \Throwable $e): string
    {
        $message = (string) strtok($e->getMessage(), "\r\n");

        return \sprintf('security advisories unavailable from %s: %s', $repository, $message === '' ? \get_class($e) : $message);
    }

    /**
     * @param array<string, array<string, Advisory>> $byName
     *
     * @return array<string, list<Advisory>>
     */
    private static function lists(array $byName): array
    {
        $lists = [];
        foreach ($byName as $name => $advisories) {
            $lists[$name] = array_values($advisories);
        }

        return $lists;
    }

    /**
     * `== <normalized version>` per name, as Composer's audit builds it; a version the parser
     * rejects is left out, since no advisory range could be matched against it anyway.
     *
     * @param array<string, string> $versionByName
     *
     * @return array<string, Constraint>
     */
    private function constraints(array $versionByName): array
    {
        $map = [];
        foreach ($versionByName as $name => $version) {
            try {
                $map[$name] = new Constraint('==', $this->parser->normalize($version));
            } catch (\UnexpectedValueException $e) {
                continue;
            }
        }

        return $map;
    }
}
