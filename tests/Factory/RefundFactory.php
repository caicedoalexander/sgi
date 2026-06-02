<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\RefundConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de Refund para tests de integración. Auto-crea el parent NOT NULL
 * created_by (user → role). El total se valida contra las facturas hijas
 * (invoices con refund_id), que el test crea aparte con InvoiceFactory.
 */
class RefundFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Refunds';
    }

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
            'code' => $generator->numerify('RE-#########'),
            'status' => RefundConstants::STATUS_TESORERIA,
        ];
    }

    public function withStatus(string $status): static
    {
        return $this->setField('status', $status);
    }
}
