<?php

declare(strict_types=1);

namespace Lockrot\Allowlist;

use Lockrot\Exception\ConfigException;
use Lockrot\Json\JsonReader;
use Lockrot\Signal\Data\Check;

/**
 * One entry of `extra.lockrot.accept-unchecked` (docs/configuration.md#accept-unchecked): a check
 * that lockrot did not run, accepted for the packages that one scope names, with a reason.
 *
 * @internal
 *
 * @phpstan-type Shape array{scope: string, value: string, reason: string, expires: ?string}
 */
final class UncheckedAcceptanceEntry implements \JsonSerializable
{
    public const CHECKS = ['repository_activity', 'release_metadata', 'advisories'];
    public const SCOPES = ['host', 'repository', 'package'];
    /** The S10 reasons that no rerun can close. Every other reason stays S10, whatever the list says. */
    public const ACCEPTABLE_REASONS = ['host_not_supported', 'no_feed', 'not_from_composer_repository'];

    /** config-2's `host` pattern: lower case, an optional port. */
    private const HOST = '{^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:[0-9]{1,5})?$}D';
    /** config-2's `repository` pattern: a scheme URL without userinfo, or the scp form `user@host:path`. */
    private const URL = '{^([a-z][a-z0-9+.-]*://[^\s/?#@]+([/?#]\S*)?|[A-Za-z0-9_.-]+@[A-Za-z0-9_.-]+:\S+)$}D';

    private string $check;
    private string $scope;
    private string $value;
    private string $reason;
    private ?string $expires;

    private function __construct(string $check, string $scope, string $value, string $reason, ?string $expires)
    {
        $this->check = $check;
        $this->scope = $scope;
        $this->value = $value;
        $this->reason = $reason;
        $this->expires = $expires;
    }

    /**
     * The checks beyond config-2's shape: a reason and a scope value that are not blank, a port from 1
     * to 65535, a real `expires` date.
     *
     * @param mixed $row
     *
     * @throws ConfigException naming the entry, for any entry that config-2 or these checks refuse
     */
    public static function fromConfig(int $index, $row): self
    {
        $at = \sprintf('extra.lockrot.accept-unchecked[%d]', $index);
        if (!\is_array($row)) {
            throw new ConfigException($at.' must be an object');
        }
        $row = JsonReader::stringKeyed($row);
        $check = $row['check'] ?? null;
        if (!\is_string($check) || !\in_array($check, self::CHECKS, true)) {
            throw new ConfigException(\sprintf('%s "check" must be one of %s', $at, implode(', ', self::CHECKS)));
        }
        $scopes = array_values(array_intersect(self::SCOPES, array_keys($row)));
        if (\count($scopes) !== 1) {
            throw new ConfigException(\sprintf('%s needs exactly one of "host", "repository" and "package"', $at));
        }
        $scope = $scopes[0];
        $value = $row[$scope];
        if (!\is_string($value) || trim($value) === '') {
            throw new ConfigException(\sprintf('%s "%s" must not be blank', $at, $scope));
        }
        $reason = $row['reason'] ?? null;
        if (!\is_string($reason) || trim($reason) === '') {
            throw new ConfigException($at.' needs a non-empty "reason"');
        }
        self::checkScope($at, $check, $scope, $value);

        return new self($check, $scope, $value, $reason, self::expires($at, $row['expires'] ?? null));
    }

