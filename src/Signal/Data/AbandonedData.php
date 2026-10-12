<?php

declare(strict_types=1);

namespace Lockrot\Signal\Data;

use Lockrot\Signal\Signal;

/** @internal */
final class AbandonedData implements SignalData
{
    private string $markedBy;
    private ?string $replacement;
    private ?string $replacementUrl;

    /** @param ?string $replacement as the repository writes it, free text */
    public function __construct(string $markedBy, ?string $replacement, ?string $replacementUrl)
    {
        $this->markedBy = $markedBy;
        $this->replacement = $replacement;
        $this->replacementUrl = $replacementUrl;
    }

    public function signalId(): string
    {
        return Signal::S1;
    }

    public function markedBy(): string
    {
        return $this->markedBy;
    }

    public function replacement(): ?string
    {
        return $this->replacement;
    }

    public function replacementUrl(): ?string
    {
        return $this->replacementUrl;
    }

    /** @return array{marked_by: string, replacement: ?string, replacement_url: ?string} */
    public function jsonSerialize(): array
    {
        return ['marked_by' => $this->markedBy, 'replacement' => $this->replacement, 'replacement_url' => $this->replacementUrl];
    }
}
