<?php

declare(strict_types=1);

namespace Attestwire;

use JsonSerializable;

/**
 * Where a finding's element is in the document you sent.
 *
 * Only present on a finding for an actual document (XML or PDF bytes) — a
 * finding about the document as a whole (`rule` starting `AW-`) has no
 * location, because it is not about one element.
 */
final class Location implements JsonSerializable
{
    public function __construct(
        public readonly ?int $line = null,
        public readonly ?int $column = null,
        public readonly ?string $path = null,
        public readonly ?bool $exact = null,
        /** Only set when the document was a Factur-X/ZUGFeRD PDF: which embedded XML this is in. */
        public readonly ?string $attachment = null
    ) {
    }

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        return new self(
            isset($data['line']) ? (int) $data['line'] : null,
            isset($data['column']) ? (int) $data['column'] : null,
            isset($data['path']) ? (string) $data['path'] : null,
            isset($data['exact']) ? (bool) $data['exact'] : null,
            isset($data['attachment']) ? (string) $data['attachment'] : null
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'line' => $this->line,
            'column' => $this->column,
            'path' => $this->path,
            'exact' => $this->exact,
            'attachment' => $this->attachment,
        ];
    }
}
