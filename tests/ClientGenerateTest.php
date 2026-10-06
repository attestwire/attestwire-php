<?php

declare(strict_types=1);

namespace Attestwire\Tests;

use Attestwire\Client;
use Attestwire\Exception\ApiException;
use Attestwire\Exception\AttestwireException;
use Attestwire\Exception\InvalidInvoiceException;
use Attestwire\Http\HttpResponse;
use Attestwire\Tests\Fixtures\FakeHttpClient;
use Attestwire\Tests\Fixtures\ThrowingHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientGenerateTest extends TestCase
{
    private const INVOICE = [
        'profile' => 'xrechnung-ubl',
        'invoiceNumber' => '2026/000142',
        'issueDate' => '2026-08-09',
        'currency' => 'EUR',
        'lines' => [['id' => '1', 'description' => 'Consulting', 'quantity' => 10, 'unitPrice' => 150.0]],
    ];

    private const XML_ENVELOPE = '{"xml":"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<ubl:Invoice/>","profile":"xrechnung-ubl","syntax":"ubl",'
        . '"warnings":[{"rule":"BR-DE-17","severity":"warning","message":"Unusual type code."}],"information":[],"provenance":{}}';

    private const REFUSAL = '{"valid":false,"profile":"xrechnung-ubl","errors":[{"rule":"BR-DE-15","field":"BT-10","severity":"fatal",'
        . '"message":"XRechnung requires a buyer reference (BT-10).","fix":"Ask your client for their Leitweg-ID."}],"warnings":[],"information":[]}';

    private const PDF_HEADERS = [
        'content-type' => 'application/pdf',
        'content-disposition' => 'attachment; filename="2026-000142.pdf"',
        'content-language' => 'de',
    ];

    public function testXmlComesBackWithItsAdvisoryFindings(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::XML_ENVELOPE));

        $out = (new Client('k', $fake))->generate(self::INVOICE);

        self::assertSame('xml', $out->format);
        self::assertSame("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<ubl:Invoice/>", $out->xml);
        self::assertNull($out->pdf);
        self::assertSame($out->xml, $out->content());
        self::assertSame('xrechnung-ubl', $out->profile);
        self::assertSame('ubl', $out->syntax);
        self::assertSame('2026_000142.xml', $out->filename);
        self::assertCount(1, $out->warnings());
        self::assertSame('BR-DE-17', $out->warnings()[0]->rule);
    }

    public function testPostsTheInvoiceAsJsonToV1Generate(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::XML_ENVELOPE));

        (new Client('aw_live_abc', $fake))->generate(self::INVOICE);

        $request = $fake->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.attestwire.com/v1/generate', $request['url']);
        self::assertSame('application/json', $request['headers']['Content-Type']);
        self::assertSame('Bearer aw_live_abc', $request['headers']['Authorization']);
        self::assertStringStartsWith('attestwire-php/', $request['headers']['User-Agent']);
        self::assertSame(self::INVOICE, json_decode($request['body'], true));
    }

    public function testAcceptsTheInvoiceAsJsonText(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::XML_ENVELOPE));

        $text = (string) json_encode(self::INVOICE);
        (new Client('k', $fake))->generate($text);

        // What the text says, number types included: json_encode writes 150.0
        // as 150, and the client sends 150, not the array the text came from.
        self::assertSame(json_decode($text, true), json_decode($fake->requests[0]['body'], true));
    }

    public function testPdfComesBackAsBytesWithWhatTheHeadersSay(): void
    {
        $headers = self::PDF_HEADERS + ['attestwire-preview' => 'watermarked', 'x-unrendered-characters' => '2'];
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, "%PDF-1.7\n...", $headers));

        $out = (new Client('k', $fake))->generate(['profile' => 'facturx-en16931'] + self::INVOICE, 'pdf');

        self::assertSame('https://api.attestwire.com/v1/generate?format=pdf', $fake->requests[0]['url']);
        self::assertSame('pdf', $out->format);
        self::assertSame("%PDF-1.7\n...", $out->pdf);
        self::assertSame($out->pdf, $out->content());
        self::assertNull($out->xml);
        self::assertSame('2026-000142.pdf', $out->filename);
        self::assertSame(Client::PDF_PROFILE, $out->profile);
        self::assertTrue($out->watermarked);
        self::assertSame('de', $out->language);
        self::assertSame(2, $out->unrenderedCharacters);
    }

    public function testAPaidPlanPdfIsNotWatermarked(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, '%PDF-1.7', self::PDF_HEADERS));

        $out = (new Client('k', $fake))->generate(self::INVOICE, 'pdf');

        self::assertFalse($out->watermarked);
        self::assertSame(0, $out->unrenderedCharacters);
    }

    public function testPageOptionsWrapTheInvoice(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, '%PDF-1.7', self::PDF_HEADERS));

        (new Client('k', $fake))->generate(self::INVOICE, 'pdf', ['language' => 'fr']);

        self::assertSame(
            ['invoice' => self::INVOICE, 'pdf' => ['language' => 'fr']],
            json_decode($fake->requests[0]['body'], true)
        );
    }

    public function testA200ThatIsNotAPdfIsAnErrorNotAFile(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, '<html>proxy</html>'));

        $this->expectException(AttestwireException::class);
        (new Client('k', $fake))->generate(self::INVOICE, 'pdf');
    }

    public function testARefusedInvoiceThrowsWithTheFindings(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(422, self::REFUSAL));

        try {
            (new Client('k', $fake))->generate(self::INVOICE);
            self::fail('Expected an InvalidInvoiceException.');
        } catch (InvalidInvoiceException $exception) {
            self::assertInstanceOf(ApiException::class, $exception);
            self::assertSame(422, $exception->getStatus());
            self::assertSame('invoice_invalid', $exception->getErrorCode());
            self::assertStringContainsString('BR-DE-15', $exception->getMessage());
            self::assertFalse($exception->getResult()->valid);
            self::assertSame('Ask your client for their Leitweg-ID.', $exception->getResult()->errors()[0]->fix);
        }
    }

    public function testOtherErrorsKeepTheApiErrorEnvelope(): void
    {
        $body = '{"error":"unsupported_profile","message":"?format=pdf carries facturx-en16931 only.","docs":"d"}';
        $fake = (new FakeHttpClient())->queue(new HttpResponse(400, $body));

        try {
            (new Client('k', $fake))->generate(self::INVOICE, 'pdf');
            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            self::assertNotInstanceOf(InvalidInvoiceException::class, $exception);
            self::assertSame('unsupported_profile', $exception->getErrorCode());
            self::assertSame('d', $exception->getDocsUrl());
        }
    }

    public function testA422WithoutFindingsIsAPlainApiException(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(422, 'not json'));

        try {
            (new Client('k', $fake))->generate(self::INVOICE);
            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            self::assertNotInstanceOf(InvalidInvoiceException::class, $exception);
            self::assertStringContainsString('HTTP 422', $exception->getMessage());
        }
    }

    public function testMissingApiKeyRaisesLocallyWithoutASend(): void
    {
        putenv('ATTESTWIRE_API_KEY');
        $fake = new FakeHttpClient();

        $this->expectException(AttestwireException::class);
        $this->expectExceptionMessageMatches('/generate\(\) needs an API key/');

        try {
            (new Client(null, $fake))->generate(self::INVOICE);
        } finally {
            self::assertSame([], $fake->requests);
        }
    }

    public function testConnectionFailureRaisesAttestwireException(): void
    {
        $this->expectException(AttestwireException::class);
        (new Client('k', new ThrowingHttpClient()))->generate(self::INVOICE);
    }

    /** @return iterable<string, array{0: array<string, mixed>|string, 1: string, 2: array<string, mixed>|null}> */
    public static function badArguments(): iterable
    {
        yield 'unknown format' => [self::INVOICE, 'docx', null];
        yield 'page options without the pdf format' => [self::INVOICE, 'xml', ['language' => 'de']];
        yield 'a string that is not JSON' => ['not json', 'xml', null];
    }

    /**
     * @param array<string, mixed>|string $invoice
     * @param array<string, mixed>|null   $pdfOptions
     */
    #[DataProvider('badArguments')]
    public function testBadArgumentsFailBeforeAnyRequest(array|string $invoice, string $format, ?array $pdfOptions): void
    {
        $fake = new FakeHttpClient();

        try {
            (new Client('k', $fake))->generate($invoice, $format, $pdfOptions);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $fake->requests);
        }
    }
}
