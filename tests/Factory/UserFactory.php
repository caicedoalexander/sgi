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
     * Auto-crea el parent NOT NULL role para que `UserFactory::new()->save()` sea
     * autosuficiente.
     */
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
            'username' => 'user' . Seq::next(),
            'password' => $generator->password(),
            'full_name' => $generator->name(),
            'email' => 'user' . Seq::next() . '@test.local',
        ];
    }
}
