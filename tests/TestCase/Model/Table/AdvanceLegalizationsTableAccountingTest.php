<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use Cake\TestSuite\TestCase;

/**
 * Los 3 campos de causación de la legalización son pipeline-controlled: se
 * persisten por asignación directa de propiedad desde el service (MI-002) y
 * NO deben poder llegar por mass-assignment desde un patchEntity con datos
 * del cliente.
 */
final class AdvanceLegalizationsTableAccountingTest extends TestCase
{
    public function testAccountingFieldsAreNotMassAssignable(): void
    {
        $entity = $this->fetchTable('AdvanceLegalizations')->newEntity([
            'advance_invoice_id' => 1,
            'created_by' => 1,
            'accrued' => true,
            'accrual_date' => '2026-06-23',
            'ready_for_payment' => InvoiceConstants::READY_FOR_PAYMENT_SI,
        ]);

        $this->assertNull($entity->accrued);
        $this->assertNull($entity->accrual_date);
        $this->assertNull($entity->ready_for_payment);
    }

    public function testAccountingFieldsPersistWhenAssignedDirectly(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $table = $this->fetchTable('AdvanceLegalizations');
        $leg->accrued = true;
        $leg->accrual_date = '2026-06-23';
        $leg->ready_for_payment = InvoiceConstants::READY_FOR_PAYMENT_SI;
        $table->saveOrFail($leg);

        $persisted = $table->get($leg->id);
        $this->assertTrue((bool)$persisted->accrued);
        $this->assertSame('2026-06-23', $persisted->accrual_date->format('Y-m-d'));
        $this->assertSame(InvoiceConstants::READY_FOR_PAYMENT_SI, $persisted->ready_for_payment);
    }

    public function testFactoryCanSeedAccountingFields(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_TESORERIA)
            ->withAccounting(true, '2026-06-23', InvoiceConstants::READY_FOR_PAYMENT_SI)
            ->save();

        $persisted = $this->fetchTable('AdvanceLegalizations')->get($leg->id);
        $this->assertTrue((bool)$persisted->accrued);
        $this->assertSame('2026-06-23', $persisted->accrual_date->format('Y-m-d'));
    }
}
