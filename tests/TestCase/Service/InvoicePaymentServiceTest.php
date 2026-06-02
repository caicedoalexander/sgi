<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\InvoiceConstants;
use App\Model\Entity\InvoicePayment;
use App\Service\AdvanceLegalizationService;
use App\Service\InvoiceHistoryService;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use App\Service\Pipeline\Invoice\Policy\AnticipoDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\LegalizacionDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\StandardDocumentTypePolicy;
use Cake\Database\Connection;
use Cake\Event\EventManagerInterface;
use Cake\ORM\Entity;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para InvoicePaymentService — el servicio que maneja dinero.
 *
 * Cubre los caminos que no dependen de queries acumulativas (`find()->sumOf()`):
 *  - registerPayment(): guardas de validación (is_refund fuera de Anticipo, monto ≤ 0).
 *  - rejectPayment(): motivo obligatorio, pago ya autorizado, rechazo no-refund
 *    (regresa a tesorería + auditoría), rechazo refund (dispatch de evento, sin tocar
 *    el pipeline de la factura) y fallo de persistencia → rollback.
 *  - editPayment(): observación obligatoria, pago autorizado/rechazado inmutable,
 *    sin campos a modificar.
 *  - confirmPayment(): guarda de estado (solo desde verificacion_pago).
 *
 * Suite pura (sin DB): TableLocator con mocks de Invoices/InvoicePayments cuyo
 * getConnection()->transactional() ejecuta el callback en memoria y cuyo save() se
 * intercepta. El history service y el EventManager se mockean para verificar la
 * transición atómica. Los caminos felices que recalculan saldos contra la BD
 * (authorizePayment, registerPayment happy path, recalculatePaymentStatus,
 * getPendingBalance, confirmPayment happy path) quedan para tests de integración.
 */
final class InvoicePaymentServiceTest extends TestCase
{
    private InvoiceHistoryService $history;
    private EventManagerInterface $events;
    private bool $saveSucceeds = true;
    private Entity $payment;
    private Entity $invoice;

    protected function setUp(): void
    {
        $this->history = $this->createMock(InvoiceHistoryService::class);
        $this->events = $this->createMock(EventManagerInterface::class);
        $this->saveSucceeds = true;

        $this->payment = new InvoicePayment([
            'id' => 50,
            'invoice_id' => 1,
            'status' => InvoiceConstants::PAYMENT_RECORD_PENDING,
            'amount' => 500,
        ]);
        $this->invoice = new Entity([
            'id' => 1,
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'pipeline_status' => InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            'amount' => 1000,
        ]);
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    private function buildService(): InvoicePaymentService
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['transactional'])
            ->getMock();
        $connection->method('transactional')
            ->willReturnCallback(fn(callable $cb) => $cb());

