<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class OperationCenterFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'OperationCenters';
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'name' => $generator->city(),
            'code' => (string)Seq::next(),
        ];
    }
}
