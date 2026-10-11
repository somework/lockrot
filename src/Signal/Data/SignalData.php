<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

/**
 * The data of one signal, as report-2 writes it in `findings[].signals[].data`. Only the encoder
 * and the report-1 view {@see \Lockrot\Legacy\SignalData013} read the serialized form. Any other
 * reader asks the class of the signal's id for its typed values.
 *
 * @internal
 */
interface SignalData extends \JsonSerializable
{
    /** @return array<string, scalar|list<string|\JsonSerializable>|array{project: ?bool, target: ?bool}|null> */
    public function jsonSerialize(): array;
}
