<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\AdvanceConstants;
use App\Model\Entity\Invoice;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de AdvanceLegalization. Auto-crea los parents NOT NULL advance_invoice
 * (un Invoice — idealmente Anticipo) y created_by (user → role).
 *
 * Para ligar a un anticipo concreto usa forAdvance($anticipo). El estado se fija
 * con withStatus() (status no es accesible en la entidad, pero fixture-factories
 * lo setea al persistir).
 */
class AdvanceLegalizationFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'AdvanceLegalizations';
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
            'status' => AdvanceConstants::STATUS_VALIDACION,
        ];
    }

    public function withStatus(string $status): static
    {
        return $this->setField('status', $status);
    }

    public function forAdvance(Invoice|int $advance): static
    {
        return $this->setField('advance_invoice_id', $advance instanceof Invoice ? $advance->id : $advance);
    }
}
