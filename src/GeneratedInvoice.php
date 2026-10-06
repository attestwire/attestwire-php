<?php

declare(strict_types=1);

namespace Attestwire;

/**
 * What `Client::generate()` returns: the e-invoice, ready to send.
 *
 * With format "xml", `xml` holds the document (UBL or CII, as the invoice's
 * `profile` decides) and `pdf` is null. With format "pdf", `pdf` holds a
 * Factur-X / ZUGFeRD PDF (PDF/A-3B, the CII XML embedded in it) and `xml` is
 * null. `content()` is whichever one is there, and `filename` a name to save
 * it under:
 *
 *     file_put_contents($invoice->filename, $invoice->content());
 *
 * Generation only succeeds for an invoice with no fatal finding, so there are
 * no errors here; the advisory findings ride along in `findings` (XML only:
 * the PDF response carries none).
 */
final class GeneratedInvoice
{
    /** @param list<Finding> $findings */
    public function __construct(
        /** "xml" or "pdf". */
        public readonly string $format,
        public readonly ?string $xml = null,
        public readonly ?string $pdf = null,
        /** From the API for a PDF, from the invoice number for XML. */
        public readonly ?string $filename = null,
        public readonly ?string $profile = null,
        /** "ubl" or "cii". XML only. */
        public readonly ?string $syntax = null,
        /** Warning and information findings. XML only. */
        public readonly array $findings = [],
        /** For CII XML: a reminder that it is the XML payload, not a Factur-X file. */
        public readonly ?string $note = null,
        /** PDF only: true when the PDF is the free plan's watermarked preview. */
        public readonly bool $watermarked = false,
        /** PDF only: the language the page was drawn in ("en", "de", "fr"). */
        public readonly ?string $language = null,
        /** PDF only: characters the page could not draw (shown as "?"); the XML inside has them right. */
        public readonly int $unrenderedCharacters = 0,
        /** The decoded JSON envelope (XML only), for fields not modelled above. */
        public readonly ?array $raw = null
    ) {
    }

    /** The PDF's bytes, or the XML as UTF-8. */
    public function content(): string
    {
        return $this->pdf ?? $this->xml ?? '';
    }

    /** @return list<Finding> */
    public function warnings(): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => $f->isWarning()));
    }

    /** @return list<Finding> */
    public function information(): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => $f->isInformation()));
    }
}
