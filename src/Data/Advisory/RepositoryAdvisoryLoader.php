<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Downloader\TransportException;
use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;
use Lockrot\Analyzer\RunNote;
use Lockrot\Data\Repository\RepositoryUrl;
use Lockrot\Deadline;

/**
 * The one advisory lookup of a run: one request per advisory-capable repository, every advisory of
 * every asked name ({@see \Composer\Repository\AdvisoryProviderInterface} documents
 * `MatchAllConstraint` for that). lockrot then attributes each advisory to the installed version
 * locally, as `composer audit` matches it ({@see \Composer\Repository\RepositorySet::getMatchingSecurityAdvisories()}).
 * It asks for full records only: a partial record in Composer's package-file cache holds only an
 * id and a range, and can be withdrawn. Composer hard-codes a ten-second timeout for the POST, so
 * lockrot checks the deadline before each repository, not inside the call. Scope and records:
 * docs/verdicts.md#which-advisories-count.
 *
 * @internal
 */
final class RepositoryAdvisoryLoader implements AdvisoryLoaderInterface
{
    /** @var list<RepositoryInterface> */
    private array $repositories;
    private bool $offline;
    private Deadline $deadline;
    private AdvisoryIgnore $ignore;
    private string $scope;
    private string $scopeSource;
    private VersionParser $parser;

    /**
     * @param list<RepositoryInterface> $repositories the project's Composer repositories, in configured order
     * @param string                    $scope        one of {@see AdvisoryCoverage::SCOPES}
     */
    public function __construct(array $repositories, bool $offline = false, ?Deadline $deadline = null, ?AdvisoryIgnore $ignore = null, string $scope = AdvisoryCoverage::SCOPE_ALL, string $scopeSource = AdvisoryCoverage::SOURCE_DEFAULT)
    {
        $this->repositories = $repositories;
        $this->offline = $offline;
        $this->deadline = $deadline ?? Deadline::never();
        $this->ignore = $ignore ?? AdvisoryIgnore::none();
        $this->scope = $scope;
        $this->scopeSource = $scopeSource;
        $this->parser = new VersionParser();
    }

    public function load(array $versionByName, array $notFromComposerRepository = []): AdvisoryBatch
    {
        if ($versionByName === []) {
            return AdvisoryBatch::empty();
        }
        $whyUnreadable = $this->ignore->whyUnreadable();
        $notes = $whyUnreadable === null ? [] : [RunNote::advisoryIgnoreUnreadable($whyUnreadable)];
        $outside = $this->scope === AdvisoryCoverage::SCOPE_COMPOSER_REPOSITORIES ? array_fill_keys($notFromComposerRepository, true) : [];
        $asked = array_diff_key($versionByName, $outside);

        $stop = $this->notAskedReason($asked);
        if ($stop !== null) {
            [$reason, $note] = $stop;

            return new AdvisoryBatch([], $note === null ? $notes : array_merge($notes, [$note]), [], [], $this->coverage($this->notAsked($reason), $versionByName, $outside, [], $reason));
        }

        [$repositories, $answers, $askNotes] = $this->ask(array_keys($asked));

        return $this->batch($versionByName, $outside, $repositories, $answers, array_merge($notes, $askNotes));
    }

    /**
     * Why no repository is asked at all, and the note that says so.
     *
     * @param array<string, string> $asked
     *
     * @return array{string, ?RunNote}|null
     */
    private function notAskedReason(array $asked): ?array
    {
        $disabledBy = $this->ignore->disabledBy();
        if ($disabledBy !== null) {
            return [AdvisoryCoverage::DISABLED_BY_POLICY, RunNote::advisoriesDisabledByPolicy($disabledBy['policy_key'], $disabledBy['value'])];
        }
        if ($this->offline) {
            return [AdvisoryCoverage::OFFLINE, RunNote::advisoriesNotChecked(RunNote::ADVISORIES_OFFLINE, 0)];
        }
        // The interface exists only on Composer 2.4 and later. The guard must stay for 2.2.
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            return [AdvisoryCoverage::COMPOSER_TOO_OLD, RunNote::advisoriesNotChecked(RunNote::ADVISORIES_COMPOSER_TOO_OLD, 0)];
        }

