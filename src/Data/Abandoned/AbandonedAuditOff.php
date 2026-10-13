<?php

declare(strict_types=1);

namespace Lockrot\Data\Abandoned;

/**
 * The Composer setting that stops `composer audit` reporting abandoned packages. It changes what
 * `composer audit` prints, never the ignore list.
 *
 * @internal
 */
final class AbandonedAuditOff
{
    private string $policyKey;
    /** @var bool|string */
    private $value;

    /**
     * @param string      $policyKey a config key, or the environment variable `COMPOSER_AUDIT_ABANDONED`
     * @param bool|string $value     as configured
     */
    public function __construct(string $policyKey, $value)
    {
        $this->policyKey = $policyKey;
        $this->value = $value;
    }

    public function policyKey(): string
    {
        return $this->policyKey;
    }

    /** @return bool|string */
    public function value()
    {
        return $this->value;
    }
}
