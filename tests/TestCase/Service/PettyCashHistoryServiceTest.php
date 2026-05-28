<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Model\Entity\PettyCashRecord;
use App\Service\PettyCashHistoryService;
use Cake\Database\Connection;
use Cake\ORM\Entity;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para PettyCashHistoryService.
 *
 * La suite es pura (sin DB). Se inyecta un TableLocator real con un mock de
 * Table cuyos newEntity()/save()/saveMany()/getConnection() se interceptan,
 * evitando cualquier acceso a la base de datos.
 */
final class PettyCashHistoryServiceTest extends TestCase
{
    private PettyCashHistoryService $service;

    /**
     * Datos capturados de cada save()/saveMany() invocado durante el test.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $savedEntities;

    protected function setUp(): void
    {
        $this->service = new PettyCashHistoryService();
        $this->savedEntities = [];

        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['transactional'])
            ->getMock();
        $connection->method('transactional')
            ->willReturnCallback(fn(callable $cb) => $cb());

        $table = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['newEntity', 'save', 'saveMany', 'getConnection'])
            ->getMock();

        $table->method('newEntity')->willReturnCallback(fn(array $data) => new Entity($data));
        $table->method('save')->willReturnCallback(function (Entity $entity) {
            $this->savedEntities[] = $entity->toArray();

            return $entity;
        });
        $table->method('saveMany')->willReturnCallback(function (array $entities) {
            foreach ($entities as $entity) {
                $this->savedEntities[] = $entity->toArray();
            }

            return $entities;
        });
        $table->method('getConnection')->willReturn($connection);

        $locator = new TableLocator();
        $locator->set('PettyCashHistories', $table);
        TableRegistry::setTableLocator($locator);
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    public function testRecordChangesPersistsDifferingFields(): void
    {
        $original = new PettyCashRecord([
            'id' => 7,
            'notes' => 'Nota vieja',
            'accrued' => false,
            'accrual_date' => null,
            'ready_for_payment' => false,
        ]);
        $modified = new PettyCashRecord([
            'id' => 7,
            'notes' => 'Nota nueva',
            'accrued' => true,
            'accrual_date' => null,
            'ready_for_payment' => false,
        ]);

        $this->service->recordChanges($original, $modified, 3);

        $this->assertCount(2, $this->savedEntities);

        $byField = [];
        foreach ($this->savedEntities as $row) {
            $byField[$row['field_changed']] = $row;
        }

        $this->assertArrayHasKey('notes', $byField);
        $this->assertSame(7, $byField['notes']['petty_cash_record_id']);
        $this->assertSame(3, $byField['notes']['user_id']);
        $this->assertSame('Nota vieja', $byField['notes']['old_value']);
        $this->assertSame('Nota nueva', $byField['notes']['new_value']);

        $this->assertArrayHasKey('accrued', $byField);
        $this->assertSame('', $byField['accrued']['old_value']);
        $this->assertSame('1', $byField['accrued']['new_value']);
    }

    public function testRecordChangesIgnoresUnchangedFields(): void
    {
        $original = new PettyCashRecord([
            'id' => 7,
            'notes' => 'Igual',
            'accrued' => true,
            'accrual_date' => '2026-05-01',
            'ready_for_payment' => true,
        ]);
        $modified = new PettyCashRecord([
            'id' => 7,
            'notes' => 'Igual',
            'accrued' => true,
            'accrual_date' => '2026-05-01',
            'ready_for_payment' => true,
        ]);

        $this->service->recordChanges($original, $modified, 3);

        $this->assertSame([], $this->savedEntities);
    }

    public function testRecordChangesTreatsEmptyStringAsNullNoNoise(): void
    {
        // normalizeValue('') === null, así que '' vs null no genera entrada.
        $original = new PettyCashRecord([
            'id' => 7,
            'notes' => '',
            'accrued' => false,
            'accrual_date' => null,
            'ready_for_payment' => false,
        ]);
        $modified = new PettyCashRecord([
            'id' => 7,
            'notes' => null,
            'accrued' => false,
            'accrual_date' => null,
            'ready_for_payment' => false,
        ]);

        $this->service->recordChanges($original, $modified, 3);

        $this->assertSame([], $this->savedEntities);
    }
}
