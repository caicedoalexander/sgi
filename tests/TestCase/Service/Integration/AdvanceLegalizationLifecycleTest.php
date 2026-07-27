<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationDocumentService;
use App\Service\AdvanceLegalizationGuard;
use App\Service\AdvanceLegalizationHistoryService;
use App\Service\AdvanceLegalizationService;
use App\Service\InvoiceHistoryService;
use App\Service\Pipeline\Invoice\LinkedInvoiceLegalizer;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\Event\EventManager;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) del ciclo de vida y las consultas de
 * AdvanceLegalizationService.
 *
 * Cubre la creación idempotente de la legalización a partir de un Anticipo
 * pagado (initialize/hasLegalization), la vinculación/desvinculación de
 * facturas de legalización (linkInvoices/unlinkInvoice) y los cálculos de
 * totales y diferencia (getLinkedTotal/getDifference). Cada caso relee desde
 * la BD para verificar la persistencia real.
 *
 * El rollback por test lo aplica la estrategia global (config/app.php →
 * TestSuite.fixtureStrategy), por eso no se declara `$fixtures`.
 */
final class AdvanceLegalizationLifecycleTest extends TestCase
{
    /**
     * Construye el servicio con un EventManager aislado (sin subscribers) y los
     * history/document services reales; el stateRegistry queda en su default.
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
     * initialize(): un Anticipo en Pagada crea una legalización en Validación.
     * La fila se relee desde la BD para verificar status y advance_invoice_id.
     *
     * @return void
     */
    public function testInitializeCreatesLegalizationInValidacion(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->initialize($anticipo, $user->id);

        $this->assertTrue($result->success);

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($result->data->id);
        $this->assertSame(AdvanceConstants::STATUS_VALIDACION, $persisted->status);
        $this->assertSame($anticipo->id, $persisted->advance_invoice_id);
    }

    /**
     * initialize() es idempotente: una segunda llamada sobre el mismo Anticipo
     * devuelve la fila existente y no crea una segunda legalización.
     *
     * @return void
     */
    public function testInitializeIsIdempotent(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $user = UserFactory::new()->save();
        $service = $this->buildService();

        $first = $service->initialize($anticipo, $user->id);
        $second = $service->initialize($anticipo, $user->id);

        $this->assertTrue($first->success);
        $this->assertTrue($second->success);
        $this->assertSame($first->data->id, $second->data->id);

        $count = $this->fetchTable('AdvanceLegalizations')
            ->find()
            ->where(['advance_invoice_id' => $anticipo->id])
            ->count();
        $this->assertSame(1, $count);
    }

    /**
     * initialize() rechaza un Anticipo que no está en Pagada (camino de error).
     *
     * @return void
     */
    public function testInitializeFailsWhenAnticipoNotPaid(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->initialize($anticipo, $user->id);

        $this->assertFalse($result->success);
        $this->assertFalse(
            $this->fetchTable('AdvanceLegalizations')->exists(['advance_invoice_id' => $anticipo->id]),
        );
    }

    /**
     * hasLegalization(): true tras initialize, false para un Anticipo sin
     * legalización asociada.
     *
     * @return void
     */
    public function testHasLegalizationReflectsExistence(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $otro = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $user = UserFactory::new()->save();
        $service = $this->buildService();

        $service->initialize($anticipo, $user->id);

        $this->assertTrue($service->hasLegalization($anticipo->id));
        $this->assertFalse($service->hasLegalization($otro->id));
    }

    /**
     * linkInvoices(): vincula 3 facturas de Legalización (creadas sin advance_id)
     * a la legalización en Validación. Tras la operación quedan con
     * advance_id = leg.advance_invoice_id y data['linked'] = 3.
     *
     * @return void
     */
    public function testLinkInvoicesAttachesInvoicesToAdvance(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $user = UserFactory::new()->save();

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $invoice = InvoiceFactory::new()->legalizacion()
                ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
            $ids[] = $invoice->id;
        }

        $result = $this->buildService()->linkInvoices($leg, $ids, $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(3, $result->data['linked']);

        $invoices = $this->fetchTable('Invoices');
        foreach ($ids as $id) {
            $persisted = $invoices->get($id);
            $this->assertSame($anticipo->id, $persisted->advance_id);
        }
    }

    public function testLinkInvoicesAcceptsReciboDeCajaInAprobacion(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $user = UserFactory::new()->save();
        $rc = InvoiceFactory::new()->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $result = $this->buildService()->linkInvoices($leg, [$rc->id], $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->data['linked']);
        $this->assertSame($anticipo->id, $this->fetchTable('Invoices')->get($rc->id)->advance_id);
    }

