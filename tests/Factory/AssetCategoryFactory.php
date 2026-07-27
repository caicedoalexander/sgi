<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class AssetCategoryFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'AssetCategories';
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'code' => 'CAT-' . Seq::next(),
            'name' => $generator->word(),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->setField('active', false);
    }
}
