# Contributing

This repository is developed in the open: fork it, branch, open a pull
request. No CLA, no template to sign.

## Where your issue belongs

This package is a thin HTTP client plus one adapter. It contains no
validation logic of its own.

- A rule that fired when it shouldn't have, or a finding whose message/fix
  text is wrong — that's the engine behind the hosted API. Report it at
  [attestwire/en16931](https://github.com/attestwire/en16931/issues).
- `Attestwire\Client`, `ValidationResult`/`Finding`/`Location`, error
  handling, or the `Attestwire\InvoiceSuite\AttestwireDocumentValidator`
  adapter — that's here.
- Something about `horstoeko/invoicesuite` itself (its other validators, its
  document builders/readers, its format providers) belongs at
  [horstoeko/invoicesuite](https://github.com/horstoeko/invoicesuite/issues).

## Running the tests

PHP 8.1+.

```bash
composer install
composer test
```

To also run `tests/InvoiceSuite/AttestwireDocumentValidatorTest.php` (needs
PHP 8.2+):

```bash
composer require --dev "horstoeko/invoicesuite:>=0.0.30"
composer test
```

Every test injects a fake `Attestwire\Http\HttpClientInterface` — no network,
no real curl handle. If you add a method to `Client` or the adapter, add a
fake-transport test alongside it rather than one that needs a live API key.

**Context for reviewers:** this package's tests were written without a PHP
runtime available to run them (see the README's Development section). If
you're the first to run `composer test` against a real PHP install, please
report anything that fails — that's expected until it happens at least once.

## Before you open the PR

Run `composer test`. If you change `Client`'s request/response handling,
check it against the published contract
(https://api.attestwire.com/openapi.json) rather than guessing —
this package's whole job is matching that contract exactly. If you change the
InvoiceSuite adapter, check the shape you're mirroring in
`horstoeko/invoicesuite`'s own
[`InvoiceSuiteDocuflairDocumentValidator`](https://github.com/horstoeko/invoicesuite/blob/master/src/validators/InvoiceSuiteDocuflairDocumentValidator.php).

## Questions

Open an issue, or email hello@attestwire.com.

Security issues go to hello@attestwire.com. See [SECURITY.md](SECURITY.md).

## Licence

MIT. By contributing, you agree your contribution ships under it.
