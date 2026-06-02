<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class ExpenseTypeFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'ExpenseTypes';
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'name' => $generator->numerify('Gasto-##########'),
        ];
    }
}
