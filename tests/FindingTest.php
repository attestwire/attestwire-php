<?php

declare(strict_types=1);

namespace Attestwire\Tests;

use Attestwire\Finding;
use Attestwire\Location;
use PHPUnit\Framework\TestCase;

final class FindingTest extends TestCase
{
    public function testMapsAFullTeachingError(): void
    {
        $finding = Finding::fromArray([
            'rule' => 'BR-DE-15',
            'field' => 'BT-10',
            'severity' => 'fatal',
            'message' => 'A German public-sector buyer requires a Leitweg-ID.',
            'fix' => 'Set buyerReference to the Leitweg-ID your client gave you.',
            'xpath' => '/ubl:Invoice/cac:OrderReference/cbc:ID',
            'docsUrl' => 'https://attestwire.com/rules/BR-DE-15',
            'example' => '04011000-1234512345-06',
            'location' => ['line' => 12, 'column' => 5, 'path' => '/ns:Invoice', 'exact' => true],
        ]);

        self::assertSame('BR-DE-15', $finding->rule);
        self::assertSame('fatal', $finding->severity);
        self::assertTrue($finding->isFatal());
        self::assertFalse($finding->isWarning());
        self::assertSame('Set buyerReference to the Leitweg-ID your client gave you.', $finding->fix);
        self::assertSame('https://attestwire.com/rules/BR-DE-15', $finding->docsUrl);
        self::assertEquals(new Location(12, 5, '/ns:Invoice', true, null), $finding->location);
        self::assertSame('BT-10', $finding->fieldAsString());
    }

    public function testAnAwFindingHasNoLocationOrDocsUrl(): void
    {
        $finding = Finding::fromArray([
            'rule' => 'AW-PROFILE-SUBSET',
            'field' => 'document',
            'severity' => 'fatal',
            'message' => 'This profile carries too little to be an EN 16931 invoice.',
            'fix' => 'Export MINIMUM or BASIC WL as a plain PDF invoice instead.',
        ]);

        self::assertNull($finding->location);
        self::assertNull($finding->docsUrl);
        self::assertNull($finding->xpath);
    }

    public function testFieldAsStringJoinsAnArrayOfTerms(): void
    {
        $finding = Finding::fromArray([
            'rule' => 'BR-CO-26',
            'field' => ['BT-31', 'BT-32'],
            'severity' => 'fatal',
            'message' => 'm',
            'fix' => 'f',
        ]);

        self::assertSame('BT-31, BT-32', $finding->fieldAsString());
    }

    public function testFieldAsStringIsEmptyWhenFieldIsAbsent(): void
    {
        $finding = Finding::fromArray(['rule' => 'AW-X', 'severity' => 'fatal', 'message' => 'm']);
        self::assertSame('', $finding->fieldAsString());
    }
}
