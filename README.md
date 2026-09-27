# attestwire/attestwire-php

Validate EN 16931 e-invoices — XRechnung, Factur-X/ZUGFeRD, Peppol BIS 3 —
from PHP, against the hosted Attestwire API. PHP 8.1+, requiring only
`ext-json` and `ext-curl`.

```bash
composer require attestwire/attestwire-php
```

```php
use Attestwire\Client;

$client = new Client($_ENV['ATTESTWIRE_API_KEY']);
$result = $client->validate(file_get_contents('invoice.xml'));

if (!$result->valid) {
    foreach ($result->errors() as $finding) {
        echo "{$finding->rule}: {$finding->message}\n";
        echo "  fix: {$finding->fix}\n";
    }
}
```

`validate()` takes the document itself — XML bytes/text, a Factur-X/ZUGFeRD
PDF's bytes, or a JSON-encoded `InvoiceInput` — sniffed from its content
(`%PDF`, `<`, `{`/`[`), not a file name; there is no separate "read this
file" step. Pass the bytes you already have.

## Two packages in one

- **`Attestwire\Client`** — a thin wrapper around
  `POST https://api.attestwire.com/v1/validate`. Use it directly, or as the
  transport under your own code.
- **`Attestwire\InvoiceSuite\AttestwireDocumentValidator`** — an adapter for
  [horstoeko/invoicesuite](https://github.com/horstoeko/invoicesuite), so its
  users can validate with Attestwire the same way they'd use its built-in
  KoSIT or DocuFlair validators, with no Java install. See
  [Using it with horstoeko/invoicesuite](#using-it-with-horstoekoinvoicesuite)
  below.

## The result

```php
final class ValidationResult
{
    public readonly bool $valid;
    /** @var list<Finding> */
    public readonly array $findings;     // errors + warnings + information, in that order
    public readonly ?string $profile;
    public readonly ?string $syntax;     // "ubl" or "cii", when the document said which
    public readonly ?string $container;  // the PDF attachment name, for a Factur-X/ZUGFeRD PDF
    public readonly ?string $source;     // what was checked, and what it does not prove
    public readonly ?array $raw;         // the full decoded JSON, for anything not modelled above

    public function errors(): array;        // severity === "fatal" — these set valid = false
    public function warnings(): array;      // severity === "warning"
    public function information(): array;   // severity === "information" (advisory)
}

final class Finding
{
    public readonly ?string $rule;              // "BR-DE-15", "ATW-CREDIT-NOTE-...", or an "AW-*" whole-document finding
    public readonly ?string $severity;          // "fatal" | "warning" | "information"
    public readonly ?string $message;
    public readonly ?string $fix;
    public readonly string|array|null $field;   // the business term(s), e.g. "BT-10" or ["BT-31", "BT-32"]
    public readonly ?string $xpath;
    public readonly ?string $docsUrl;           // https://attestwire.com/rules/<rule>, when the finding is a rule
    public readonly ?string $example;
    public readonly ?Location $location;        // line/column/path in the document you sent, when applicable

    public function fieldAsString(): string;    // $field joined with ", "; "" when there is none
    public function isFatal(): bool;
    public function isWarning(): bool;
    public function isInformation(): bool;
}

final class Location
{
    public readonly ?int $line;
    public readonly ?int $column;
    public readonly ?string $path;
    public readonly ?bool $exact;
    public readonly ?string $attachment;   // set only for a PDF: which embedded XML this is in
}
```

## Errors you handle vs. exceptions you don't expect

A **non-compliant invoice is never an exception.** `validate()` returns
normally with `$result->valid === false` and the findings that explain
why — that's the whole point of the package. What *can* throw:

| Exception | When |
| --- | --- |
| `Attestwire\Exception\ApiException` | The API answered with an HTTP error status: `getStatus()`, `getErrorCode()`, `getMessage()`, `getDocsUrl()`, `getUpgradeUrl()` (e.g. `401 invalid_api_key`, `413 too_large`, `429 rate_limited`). |
| `Attestwire\Exception\AttestwireException` | No API key available anywhere, or the request never reached the API (DNS, connection refused, timeout). Base class of `ApiException` too, if you want to catch either. |

## Configuration

```php
new Client(
    apiKey: 'aw_live_...',                       // or omit and set ATTESTWIRE_API_KEY
    httpClient: null,                              // your own Attestwire\Http\HttpClientInterface; defaults to CurlHttpClient
    origin: Client::DEFAULT_ORIGIN,                // 'https://api.attestwire.com'; override for a self-hosted/staging deployment
);

$client->validate(
    data: $bytes,
    contentType: null,                             // force it instead of sniffing $data
    timeoutSeconds: 30.0,
);
```

No API key passed to the constructor falls back to the `ATTESTWIRE_API_KEY`
environment variable; if neither is set, `validate()` throws
`AttestwireException` before making any request. Get a free key (no card
required) with `POST /v1/keys` — see
[api.attestwire.com/docs#auth](https://api.attestwire.com/docs#auth).

## Using it with horstoeko/invoicesuite

[horstoeko/invoicesuite](https://github.com/horstoeko/invoicesuite) is an
extensible PHP library for building, reading and validating XRechnung,
ZUGFeRD and Factur-X invoices. Its own validators —
`InvoiceSuiteKositDocumentValidator` (a Java KoSIT install) and
`InvoiceSuiteDocuflairDocumentValidator` (the hosted DocuFlair API) — share
one abstract base, `InvoiceSuiteAbstractDocumentValidator`.
`Attestwire\InvoiceSuite\AttestwireDocumentValidator` extends that same base,
so it drops in wherever either of those does:

```php
use Attestwire\InvoiceSuite\AttestwireDocumentValidator;

$validator = AttestwireDocumentValidator::createFromFile($invoiceXmlFilename)
    ->setApiKey($attestwireApiKey)
    ->validate();

$validationWasSuccessful = !$validator->hasErrorMessagesInMessageBag()
    && !$validator->hasInternalErrorMessagesInMessageBag();

foreach ($validator->getMessageBag() as $messageBagItem) {
    echo "{$messageBagItem->getMessageSeverityValue()}: {$messageBagItem->getMessageContent()}\n";
}
```

Same `createFromFile()` / `createFromContent()` / `createFromDocumentReader()`
/ `createFromDocumentBuilder()` factories, same `validate()` /
`getMessageBag()` / `hasErrorMessagesInMessageBag()` /
`countWarningMessagesInMessageBag()` contract as
`InvoiceSuiteDocuflairDocumentValidator` — swap one line
(`InvoiceSuiteDocuflairDocumentValidator::createFromFile(...)->setApiKey($docuflairKey)`
for `AttestwireDocumentValidator::createFromFile(...)->setApiKey($attestwireKey)`)
and everything downstream keeps working.

**One deliberate difference**, worth knowing about: `InvoiceSuiteDocuflairDocumentValidator`
only ever adds an ERROR message, and only when its response says the document
is invalid. The Attestwire API always returns three findings arrays —
errors, warnings, information — regardless of `valid`, and warnings/information
are meaningful even on an invoice that otherwise passes (a missing time of
supply, say). This adapter maps all three every time, to
`addErrorMessageToMessageBag()` / `addWarningMessageToMessageBag()` /
`addInfoMessageToMessageBag()` respectively — so
`countWarningMessagesInMessageBag()` and `countInfoMessagesInMessageBag()`
are never silently empty here.

**Requirements this adapter has, beyond the base package's:** PHP ≥ 8.2
(`horstoeko/invoicesuite`'s own floor) and `horstoeko/invoicesuite` itself,
which this package does **not** require — see `composer.json`'s `suggest`
entry. Install it yourself:

```bash
composer require horstoeko/invoicesuite
```

It only validates XML content (matching `InvoiceSuiteDocuflairDocumentValidator`'s
own restriction) — a document built or read through invoicesuite's own
readers/builders, not a raw PDF.

## Development

**PHP is not installed in the environment this package was written in, and
none of the code below has been executed.** Treat it as reviewed-but-unverified
until it has run once in a real PHP environment.

```bash
composer install
composer test        # vendor/bin/phpunit
```

`tests/InvoiceSuite/AttestwireDocumentValidatorTest.php` self-skips unless
`horstoeko/invoicesuite` is also installed:

```bash
composer require --dev "horstoeko/invoicesuite:>=0.0.30"
composer test
```

Every test injects a fake `Attestwire\Http\HttpClientInterface`
(`tests/Fixtures/FakeHttpClient.php` / `ThrowingHttpClient.php`) — nothing
here touches the network or a real curl handle.

A GitHub Actions workflow (`.github/workflows/ci.yml`) is included for when
this package becomes its own repository; it has likewise never run.

## Related

- [`@attestwire/en16931`](https://github.com/attestwire/en16931) — the rule
  engine behind the hosted API this package calls.
- [Attestwire API docs](https://api.attestwire.com/docs) — the full
  request/response contract `Client` implements.
- [Rule reference](https://attestwire.com/rules/) — one page per rule, with
  the reason and the fix.
- [horstoeko/invoicesuite](https://github.com/horstoeko/invoicesuite) — the
  library `Attestwire\InvoiceSuite\AttestwireDocumentValidator` adapts to.

## License

MIT.

## Trademark

"Attestwire"™ and the Attestwire logo are trademarks of this project's owner.
The MIT license covers the code and grants no trademark rights. You may say
your integration uses this package, or is built on it; you may not name or
brand a product or service "Attestwire", or imply that we endorse yours.
