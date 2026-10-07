<?php

declare(strict_types=1);

namespace Lockrot\Security;

/**
 * One link of the lock that excludes a candidate release: a `held_by[]` entry of SPEC-0.14 5.3,
 * without its `holder` block, which the report fills from the holder's own finding.
 *
 * @internal
 */
final class Holder
{
    public const ROOT = 'root';
    public const PACKAGE = 'package';

    public const REQUIRE = 'require';
    public const REQUIRE_DEV = 'require-dev';
    public const CONFLICT = 'conflict';

    /** @var self::ROOT|self::PACKAGE */
    private string $source;
    private ?string $package;
    private ?string $version;
    /** @var self::REQUIRE|self::REQUIRE_DEV|self::CONFLICT */
    private string $link;
    private string $constraint;

    /**
     * @param self::ROOT|self::PACKAGE                     $source
     * @param ?string                                      $package the root's `name` (null when composer.json has none) or the locked package
     * @param ?string                                      $version the locked package's version, null for the root
     * @param self::REQUIRE|self::REQUIRE_DEV|self::CONFLICT $link    a locked package's link is `require` or `conflict`, from packages-dev too
     */
    public function __construct(string $source, ?string $package, ?string $version, string $link, string $constraint)
    {
        $this->source = $source;
        $this->package = $package;
        $this->version = $version;
        $this->link = $link;
        $this->constraint = $constraint;
    }

    /** @return self::ROOT|self::PACKAGE */
    public function source(): string
    {
        return $this->source;
    }

    public function package(): ?string
    {
        return $this->package;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    /** @return self::REQUIRE|self::REQUIRE_DEV|self::CONFLICT */
    public function link(): string
    {
        return $this->link;
    }

    public function constraint(): string
    {
        return $this->constraint;
    }

    /** @return array{source: string, package: ?string, version: ?string, link: string, constraint: string} */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'package' => $this->package,
            'version' => $this->version,
            'link' => $this->link,
            'constraint' => $this->constraint,
        ];
    }
}
