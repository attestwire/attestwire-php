<?php

declare(strict_types=1);

namespace Attestwire\Tests;

use Attestwire\ContentTypeSniffer;
use PHPUnit\Framework\TestCase;

final class ContentTypeSnifferTest extends TestCase
{
    public function testPdfMagicNumber(): void
    {
        self::assertSame('application/pdf', ContentTypeSniffer::sniff("%PDF-1.7\n..."));
    }

    public function testXml(): void
    {
        self::assertSame('application/xml', ContentTypeSniffer::sniff("<?xml version='1.0'?><Invoice/>"));
    }

    public function testXmlWithLeadingWhitespace(): void
    {
        self::assertSame('application/xml', ContentTypeSniffer::sniff("\n\n  <Invoice/>"));
    }

    public function testXmlWithUtf8Bom(): void
    {
        self::assertSame('application/xml', ContentTypeSniffer::sniff("\xEF\xBB\xBF<?xml version='1.0'?><Invoice/>"));
    }

    public function testJsonObject(): void
    {
        self::assertSame('application/json', ContentTypeSniffer::sniff('{"profile": "xrechnung-ubl"}'));
    }

    public function testJsonArray(): void
    {
        self::assertSame('application/json', ContentTypeSniffer::sniff('[1, 2, 3]'));
    }

    public function testUnrecognisedFallsBackToOctetStream(): void
    {
        self::assertSame('application/octet-stream', ContentTypeSniffer::sniff("\x00\x01garbage"));
    }

    public function testEmptyString(): void
    {
        self::assertSame('application/octet-stream', ContentTypeSniffer::sniff(''));
    }
}