        return $asked === [] ? [AdvisoryCoverage::NOT_FROM_COMPOSER_REPOSITORY, null] : null;
    }

    /** @return list<array{composer_repository: string, outcome: string, reason: ?string, message: ?string, records: ?int, packages_with_records: ?int}> */
    private function notAsked(string $reason): array
    {
        $repositories = [];
        foreach ($this->repositories as $repository) {
            if ($repository instanceof AdvisoryProviderInterface) {
                $repositories[] = self::repository($repository->getRepoName(), AdvisoryCoverage::NOT_ASKED, $reason);
            }
        }

        return $repositories;
    }

    /**
     * Each advisory-capable repository once, in configured order, with every asked name.
     *
     * @param list<string> $names
     *
     * @return array{list<array{composer_repository: string, outcome: string, reason: ?string, message: ?string, records: ?int, packages_with_records: ?int}>, array<int, array<string, list<PartialSecurityAdvisory>>>, list<RunNote>}
     *                     the repositories, the answer of each one that answered by its index there, and the notes
     */
    private function ask(array $names): array
    {
        $map = array_fill_keys($names, new MatchAllConstraint());
        $repositories = [];
        $answers = [];
        $notes = [];
        $checked = 0;
        $budgetSpent = false;
        foreach ($this->repositories as $repository) {
            if (!$repository instanceof AdvisoryProviderInterface) {
                continue;
            }
            if ($budgetSpent || $this->deadline->isPast()) {
                if (!$budgetSpent) {
                    $notes[] = RunNote::advisoriesNotChecked(RunNote::INSTALL_TIME_BUDGET, $checked);
                    $budgetSpent = true;
                }
                $repositories[] = self::repository($repository->getRepoName(), AdvisoryCoverage::NOT_ASKED, AdvisoryCoverage::INSTALL_TIME_BUDGET);
                continue;
            }
            ++$checked;

            try {
                if (!$repository->hasSecurityAdvisories()) {
                    $repositories[] = self::repository($repository->getRepoName(), AdvisoryCoverage::NO_FEED);
                    continue;
                }
                $answers[\count($repositories)] = $repository->getSecurityAdvisories($map, false)['advisories'];
                $repositories[] = self::repository($repository->getRepoName(), AdvisoryCoverage::ANSWERED);
            } catch (\Throwable $e) {
                // ComposerRepository::fetchFile() can throw a ParsingException, a
                // RepositorySecurityException or a LogicException, none of them a RuntimeException.
                // A failing repository must become a run note, never the end of the report. Only a
                // TransportException is a network failure, which the note decides.
                $note = RunNote::advisoriesUnavailable($repository->getRepoName(), $e);
                $notes[] = $note;
                $message = $note->data()['message'] ?? null;
                $repositories[] = self::repository($repository->getRepoName(), AdvisoryCoverage::FAILED, $e instanceof TransportException ? AdvisoryCoverage::TRANSPORT : AdvisoryCoverage::INVALID_RESPONSE, \is_string($message) ? $message : null);
            }
        }

        return [$repositories, $answers, $notes];
    }

    /** @return array{composer_repository: string, outcome: string, reason: ?string, message: ?string, records: ?int, packages_with_records: ?int} */
    private static function repository(string $name, string $outcome, ?string $reason = null, ?string $message = null): array
    {
        return ['composer_repository' => RepositoryUrl::inText($name), 'outcome' => $outcome, 'reason' => $reason, 'message' => $message, 'records' => null, 'packages_with_records' => null];
    }

    /**
     * @param array<string, string>                                                                                                                  $versionByName
     * @param array<string, true>                                                                                                                    $outside
     * @param list<array{composer_repository: string, outcome: string, reason: ?string, message: ?string, records: ?int, packages_with_records: ?int}> $repositories
     * @param array<int, array<string, list<PartialSecurityAdvisory>>>                                                                               $answers
     * @param list<RunNote>                                                                                                                          $notes
     */
    private function batch(array $versionByName, array $outside, array $repositories, array $answers, array $notes): AdvisoryBatch
    {
        /** @var array<string, array<string, PartialSecurityAdvisory>> $every name => advisory id => advisory, so an advisory that two repositories serve counts once */
        $every = [];
        foreach ($repositories as $index => $repository) {
            if (!isset($answers[$index])) {
                continue;
            }
            $ids = [];
            $withRecords = 0;
            foreach ($answers[$index] as $name => $advisories) {
                foreach ($advisories as $advisory) {
                    $every[$name][$advisory->advisoryId] = $advisory;
                    $ids[$advisory->advisoryId] = true;
                }
                $withRecords += $advisories === [] ? 0 : 1;
            }
            $repository['records'] = \count($ids);
            $repository['packages_with_records'] = $withRecords;
            $repositories[$index] = $repository;
        }
        [$counted, $ignored, $kept] = $this->attribute($every, $versionByName, $outside);
        $complete = $answers !== [] && \count($answers) === \count(array_filter($repositories, static fn (array $repository): bool => $repository['outcome'] !== AdvisoryCoverage::NO_FEED));

        return new AdvisoryBatch($counted, $notes, $ignored, $kept, $this->coverage($repositories, $versionByName, $outside, $answers, null), $complete);
    }

    /**
     * Counts each advisory that affects the installed version, unless the ignore lists keep it out,
     * as Composer's Auditor matches it: the advisory's range against `== <normalized version>`.
     * A version the parser rejects is not attributed.
     *
     * @param array<string, array<string, PartialSecurityAdvisory>> $every
     * @param array<string, string>                                 $versionByName
     * @param array<string, true>                                   $outside
     *
     * @return array{array<string, list<Advisory>>, array<string, list<IgnoredAdvisory>>, array<string, list<Advisory>>}
     */
    private function attribute(array $every, array $versionByName, array $outside): array
    {
        $counted = [];
        $ignored = [];
        $kept = [];
        foreach ($every as $name => $advisories) {
            $name = (string) $name;
            $installed = isset($outside[$name]) ? null : $this->normalized($versionByName[$name] ?? null);
            foreach ($advisories as $advisory) {
                $match = $this->ignore->match($name, $advisory);
                if ($match === null) {
                    $kept[$name][] = Advisory::fromComposer($advisory);
                }
                if ($installed === null || !$advisory->affectedVersions->matches(new Constraint('==', $installed))) {
                    continue;
                }
                if ($match === null) {
                    $counted[$name][] = Advisory::fromComposer($advisory);
                } else {
                    $ignored[$name][] = new IgnoredAdvisory(Advisory::fromComposer($advisory), $match);
                }
            }
        }

        return [$counted, $ignored, $kept];
    }

    private function normalized(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }
        try {
            return $this->parser->normalize($version);
        } catch (\UnexpectedValueException $e) {
            return null;
        }
    }

    /**
     * @param list<array{composer_repository: string, outcome: string, reason: ?string, message: ?string, records: ?int, packages_with_records: ?int}> $repositories
     * @param array<string, string>                                                                                                                  $versionByName
     * @param array<string, true>                                                                                                                    $outside
     * @param array<int, array<string, list<PartialSecurityAdvisory>>>                                                                               $answers
     * @param ?string                                                                                                                                $notAsked why no repository was asked, null when they were
     */
    private function coverage(array $repositories, array $versionByName, array $outside, array $answers, ?string $notAsked): AdvisoryCoverage
    {
        $byName = [];
        foreach ($versionByName as $name => $version) {
            $name = (string) $name;
            $byName[$name] = isset($outside[$name])
                ? self::outsideCoverage($repositories, $notAsked)
                : $this->nameCoverage($name, $version, $repositories, $answers, $notAsked);
        }
        $other = \count($this->repositories) - \count($repositories);

        return new AdvisoryCoverage($this->scope, $this->scopeSource, $repositories, $other, $byName);
    }

    /** @param list<array{composer_repository: string, outcome: string, reason: ?string, message: ?string, records: ?int, packages_with_records: ?int}> $repositories */
    private static function outsideCoverage(array $repositories, ?string $notAsked): AdvisoryNameCoverage
    {
        $feeds = [];
        foreach ($repositories as $repository) {
            if (\in_array($repository['outcome'], [AdvisoryCoverage::ANSWERED, AdvisoryCoverage::FAILED], true)) {
                $feeds[] = ['composer_repository' => $repository['composer_repository'], 'answer' => AdvisoryCoverage::NOT_ASKED, 'reason' => AdvisoryCoverage::NOT_FROM_COMPOSER_REPOSITORY, 'message' => null, 'records' => null];
            }
        }

        return new AdvisoryNameCoverage($feeds, null, $notAsked === AdvisoryCoverage::DISABLED_BY_POLICY ? $notAsked : AdvisoryCoverage::NOT_FROM_COMPOSER_REPOSITORY);
    }

    /**
     * @param list<array{composer_repository: string, outcome: string, reason: ?string, message: ?string, records: ?int, packages_with_records: ?int}> $repositories
     * @param array<int, array<string, list<PartialSecurityAdvisory>>>                                                                               $answers
     */
    private function nameCoverage(string $name, string $version, array $repositories, array $answers, ?string $notAsked): AdvisoryNameCoverage
    {
        if ($notAsked !== null) {
            return new AdvisoryNameCoverage([], null, $notAsked);
        }
        $feeds = [];
        $ids = [];
        foreach ($repositories as $index => $repository) {
            if ($repository['outcome'] === AdvisoryCoverage::ANSWERED) {
                $records = array_map(static fn (PartialSecurityAdvisory $advisory): string => $advisory->advisoryId, $answers[$index][$name] ?? []);
                $ids += array_fill_keys($records, true);
                $feeds[] = ['composer_repository' => $repository['composer_repository'], 'answer' => AdvisoryCoverage::ANSWERED, 'reason' => null, 'message' => null, 'records' => \count(array_unique($records))];
            } elseif ($repository['outcome'] === AdvisoryCoverage::FAILED) {
                $feeds[] = ['composer_repository' => $repository['composer_repository'], 'answer' => AdvisoryCoverage::FAILED, 'reason' => $repository['reason'], 'message' => $repository['message'], 'records' => null];
            }
        }
        $answered = array_filter($feeds, static fn (array $feed): bool => $feed['answer'] === AdvisoryCoverage::ANSWERED);

        return new AdvisoryNameCoverage($feeds, $answered === [] ? null : \count($ids), self::incomplete($feeds, $repositories, $this->normalized($version) === null));
    }

    /**
     * @param list<array{composer_repository: string, answer: string, reason: ?string, message: ?string, records: ?int}>                                $feeds
     * @param list<array{composer_repository: string, outcome: string, reason: ?string, message: ?string, records: ?int, packages_with_records: ?int}> $repositories
     */
    private static function incomplete(array $feeds, array $repositories, bool $unparseable): ?string
    {
        $budgetSpent = \in_array(AdvisoryCoverage::INSTALL_TIME_BUDGET, array_column($repositories, 'reason'), true);
        if ($feeds === []) {
            return $budgetSpent ? AdvisoryCoverage::INSTALL_TIME_BUDGET : AdvisoryCoverage::NO_FEED;
        }
        if ($unparseable) {
            return AdvisoryCoverage::UNPARSEABLE_VERSION;
        }
        if (\in_array(AdvisoryCoverage::FAILED, array_column($feeds, 'answer'), true)) {
            return AdvisoryCoverage::LOOKUP_FAILED;
        }

        return $budgetSpent ? AdvisoryCoverage::INSTALL_TIME_BUDGET : null;
    }
}
