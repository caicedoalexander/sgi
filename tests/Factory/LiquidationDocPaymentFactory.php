<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Model\Entity\NoveltyLiquidationDoc;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de LiquidationDocPayment. Auto-crea los parents NOT NULL banking_entity
 * y created_by (user → role). El liquidation_doc se liga con forDoc() a un
 * documento existente.
 */
class LiquidationDocPaymentFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'LiquidationDocPayments';
    }

    public static function new(mixed $makeParameter = [], int $times = 1): static
    {
        return parent::new($makeParameter, $times)->withRequiredParents(['NoveltyLiquidationDocs']);
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
            'status' => 'pending',
            'authorized' => false,
        ];
    }

    public function forDoc(NoveltyLiquidationDoc|int $doc): static
    {
        return $this->setField('liquidation_doc_id', $doc instanceof NoveltyLiquidationDoc ? $doc->id : $doc);
    }

    public function withAmount(float $amount): static
    {
        return $this->setField('amount', $amount);
    }

    public function authorized(): static
    {
        return $this->setField('status', 'authorized')->setField('authorized', true);
    }
}
