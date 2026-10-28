<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\ArrayRepository;

/**
 * The advisory repository that Composer's `SecurityAdvisoryPoolFilter` asks during one solve. It
 * answers from {@see PoolAdvisories} and keeps the asked names that the last call could not read:
 * that call's answer is the one that the filter blocks with.
 *
 * @internal
 */
final class PoolAdvisoryFeed extends ArrayRepository implements AdvisoryProviderInterface
{
    private PoolAdvisories $advisories;
    /** @var list<string> */
    private array $unread = [];

    public function __construct(PoolAdvisories $advisories)
    {
        parent::__construct();
        $this->advisories = $advisories;
    }

    public function hasSecurityAdvisories(): bool
    {
        return true;
    }

    public function getSecurityAdvisories(array $packageConstraintMap, bool $allowPartialAdvisories = false): array
    {
        $answer = $this->advisories->answer($packageConstraintMap, $allowPartialAdvisories);
        $this->unread = $answer['unread'];

        return ['namesFound' => $answer['namesFound'], 'advisories' => $answer['advisories']];
    }

    /** @return list<string> the names that the last call asked and could not read */
    public function unread(): array
    {
        return $this->unread;
    }
}
