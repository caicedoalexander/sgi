<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\PaymentSchedulingConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de PaymentScheduling. Auto-crea el parent NOT NULL created_by (user → role).
 */
class PaymentSchedulingFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'PaymentSchedulings';
    }

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
            'code' => 'PG-' . Seq::next(),
            'title' => 'Programación ' . Seq::next(),
            'pipeline_status' => PaymentSchedulingConstants::STATUS_BORRADOR,
        ];
    }

    public function withStatus(string $status): static
    {
        return $this->setField('pipeline_status', $status);
    }
}