    public function testLinkInvoicesRejectsReciboDeCajaOutsideAprobacion(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $user = UserFactory::new()->save();
        // RC fuera de Aprobación: su id se inyecta directo (form rancio / 2.ª pestaña).
        $rc = InvoiceFactory::new()->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();

        $result = $this->buildService()->linkInvoices($leg, [$rc->id], $user->id);

        // No hace match en el updateAll → ok con 0 vinculadas, advance_id intacto.
        $this->assertSame(0, $result->data['linked'] ?? null);
        $this->assertNull($this->fetchTable('Invoices')->get($rc->id)->advance_id);
    }

    /**
     * linkInvoices() camino de error: si la legalización no está en Validación,
     * no vincula y retorna fail.
     *
     * @return void
     */
    public function testLinkInvoicesFailsOutsideValidacion(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();
        $invoice = InvoiceFactory::new()->legalizacion()
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $result = $this->buildService()->linkInvoices($leg, [$invoice->id], $user->id);

        $this->assertFalse($result->success);
        $this->assertNull($this->fetchTable('Invoices')->get($invoice->id)->advance_id);
    }

    /**
     * unlinkInvoice(): tras vincular una factura, desvincularla deja su
     * advance_id en null releyendo desde la BD.
     *
     * @return void
     */
    public function testUnlinkInvoiceDetachesInvoice(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $user = UserFactory::new()->save();
        $invoice = InvoiceFactory::new()->legalizacion()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $service = $this->buildService();

        $link = $service->linkInvoices($leg, [$invoice->id], $user->id);
        $this->assertTrue($link->success);

        $result = $service->unlinkInvoice($leg, $invoice->id, $user->id);

        $this->assertTrue($result->success);
        $this->assertNull($this->fetchTable('Invoices')->get($invoice->id)->advance_id);
    }

    /**
     * getLinkedTotal() y getDifference(): un Anticipo de 1000 con 2 facturas de
     * Legalización vinculadas que suman 800 → total 800 y diferencia 200
     * (faltante positivo).
     *
     * @return void
     */
    public function testGetLinkedTotalAndDifference(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(500.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(300.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $service = $this->buildService();

        $this->assertEqualsWithDelta(800.0, $service->getLinkedTotal($leg), 0.001);
        $this->assertEqualsWithDelta(200.0, $service->getDifference($leg), 0.001);
    }

    /**
     * Un Recibo de Caja vinculado (advance_id = anticipo) en Contabilidad cuenta
     * como factura vinculada para el guard de validación y suma al total.
     *
     * @return void
     */
    public function testGuardAndTotalIncludeLinkedReciboDeCaja(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        // RC ya vinculado (advance_id = anticipo) en Contabilidad.
        InvoiceFactory::new(['advance_id' => $anticipo->id])->reciboDeCaja()
            ->withAmount(500.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        // Guard: lo cuenta como factura vinculada.
        $linked = (new AdvanceLegalizationGuard())
            ->linkedLegalizationInvoices((int)$anticipo->id);
        $this->assertCount(1, $linked);

        // Total: su monto suma.
        $this->assertEqualsWithDelta(500.0, $this->buildService()->getLinkedTotal($leg), 0.001);
    }

    public function testLegalizeForPromotesLinkedReciboDeCaja(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();

        // Un RC vinculado en Contabilidad y una Legalización vinculada en Contabilidad.
        $rc = InvoiceFactory::new(['advance_id' => $anticipo->id])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $leg = InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $count = (new LinkedInvoiceLegalizer(new InvoiceHistoryService()))
            ->legalizeFor((int)$anticipo->id, (int)$user->id);

        $this->assertSame(2, $count);
        $invoices = $this->fetchTable('Invoices');
        $this->assertSame(InvoiceConstants::STATUS_LEGALIZADA, $invoices->get($rc->id)->pipeline_status);
        $this->assertSame(InvoiceConstants::STATUS_LEGALIZADA, $invoices->get($leg->id)->pipeline_status);
    }

    public function testLegalizationInValidacionReturnsLegWhenInValidacion(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        $found = $this->buildService()->legalizationInValidacion((int)$anticipo->id);

        $this->assertNotNull($found);
        $this->assertSame($leg->id, $found->id);
    }

    public function testLegalizationInValidacionNullWhenNotInValidacion(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->assertNull($this->buildService()->legalizationInValidacion((int)$anticipo->id));
    }

    public function testLegalizationInValidacionNullWhenNoLegalization(): void
    {
        $this->assertNull($this->buildService()->legalizationInValidacion(999999));
    }

    public function testRecordDirectLinkWritesLinkHistory(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $rc = InvoiceFactory::new()->reciboDeCaja()->save();
        $user = UserFactory::new()->save();

        $this->buildService()->recordDirectLink($leg, $rc, (int)$user->id);

        $count = $this->fetchTable('AdvanceLegalizationHistories')->find()
            ->where(['legalization_id' => $leg->id, 'field_changed' => 'invoices_linked'])
            ->count();
        $this->assertSame(1, $count);
    }
}
