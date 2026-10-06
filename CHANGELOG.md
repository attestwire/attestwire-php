# Changelog

## [0.2.0] — 2026-10-06

- **`Client::generate($invoice, $format = 'xml', $pdfOptions = null)` creates
  e-invoices.** Pass the invoice as an array (the `InvoiceInput` shape) and get
  the XML for its profile, or with `'pdf'` a Factur-X / ZUGFeRD PDF (PDF/A-3B,
  CII embedded, page in German, French or English; `$pdfOptions` for the
  language, logo, payment QR code and payment link). Calls `POST /v1/generate`
  on the hosted API. Returns a `GeneratedInvoice` (`content()`, `filename`,
  `xml` or `pdf`, `profile`, `warnings()`, `watermarked`).
- **`InvalidInvoiceException`**: an invoice that breaks a rule is not
  generated; `getResult()` is the `ValidationResult` with each fix. A subclass
  of `ApiException` (status 422, code `invoice_invalid`), which is no longer
  `final`.
- `HttpResponse` carries the response headers (optional third argument, so a
  custom `HttpClientInterface` written for 0.1.0 still works for `validate()`);
  `CurlHttpClient` fills them in.

## [0.1.0] — 2026-09-26

- Initial release: `Client::validate()` against `POST /v1/validate`, a typed
  `ValidationResult` / `Finding` / `Location`, and an adapter for
  horstoeko/invoicesuite's validator.
