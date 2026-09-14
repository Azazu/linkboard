<?php

declare(strict_types=1);

namespace App\Link\UseCase;

/**
 * The fields an update changes, and only those (design decision 2 of
 * add-web-ui). A field never named is left alone; a field named with null is
 * cleared. Null is a legal new value for the expiry, the click limit, the UTM
 * members and the rules, which is why "absent" cannot be expressed as null and
 * gets its own state.
 *
 * The two fields that may not be cleared — the target URL and the active flag —
 * are not nullable in their setters, so the type system enforces what the API
 * expresses as a violation. The slug is absent entirely: it is immutable.
 */
final class LinkChanges
{
    /** @var array<string, true> */
    private array $named = [];

    private string $targetUrl = '';
    /** @var array<string, string>|null */
    private ?array $utm = null;
    private ?\DateTimeImmutable $expiresAt = null;
    private ?int $maxClicks = null;
    /** @var array<string, mixed>|null */
    private ?array $rules = null;
    private bool $active = true;

    public function withTarget(string $targetUrl): self
    {
        $clone = clone $this;
        $clone->named['targetUrl'] = true;
        $clone->targetUrl = $targetUrl;

        return $clone;
    }

    /** @param array<string, string>|null $utm null clears every member */
    public function withUtm(?array $utm): self
    {
        $clone = clone $this;
        $clone->named['utm'] = true;
        $clone->utm = $utm;

        return $clone;
    }

    public function withExpiry(?\DateTimeImmutable $expiresAt): self
    {
        $clone = clone $this;
        $clone->named['expiresAt'] = true;
        $clone->expiresAt = $expiresAt;

        return $clone;
    }

    public function withClickLimit(?int $maxClicks): self
    {
        $clone = clone $this;
        $clone->named['maxClicks'] = true;
        $clone->maxClicks = $maxClicks;

        return $clone;
    }

    /** @param array<string, mixed>|null $rules canonical form; null clears the document */
    public function withRules(?array $rules): self
    {
        $clone = clone $this;
        $clone->named['rules'] = true;
        $clone->rules = $rules;

        return $clone;
    }

    public function withActive(bool $active): self
    {
        $clone = clone $this;
        $clone->named['isActive'] = true;
        $clone->active = $active;

        return $clone;
    }

    public function names(string $field): bool
    {
        return isset($this->named[$field]);
    }

    public function isEmpty(): bool
    {
        return [] === $this->named;
    }

    public function targetUrl(): string
    {
        return $this->targetUrl;
    }

    /** @return array<string, string>|null */
    public function utm(): ?array
    {
        return $this->utm;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function maxClicks(): ?int
    {
        return $this->maxClicks;
    }

    /** @return array<string, mixed>|null */
    public function rules(): ?array
    {
        return $this->rules;
    }

    public function active(): bool
    {
        return $this->active;
    }
}
