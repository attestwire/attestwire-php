<?php

declare(strict_types=1);

namespace Attestwire\Tests\InvoiceSuite;

use Attestwire\Http\HttpResponse;
use Attestwire\InvoiceSuite\AttestwireDocumentValidator;
use Attestwire\Tests\Fixtures\FakeHttpClient;
use Attestwire\Tests\Fixtures\ThrowingHttpClient;
use horstoeko\invoicesuite\validators\abstracts\InvoiceSuiteAbstractDocumentValidator;
use PHPUnit\Framework\TestCase;

/**
 * Requires horstoeko/invoicesuite (require-dev; see composer.json's `suggest`
 * for why it is not a runtime dependency of this package). Skips itself
 * rather than fataling if it is not installed, since the adapter class it
 * tests is meant to be usable by a project that WILL have installed it —
 * this package itself does not require it.
 */
final class AttestwireDocumentValidatorTest extends TestCase
{
    // Matches horstoeko/invoicesuite's InvoiceSuiteXRechnungUBLInvoiceProvider::getSerializedContentMatchesScheme():
    // root Invoice element, in namespace, with a CustomizationID from its configured list and its ProfileID —
    // exactly what that provider's format-detection XPath queries look for (verified by reading
    // src/documents/providers/xr/InvoiceSuiteXRechnungUBLInvoiceProvider.php on GitHub; the base content itself is
    // not required to be otherwise complete since only createFromContent()'s provider detection reads it here).
    private const VALID_UBL = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
                 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
            <cbc:CustomizationID>urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0</cbc:CustomizationID>
            <cbc:ProfileID>urn:fdc:peppol.eu:2017:poacc:billing:01:1.0</cbc:ProfileID>
            <cbc:ID>2026-000142</cbc:ID>
        </Invoice>
        XML;

    private const VALID_RESULT = '{"valid":true,"profile":"xrechnung-ubl","errors":[],"warnings":[],"information":[{"rule":"BR-DE-TMP-32","field":"BT-72","severity":"information","message":"No time of supply stated.","fix":"Consider stating BT-72."}]}';

    private const INVALID_RESULT = '{"valid":false,"profile":"xrechnung-ubl","errors":[{"rule":"BR-DE-15","field":"BT-10","severity":"fatal","message":"A German public-sector buyer requires a Leitweg-ID.","fix":"Set buyerReference to the Leitweg-ID your client gave you."}],"warnings":[{"rule":"ATW-SOMETHING","field":"BT-1","severity":"warning","message":"w","fix":"f"}],"information":[]}';

    protected function setUp(): void
    {
        if (!class_exists(InvoiceSuiteAbstractDocumentValidator::class)) {
            self::markTestSkipped('horstoeko/invoicesuite is not installed (require-dev); skipping the adapter test.');
        }
    }

    public function testAValidDocumentMapsInformationFindingsEvenThoughValidIsTrue(): void
    {
        // Deliberately different from InvoiceSuiteDocuflairDocumentValidator,
        // which never looks at findings when its response says isValid: true.
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::VALID_RESULT));

        $validator = AttestwireDocumentValidator::createFromContent(self::VALID_UBL)
            ->setApiKey('aw_live_test')
            ->setHttpClient($fake)
            ->validate();

        self::assertFalse($validator->hasErrorMessagesInMessageBag());
        self::assertFalse($validator->hasInternalErrorMessagesInMessageBag());
        self::assertSame(1, $validator->countInfoMessagesInMessageBag());
    }

    public function testAnInvalidDocumentMapsErrorsAndWarningsBySeverity(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::INVALID_RESULT));

        $validator = AttestwireDocumentValidator::createFromContent(self::VALID_UBL)
            ->setApiKey('aw_live_test')
            ->setHttpClient($fake)
            ->validate();

        self::assertTrue($validator->hasErrorMessagesInMessageBag());
        self::assertSame(1, $validator->countErrorMessagesInMessageBag());
        self::assertSame(1, $validator->countWarningMessagesInMessageBag());

        $messages = $validator->getErrorMessagesInMessageBag();
        self::assertStringContainsString('BR-DE-15', $messages[array_key_first($messages)]->getMessageContent());
    }

    public function testMissingApiKeyIsAnInternalErrorNotAnException(): void
    {
        $fake = new FakeHttpClient();

        $validator = AttestwireDocumentValidator::createFromContent(self::VALID_UBL)
            ->setHttpClient($fake)
            ->validate();

        self::assertTrue($validator->hasInternalErrorMessagesInMessageBag());
        self::assertSame([], $fake->requests);
    }

    // Not tested here: content that isn't XML at all. createFromContent() itself already
    // rejects that (InvoiceSuiteFormatProviderNotFoundException, before doValidate() ever
    // runs) for any content no registered provider recognises — this class's own
    // content-type guard in checkRequirements() is defence-in-depth for the
    // createFromDocumentReader()/createFromDocumentBuilder() construction paths, which
    // pick a provider directly rather than by sniffing content, and reproducing that path
    // reliably needs a real provider/reader/builder this test file does not construct.

    public function testANetworkFailureIsAnInternalErrorNotAnUncaughtException(): void
    {
        $validator = AttestwireDocumentValidator::createFromContent(self::VALID_UBL)
            ->setApiKey('aw_live_test')
            ->setHttpClient(new ThrowingHttpClient())
            ->validate();

        self::assertTrue($validator->hasInternalErrorMessagesInMessageBag());
    }

    public function testAnHttpErrorStatusIsAnInternalErrorCarryingTheApiCode(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(
            401,
            '{"error":"invalid_api_key","message":"That API key is not recognised."}'
        ));

        $validator = AttestwireDocumentValidator::createFromContent(self::VALID_UBL)
            ->setApiKey('aw_live_bad')
            ->setHttpClient($fake)
            ->validate();

        self::assertTrue($validator->hasInternalErrorMessagesInMessageBag());
        $messages = $validator->getInternalErrorMessagesInMessageBag();
        $found = array_filter($messages, static fn ($m) => str_contains($m->getMessageContent(), 'invalid_api_key'));
        self::assertNotEmpty($found);
    }

    public function testCustomBaseUrlIsUsed(): void
    {
        $fake = (new FakeHttpClient())->queue(new HttpResponse(200, self::VALID_RESULT));

        AttestwireDocumentValidator::createFromContent(self::VALID_UBL)
            ->setApiKey('k')
            ->setBaseUrl('https://staging.example.com')
            ->setHttpClient($fake)
            ->validate();

        self::assertSame('https://staging.example.com/v1/validate', $fake->requests[0]['url']);
    }
}
