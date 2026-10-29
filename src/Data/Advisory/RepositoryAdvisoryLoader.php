<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\Downloader\TransportException;
use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\FilterRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;
use Lockrot\Analyzer\RunNote;
use Lockrot\Deadline;

/**
 * One request per advisory-capable repository asks every advisory of every name
 * ({@see \Composer\Repository\AdvisoryProviderInterface}: `MatchAllConstraint`), then attributes them
 * locally as {@see \Composer\Repository\RepositorySet::getMatchingSecurityAdvisories()} does. Full
 * records only: a partial cached record holds an id and a range, and can be withdrawn. Composer
 * hard-codes a ten-second POST timeout, so the deadline is checked before each repository.
 * Scope and records: docs/verdicts.md#which-advisories-count.
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

    public function load(array $versionByName, array $notFromComposerRepository = [], array $aliasVersionsByName = []): AdvisoryBatch
    {
        if ($versionByName === []) {
            return new AdvisoryBatch([], [], [], [], new AdvisoryCoverage($this->scope, $this->scopeSource, [], 0, []));
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

        $disabledBy = $this->ignore->disabledBy();
        if ($disabledBy !== null) {
            $notes[] = RunNote::advisoriesDisabledByPolicy($disabledBy['policy_key'], $disabledBy['value']);
        }
        [$repositories, $answers, $askNotes] = $this->ask(array_keys($asked));

        return $this->batch($versionByName, $outside, $aliasVersionsByName, $repositories, $answers, array_merge($notes, $askNotes))
            ->withRecords(self::merged($answers), self::readEvery($repositories) ? array_keys($asked) : []);
    }

    /**
     * Every record of each name, ignored ones included: the move check's lookup of the names that
     * a solve's pool holds and the run did not ask. A partial record comes from the metadata that
     * the solve loaded. A name that no repository answers has no record.
     *
     * @param list<string> $names
     *
     * @return array<string, list<PartialSecurityAdvisory>>|null null when a repository with a feed was not asked or failed
     */
    public function records(array $names, bool $complete): ?array
    {
        if ($names === []) {
            return [];
        }
        if ($this->notAskedReason(array_fill_keys($names, '')) !== null) {
            return null;
        }
        [$repositories, $answers] = $this->ask($names, !$complete);

        return self::readEvery($repositories) ? self::merged($answers) : null;
    }

    /** @param list<AdvisoryRepository> $repositories */
    private static function readEvery(array $repositories): bool
    {
        foreach ($repositories as $repository) {
            if (\in_array($repository->outcome(), [AdvisoryCoverage::FAILED, AdvisoryCoverage::NOT_ASKED], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, list<PartialSecurityAdvisory>>> $answers
     *
     * @return array<string, list<PartialSecurityAdvisory>>
     */
    private static function merged(array $answers): array
    {
        $records = [];
        foreach ($answers as $answer) {
            foreach ($answer as $name => $advisories) {
                $records[$name] = array_merge($records[$name] ?? [], $advisories);
            }
        }

        return $records;
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
        if ($this->offline) {
            return [AdvisoryCoverage::OFFLINE, RunNote::advisoriesNotChecked(RunNote::ADVISORIES_OFFLINE, 0)];
        }

        return $asked === [] ? [AdvisoryCoverage::NOT_FROM_COMPOSER_REPOSITORY, null] : null;
    }

    /** @return list<AdvisoryRepository> */
    private function notAsked(string $reason): array
    {
        $repositories = [];
        foreach ($this->repositories as $repository) {
            $inner = $repository instanceof FilterRepository ? $repository->getRepository() : $repository;
            if ($inner instanceof AdvisoryProviderInterface) {
                $repositories[] = AdvisoryRepository::of($repository->getRepoName(), AdvisoryCoverage::NOT_ASKED, $reason);
            }
        }

        return $repositories;
    }

    /**
     * Each advisory-capable repository once, in configured order, with every asked name.
     *
     * @param list<string> $names
     * @param bool         $partial whether a repository can answer from its packages' metadata, with partial records
     *
     * @return array{list<AdvisoryRepository>, array<int, array<string, list<PartialSecurityAdvisory>>>, list<RunNote>}
     *                     the repositories, the answer of each one that answered by its index there, and the notes
     */
    private function ask(array $names, bool $partial = false): array
    {
        $map = array_fill_keys($names, new MatchAllConstraint());
        $repositories = [];
        $answers = [];
        $notes = [];
        $checked = 0;
        $budgetSpent = false;
        foreach ($this->repositories as $repository) {
            if (!$repository instanceof AdvisoryProviderInterface || !self::isAdvisoryCapable($repository)) {
                continue;
            }
            if ($budgetSpent || $this->deadline->isPast()) {
                if (!$budgetSpent) {
                    $notes[] = RunNote::advisoriesNotChecked(RunNote::INSTALL_TIME_BUDGET, $checked);
                    $budgetSpent = true;
                }
                $repositories[] = AdvisoryRepository::of($repository->getRepoName(), AdvisoryCoverage::NOT_ASKED, AdvisoryCoverage::INSTALL_TIME_BUDGET);
                continue;
            }
            ++$checked;

            try {
                if (!$repository->hasSecurityAdvisories()) {
                    $repositories[] = AdvisoryRepository::of($repository->getRepoName(), AdvisoryCoverage::NO_FEED);
                    continue;
                }
                $answer = $repository->getSecurityAdvisories($map, $partial)['advisories'];
                if (!$partial) {
                    self::refusePartialRecords($repository->getRepoName(), $answer);
                }
                $answers[\count($repositories)] = $answer;
                $repositories[] = AdvisoryRepository::of($repository->getRepoName(), AdvisoryCoverage::ANSWERED);
            } catch (\Throwable $e) {
                // ComposerRepository::fetchFile() can throw a ParsingException, a
                // RepositorySecurityException or a LogicException, none of them a RuntimeException.
                // A failing repository must become a run note, never the end of the report. Only a
                // TransportException is a network failure, which the note decides.
                $note = RunNote::advisoriesUnavailable($repository->getRepoName(), $e);
                $notes[] = $note;
                $message = $note->data()['message'] ?? null;
                $repositories[] = AdvisoryRepository::of($repository->getRepoName(), AdvisoryCoverage::FAILED, $e instanceof TransportException ? AdvisoryCoverage::TRANSPORT : AdvisoryCoverage::INVALID_RESPONSE, \is_string($message) ? $message : null);
            }
        }

        return [$repositories, $answers, $notes];
    }

    /**
     * No finding rests on a partial record, which a repository can withdraw after the cache holds
     * it. Composer 2.10 refuses a partial record when full records are asked, and Composer 2.11
     * returns it, so lockrot refuses it on each minor.
     *
     * @param array<string, array<PartialSecurityAdvisory>> $answer
     *
     * @throws \RuntimeException for a record that is not a full advisory
     */
    private static function refusePartialRecords(string $repoName, array $answer): void
    {
        foreach ($answer as $name => $advisories) {
            foreach ($advisories as $advisory) {
                if (!$advisory instanceof SecurityAdvisory) {
                    throw new \RuntimeException('Advisory for '.$name.' could not be loaded as a full advisory from '.$repoName);
                }
            }
        }
    }

    /**
     * Composer wraps a repository with `only`, `exclude` or `canonical` in a FilterRepository, which
     * implements the interface whatever it wraps. Naming a wrapped vcs repository can start network I/O.
     */
    private static function isAdvisoryCapable(RepositoryInterface $repository): bool
    {
        if ($repository instanceof FilterRepository) {
            return $repository->getRepository() instanceof AdvisoryProviderInterface;
        }

        return $repository instanceof AdvisoryProviderInterface;
    }

    /**
     * @param array<string, string>                                                                                                                  $versionByName
     * @param array<string, true>                                                                                                                    $outside
     * @param array<string, list<string>>                                                                                                            $aliasVersionsByName
     * @param list<AdvisoryRepository> $repositories
     * @param array<int, array<string, list<PartialSecurityAdvisory>>>                                                                               $answers
     * @param list<RunNote>                                                                                                                          $notes
     */
    private function batch(array $versionByName, array $outside, array $aliasVersionsByName, array $repositories, array $answers, array $notes): AdvisoryBatch
    {
        /** @var array<string, array<string, list<PartialSecurityAdvisory>>> $records name => advisory id => each repository's record of it */
        $records = [];
        foreach ($repositories as $index => $repository) {
            if (!isset($answers[$index])) {
                continue;
            }
            $ids = [];
            $withRecords = 0;
            foreach ($answers[$index] as $name => $advisories) {
                foreach ($advisories as $advisory) {
                    $records[$name][$advisory->advisoryId][] = $advisory;
                    $ids[$advisory->advisoryId] = true;
                }
                if ($advisories !== []) {
                    ++$withRecords;
                }
            }
            $repositories[$index] = $repository->withRecords(\count($ids), $withRecords);
        }
        [$counted, $ignored, $kept] = $this->attribute($records, $versionByName, $outside, $aliasVersionsByName);
        $complete = $answers !== [] && \count($answers) === \count(array_filter($repositories, static fn (AdvisoryRepository $repository): bool => $repository->outcome() !== AdvisoryCoverage::NO_FEED));

        return new AdvisoryBatch($counted, $notes, $ignored, $kept, $this->coverage($repositories, $versionByName, $outside, $answers, null), $complete);
    }

    /**
     * Counts each advisory that affects the installed version or one of its aliases, unless the
     * ignore lists keep it out, as Composer's Auditor matches it: each repository's record of the
     * advisory against `== <normalized version>`. A version the parser rejects is not attributed.
     *
     * @param array<string, array<string, list<PartialSecurityAdvisory>>> $records
     * @param array<string, string>                                       $versionByName
     * @param array<string, true>                                         $outside
     * @param array<string, list<string>>                                 $aliasVersionsByName
     *
     * @return array{array<string, list<Advisory>>, array<string, list<IgnoredAdvisory>>, array<string, list<Advisory>>}
     */
    private function attribute(array $records, array $versionByName, array $outside, array $aliasVersionsByName): array
    {
        $counted = [];
        $ignored = [];
        $kept = [];
        foreach ($records as $name => $byId) {
            $name = (string) $name;
            $installed = isset($outside[$name]) ? null : $this->normalized($versionByName[$name] ?? null);
            $versions = $installed === null ? [] : array_merge([$installed], $aliasVersionsByName[$name] ?? []);
            foreach ($byId as $copies) {
                foreach ($copies as $copy) {
                    if ($this->ignore->match($name, $copy) === null) {
                        $kept[$name][] = Advisory::fromComposer($copy);
                        break;
                    }
                }
                // Composer's Auditor judges each repository's record on its own: one that no rule
                // ignores is reported, whatever another repository's record of the id says.
                $firstIgnored = null;
                foreach (self::affecting($copies, $versions) as $copy) {
                    $match = $this->ignore->match($name, $copy);
                    if ($match === null) {
                        $counted[$name][] = Advisory::fromComposer($copy);
                        $firstIgnored = null;
                        break;
                    }
                    $firstIgnored ??= new IgnoredAdvisory(Advisory::fromComposer($copy), $match);
                }
                if ($firstIgnored !== null) {
                    $ignored[$name][] = $firstIgnored;
                }
            }
        }

        return [$counted, $ignored, $kept];
    }

    /**
     * @param list<PartialSecurityAdvisory> $copies
     * @param list<string>                  $versions
     *
     * @return list<PartialSecurityAdvisory> the records whose range holds one of the versions
     */
    private static function affecting(array $copies, array $versions): array
    {
        return array_values(array_filter($copies, static function (PartialSecurityAdvisory $advisory) use ($versions): bool {
            foreach ($versions as $version) {
                if ($advisory->affectedVersions->matches(new Constraint('==', $version))) {
                    return true;
                }
            }

            return false;
        }));
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
     * @param list<AdvisoryRepository> $repositories
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
                ? self::outsideCoverage($repositories)
                : $this->nameCoverage($name, $version, $repositories, $answers, $notAsked);
        }
        $other = \count($this->repositories) - \count($repositories);

        return new AdvisoryCoverage($this->scope, $this->scopeSource, $repositories, $other, $byName);
    }

    /** @param list<AdvisoryRepository> $repositories */
    private static function outsideCoverage(array $repositories): AdvisoryNameCoverage
    {
        $feeds = [];
        foreach ($repositories as $repository) {
            if ($repository->outcome() !== AdvisoryCoverage::NO_FEED) {
                $feeds[] = AdvisoryFeed::notAsked($repository->composerRepository(), AdvisoryCoverage::NOT_FROM_COMPOSER_REPOSITORY);
            }
        }

        return new AdvisoryNameCoverage($feeds, null, AdvisoryCoverage::NOT_FROM_COMPOSER_REPOSITORY);
    }

    /**
     * @param list<AdvisoryRepository> $repositories
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
            if ($repository->outcome() === AdvisoryCoverage::ANSWERED) {
                $records = array_map(static fn (PartialSecurityAdvisory $advisory): string => $advisory->advisoryId, $answers[$index][$name] ?? []);
                $ids += array_fill_keys($records, true);
                $feeds[] = AdvisoryFeed::answered($repository->composerRepository(), \count(array_unique($records)));
            } elseif ($repository->outcome() === AdvisoryCoverage::FAILED) {
                $feeds[] = AdvisoryFeed::failed($repository->composerRepository(), $repository->message());
            }
        }
        $answered = array_filter($feeds, static fn (AdvisoryFeed $feed): bool => $feed->answer() === AdvisoryCoverage::ANSWERED);

        return new AdvisoryNameCoverage($feeds, $answered === [] ? null : \count($ids), self::incomplete($feeds, $repositories, $this->normalized($version) === null));
    }

    /**
     * @param list<AdvisoryFeed>                                $feeds
     * @param list<AdvisoryRepository> $repositories
     */
    private static function incomplete(array $feeds, array $repositories, bool $unparseable): ?string
    {
        $budgetSpent = \in_array(AdvisoryCoverage::INSTALL_TIME_BUDGET, array_map(static fn (AdvisoryRepository $repository): ?string => $repository->reason(), $repositories), true);
        if ($feeds === []) {
            return $budgetSpent ? AdvisoryCoverage::INSTALL_TIME_BUDGET : AdvisoryCoverage::NO_FEED;
        }
        if ($unparseable) {
            return AdvisoryCoverage::UNPARSEABLE_VERSION;
        }
        if (\in_array(AdvisoryCoverage::FAILED, array_map(static fn (AdvisoryFeed $feed): string => $feed->answer(), $feeds), true)) {
            return AdvisoryCoverage::LOOKUP_FAILED;
        }

        return $budgetSpent ? AdvisoryCoverage::INSTALL_TIME_BUDGET : null;
    }
}
