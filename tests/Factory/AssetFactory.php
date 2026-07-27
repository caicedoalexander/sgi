<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\AssetConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de Asset. Auto-crea los parents NOT NULL (asset_category_id,
 * operation_center_id) vía withRequiredParents.
 */
class AssetFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Assets';
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
            'code' => 'ACT-' . Seq::next(),
            'status' => AssetConstants::STATUS_DISPONIBLE,
            'brand' => $generator->word(),
            'model' => $generator->word(),
        ];
    }

    public function withStatus(string $status): static
    {
        return $this->setField('status', $status);
    }

    public function withCategory(int $categoryId): static
    {
        return $this->setField('asset_category_id', $categoryId);
    }

    public function withOperationCenter(int $operationCenterId): static
    {
        return $this->setField('operation_center_id', $operationCenterId);
    }

    public function withResponsible(int $employeeId): static
    {
        return $this->setField('responsible_employee_id', $employeeId);
    }
}
