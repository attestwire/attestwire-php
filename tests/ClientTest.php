<?php

declare(strict_types=1);

namespace Attestwire\Tests;

use Attestwire\Client;
use Attestwire\Exception\ApiException;
use Attestwire\Exception\AttestwireException;
use Attestwire\Http\HttpResponse;
use Attestwire\Tests\Fixtures\FakeHttpClient;
use Attestwire\Tests\Fixtures\ThrowingHttpClient;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const VALID_RESULT = '{"valid":true,"profile":"xrechnung-ubl","errors":[],"warnings":[],"information":[]}';

    private const INVALID_RESULT = '{"valid":false,"profile":"xrechnung-ubl","errors":[{"rule":"BR-DE-15","field":"BT-10","severity":"fatal","message":"A German public-sector buyer requires a Leitweg-ID.","fix":"Set buyerReference to the Leitweg-ID your client gave you.","docsUrl":"https://attestwire.com/rules/BR-DE-15"}],"warnings":[],"information":[]}';

    public function testValidDocumentIsReportedAsSuchWithNoFindings(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::VALID_RESULT));
        $client = new Client('aw_live_test', $fake);

        $result = $client->validate('<Invoice/>');

        self::assertTrue($result->valid);
        self::assertSame([], $result->findings);
        self::assertSame('xrechnung-ubl', $result->profile);
    }

    public function testInvalidDocumentReportsFindingsNotAnException(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::INVALID_RESULT));
        $client = new Client('aw_live_test', $fake);

        $result = $client->validate('<Invoice/>');

        self::assertFalse($result->valid);
        self::assertCount(1, $result->errors());
        $finding = $result->errors()[0];
        self::assertSame('BR-DE-15', $finding->rule);
        self::assertSame('fatal', $finding->severity);
        self::assertSame('Set buyerReference to the Leitweg-ID your client gave you.', $finding->fix);
    }

    public function testSendsBearerAuthAndUserAgent(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::VALID_RESULT));
        (new Client('aw_live_abc123', $fake))->validate('<Invoice/>');

        $request = $fake->requests[0];
        self::assertSame('Bearer aw_live_abc123', $request['headers']['Authorization']);
        self::assertStringStartsWith('attestwire-php/', $request['headers']['User-Agent']);
    }

    public function testDefaultOriginAndPath(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::VALID_RESULT));
        (new Client('k', $fake))->validate('<Invoice/>');

        self::assertSame('https://api.attestwire.com/v1/validate', $fake->requests[0]['url']);
    }

    public function testCustomOrigin(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::VALID_RESULT));
        (new Client('k', $fake, 'https://staging.example.com/'))->validate('<Invoice/>');

        self::assertSame('https://staging.example.com/v1/validate', $fake->requests[0]['url']);
    }

    public function testContentTypeIsSniffedFromThePdfMagicNumber(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::VALID_RESULT));
        (new Client('k', $fake))->validate('%PDF-1.7 ...');

        self::assertSame('application/pdf', $fake->requests[0]['headers']['Content-Type']);
    }

    public function testContentTypeIsSniffedFromXml(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::VALID_RESULT));
        (new Client('k', $fake))->validate('<Invoice/>');

        self::assertSame('application/xml', $fake->requests[0]['headers']['Content-Type']);
    }

    public function testContentTypeCanBeForced(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::VALID_RESULT));
        (new Client('k', $fake))->validate('{}', 'application/json');

        self::assertSame('application/json', $fake->requests[0]['headers']['Content-Type']);
    }

    public function testApiKeyFallsBackToEnvironmentVariable(): void
    {
        putenv('ATTESTWIRE_API_KEY=aw_live_from_env');
        try {
            $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::VALID_RESULT));
            (new Client(null, $fake))->validate('<Invoice/>');

            self::assertSame('Bearer aw_live_from_env', $fake->requests[0]['headers']['Authorization']);
        } finally {
            putenv('ATTESTWIRE_API_KEY');
        }
    }

    public function testExplicitApiKeyWinsOverEnvironmentVariable(): void
    {
        putenv('ATTESTWIRE_API_KEY=aw_live_from_env');
        try {
            $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::VALID_RESULT));
            (new Client('aw_live_explicit', $fake))->validate('<Invoice/>');

            self::assertSame('Bearer aw_live_explicit', $fake->requests[0]['headers']['Authorization']);
        } finally {
            putenv('ATTESTWIRE_API_KEY');
        }
    }

    public function testMissingApiKeyRaisesLocallyWithoutASend(): void
    {
        putenv('ATTESTWIRE_API_KEY');
        $fake = new FakeHttpClient();

        $this->expectException(AttestwireException::class);
        $this->expectExceptionMessageMatches('/api key/i');

        try {
            (new Client(null, $fake))->validate('<Invoice/>');
        } finally {
            self::assertSame([], $fake->requests, 'no HTTP request should be sent with no API key');
        }
    }

    public function testHttpErrorWithJsonBodyBecomesApiException(): void
    {
        $body = '{"error":"invalid_api_key","message":"That API key is not recognised.","docs":"https://api.attestwire.com/docs#auth"}';
        $fake = (new FakeHttpClient())->queue(new HttpResponse(401, $body));

        try {
            (new Client('aw_live_bad', $fake))->validate('<Invoice/>');
            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(401, $exception->getStatus());
            self::assertSame('invalid_api_key', $exception->getErrorCode());
            self::assertSame('https://api.attestwire.com/docs#auth', $exception->getDocsUrl());
        }
    }

    public function testHttpErrorWithUpgradeUrl(): void
    {
        $body = '{"error":"plan_required","message":"Needs a paid plan.","upgrade_url":"https://attestwire.com/pricing"}';
        $fake = (new FakeHttpClient())->queue(new HttpResponse(402, $body));

        try {
            (new Client('k', $fake))->validate('<Invoice/>');
            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            self::assertSame('https://attestwire.com/pricing', $exception->getUpgradeUrl());
        }
    }

    public function testHttpErrorWithNonJsonBodyGetsAFallbackMessage(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(500, '<html>oops</html>'));

        try {
            (new Client('k', $fake))->validate('<Invoice/>');
            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(500, $exception->getStatus());
            self::assertStringContainsString('HTTP 500', $exception->getMessage());
        }
    }

    public function testConnectionFailureRaisesAttestwireException(): void
    {
        $this->expectException(AttestwireException::class);
        (new Client('k', new ThrowingHttpClient()))->validate('<Invoice/>');
    }
}
