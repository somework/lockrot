<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support\Contract;

/** A renderer read a field that its listed inputs leave out. */
final class MissingField extends \OutOfRangeException
{
    public function __construct(string $field)
    {
        parent::__construct('reads "'.$field.'", which its listed fields leave out');
    }
}
