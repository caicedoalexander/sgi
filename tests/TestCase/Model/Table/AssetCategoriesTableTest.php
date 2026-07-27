<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Test\Factory\AssetCategoryFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class AssetCategoriesTableTest extends TestCase
{
    public function testRequiresCodeAndName(): void
    {
        $table = TableRegistry::getTableLocator()->get('AssetCategories');
        $entity = $table->newEntity([]);
        $this->assertArrayHasKey('code', $entity->getErrors());
        $this->assertArrayHasKey('name', $entity->getErrors());
    }

    public function testFindActiveExcludesInactive(): void
    {
        AssetCategoryFactory::new()->save();
        AssetCategoryFactory::new()->inactive()->save();

        $table = TableRegistry::getTableLocator()->get('AssetCategories');
        $active = $table->find('active')->all()->toArray();

        $this->assertCount(1, $active);
        $this->assertTrue($active[0]->active);
    }
}
