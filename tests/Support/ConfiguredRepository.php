<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use Composer\Repository\ArrayRepository;
use Composer\Repository\ConfigurableRepositoryInterface;

/** A repository that the version source does not load, with the config that RepositoryManager gives a vcs repository. */
final class ConfiguredRepository extends ArrayRepository implements ConfigurableRepositoryInterface
{
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        parent::__construct();
        $this->config = $config;
    }

    public function getRepoConfig(): array
    {
        return $this->config;
    }

    public function getPackages(): array
    {
        throw new \LogicException('the version source must not load this repository');
    }
}
