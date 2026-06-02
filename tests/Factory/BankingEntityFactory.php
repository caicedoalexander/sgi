<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class BankingEntityFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'BankingEntities';
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'code' => $generator->numerify('BANK#########'),
            'name' => $generator->company(),
        ];
    }
}
