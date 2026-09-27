<?php

declare(strict_types=1);

namespace Attestwire;

/**
 * Sniff a document's bytes to choose the HTTP Content-Type.
 *
 * The hosted API reads a document by its declared Content-Type, not a file
 * name (https://api.attestwire.com/docs): `application/pdf`
 * for a Factur-X/ZUGFeRD PDF, `application/xml` for an XML file,
 * `application/json` for the JSON `InvoiceInput` model. `Client::validate()`
 * has no file name to go by, only bytes, so it sniffs the same way the
 * engine's own CLI sniffs a file by its content rather than trusting an
 * extension.
 */
final class ContentTypeSniffer
{
    private function __construct()
    {
    }

    /**
     * `"application/pdf"`, `"application/xml"`, `"application/json"`, or
     * `"application/octet-stream"` when none of those is recognisable — the
     * API accepts that too, and sniffs the bytes itself from there.
     */
    public static function sniff(string $data): string
    {
        // Strip a leading UTF-8 BOM and whitespace, same as the JSON/XML the
        // API reads would have them.
        $stripped = ltrim($data, " \t\r\n\xEF\xBB\xBF");

        if (str_starts_with($stripped, '%PDF')) {
            return 'application/pdf';
        }
        if (str_starts_with($stripped, '<')) {
            return 'application/xml';
        }
        if ($stripped !== '' && ($stripped[0] === '{' || $stripped[0] === '[')) {
            return 'application/json';
        }

        return 'application/octet-stream';
    }
}
