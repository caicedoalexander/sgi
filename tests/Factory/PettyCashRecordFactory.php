<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\PettyCashConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de PettyCashRecord. Auto-crea el parent NOT NULL created_by (user → role).
 */
class PettyCashRecordFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'PettyCashRecords';
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
            'code' => 'CM-' . Seq::next(),
            'status' => PettyCashConstants::STATUS_AGRUPACION,
            'total_amount' => 0,
        ];
    }

    public function withStatus(string $status): static
    {
        return $this->setField('status', $status);
    }
}
