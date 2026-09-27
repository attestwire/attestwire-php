<?php

declare(strict_types=1);

namespace Attestwire;

use JsonSerializable;

/**
 * The result of validating one document against EN 16931 and its CIUS rules.
 *
 * `findings` is `errors + warnings + information`, in that order, each
 * already carrying its own `severity` — filter it however you like, or use
 * `errors()` / `warnings()` / `information()` below. `valid` is true exactly
 * when there is no `severity === "fatal"` finding, matching the API's own
 * `valid` field.
 */
final class ValidationResult implements JsonSerializable
{
    /** @param list<Finding> $findings */
    public function __construct(
        public readonly bool $valid,
        public readonly array $findings,
        public readonly ?string $profile = null,
        /** "ubl" or "cii" — which syntax was read. Null for a JSON InvoiceInput body. */
        public readonly ?string $syntax = null,
        /** The PDF attachment the XML was read from (Factur-X/ZUGFeRD), or null. */
        public readonly ?string $container = null,
        /** Plain-English statement of what was checked and what it does not prove. */
        public readonly ?string $source = null,
        /** The full decoded JSON this was built from, for anything not modelled above. */
        public readonly ?array $raw = null
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        $findings = [];
        foreach (['errors', 'warnings', 'information'] as $key) {
            foreach ($body[$key] ?? [] as $item) {
                $findings[] = Finding::fromArray($item);
            }
        }

        return new self(
            (bool) ($body['valid'] ?? false),
            $findings,
            isset($body['profile']) ? (string) $body['profile'] : null,
            isset($body['syntax']) ? (string) $body['syntax'] : null,
            $body['container'] ?? null,
            isset($body['source']) ? (string) $body['source'] : null,
            $body
        );
    }

    /** @return list<Finding> severity === "fatal"; non-empty means valid === false */
    public function errors(): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => $f->isFatal()));
    }

    /** @return list<Finding> severity === "warning" */
    public function warnings(): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => $f->isWarning()));
    }

    /** @return list<Finding> severity === "information" */
    public function information(): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => $f->isInformation()));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'valid' => $this->valid,
            'profile' => $this->profile,
            'syntax' => $this->syntax,
            'container' => $this->container,
            'source' => $this->source,
            'findings' => array_map(static fn (Finding $f): array => $f->jsonSerialize(), $this->findings),
        ];
    }
}
