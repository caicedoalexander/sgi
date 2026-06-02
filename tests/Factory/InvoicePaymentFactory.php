<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de InvoicePayment para tests de integración.
 *
 * Crea por defecto los parents NOT NULL banking_entity y created_by (user → role).
 * NO crea Invoice por defecto: el test debe ligar el pago a un invoice existente
 * con forInvoice($invoice) o ['invoice_id' => $id].
 *
 * Estados: pending() (default), authorized(), rejected().
 */
class InvoicePaymentFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'InvoicePayments';
    }

    /**
     * Auto-crea los parents NOT NULL banking_entity y created_by (user → role).
     * `Invoices` se excluye: el test liga el pago con forInvoice() a un invoice
     * existente.
     */
    public static function new(mixed $makeParameter = [], int $times = 1): static
    {
        return parent::new($makeParameter, $times)->withRequiredParents(['Invoices']);
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'amount' => 1000.0,
            'payment_date' => $generator->date(),
            'status' => InvoiceConstants::PAYMENT_RECORD_PENDING,
            'authorized' => false,
            'is_refund' => false,
        ];
    }

    public function forInvoice(Invoice|int $invoice): static
    {
        return $this->setField('invoice_id', $invoice instanceof Invoice ? $invoice->id : $invoice);
    }

    public function withAmount(float $amount): static
    {
        return $this->setField('amount', $amount);
    }

    public function authorized(): static
    {
        return $this->setField('status', InvoiceConstants::PAYMENT_RECORD_AUTHORIZED)
            ->setField('authorized', true);
    }
}
