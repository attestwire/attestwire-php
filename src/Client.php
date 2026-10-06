<?php

declare(strict_types=1);

namespace Attestwire;

use Attestwire\Exception\ApiException;
use Attestwire\Exception\AttestwireException;
use Attestwire\Exception\InvalidInvoiceException;
use Attestwire\Http\CurlHttpClient;
use Attestwire\Http\HttpClientInterface;

/**
 * A thin client for the hosted Attestwire API: `POST /v1/validate` to check
 * an e-invoice, `POST /v1/generate` to create one (the XML, or a Factur-X /
 * ZUGFeRD PDF).
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
 *
 *     $pdf = $client->generate($invoice, 'pdf');
 *     file_put_contents($pdf->filename, $pdf->content());
 */
class Client
{
    public const DEFAULT_ORIGIN = 'https://api.attestwire.com';

    /** Read when no API key is passed to the constructor. */
    public const API_KEY_ENV_VAR = 'ATTESTWIRE_API_KEY';

    public const VERSION = '0.2.0';

    /** The one profile a Factur-X / ZUGFeRD PDF carries. */
    public const PDF_PROFILE = 'facturx-en16931';

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
        $apiKey = $this->resolveApiKey('Client::validate()');
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
            throw self::apiException($response->status, is_array($decoded) ? $decoded : [], $url);
        }

        if (!is_array($decoded)) {
            throw new AttestwireException(sprintf('POST %s returned a 2xx response that was not a JSON object.', $url));
        }

        return ValidationResult::fromArray($decoded);
    }

    /**
     * Create an e-invoice from your own data: the XML, or a Factur-X / ZUGFeRD PDF.
     *
     * The invoice is checked against the rules first. If it breaks any,
     * nothing is generated and `InvalidInvoiceException` says which rules and
     * how to fix them, exactly as `validate()` would.
     *
     * @param  array<string, mixed>|string $invoice        the invoice as an array (or its JSON):
     *                                                     seller, buyer, lines, VAT, in the
     *                                                     `InvoiceInput` shape documented at
     *                                                     https://api.attestwire.com/docs#input.
     *                                                     Its `profile` picks the format:
     *                                                     "xrechnung-ubl", "xrechnung-cii",
     *                                                     "peppol-bis-3", "facturx-en16931",
     *                                                     "en16931", or "auto". Totals are
     *                                                     calculated from the lines.
     * @param  string                      $format         "xml" (the document for the invoice's
     *                                                     profile) or "pdf" (a Factur-X /
     *                                                     ZUGFeRD PDF, PDF/A-3B with the CII
     *                                                     XML embedded; needs the
     *                                                     "facturx-en16931" profile, and is a
     *                                                     watermarked preview on the free plan)
     * @param  array<string, mixed>|null   $pdfOptions     for "pdf": the page options, named as
     *                                                     the API names them — `language` ("en",
     *                                                     "de", "fr"), `logo`, `paymentQr`,
     *                                                     `paymentLink`; see
     *                                                     https://api.attestwire.com/docs#pdf-options
     * @param  float                       $timeoutSeconds
     * @return GeneratedInvoice
     *
     * @throws InvalidInvoiceException  the invoice breaks a rule, so nothing was
     *                                  generated (HTTP 422): getResult()->errors()
     *                                  lists each one with its fix. Not charged.
     * @throws ApiException             any other HTTP error (401 unknown key, 400 a
     *                                  profile the PDF cannot carry, 429, ...)
     * @throws AttestwireException      no API key, the API could not be reached, or
     *                                  a 200 that was not what it should be
     * @throws \InvalidArgumentException the arguments themselves are wrong
     */
    public function generate(
        array|string $invoice,
        string $format = 'xml',
        ?array $pdfOptions = null,
        float $timeoutSeconds = 60.0
    ): GeneratedInvoice {
        if ($format !== 'xml' && $format !== 'pdf') {
            throw new \InvalidArgumentException(sprintf('$format must be "xml" or "pdf", got "%s".', $format));
        }
        if ($pdfOptions !== null && $format !== 'pdf') {
            throw new \InvalidArgumentException('$pdfOptions only apply to the "pdf" format.');
        }
        if (is_string($invoice)) {
            $decodedInvoice = json_decode($invoice, true);
            if (!is_array($decodedInvoice)) {
                throw new \InvalidArgumentException('Client::generate() was given a string that is not a JSON object.');
            }
            $invoice = $decodedInvoice;
        }

        $apiKey = $this->resolveApiKey('Client::generate()');
        $body = $pdfOptions !== null && $pdfOptions !== []
            ? ['invoice' => $invoice, 'pdf' => $pdfOptions]
            : $invoice;
        $url = $this->origin . '/v1/generate' . ($format === 'pdf' ? '?format=pdf' : '');

        $response = $this->httpClient->send(
            'POST',
            $url,
            [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'User-Agent' => 'attestwire-php/' . self::VERSION,
                'Accept' => $format === 'pdf' ? 'application/pdf, application/json' : 'application/json',
            ],
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            $timeoutSeconds
        );

        if ($response->status < 200 || $response->status >= 300) {
            $errorBody = json_decode($response->body, true);
            $errorBody = is_array($errorBody) ? $errorBody : [];
            if ($response->status === 422 && array_key_exists('valid', $errorBody)) {
                throw new InvalidInvoiceException(ValidationResult::fromArray($errorBody));
            }

            throw self::apiException($response->status, $errorBody, $url);
        }

        if ($format === 'pdf') {
            if (!str_starts_with($response->body, '%PDF')) {
                throw new AttestwireException(sprintf('POST %s answered 200 with something that is not a PDF.', $url));
            }
            $disposition = $response->header('Content-Disposition') ?? '';

            return new GeneratedInvoice(
                format: 'pdf',
                pdf: $response->body,
                filename: preg_match('/filename="([^"]+)"/', $disposition, $m) === 1 ? $m[1] : null,
                profile: self::PDF_PROFILE,
                watermarked: $response->header('Attestwire-Preview') === 'watermarked',
                language: $response->header('Content-Language'),
                unrenderedCharacters: (int) ($response->header('X-Unrendered-Characters') ?? 0),
            );
        }

        $envelope = json_decode($response->body, true);
        if (!is_array($envelope)) {
            throw new AttestwireException(sprintf('POST %s returned a 2xx response that was not a JSON object.', $url));
        }
        $stem = substr((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($invoice['invoiceNumber'] ?? 'invoice')), 0, 80);

        return new GeneratedInvoice(
            format: 'xml',
            xml: isset($envelope['xml']) ? (string) $envelope['xml'] : null,
            filename: ($stem !== '' ? $stem : 'invoice') . '.xml',
            profile: isset($envelope['profile']) ? (string) $envelope['profile'] : null,
            syntax: isset($envelope['syntax']) ? (string) $envelope['syntax'] : null,
            findings: ValidationResult::fromArray($envelope)->findings,
            note: isset($envelope['note']) ? (string) $envelope['note'] : null,
            raw: $envelope,
        );
    }

    /** @param array<string, mixed> $errorBody the API's `{error, message, docs, upgrade_url}` envelope */
    private static function apiException(int $status, array $errorBody, string $url): ApiException
    {
        return new ApiException(
            $status,
            (string) ($errorBody['error'] ?? 'unknown_error'),
            (string) ($errorBody['message'] ?? sprintf('POST %s failed with HTTP %d.', $url, $status)),
            isset($errorBody['docs']) ? (string) $errorBody['docs'] : null,
            isset($errorBody['upgrade_url']) ? (string) $errorBody['upgrade_url'] : null
        );
    }

    private function resolveApiKey(string $caller): string
    {
        $apiKey = $this->apiKey;
        if ($apiKey === null || $apiKey === '') {
            $fromEnv = getenv(self::API_KEY_ENV_VAR);
            $apiKey = $fromEnv !== false ? $fromEnv : null;
        }

        if ($apiKey === null || $apiKey === '') {
            throw new AttestwireException(sprintf(
                '%s needs an API key: pass it to the constructor, or set the %s '
                    . 'environment variable. Get one free, no card required, with POST %s/v1/keys '
                    . '— see %s/docs#auth.',
                $caller,
                self::API_KEY_ENV_VAR,
                $this->origin,
                $this->origin
            ));
        }

        return $apiKey;
    }
}
