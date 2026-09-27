<?php

declare(strict_types=1);

namespace Attestwire\Tests;

use Attestwire\ValidationResult;
use PHPUnit\Framework\TestCase;

final class ValidationResultTest extends TestCase
{
    public function testConcatenatesErrorsWarningsInformationInOrder(): void
    {
        $result = ValidationResult::fromArray([
            'valid' => false,
            'profile' => 'xrechnung-ubl',
            'errors' => [['rule' => 'BR-11', 'severity' => 'fatal', 'message' => 'e', 'fix' => 'f']],
            'warnings' => [['rule' => 'BR-DE-TMP-32', 'severity' => 'warning', 'message' => 'w', 'fix' => 'f']],
            'information' => [['rule' => 'ATW-CREDIT-NOTE-NO-PRECEDING-INVOICE', 'severity' => 'information', 'message' => 'i', 'fix' => 'f']],
        ]);

        self::assertSame(
            ['BR-11', 'BR-DE-TMP-32', 'ATW-CREDIT-NOTE-NO-PRECEDING-INVOICE'],
            array_map(static fn ($f) => $f->rule, $result->findings)
        );
    }

    public function testMissingArraysAreTreatedAsEmpty(): void
    {
        $result = ValidationResult::fromArray(['valid' => true]);
        self::assertSame([], $result->findings);
        self::assertTrue($result->valid);
    }

    public function testErrorsWarningsInformationFilterBySeverity(): void
    {
        $result = ValidationResult::fromArray([
            'valid' => false,
            'errors' => [
                ['rule' => 'A', 'severity' => 'fatal', 'message' => 'a'],
                ['rule' => 'D', 'severity' => 'fatal', 'message' => 'd'],
            ],
            'warnings' => [['rule' => 'B', 'severity' => 'warning', 'message' => 'b']],
            'information' => [['rule' => 'C', 'severity' => 'information', 'message' => 'c']],
        ]);

        self::assertSame(['A', 'D'], array_map(static fn ($f) => $f->rule, $result->errors()));
        self::assertSame(['B'], array_map(static fn ($f) => $f->rule, $result->warnings()));
        self::assertSame(['C'], array_map(static fn ($f) => $f->rule, $result->information()));
    }

    public function testValidResultWithNoFindings(): void
    {
        $result = ValidationResult::fromArray(['valid' => true, 'errors' => [], 'warnings' => [], 'information' => []]);
        self::assertSame([], $result->errors());
        self::assertSame([], $result->warnings());
        self::assertSame([], $result->information());
    }

    public function testMapsProfileSyntaxContainerAndSource(): void
    {
        $result = ValidationResult::fromArray([
            'valid' => true,
            'profile' => 'facturx-en16931',
            'syntax' => 'cii',
            'container' => 'factur-x.xml',
            'source' => 'The document was read into the invoice model and validated.',
        ]);

        self::assertSame('facturx-en16931', $result->profile);
        self::assertSame('cii', $result->syntax);
        self::assertSame('factur-x.xml', $result->container);
        self::assertSame('The document was read into the invoice model and validated.', $result->source);
    }
}
