<?php

declare(strict_types=1);

namespace Attestwire;

use Attestwire\Exception\ApiException;
use Attestwire\Exception\AttestwireException;
use Attestwire\Http\CurlHttpClient;
use Attestwire\Http\HttpClientInterface;

/**
 * A thin client for the hosted Attestwire API's `POST /v1/validate`.
 *
 * The request/response contract here is the hosted API's published one
 * (https://api.attestwire.com/openapi.json and https://api.attestwire.com/docs):
 * auth is `Authorization: Bearer <key>`, the body is the raw document (XML or
 * PDF bytes, or a JSON `InvoiceInput`) sent with the matching Content-Type,
 * and on every status — success or error — the response is JSON. A `200` is
 * a `ValidationResult`: `valid` plus three findings arrays
 * (`errors`/`warnings`/`information`), whether or not the invoice passed
 * (`valid: false` is still a normal, billable 200). An error status carries
 * `{error, message, docs, ...}`.
 *
 *     $client = new Client($apiKey);
 *     $result = $client->validate(file_get_contents('invoice.xml'));
 *     if (!$result->valid) {
 *         foreach ($result->errors() as $finding) {
 *             echo "{$finding->rule}: {$finding->message}\n";
 *             echo "  fix: {$finding->fix}\n";
 *         }
 *     }
 */
class Client
{
    public const DEFAULT_ORIGIN = 'https://api.attestwire.com';

    /** Read when no API key is passed to the constructor. */
    public const API_KEY_ENV_VAR = 'ATTESTWIRE_API_KEY';

    public const VERSION = '0.1.0';

    private readonly HttpClientInterface $httpClient;

    private readonly string $origin;

    public function __construct(
        private readonly ?string $apiKey = null,
        ?HttpClientInterface $httpClient = null,
        string $origin = self::DEFAULT_ORIGIN
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->origin = rtrim($origin, '/');
    }

    /**
     * Validate one e-invoice document: XML/PDF bytes, or a JSON-encoded
     * `InvoiceInput`.
     *
     * @param  string      $data           the raw document, exactly as it exists on disk
     *                                     or in memory — a UBL 2.1 Invoice/CreditNote, a
     *                                     UN/CEFACT CII CrossIndustryInvoice, a Factur-X/
     *                                     ZUGFeRD PDF, or a JSON InvoiceInput.
     * @param  null|string $contentType    force the Content-Type instead of sniffing
     *                                     `$data` with `ContentTypeSniffer` — one of
     *                                     "application/xml", "application/pdf",
     *                                     "application/json".
     * @param  float       $timeoutSeconds
     * @return ValidationResult
     *
     * @throws AttestwireException no API key available anywhere, or the
     *                             request never reached the API (DNS,
     *                             connection refused, timeout, ...)
     * @throws ApiException        the API answered with an HTTP error status
     *                             (401 unknown key, 413 too large, 429 rate
     *                             limited, ...) — see getStatus() / getErrorCode()
     *                             / getDocsUrl() / getUpgradeUrl(). A
     *                             *validation* failure — the invoice itself
     *                             does not comply — is never this: it is a
     *                             normal return with valid === false.
     */
    public function validate(string $data, ?string $contentType = null, float $timeoutSeconds = 30.0): ValidationResult
    {
        $apiKey = $this->resolveApiKey();
        $resolvedContentType = $contentType ?? ContentTypeSniffer::sniff($data);
        $url = $this->origin . '/v1/validate';

        $response = $this->httpClient->send(
            'POST',
            $url,
            [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => $resolvedContentType,
                'User-Agent' => 'attestwire-php/' . self::VERSION,
                'Accept' => 'application/json',
            ],
            $data,
            $timeoutSeconds
        );

        $decoded = json_decode($response->body, true);

        if ($response->status < 200 || $response->status >= 300) {
            $errorBody = is_array($decoded) ? $decoded : [];

            throw new ApiException(
                $response->status,
                (string) ($errorBody['error'] ?? 'unknown_error'),
                (string) ($errorBody['message'] ?? sprintf('POST %s failed with HTTP %d.', $url, $response->status)),
                isset($errorBody['docs']) ? (string) $errorBody['docs'] : null,
                isset($errorBody['upgrade_url']) ? (string) $errorBody['upgrade_url'] : null
            );
        }

        if (!is_array($decoded)) {
            throw new AttestwireException(sprintf('POST %s returned a 2xx response that was not a JSON object.', $url));
        }

        return ValidationResult::fromArray($decoded);
    }

    private function resolveApiKey(): string
    {
        $apiKey = $this->apiKey;
        if ($apiKey === null || $apiKey === '') {
            $fromEnv = getenv(self::API_KEY_ENV_VAR);
            $apiKey = $fromEnv !== false ? $fromEnv : null;
        }

        if ($apiKey === null || $apiKey === '') {
            throw new AttestwireException(sprintf(
                'Client::validate() needs an API key: pass it to the constructor, or set the %s '
                    . 'environment variable. Get one free, no card required, with POST %s/v1/keys '
                    . '— see %s/docs#auth.',
                self::API_KEY_ENV_VAR,
                $this->origin,
                $this->origin
            ));
        }

        return $apiKey;
    }
}
