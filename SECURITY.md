# Security

## Reporting

Email **hello@attestwire.com**. The maintainer reads these directly.

Include what you found, how to reproduce it, and the package version you were
on. Please don't open a public issue for a security report.

There's no bug bounty. If you'd like credit, say so and you'll be named in
the release notes for the fix.

## Scope

This package sends invoice documents — which carry customer names, addresses,
VAT IDs and bank details — to the hosted Attestwire API, and handles your API
key to do it. Worth reporting:

- Anything that sends your document or API key somewhere other than the
  `origin` you configured (default `https://api.attestwire.com`).
- `CurlHttpClient` disabling or weakening TLS verification (it deliberately
  does not, unlike `horstoeko/invoicesuite`'s own DocuFlair validator, which
  is the closest prior art and is not a pattern to copy here).
- Anything that logs a document's contents or your API key.
- The `AttestwireDocumentValidator` adapter putting your API key or a
  document's contents into a `horstoeko/invoicesuite` message bag entry in a
  way that could reach a place it shouldn't (a public error page, a log
  aggregator).

## Out of scope

A wrong verdict, or malformed XML the engine produced, is a bug in the rule
engine. Report it at
[attestwire/en16931](https://github.com/attestwire/en16931/issues).
Vulnerabilities in the engine itself (XML parsing, entity expansion, the PDF
reader) belong in that repository's
[SECURITY.md](https://github.com/attestwire/en16931/blob/main/SECURITY.md)
process, same email either way.

Vulnerabilities in the hosted Attestwire API (`api.attestwire.com`) go to
Attestwire directly, not this repository. Vulnerabilities in
`horstoeko/invoicesuite` itself go to
[that project's own security policy](https://github.com/horstoeko/invoicesuite/blob/master/SECURITY.md).
