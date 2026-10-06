<?php

declare(strict_types=1);

namespace Attestwire\Exception;

/**
 * The hosted API answered with an HTTP error status.
 *
 * Mirrors the error envelope every non-2xx response carries:
 * `{"error": "...", "message": "...", "docs": "...", ...}`, as documented at
 * https://api.attestwire.com/docs.
 */
class ApiException extends AttestwireException
{
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly ?string $docsUrl = null,
        private readonly ?string $upgradeUrl = null
    ) {
        parent::__construct(sprintf('%d %s: %s', $status, $errorCode, $message));
    }

    /** The HTTP status code, e.g. 401, 413, 429. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** The stable machine-readable code, e.g. "invalid_api_key". */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** Where the code is documented, when the API said. */
    public function getDocsUrl(): ?string
    {
        return $this->docsUrl;
    }

    /** Present on 402 plan_required: where a human upgrades the plan. */
    public function getUpgradeUrl(): ?string
    {
        return $this->upgradeUrl;
    }
}
