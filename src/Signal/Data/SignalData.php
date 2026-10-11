<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

/**
 * The data of one signal, as report-2 writes it in `findings[].signals[].data`. Only the encoder
 * and the report-1 view {@see \Lockrot\Legacy\SignalData013} read the serialized form. Any other
 * reader selects the data by its class and reads its typed values.
 *
 * @internal
 */
interface SignalData extends \JsonSerializable
{
    /** The id of the signal that carries this data. Each class has one id, so the two cannot disagree. */
    public function signalId(): string;

    /** @return array<string, scalar|list<string|\JsonSerializable>|array{project: ?bool, target: ?bool}|null> */
    public function jsonSerialize(): array;
}
