<?php

declare(strict_types=1);

namespace Lockrot\Signal;

/** @internal */
interface SignalRule
{
    public function evaluate(PackageFacts $facts): ?Signal;
}
