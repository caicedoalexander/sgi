<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory mínima de Employee. Todas las FK de employees son nullable, así que
 * no requiere parents. document_number es único.
 */
class EmployeeFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Employees';
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'document_type' => 'CC',
            'document_number' => 'DOC-' . Seq::next(),
            'first_name' => $generator->firstName(),
            'last_name1' => $generator->lastName(),
        ];
    }
}
