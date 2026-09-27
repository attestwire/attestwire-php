<?php

declare(strict_types=1);

namespace Attestwire\InvoiceSuite;

use Attestwire\Client;
use Attestwire\Exception\ApiException;
use Attestwire\Exception\AttestwireException;
use Attestwire\Finding;
use Attestwire\Http\HttpClientInterface;
use horstoeko\invoicesuite\utils\InvoiceSuiteContentType;
use horstoeko\invoicesuite\utils\InvoiceSuiteContentTypeResolver;
use horstoeko\invoicesuite\validators\abstracts\InvoiceSuiteAbstractDocumentValidator;

/**
 * An EN 16931 validator for horstoeko/invoicesuite, backed by the hosted
 * Attestwire API instead of a Java KoSIT install.
 *
 * Same abstract base as invoicesuite's own validators
 * (`InvoiceSuiteKositDocumentValidator`, `InvoiceSuiteDocuflairDocumentValidator`):
 * `InvoiceSuiteAbstractDocumentValidator`. Same factories
 * (`createFromFile()` / `createFromContent()` / `createFromDocumentReader()` /
 * `createFromDocumentBuilder()`), same `validate()` / `getMessageBag()`
 * contract. Swap:
 *
 *     $validator = InvoiceSuiteDocuflairDocumentValidator::createFromFile($xmlFile)
 *         ->setApiKey($docuflairKey);
 *
 * for:
 *
 *     $validator = AttestwireDocumentValidator::createFromFile($xmlFile)
 *         ->setApiKey($attestwireKey);
 *
 * and everything downstream — `getMessageBag()`, `hasErrorMessagesInMessageBag()`,
 * `countWarningMessagesInMessageBag()`, and so on — keeps working unchanged.
 *
 * ONE DELIBERATE DIFFERENCE from `InvoiceSuiteDocuflairDocumentValidator` (the
 * closest template for this class, and the one it borrows its
 * setApiKey()/setBaseUrl()/checkRequirements() shape from): that validator
 * only calls `addErrorMessageToMessageBag()`, and only for findings, and only
 * when its response's `isValid` is false. The Attestwire API always returns
 * three arrays — `errors`, `warnings`, `information` — regardless of `valid`,
 * and warnings/information are meaningful even on an invoice that otherwise
 * passes (e.g. `BR-DE-TMP-32`, no time of supply). This class maps all three,
 * every time, to `addErrorMessageToMessageBag()` /
 * `addWarningMessageToMessageBag()` / `addInfoMessageToMessageBag()`
 * respectively — so `countWarningMessagesInMessageBag()` and
 * `countInfoMessagesInMessageBag()` are never silently empty here the way
 * they always are for the DocuFlair validator today.
 *
 * Also unlike the DocuFlair validator, an HTTP-level failure (network error,
 * or an error status from the API) is reported as an INTERNALERROR message —
 * matching that validator's own convention that "the check itself couldn't
 * run" is a different kind of message than "the check ran and found a
 * problem" (ERROR/WARNING/INFO).
 */
class AttestwireDocumentValidator extends InvoiceSuiteAbstractDocumentValidator
{
    private string $baseUrl = Client::DEFAULT_ORIGIN;

    private string $apiKey = '';

    private float $timeoutSeconds = 30.0;

    /** Overridable for tests; defaults to a real network call (CurlHttpClient) when null. */
    private ?HttpClientInterface $httpClient = null;

    /** Point at a self-hosted or staging deployment. Default `https://api.attestwire.com`. */
    public function setBaseUrl(string $newBaseUrl): static
    {
        if (false !== filter_var($newBaseUrl, FILTER_VALIDATE_URL)) {
            $this->baseUrl = rtrim($newBaseUrl, '/');
        }

        return $this;
    }

    /** Your Attestwire API key (`aw_live_...` / `aw_test_...`). Get one free at `POST /v1/keys`. */
    public function setApiKey(string $newApiKey): static
    {
        if ($newApiKey !== '') {
            $this->apiKey = $newApiKey;
        }

        return $this;
    }

    public function setTimeoutSeconds(float $newTimeoutSeconds): static
    {
        $this->timeoutSeconds = $newTimeoutSeconds;

        return $this;
    }

    /** Inject a fake transport for tests, or your own HttpClientInterface. Not part of invoicesuite's own contract. */
    public function setHttpClient(HttpClientInterface $newHttpClient): static
    {
        $this->httpClient = $newHttpClient;

        return $this;
    }

    protected function doValidate(): static
    {
        if (!$this->checkRequirements()) {
            return $this;
        }

        $this->performValidation();

        return $this;
    }

    private function checkRequirements(): bool
    {
        if (InvoiceSuiteContentType::XML !== InvoiceSuiteContentTypeResolver::resolveContentType($this->getRawDocumentContent())) {
            $this->addInternalErrorMessageToMessageBag('Only XML content can be validated with this validator.');

            return false;
        }

        if ($this->apiKey === '') {
            $this->addInternalErrorMessageToMessageBag(sprintf(
                'An Attestwire API key must be given (setApiKey()). Get one free, no card required, with '
                    . 'POST %s/v1/keys.',
                $this->baseUrl
            ));

            return false;
        }

        return true;
    }

    private function performValidation(): void
    {
        $client = new Client($this->apiKey, $this->httpClient, $this->baseUrl);

        try {
            $result = $client->validate($this->getRawDocumentContent(), 'application/xml', $this->timeoutSeconds);
        } catch (ApiException $exception) {
            $this->addInternalErrorMessageToMessageBag(
                sprintf(
                    'Attestwire API error %d (%s): %s',
                    $exception->getStatus(),
                    $exception->getErrorCode(),
                    $exception->getMessage()
                ),
                null,
                [
                    'status' => $exception->getStatus(),
                    'error' => $exception->getErrorCode(),
                    'docs' => $exception->getDocsUrl(),
                    'upgradeUrl' => $exception->getUpgradeUrl(),
                ]
            );

            return;
        } catch (AttestwireException $exception) {
            $this->addInternalErrorMessageToMessageBag($exception->getMessage());

            return;
        }

        foreach ($result->errors() as $finding) {
            $this->addErrorMessageToMessageBag($this->formatFinding($finding), null, $finding->jsonSerialize());
        }

        foreach ($result->warnings() as $finding) {
            $this->addWarningMessageToMessageBag($this->formatFinding($finding), null, $finding->jsonSerialize());
        }

        foreach ($result->information() as $finding) {
            $this->addInfoMessageToMessageBag($this->formatFinding($finding), null, $finding->jsonSerialize());
        }
    }

    private function formatFinding(Finding $finding): string
    {
        $field = $finding->fieldAsString();

        return sprintf(
            '%s (%s): %s',
            $finding->rule ?? 'unknown-rule',
            $field !== '' ? $field : 'document',
            $finding->message ?? ''
        );
    }
}
