<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice;

use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationService;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use App\Service\Pipeline\Invoice\Policy\AnticipoDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\LegalizacionDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\ReciboCajaDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\StandardDocumentTypePolicy;
use PHPUnit\Framework\TestCase;

final class DocumentTypePolicyFactoryTest extends TestCase
{
    private DocumentTypePolicyFactory $factory;

    protected function setUp(): void
    {
        $advanceService = $this->createStub(AdvanceLegalizationService::class);
        $this->factory = new DocumentTypePolicyFactory(
            new StandardDocumentTypePolicy(),
            new AnticipoDocumentTypePolicy($advanceService),
            new LegalizacionDocumentTypePolicy(),
        );
    }

    public function testAnticipoReturnsAnticipoPolicy(): void
    {
        $policy = $this->factory->for(InvoiceConstants::DOCTYPE_ANTICIPO);
        $this->assertInstanceOf(AnticipoDocumentTypePolicy::class, $policy);
    }

    public function testLegalizacionReturnsLegalizacionPolicy(): void
    {
        $policy = $this->factory->for(InvoiceConstants::DOCTYPE_LEGALIZACION);
        $this->assertInstanceOf(LegalizacionDocumentTypePolicy::class, $policy);
    }

    public function testFacturaReturnsStandardPolicy(): void
    {
        $policy = $this->factory->for(InvoiceConstants::DOCTYPE_FACTURA);
        $this->assertInstanceOf(StandardDocumentTypePolicy::class, $policy);
    }

    public function testNullReturnsStandard(): void
    {
        $this->assertInstanceOf(StandardDocumentTypePolicy::class, $this->factory->for(null));
    }

    public function testUnknownReturnsStandard(): void
    {
        $this->assertInstanceOf(StandardDocumentTypePolicy::class, $this->factory->for('Algo Inexistente'));
    }

    public function testEachDoctypeWithoutSpecialPolicyFallsBackToStandard(): void
    {
        $standardDoctypes = [
            InvoiceConstants::DOCTYPE_FACTURA,
            InvoiceConstants::DOCTYPE_NOTA_DEBITO,
            InvoiceConstants::DOCTYPE_CAJA_MENOR,
            InvoiceConstants::DOCTYPE_TARJETA_CREDITO,
            InvoiceConstants::DOCTYPE_REINTEGRO,
            InvoiceConstants::DOCTYPE_RECIBO,
        ];
        foreach ($standardDoctypes as $doctype) {
            $this->assertInstanceOf(
                StandardDocumentTypePolicy::class,
                $this->factory->for($doctype),
                "doctype={$doctype}",
            );
        }
    }

    public function testReciboCajaReturnsReciboCajaPolicy(): void
    {
        $policy = $this->factory->for(InvoiceConstants::DOCTYPE_RECIBO_CAJA);
        $this->assertInstanceOf(ReciboCajaDocumentTypePolicy::class, $policy);
    }

    public function testRequiresDianForReciboCajaIsFalse(): void
    {
        $this->assertFalse(DocumentTypePolicyFactory::requiresDianFor(InvoiceConstants::DOCTYPE_RECIBO_CAJA));
        $this->assertTrue(DocumentTypePolicyFactory::requiresDianFor(InvoiceConstants::DOCTYPE_FACTURA));
        $this->assertTrue(DocumentTypePolicyFactory::requiresDianFor(InvoiceConstants::DOCTYPE_ANTICIPO));
        $this->assertTrue(DocumentTypePolicyFactory::requiresDianFor(null));
    }

    public function testRequiresSupportForAllTypesIsTrueToday(): void
    {
        $this->assertTrue(DocumentTypePolicyFactory::requiresSupportFor(InvoiceConstants::DOCTYPE_RECIBO_CAJA));
        $this->assertTrue(DocumentTypePolicyFactory::requiresSupportFor(InvoiceConstants::DOCTYPE_FACTURA));
        $this->assertTrue(DocumentTypePolicyFactory::requiresSupportFor(null));
    }

    public function testExemptListsAreDerivedFromPolicies(): void
    {
        $this->assertSame([InvoiceConstants::DOCTYPE_RECIBO_CAJA], DocumentTypePolicyFactory::dianExemptDocumentTypes());
        $this->assertSame([], DocumentTypePolicyFactory::supportExemptDocumentTypes());
    }

    public function testPolicyClassesMirrorsFactoryInstances(): void
    {
        // Guard anti-drift BIDIRECCIONAL: para TODO doctype conocido, la clase que
        // resuelve for() debe ser exactamente la que POLICY_CLASSES declara (o
        // Standard si no está declarada). Caza tanto la entrada que falta en
        // POLICY_CLASSES como la que falta en el byType del constructor.
        $factory = new DocumentTypePolicyFactory(
            new StandardDocumentTypePolicy(),
            new AnticipoDocumentTypePolicy($this->createStub(AdvanceLegalizationService::class)),
            new LegalizacionDocumentTypePolicy(),
        );
        foreach (InvoiceConstants::DOCUMENT_TYPES as $doctype) {
            $expected = DocumentTypePolicyFactory::POLICY_CLASSES[$doctype] ?? StandardDocumentTypePolicy::class;
            $this->assertSame($expected, $factory->for($doctype)::class, "Drift POLICY_CLASSES↔byType en '{$doctype}'");
        }
    }
}
