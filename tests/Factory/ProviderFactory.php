<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\ProviderConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de ejemplo para Providers — valida el setup de fixture-factories.
 *
 * Uso:
 *   ProviderFactory::new()->save();              // persiste 1
 *   ProviderFactory::new()->count(3)->saveMany(); // persiste 3
 *   ProviderFactory::new()->inactive()->build(); // sin persistir
 */
class ProviderFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Providers';
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'document_type' => ProviderConstants::DOCUMENT_TYPE_NIT,
            'document_number' => $generator->numerify('#########'),
            'name' => $generator->company(),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->setField('active', false);
    }
}
