<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\NoveltyConstants;
use App\Model\Entity\NoveltyLiquidationDoc;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de EmployeeNovelty. Auto-crea los parents NOT NULL novelty_type y
 * registered_by (user → role). El liquidation_doc se liga con forLiquidationDoc().
 */
class EmployeeNoveltyFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'EmployeeNovelties';
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
            'filing_date' => $generator->date(),
            'pipeline_status' => NoveltyConstants::STATUS_TESORERIA,
        ];
    }

    public function withStatus(string $status): static
    {
        return $this->setField('pipeline_status', $status);
    }

    public function forLiquidationDoc(NoveltyLiquidationDoc|int $doc): static
    {
        return $this->setField('liquidation_doc_id', $doc instanceof NoveltyLiquidationDoc ? $doc->id : $doc);
    }
}