    private static function checkScope(string $at, string $check, string $scope, string $value): void
    {
        if ($scope === 'host') {
            if ($check === 'advisories') {
                throw new ConfigException($at.' accepts "advisories" by host: scope it by repository or package, because the gap comes from the Composer repositories');
            }
            if (preg_match(self::HOST, $value) !== 1) {
                throw new ConfigException($at.' "host" must be a lower-case host name with an optional port, without a scheme or a path');
            }
            $colon = strrchr($value, ':');
            // FILTER_VALIDATE_INT refuses a leading zero, which the host pattern allows.
            if ($colon !== false && filter_var(ltrim(substr($colon, 1), '0'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
                throw new ConfigException($at.' "host" has a port outside 1 to 65535');
            }
        }
        if ($scope === 'repository' && (preg_match(self::URL, $value) !== 1 || self::urlKey($value) === null)) {
            throw new ConfigException($at.' "repository" must be a URL with a scheme and no user name or password, or the scp form user@host:path');
        }
    }

    /** @param mixed $expires */
    private static function expires(string $at, $expires): ?string
    {
        if ($expires === null) {
            return null;
        }
        $m = [];
        if (!\is_string($expires) || preg_match('{^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$}D', $expires, $m) !== 1 || !checkdate((int) $m['month'], (int) $m['day'], (int) $m['year'])) {
            throw new ConfigException($at.' "expires" must be a valid YYYY-MM-DD date');
        }

        return $expires;
    }

    /**
     * A repository URL as the `repository` scope compares it: the lower-case host, the port, the
     * case-sensitive path without a trailing `/` and `.git`, the query and the fragment. Null for a
     * URL without a host, such as `file://`.
     *
     * @return ?array{0: string, 1: ?int, 2: string, 3: ?string, 4: ?string}
     */
    private static function urlKey(string $url): ?array
    {
        if (preg_match('{^[a-z][a-z0-9+.-]*://}i', $url) === 1) {
            $parts = parse_url($url);
            $host = \is_array($parts) ? ($parts['host'] ?? '') : '';
            if ($host === '') {
                return null;
            }
            $key = [strtolower($host), $parts['port'] ?? null, $parts['path'] ?? '', $parts['query'] ?? null, $parts['fragment'] ?? null];
        } elseif (preg_match('{^[^@\s/]+@([^:\s/]+):(\S+)$}', $url, $m) === 1) {
            $key = [strtolower($m[1]), null, $m[2], null, null];
        } else {
            return null;
        }
        $path = trim($key[2], '/');
        if (substr($path, -4) === '.git') {
            $path = rtrim(substr($path, 0, -4), '/');
        }
        $key[2] = $path;

        return $key;
    }

    /**
     * Whether the entry accepts the S10 entry of the package: an acceptable reason, the same check,
     * not expired on the run's UTC date, and a scope that names the package.
     *
     * @param ?string $activityHost   `activity.host`, its GitLab path prefix included
     * @param ?string $activityRepo   `activity.repo`
     * @param ?string $sourceUrl      the lock entry's repository URL
     * @param ?string $composerUrl    the URL of the Composer repository the run read the package from
     */
    public function accepts(Check $unchecked, string $package, ?string $activityHost, ?string $activityRepo, ?string $sourceUrl, ?string $composerUrl, \DateTimeImmutable $now): bool
    {
        if (!\in_array($unchecked->reason(), self::ACCEPTABLE_REASONS, true) || $unchecked->check() !== $this->check) {
            return false;
        }
        if ($this->expires !== null && $this->expires < $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d')) {
            return false;
        }
        switch ($this->scope) {
            case 'package':
                return fnmatch($this->value, $package);
            case 'host':
                return $activityHost === null ? false : strtok($activityHost, '/') === $this->value;
            default:
                foreach ([$sourceUrl, $composerUrl, $activityRepo === null ? null : 'https://'.$activityHost.'/'.$activityRepo] as $url) {
                    if ($url !== null && self::urlKey($url) === self::urlKey($this->value)) {
                        return true;
                    }
                }

                return false;
        }
    }

    public function check(): string
    {
        return $this->check;
    }

    /** @return Shape `accepted_by`: the entry's scope value and reason, as the project wrote them */
    public function jsonSerialize(): array
    {
        return ['scope' => $this->scope, 'value' => $this->value, 'reason' => $this->reason, 'expires' => $this->expires];
    }
}
