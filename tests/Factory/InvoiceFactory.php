<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\InvoiceConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de Invoice para tests de integración.
 *
 * Crea por defecto los parents NOT NULL (operation_center, expense_type,
 * registered_by user → role). Estado por defecto: tesoreria, document_type Factura.
 *
 * Métodos de estado: enTesoreria(), enAutorizacionPago(), enVerificacionPago(),
 * pagada(), anticipo().
 */
class InvoiceFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Invoices';
    }

    /**
     * Auto-crea los parents NOT NULL (operation_center, expense_type, registered_by
     * user → role) para que `InvoiceFactory::new()->save()` sea autosuficiente.
     */
    public static function new(mixed $makeParameter = [], int $times = 1): static
    {
        return parent::new($makeParameter, $times)->withRequiredParents();
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'invoice_number' => 'F-' . Seq::next(),
            'registration_date' => $generator->date(),
            'issue_date' => $generator->date(),
            'due_date' => $generator->date(),
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'detail' => $generator->sentence(),
            'amount' => 1000.0,
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
        ];
    }

    public function withStatus(string $status): static
    {
        return $this->setField('pipeline_status', $status);
    }

    public function withAmount(float $amount): static
    {
        return $this->setField('amount', $amount);
    }

    public function enVerificacionPago(): static
    {
        return $this->setField('pipeline_status', InvoiceConstants::STATUS_VERIFICACION_PAGO);
    }

    public function anticipo(): static
    {
        return $this->setField('document_type', InvoiceConstants::DOCTYPE_ANTICIPO);
    }

    public function legalizacion(): static
    {
        return $this->setField('document_type', InvoiceConstants::DOCTYPE_LEGALIZACION);
    }
}
