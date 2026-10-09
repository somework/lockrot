<?php

declare(strict_types=1);

namespace Lockrot\Score;

/**
 * One entry of `finding.score.terms[]`.
 *
 * @internal
 *
 * @phpstan-import-type Shape from MaintenanceTerm as MaintenanceShape
 * @phpstan-import-type Shape from SecurityTerm as SecurityShape
 */
interface Term
{
    public function flag(): string;

    public function role(): string;

    /** @return MaintenanceShape|SecurityShape */
    public function toArray(): array;
}
