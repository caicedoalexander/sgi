<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\NoveltyConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de NoveltyLiquidationDoc. Auto-crea los parents NOT NULL performed_by
 * y created_by (user → role).
 */
class NoveltyLiquidationDocFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'NoveltyLiquidationDocs';
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
            'liquidation_number' => 'LIQ-' . Seq::next(),
            'period' => NoveltyConstants::PERIOD_PRIMERA_QUINCENA,
            'document_date' => $generator->date(),
            'pipeline_status' => NoveltyConstants::STATUS_TESORERIA,
        ];
    }

    public function withStatus(string $status): static
    {
        return $this->setField('pipeline_status', $status);
    }
}
