<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

/**
 * The data of one signal, as report-2 writes it in `findings[].signals[].data`. Only the encoder
 * reads the serialized form: a reader asks the class of the signal's id for its typed values.
 *
 * @internal
 */
interface SignalData extends \JsonSerializable
{
}
