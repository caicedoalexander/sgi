<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Constants\AssetConstants;
use App\Service\CodeGeneratorService;
use App\Test\Factory\AssetCategoryFactory;
use App\Test\Factory\AssetFactory;
use App\Test\Factory\OperationCenterFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class AssetsTableTest extends TestCase
{
    public function testRequiresCategoryAndOperationCenter(): void
    {
        $table = TableRegistry::getTableLocator()->get('Assets');
        $entity = $table->newEntity([]);
        $this->assertArrayHasKey('asset_category_id', $entity->getErrors());
        $this->assertArrayHasKey('operation_center_id', $entity->getErrors());
    }

    public function testBeforeSaveAutogeneratesCode(): void
    {
        $category = AssetCategoryFactory::new()->save();
        $center = OperationCenterFactory::new(['code' => '7'])->save();
        $table = TableRegistry::getTableLocator()->get('Assets');

        $asset = $table->newEntity([
            'asset_category_id' => $category->id,
            'operation_center_id' => $center->id,
            'status' => AssetConstants::STATUS_DISPONIBLE,
        ]);
        $table->saveOrFail($asset);

        $this->assertMatchesRegularExpression('/^ACT-\d{2}-007-0001$/', $asset->code);
    }

    public function testFindFilteredByStatusAndText(): void
    {
        AssetFactory::new()->withStatus(AssetConstants::STATUS_DISPONIBLE)
            ->setField('serial_number', 'SN-ALPHA')->save();
        AssetFactory::new()->withStatus(AssetConstants::STATUS_ASIGNADO)
            ->setField('serial_number', 'SN-BETA')->save();

        $table = TableRegistry::getTableLocator()->get('Assets');

        $byStatus = $table->find('filtered', options: ['status' => AssetConstants::STATUS_ASIGNADO])->all();
        $this->assertCount(1, $byStatus);

        $byText = $table->find('filtered', options: ['q' => 'ALPHA'])->all();
        $this->assertCount(1, $byText);
    }

    public function testGenerateAssetCode(): void
    {
        $center = OperationCenterFactory::new(['code' => '3'])->save();
        $code = (new CodeGeneratorService())->generateAssetCode($center->id);
        $this->assertMatchesRegularExpression('/^ACT-\d{2}-003-0001$/', $code);
    }
}
