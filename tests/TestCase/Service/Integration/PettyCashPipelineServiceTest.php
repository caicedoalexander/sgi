<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Authorization\AuthorizationFacade;
use App\Constants\Domain\Pipeline\DenialReason;
use App\Constants\InvoiceConstants;
use App\Constants\PettyCashConstants;
use App\Service\InvoiceHistoryService;
use App\Service\PettyCashPipelineService;
use App\Service\Pipeline\PettyCash\Policy\PettyCashFieldAccessPolicy;
use App\Test\Factory\InvoiceDocumentFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

/**
 * Tests de integración (con BD) de PettyCashPipelineService.
 *
 * Coordinador del pipeline de Caja Menor (agrupacion → contabilidad → tesoreria →
 * autorizacion_pago → verificacion_pago → pagada). Estos casos ejercitan el avance
 * y la regresión releyendo siempre desde la BD para verificar la persistencia del
 * estado, además del cálculo de motivos de denegación (`denialReasonForAdvance`).
 *
 * El rollback por test lo aplica la estrategia global (config/app.php →
 * TestSuite.fixtureStrategy), por eso no se declara `$fixtures`.
 */
final class PettyCashPipelineServiceTest extends TestCase
{
    /**
     * Construye el servicio con un stub de RBAC que autoriza según `$canOperate`.
     * Las policies se construyen reales (alimentadas por el mismo stub) y los
     * history services reales; los parámetros opcionales quedan en sus defaults.
     *
     * @param bool $canOperate Valor que devuelve el stub de `canOperate`.
     * @return \App\Service\PettyCashPipelineService
     */
    private function buildService(bool $canOperate = true): PettyCashPipelineService
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn($canOperate);
        $auth->method('operableSteps')->willReturn([]);

