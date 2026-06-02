<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class UserFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Users';
    }

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'username' => $generator->numerify('user##########'),
            'password' => $generator->password(),
            'full_name' => $generator->name(),
            'email' => $generator->numerify('user##########') . '@test.local',
        ];
    }
}
