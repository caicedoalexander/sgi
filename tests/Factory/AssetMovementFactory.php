<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\AssetConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de AssetMovement. Auto-crea asset_id y performed_by_user_id (NOT NULL).
 */
class AssetMovementFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'AssetMovements';
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
            'movement_type' => AssetConstants::MOVEMENT_ENTREGA,
            'movement_date' => date('Y-m-d H:i:s'),
            'source' => AssetConstants::SOURCE_WEB,
        ];
    }

    public function forAsset(int $assetId): static
    {
        return $this->setField('asset_id', $assetId);
    }

    public function withType(string $type): static
    {
        return $this->setField('movement_type', $type);
    }

    public function withActaStatus(?string $status): static
    {
        return $this->setField('acta_status', $status);
    }

    public function withMovementDate(string $date): static
    {
        return $this->setField('movement_date', $date);
    }
}