        return new PettyCashPipelineService(
            new InvoiceHistoryService(),
            $auth,
            new PettyCashFieldAccessPolicy($auth),
        );
    }

    /**
     * advance() camino feliz en una transición agrupacion → contabilidad.
     * Exige ≥1 factura agrupada y, vía PettyCashGuard, que sus hijas tengan
     * soporte documental (el DIAN no aplica: las hijas de caja menor saltan
     * Aprobación por diseño); tras avanzar el record queda en contabilidad
     * releyendo desde la BD.
     *
     * @return void
     */
    public function testAdvanceMovesRecordFromAgrupacionToContabilidad(): void
    {
        $record = PettyCashRecordFactory::new()
            ->withStatus(PettyCashConstants::STATUS_AGRUPACION)->save();
        $invoice = InvoiceFactory::new(['petty_cash_record_id' => $record->id])->save();
        InvoiceDocumentFactory::new(['invoice_id' => $invoice->id])->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->advance($record, $user->role_id, $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(PettyCashConstants::STATUS_CONTABILIDAD, $result->data['nextStatus']);

        $persisted = $this->fetchTable('PettyCashRecords')->get($record->id);
        $this->assertSame(PettyCashConstants::STATUS_CONTABILIDAD, $persisted->status);
    }

    /**
     * regress() un paso: un record en tesoreria vuelve a contabilidad y la
     * transición se persiste en la BD junto con el estado anterior reportado.
     *
     * @return void
     */
    public function testRegressMovesRecordFromTesoreriaToContabilidad(): void
    {
        $record = PettyCashRecordFactory::new()
            ->withStatus(PettyCashConstants::STATUS_TESORERIA)->save();
        InvoiceFactory::new(['petty_cash_record_id' => $record->id])->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->regress(
            $record,
            $user->role_id,
            $user->id,
            'Faltan soportes contables del registro.',
        );

        $this->assertTrue($result->success);
        $this->assertSame(PettyCashConstants::STATUS_CONTABILIDAD, $result->data['previousStatus']);

        $persisted = $this->fetchTable('PettyCashRecords')->get($record->id);
        $this->assertSame(PettyCashConstants::STATUS_CONTABILIDAD, $persisted->status);
    }

    /**
     * denialReasonForAdvance(): cuando el rol no puede operar el paso (stub con
     * canOperate=false) sobre un estado no terminal, retorna UNAUTHORIZED.
     *
     * @return void
     */
    public function testDenialReasonForAdvanceReturnsUnauthorizedWhenRoleCannotOperate(): void
    {
        $record = PettyCashRecordFactory::new()
            ->withStatus(PettyCashConstants::STATUS_AGRUPACION)->save();
        $user = UserFactory::new()->save();

        $reason = $this->buildService(false)->denialReasonForAdvance($record, $user->role_id);

        $this->assertSame(DenialReason::UNAUTHORIZED, $reason);
    }

    /**
     * denialReasonForAdvance(): un record en el estado terminal `pagada` no puede
     * avanzar y retorna TERMINAL_STATE (incluso con un rol que sí autoriza).
     *
     * @return void
     */
    public function testDenialReasonForAdvanceReturnsTerminalStateOnPagada(): void
    {
        $record = PettyCashRecordFactory::new()
            ->withStatus(PettyCashConstants::STATUS_PAGADA)->save();
        $user = UserFactory::new()->save();

        $reason = $this->buildService()->denialReasonForAdvance($record, $user->role_id);

        $this->assertSame(DenialReason::TERMINAL_STATE, $reason);
    }

    /**
     * addInvoices(): una hija en `aprobacion` se vincula y se auto-avanza a
     * `contabilidad` en la misma operación (el vínculo a Caja Menor certifica la
     * aprobación), dejando rastro en el historial de la factura y del registro padre.
     *
     * @return void
     */
    public function testAddInvoicesAutoAdvancesChildrenInAprobacion(): void
    {
        $record = PettyCashRecordFactory::new()
            ->withStatus(PettyCashConstants::STATUS_AGRUPACION)->save();
        $user = UserFactory::new()->save();
        $child = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $errors = $this->buildService()->addInvoices($record, [(int)$child->id], (int)$user->id);

        $this->assertSame([], $errors);

        $fresh = $this->fetchTable('Invoices')->get($child->id);
        $this->assertSame((int)$record->id, (int)$fresh->petty_cash_record_id);
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $fresh->pipeline_status);

        // Auditoría del cambio de estado en el historial de la hija.
        $childHistories = $this->fetchTable('InvoiceHistories')->find()
            ->where(['invoice_id' => $child->id, 'field_changed' => 'pipeline_status'])
            ->all()
            ->toList();
        $this->assertCount(1, $childHistories);
        $this->assertSame(
            InvoiceConstants::STATUS_LABELS[InvoiceConstants::STATUS_CONTABILIDAD],
            $childHistories[0]->new_value,
        );

        // Auditoría del auto-avance en el historial del registro padre.
        $recordHistories = $this->fetchTable('PettyCashHistories')->find()
            ->where([
                'petty_cash_record_id' => $record->id,
                'field_changed' => 'invoices_auto_advanced',
            ])
            ->all()
            ->toList();
        $this->assertCount(1, $recordHistories);
        $this->assertStringContainsString('(1 factura)', (string)$recordHistories[0]->new_value);
    }

    /**
     * addInvoices(): una hija que ya está en `contabilidad` se vincula sin
     * cambiar de estado y sin generar entradas de auto-avance.
     *
     * @return void
     */
    public function testAddInvoicesLeavesContabilidadChildrenUntouched(): void
    {
        $record = PettyCashRecordFactory::new()
            ->withStatus(PettyCashConstants::STATUS_AGRUPACION)->save();
        $user = UserFactory::new()->save();
        $child = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $errors = $this->buildService()->addInvoices($record, [(int)$child->id], (int)$user->id);

        $this->assertSame([], $errors);

        $fresh = $this->fetchTable('Invoices')->get($child->id);
        $this->assertSame((int)$record->id, (int)$fresh->petty_cash_record_id);
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $fresh->pipeline_status);

        $childHistories = $this->fetchTable('InvoiceHistories')->find()
            ->where(['invoice_id' => $child->id, 'field_changed' => 'pipeline_status'])
            ->all();
        $this->assertSame(0, $childHistories->count());

        $recordHistories = $this->fetchTable('PettyCashHistories')->find()
            ->where([
                'petty_cash_record_id' => $record->id,
                'field_changed' => 'invoices_auto_advanced',
            ])
            ->all();
        $this->assertSame(0, $recordHistories->count());
    }

    /**
     * addInvoices(): si una sola candidata del lote es inválida (estado no
     * vinculable), el lote completo se rechaza: ninguna factura queda vinculada
     * ni promovida (el vínculo y el auto-avance son una unidad atómica).
     *
     * @return void
     */
    public function testAddInvoicesLinksNothingWhenOneCandidateIsInvalid(): void
    {
        $record = PettyCashRecordFactory::new()
            ->withStatus(PettyCashConstants::STATUS_AGRUPACION)->save();
        $user = UserFactory::new()->save();
        $valid = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $invalid = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR])
            ->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();

        $errors = $this->buildService()->addInvoices(
            $record,
            [(int)$valid->id, (int)$invalid->id],
            (int)$user->id,
        );

        $this->assertNotEmpty($errors);

        $freshValid = $this->fetchTable('Invoices')->get($valid->id);
        $this->assertNull($freshValid->petty_cash_record_id);
        $this->assertSame(InvoiceConstants::STATUS_APROBACION, $freshValid->pipeline_status);

        $freshInvalid = $this->fetchTable('Invoices')->get($invalid->id);
        $this->assertNull($freshInvalid->petty_cash_record_id);
        $this->assertSame(InvoiceConstants::STATUS_TESORERIA, $freshInvalid->pipeline_status);
    }

    /**
     * addInvoices(): un `Recibo de Caja` libre en `aprobacion` es un doctype
     * vinculable a Caja Menor (junto con `Caja menor`): se vincula y se
     * auto-avanza a `contabilidad` igual que una factura de caja menor.
     *
     * @return void
     */
    public function testLinksReciboDeCajaAndAutoAdvances(): void
    {
        $record = PettyCashRecordFactory::new()
            ->withStatus(PettyCashConstants::STATUS_AGRUPACION)->save();
        $user = UserFactory::new()->save();
        $recibo = InvoiceFactory::new()->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        InvoiceDocumentFactory::new(['invoice_id' => $recibo->id])->save();

        $errors = $this->buildService()->addInvoices($record, [(int)$recibo->id], (int)$user->id);

        $this->assertSame([], $errors);
        $fresh = $this->fetchTable('Invoices')->get($recibo->id);
        $this->assertSame((int)$record->id, (int)$fresh->petty_cash_record_id);
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $fresh->pipeline_status);
    }

    /**
     * addInvoices(): un `Recibo de Caja` que ya está vinculado a un anticipo
     * (`advance_id` no nulo) NO es vinculable a Caja Menor aunque su doctype sí
     * lo sea: el lote se rechaza y la factura queda sin vincular.
     *
     * @return void
     */
    public function testDoesNotLinkReciboAlreadyOnAnAdvance(): void
    {
        $record = PettyCashRecordFactory::new()
            ->withStatus(PettyCashConstants::STATUS_AGRUPACION)->save();
        $user = UserFactory::new()->save();
        $anticipo = InvoiceFactory::new()->anticipo()->save();
        $recibo = InvoiceFactory::new(['advance_id' => $anticipo->id])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $errors = $this->buildService()->addInvoices($record, [(int)$recibo->id], (int)$user->id);

        $this->assertNotEmpty($errors);
        $fresh = $this->fetchTable('Invoices')->get($recibo->id);
        $this->assertNull($fresh->petty_cash_record_id);
    }
}
