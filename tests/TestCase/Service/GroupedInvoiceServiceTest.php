<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\InvoiceConstants;
use App\Service\GroupedInvoiceService;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\InvoiceHistoryService;
use App\Test\Factory\EmployeeFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PettyCashRecordFactory;
use App\View\Presentation\InvoiceBeneficiary;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class GroupedInvoiceServiceTest extends TestCase
{
    private function _service(string $linkableStatus): GroupedInvoiceService
    {
        return new GroupedInvoiceService(
            documentType: InvoiceConstants::DOCTYPE_REINTEGRO,
            fkField: 'refund_id',
            recordTableName: 'Refunds',
            fkLabel: 'Reintegro',
            historyService: $this->createMock(HistoryServiceInterface::class),
            linkableStatus: $linkableStatus,
        );
    }

    public function testValidateGroupingRejectsWrongStatus(): void
    {
        $inv = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_REINTEGRO])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $errors = $this->_service(InvoiceConstants::STATUS_APROBACION)->validateGrouping([$inv->id]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('aprobación', mb_strtolower(implode(' ', $errors)));
    }

    public function testValidateGroupingAcceptsMatchingStatus(): void
    {
        $inv = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_REINTEGRO])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->assertSame([], $this->_service(InvoiceConstants::STATUS_APROBACION)->validateGrouping([$inv->id]));
    }

    public function testValidateGroupingAcceptsAnyLinkableStatus(): void
    {
        PettyCashRecordFactory::new()->save();
        $svc = new GroupedInvoiceService(
            documentType: InvoiceConstants::DOCTYPE_CAJA_MENOR,
            fkField: 'petty_cash_record_id',
            recordTableName: 'PettyCashRecords',
            fkLabel: 'Caja Menor',
            historyService: new InvoiceHistoryService(),
            linkableStatus: [InvoiceConstants::STATUS_APROBACION, InvoiceConstants::STATUS_CONTABILIDAD],
        );

        $enAprobacion = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $enTesoreria = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR])
            ->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();

        $this->assertSame([], $svc->validateGrouping([$enAprobacion->id]));
        $errors = $svc->validateGrouping([$enTesoreria->id]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('estado vinculable', $errors[0]);
    }

    private function _cajaMenorService(): GroupedInvoiceService
    {
        return new GroupedInvoiceService(
            documentType: [InvoiceConstants::DOCTYPE_CAJA_MENOR, InvoiceConstants::DOCTYPE_RECIBO_CAJA],
            fkField: 'petty_cash_record_id',
            recordTableName: 'PettyCashRecords',
            fkLabel: 'Caja Menor',
            historyService: $this->createMock(HistoryServiceInterface::class),
            linkableStatus: [InvoiceConstants::STATUS_APROBACION, InvoiceConstants::STATUS_CONTABILIDAD],
        );
    }

    public function testValidateGroupingAcceptsBothCajaMenorAndReciboDeCaja(): void
    {
        $cajaMenor = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $reciboLibre = InvoiceFactory::new()->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->assertSame([], $this->_cajaMenorService()->validateGrouping([$cajaMenor->id, $reciboLibre->id]));
    }

    public function testValidateGroupingRejectsReciboLinkedToAdvance(): void
    {
        // advance_id es FK a invoices(id): sembrar un anticipo real (no un id literal).
        $anticipo = InvoiceFactory::new()->anticipo()->save();
        $reciboConAnticipo = InvoiceFactory::new(['advance_id' => $anticipo->id])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $errors = $this->_cajaMenorService()->validateGrouping([$reciboConAnticipo->id]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('anticipo', mb_strtolower(implode(' ', $errors)));
    }

    public function testValidateGroupingRejectsUnlinkableDoctypeWithGenericMessage(): void
    {
        // El mensaje nuevo lista los doctypes vinculables derivados de $documentTypes.
        // Servicio de Reintegro → debe incluir "Reintegro" (fkLabel) y el doctype vinculable.
        $factura = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_FACTURA])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $errors = $this->_service(InvoiceConstants::STATUS_APROBACION)->validateGrouping([$factura->id]);
        $this->assertNotEmpty($errors);
        // Discrimina el copy NUEVO: la porción "vinculable a Reintegro (Reintegro)" no existía antes.
        $this->assertStringContainsString('vinculable a Reintegro', implode(' ', $errors));
    }

    public function testAddInvoicesLinksFreeReciboAndValidationRejectsAdvanceLinked(): void
    {
        // NOTA: el rechazo del RC-con-advance_id lo hace validateGrouping (early-return),
        // NO el compare-and-set del updateAll (ambos comparten criterio; la rama de aborto
        // del compare-and-set solo dispara bajo concurrencia real). Este test verifica el
        // comportamiento observable; la atomicidad del updateAll se valida por review del diff.
        $record = PettyCashRecordFactory::new()->save();
        $svc = $this->_cajaMenorService();

        $reciboLibre = InvoiceFactory::new()->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $this->assertSame([], $svc->addInvoices($record, [(int)$reciboLibre->id]));
        $freshLibre = TableRegistry::getTableLocator()->get('Invoices')->get($reciboLibre->id);
        $this->assertSame((int)$record->id, (int)$freshLibre->petty_cash_record_id);

        // Un RC con advance_id NO queda vinculado a caja menor (fila intacta).
        // advance_id es FK a invoices(id): sembrar un anticipo real (no un id literal).
        $anticipo = InvoiceFactory::new()->anticipo()->save();
        $reciboConAnticipo = InvoiceFactory::new(['advance_id' => $anticipo->id])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $errors = $svc->addInvoices($record, [(int)$reciboConAnticipo->id]);
        $this->assertNotEmpty($errors);
        $freshLinked = TableRegistry::getTableLocator()->get('Invoices')->get($reciboConAnticipo->id);
        $this->assertNull($freshLinked->petty_cash_record_id);
    }

    public function testAddInvoicesToleratesDuplicateIds(): void
    {
        // Regresión de I3: el loop viejo era idempotente ante ids repetidos; el compare-and-set
        // con count() crudo daría falso "no disponible". array_unique lo evita.
        $record = PettyCashRecordFactory::new()->save();
        $recibo = InvoiceFactory::new()->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->assertSame([], $this->_cajaMenorService()->addInvoices($record, [(int)$recibo->id, (int)$recibo->id]));
    }

    public function testGetAvailableInvoicesExcludesReciboLinkedToAdvance(): void
    {
        $svc = $this->_cajaMenorService();
        // issue_date reciente: getAvailableInvoices aplica un lookback por defecto de 90 días
        // cuando no hay date_from (la factory genera fechas aleatorias fuera de la ventana).
        $today = date('Y-m-d');
        $libre = InvoiceFactory::new(['issue_date' => $today])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        // advance_id es FK a invoices(id): sembrar un anticipo real (no un id literal).
        $anticipo = InvoiceFactory::new()->anticipo()->save();
        $conAnticipo = InvoiceFactory::new(['issue_date' => $today, 'advance_id' => $anticipo->id])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $ids = array_map('intval', $svc->getAvailableInvoices()->all()->extract('id')->toList());
        $this->assertContains((int)$libre->id, $ids);
        $this->assertNotContains((int)$conAnticipo->id, $ids);
    }

    public function testGetAvailableInvoicesContainsEmployeeForReciboDeCaja(): void
    {
        // Un Recibo de Caja con beneficiario empleado debe traer la asociación
        // Employees contenida, para que InvoiceBeneficiary::label resuelva el nombre
        // (sin el contain, el modal de vincular mostraba '—').
        $emp = EmployeeFactory::new()->save();
        $rc = InvoiceFactory::new([
            'issue_date' => date('Y-m-d'),
            'employee_id' => $emp->id,
            'equivalent_holder_type' => InvoiceConstants::HOLDER_TYPE_EMPLOYEE,
        ])->reciboDeCaja()->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $rows = $this->_cajaMenorService()->getAvailableInvoices()->all()->indexBy('id')->toArray();
        $this->assertArrayHasKey((int)$rc->id, $rows);
        $this->assertTrue($rows[(int)$rc->id]->hasValue('employee'), 'El RC debe traer employee contenido');
        $this->assertSame($emp->full_name, InvoiceBeneficiary::label($rows[(int)$rc->id]));
    }
}
