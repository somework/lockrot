<?php

declare(strict_types=1);

namespace Lockrot\Exception;

/** @internal */
final class ConfigException extends \RuntimeException
{
    /** @var list<string> */
    private array $details = [];

    /**
     * An error whose message names what to fix, with the lines that only `-v` prints.
     *
     * @param list<string> $details
     */
    public static function withDetails(string $message, array $details, ?\Throwable $previous = null): self
    {
        $exception = new self($message, 0, $previous);
        $exception->details = $details;

        return $exception;
    }

    /** @return list<string> the lines of the error that only `-v` prints */
    public function details(): array
    {
        return $this->details;
    }
}
