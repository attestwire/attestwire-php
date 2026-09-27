<?php

declare(strict_types=1);

namespace Attestwire;

use JsonSerializable;

/**
 * One rule finding: the rule id, what's wrong, the fix, and where.
 *
 * Matches the hosted API's `TeachingError` shape exactly (see
 * the `TeachingError` schema in https://api.attestwire.com/openapi.json): `rule`, `field`, `severity`, `message`, `fix`, `xpath`,
 * `docsUrl`, `example`, `location`.
 */
final class Finding implements JsonSerializable
{
    /**
     * @param string|list<string>|null $field the business term(s) the rule constrains,
     *                                         e.g. "BT-10" or ["BT-31", "BT-32"]
     */
    public function __construct(
        public readonly ?string $rule,
        public readonly ?string $severity,
        public readonly ?string $message,
        public readonly ?string $fix = null,
        public readonly string|array|null $field = null,
        public readonly ?string $xpath = null,
        public readonly ?string $docsUrl = null,
        public readonly ?string $example = null,
        public readonly ?Location $location = null
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['rule']) ? (string) $data['rule'] : null,
            isset($data['severity']) ? (string) $data['severity'] : null,
            isset($data['message']) ? (string) $data['message'] : null,
            isset($data['fix']) ? (string) $data['fix'] : null,
            $data['field'] ?? null,
            isset($data['xpath']) ? (string) $data['xpath'] : null,
            isset($data['docsUrl']) ? (string) $data['docsUrl'] : null,
            isset($data['example']) ? (string) $data['example'] : null,
            Location::fromArray($data['location'] ?? null)
        );
    }

    public function isFatal(): bool
    {
        return $this->severity === 'fatal';
    }

    public function isWarning(): bool
    {
        return $this->severity === 'warning';
    }

    public function isInformation(): bool
    {
        return $this->severity === 'information';
    }

    /** The business term(s), joined with ", " when there's more than one; "" when there are none. */
    public function fieldAsString(): string
    {
        if ($this->field === null) {
            return '';
        }

        return is_array($this->field) ? implode(', ', $this->field) : $this->field;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'rule' => $this->rule,
            'severity' => $this->severity,
            'message' => $this->message,
            'fix' => $this->fix,
            'field' => $this->field,
            'xpath' => $this->xpath,
            'docsUrl' => $this->docsUrl,
            'example' => $this->example,
            'location' => $this->location?->jsonSerialize(),
        ];
    }
}
