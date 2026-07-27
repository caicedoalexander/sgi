<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Test\Factory\BankingEntityFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PaymentSchedulingFactory;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\RefundFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * findWithoutParent() excluye las facturas CONTENIDAS en un registro padre
 * (caja menor, reintegro, anticipo), cuyo pipeline_status escribe el padre.
 * NO excluye las agendadas en una programación de pagos: eso es una
 * referencia, no una contención.
 */
final class InvoicesTableWithoutParentTest extends TestCase
{
    /** @return list<int> */
    private function _findIds(): array
    {
        return TableRegistry::getTableLocator()->get('Invoices')
            ->find('withoutParent')
            ->all()
            ->extract('id')
            ->toList();
    }

    public function testExcludesInvoiceContainedInPettyCashRecord(): void
    {
        $record = PettyCashRecordFactory::new()->save();
        InvoiceFactory::new(['petty_cash_record_id' => $record->id])->save();
        $libre = InvoiceFactory::new()->save();

        $this->assertSame([$libre->id], $this->_findIds());
    }

    public function testExcludesInvoiceContainedInRefund(): void
    {
        $refund = RefundFactory::new()->save();
        InvoiceFactory::new(['refund_id' => $refund->id])->save();
        $libre = InvoiceFactory::new()->save();

        $this->assertSame([$libre->id], $this->_findIds());
    }

    public function testExcludesInvoiceLinkedToAdvance(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()->save();

        // El anticipo padre no tiene padre: sigue apareciendo.
        $this->assertSame([$anticipo->id], $this->_findIds());
    }

    public function testDoesNotExcludeInvoiceScheduledForPayment(): void
    {
        $scheduling = PaymentSchedulingFactory::new()->save();
        $bank = BankingEntityFactory::new()->save();
        $invoice = InvoiceFactory::new()->save();

        $items = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');
        $items->saveOrFail($items->newEntity([
            'payment_scheduling_id' => $scheduling->id,
            'invoice_id' => $invoice->id,
            'banking_entity_id' => $bank->id,
            'amount' => 1000.0,
        ]));

        $this->assertSame([$invoice->id], $this->_findIds());
    }
}
