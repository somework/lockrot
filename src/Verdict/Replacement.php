<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

/**
 * The replacement a `replace` move names (`next_step.replacement`). `suggestion` is the registry's
 * or the lock's text: data, never a sentence or markup.
 *
 * @internal
 */
final class Replacement implements \JsonSerializable
{
    private ?string $package;
    private string $suggestion;
    private ?string $url;
    private string $namedBy;
    private ?GradedPackage $finding = null;

    /**
     * @param string  $namedBy        `repository` or `lock`: S1's `marked_by`
     * @param ?string $replacementUrl S1's `replacement_url`: the registry page of the package
     */
    public function __construct(string $package, string $suggestion, string $namedBy, ?string $replacementUrl)
    {
        $this->package = Finding::successorOf($package, $suggestion);
        $this->suggestion = $suggestion;
        $this->namedBy = $namedBy;
        $this->url = $this->package !== null
            ? $replacementUrl
            : (preg_match('{^https?://}i', $suggestion) === 1 ? $suggestion : null);
    }

    /** Null unless the replacement package is graded in the same report. */
    public function withFinding(?GradedPackage $finding): self
    {
        $clone = clone $this;
        $clone->finding = $finding !== null && $finding->isGraded() ? $finding->asReplacement() : null;

        return $clone;
    }

    public function package(): ?string
    {
        return $this->package;
    }

    public function suggestion(): string
    {
        return $this->suggestion;
    }

    public function namedBy(): string
    {
        return $this->namedBy;
    }

    public function finding(): ?GradedPackage
    {
        return $this->finding;
    }

    /** @return array{package: ?string, suggestion: string, url: ?string, named_by: string, finding: ?GradedPackage} */
    public function jsonSerialize(): array
    {
        return ['package' => $this->package, 'suggestion' => $this->suggestion, 'url' => $this->url, 'named_by' => $this->namedBy, 'finding' => $this->finding];
    }
}
