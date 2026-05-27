<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice;

use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationService;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use App\Service\Pipeline\Invoice\Policy\AnticipoDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\LegalizacionDocumentTypePolicy;
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
            InvoiceConstants::DOCTYPE_RECIBO_CAJA,
        ];
        foreach ($standardDoctypes as $doctype) {
            $this->assertInstanceOf(
                StandardDocumentTypePolicy::class,
                $this->factory->for($doctype),
                "doctype={$doctype}"
            );
        }
    }
}
