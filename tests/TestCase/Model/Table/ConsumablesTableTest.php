<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Test\Factory\ConsumableFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

final class ConsumablesTableTest extends TestCase
{
    public function testRequiresReferenceAndDescription(): void
    {
        $table = TableRegistry::getTableLocator()->get('Consumables');
        $entity = $table->newEntity([]);
        $this->assertArrayHasKey('reference', $entity->getErrors());
        $this->assertArrayHasKey('description', $entity->getErrors());
    }

    public function testFindLowStockReturnsAtOrBelowMinimum(): void
    {
        ConsumableFactory::new()->withStock(2, 5)->save(); // bajo
        ConsumableFactory::new()->withStock(10, 5)->save(); // ok
        ConsumableFactory::new()->withStock(5, 5)->save(); // en el mínimo => bajo

        $table = TableRegistry::getTableLocator()->get('Consumables');
        $low = $table->find('lowStock')->all();

        $this->assertCount(2, $low);
    }
}
