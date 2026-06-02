<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationDocumentService;
use App\Service\AdvanceLegalizationHistoryService;
use App\Service\AdvanceLegalizationService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\Event\EventManager;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) del caso FALTANTE (shortage) de
 * AdvanceLegalizationService.
 *
 * Flujo cubierto: contabilidad --registerShortage--> tesoreria
 * --confirmShortageReceipt--> legalizada.
 *
 * Cada caso siembra la legalización DIRECTAMENTE en el estado de entrada con
 * AdvanceLegalizationFactory (withStatus/withCaseType/withShortageAmount setean
 * campos no-accesibles vía fixture-factories) y relee desde la BD tras la
 * operación para verificar la persistencia. El EventManager se construye aislado
 * (sin subscribers) para que el cierre a `legalizada` no dispare efectos
 * colaterales fuera del alcance del test.
 *
 * El rollback por test lo aplica la estrategia global (config/app.php →
 * TestSuite.fixtureStrategy), por eso no se declara `$fixtures`.
 */
final class AdvanceLegalizationShortageTest extends TestCase
{
    /**
     * Construye el servicio con un EventManager aislado y los history/document
     * services reales. El stateRegistry queda en su default.
     *
     * @return \App\Service\AdvanceLegalizationService
     */
    private function buildService(): AdvanceLegalizationService
    {
        return new AdvanceLegalizationService(
            new EventManager(),
            new AdvanceLegalizationHistoryService(),
            new AdvanceLegalizationDocumentService(),
        );
    }

    /**
     * registerShortage (happy path): legalización en Contabilidad con case_type
     * null. Declara un faltante de 250 → success; releído de BD: case_type
     * 'faltante', shortage_amount=250 y status=Tesorería.
     *
     * @return void
     */
    public function testRegisterShortageDeclaresShortageAndMovesToTesoreria(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->registerShortage($leg, 250.0, $user->id);

        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_TESORERIA, $persisted->status);
        $this->assertSame(AdvanceConstants::CASE_FALTANTE, $persisted->case_type);
        $this->assertSame(250.0, (float)$persisted->shortage_amount);
    }

    /**
     * registerShortage (error): con un monto <= 0 falla y la legalización
     * permanece en Contabilidad sin caso declarado.
     *
     * @return void
     */
    public function testRegisterShortageFailsWhenAmountIsNotPositive(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->registerShortage($leg, 0.0, $user->id);

        $this->assertFalse($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_CONTABILIDAD, $persisted->status);
        $this->assertNull($persisted->case_type);
    }

    /**
     * registerShortage (error): una legalización fuera de Contabilidad (en
     * Validación) no permite declarar faltante (canRegisterShortage falso). El
     * estado permanece sin cambios.
     *
     * @return void
     */
    public function testRegisterShortageFailsWhenNotInContabilidad(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->registerShortage($leg, 250.0, $user->id);

        $this->assertFalse($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_VALIDACION, $persisted->status);
        $this->assertNull($persisted->case_type);
    }

    /**
     * confirmShortageReceipt (happy path): legalización en Tesorería con
     * case_type 'faltante' y shortage_amount>0. Tesorería confirma la
     * consignación con un número de comprobante → success; releído de BD:
     * status=Legalizada, shortage_receipt_number='REC-123' y legalized_at fijado.
     *
     * @return void
     */
    public function testConfirmShortageReceiptClosesLegalization(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_TESORERIA)
            ->withCaseType(AdvanceConstants::CASE_FALTANTE)
            ->withShortageAmount(250.0)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->confirmShortageReceipt(
            $leg,
            ['receipt_number' => 'REC-123'],
            $user->id,
        );

        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_LEGALIZADA, $persisted->status);
        $this->assertSame('REC-123', $persisted->shortage_receipt_number);
        $this->assertNotNull($persisted->legalized_at);
    }

    /**
     * confirmShortageReceipt (error): con número de comprobante vacío falla y la
     * legalización permanece en Tesorería.
     *
     * @return void
     */
    public function testConfirmShortageReceiptFailsWithEmptyReceiptNumber(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_TESORERIA)
            ->withCaseType(AdvanceConstants::CASE_FALTANTE)
            ->withShortageAmount(250.0)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->confirmShortageReceipt(
            $leg,
            ['receipt_number' => '   '],
            $user->id,
        );

        $this->assertFalse($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_TESORERIA, $persisted->status);
    }
}