        $paymentsTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'save', 'getConnection'])
            ->getMock();
        $paymentsTable->method('get')->willReturnCallback(fn() => $this->payment);
        $paymentsTable->method('getConnection')->willReturn($connection);
        $paymentsTable->method('save')->willReturnCallback(fn($entity) => $this->saveSucceeds ? $entity : false);

        $invoicesTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'save', 'getConnection'])
            ->getMock();
        $invoicesTable->method('get')->willReturnCallback(fn() => $this->invoice);
        $invoicesTable->method('getConnection')->willReturn($connection);
        $invoicesTable->method('save')->willReturnCallback(fn($entity) => $this->saveSucceeds ? $entity : false);

        $locator = new TableLocator();
        $locator->set('InvoicePayments', $paymentsTable);
        $locator->set('Invoices', $invoicesTable);
        TableRegistry::setTableLocator($locator);

        $factory = new DocumentTypePolicyFactory(
            new StandardDocumentTypePolicy(),
            new AnticipoDocumentTypePolicy($this->createStub(AdvanceLegalizationService::class)),
            new LegalizacionDocumentTypePolicy(),
        );

        return new InvoicePaymentService($this->history, $factory, $this->events);
    }

    // --- registerPayment: guardas de validación ---

    public function testRegisterPaymentRejectsRefundOnNonAnticipoDocument(): void
    {
        $service = $this->buildService();

        $result = $service->registerPayment(1, ['is_refund' => true, 'amount' => 500], 9);

        $this->assertFalse($result->success);
        $this->assertSame(['is_refund solo es válido en pagos de Anticipos.'], $result->errors);
    }

    public function testRegisterPaymentRejectsNonPositiveAmount(): void
    {
        $service = $this->buildService();

        $result = $service->registerPayment(1, ['amount' => 0], 9);

        $this->assertFalse($result->success);
        $this->assertSame(['El monto del pago debe ser mayor a cero.'], $result->errors);
    }

    // --- rejectPayment ---

    public function testRejectPaymentRequiresReason(): void
    {
        $service = $this->buildService();

        $result = $service->rejectPayment(50, 9, '   ');

        $this->assertFalse($result->success);
        $this->assertSame(['El motivo de rechazo es obligatorio.'], $result->errors);
    }

    public function testRejectPaymentRefusesAlreadyAuthorizedPayment(): void
    {
        $this->payment->status = InvoiceConstants::PAYMENT_RECORD_AUTHORIZED;
        $service = $this->buildService();

        $result = $service->rejectPayment(50, 9, 'monto equivocado');

        $this->assertFalse($result->success);
        $this->assertSame(['No se puede rechazar un pago ya autorizado.'], $result->errors);
    }

    public function testRejectPaymentNonRefundReturnsInvoiceToTreasuryAndRecordsHistory(): void
    {
        $this->events->expects($this->never())->method('dispatch');
        $this->history->expects($this->exactly(2))->method('recordFieldChange');
        $this->history->expects($this->once())
            ->method('recordStatusChange')
            ->with(
                1,
                InvoiceConstants::STATUS_AUTORIZACION_PAGO,
                InvoiceConstants::STATUS_TESORERIA,
                9,
            );

        $service = $this->buildService();

        $result = $service->rejectPayment(50, 9, 'monto equivocado');

        $this->assertTrue($result->success);
        $this->assertSame('Pago rechazado. Factura devuelta a Tesorería.', $result->data);
        $this->assertSame(InvoiceConstants::PAYMENT_RECORD_REJECTED, $this->payment->status);
        $this->assertSame('monto equivocado', $this->payment->rejection_reason);
        $this->assertSame(InvoiceConstants::STATUS_TESORERIA, $this->invoice->pipeline_status);
    }

    public function testRejectPaymentRefundDispatchesEventAndKeepsInvoicePipeline(): void
    {
        $this->payment->is_refund = true;
        $this->invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;

        $this->events->expects($this->once())->method('dispatch');
        $this->history->expects($this->exactly(2))->method('recordFieldChange');
        $this->history->expects($this->never())->method('recordStatusChange');

        $service = $this->buildService();

        $result = $service->rejectPayment(50, 9, 'devolución incorrecta');

        $this->assertTrue($result->success);
        $this->assertSame('Reintegro rechazado. La legalización volvió a Tesorería.', $result->data);
        $this->assertSame(InvoiceConstants::PAYMENT_RECORD_REJECTED, $this->payment->status);
        $this->assertSame('devolución incorrecta', $this->payment->rejection_reason);
        // La factura del Anticipo permanece en `pagada`: el rechazo solo afecta la legalización.
        $this->assertSame(InvoiceConstants::STATUS_PAGADA, $this->invoice->pipeline_status);
    }

    public function testRejectPaymentFailsWhenSaveFails(): void
    {
        $this->saveSucceeds = false;
        $this->history->expects($this->never())->method('recordStatusChange');

        $service = $this->buildService();

        $result = $service->rejectPayment(50, 9, 'monto equivocado');

        $this->assertFalse($result->success);
        $this->assertSame(['No se pudo rechazar el pago.'], $result->errors);
    }

    // --- editPayment ---

    public function testEditPaymentRequiresReason(): void
    {
        $service = $this->buildService();

        $result = $service->editPayment(50, ['amount' => 700], '', 9);

        $this->assertFalse($result->success);
        $this->assertSame(['La observación es obligatoria.'], $result->errors);
    }

    public function testEditPaymentRefusesAuthorizedPayment(): void
    {
        $this->payment->status = InvoiceConstants::PAYMENT_RECORD_AUTHORIZED;
        $service = $this->buildService();

        $result = $service->editPayment(50, ['amount' => 700], 'corrección', 9);

        $this->assertFalse($result->success);
        $this->assertSame(['No se puede editar un pago autorizado.'], $result->errors);
    }

    public function testEditPaymentRefusesRejectedPayment(): void
    {
        $this->payment->status = InvoiceConstants::PAYMENT_RECORD_REJECTED;
        $service = $this->buildService();

        $result = $service->editPayment(50, ['amount' => 700], 'corrección', 9);

        $this->assertFalse($result->success);
        $this->assertSame(['No se puede editar un pago rechazado.'], $result->errors);
    }

    public function testEditPaymentRejectsWhenNoEditableFields(): void
    {
        $service = $this->buildService();

        $result = $service->editPayment(50, ['unexpected' => 'x'], 'corrección', 9);

        $this->assertFalse($result->success);
        $this->assertSame(['No se recibieron datos a modificar.'], $result->errors);
    }

    // --- confirmPayment ---

    public function testConfirmPaymentRequiresVerificationStatus(): void
    {
        $this->invoice->pipeline_status = InvoiceConstants::STATUS_AUTORIZACION_PAGO;
        $service = $this->buildService();

        $result = $service->confirmPayment(1, 9);

        $this->assertFalse($result->success);
        $this->assertSame(['La factura no está en verificación de pago.'], $result->errors);
    }
}
