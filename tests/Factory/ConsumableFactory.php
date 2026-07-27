<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class ConsumableFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Consumables';
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'reference' => 'REF-' . Seq::next(),
            'description' => $generator->word(),
            'current_stock' => 10,
            'minimum_stock' => 2,
        ];
    }

    public function withStock(int $current, int $minimum): static
    {
        return $this->setField('current_stock', $current)->setField('minimum_stock', $minimum);
    }
}
