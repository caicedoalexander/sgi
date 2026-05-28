<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Model\Entity\Refund;
use App\Service\RefundHistoryService;
use Cake\Database\Connection;
use Cake\ORM\Entity;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para RefundHistoryService.
 *
 * La suite es pura (sin DB). Se inyecta un TableLocator real con un mock de
 * Table cuyos newEntity()/save()/saveMany()/getConnection() se interceptan,
 * evitando cualquier acceso a la base de datos.
 */
final class RefundHistoryServiceTest extends TestCase
{
    private RefundHistoryService $service;

    /**
     * Datos capturados de cada save()/saveMany() invocado durante el test.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $savedEntities;

    protected function setUp(): void
    {
        $this->service = new RefundHistoryService();
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
        $locator->set('RefundHistories', $table);
        TableRegistry::setTableLocator($locator);
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    public function testRecordChangesPersistsDifferingFields(): void
    {
        $original = new Refund([
            'id' => 7,
            'beneficiary_type' => 'employee',
            'beneficiary_employee_id' => 11,
            'beneficiary_provider_id' => null,
            'accrued' => false,
            'accrual_date' => null,
            'ready_for_payment' => false,
        ]);
        $modified = new Refund([
            'id' => 7,
            'beneficiary_type' => 'employee',
            'beneficiary_employee_id' => 11,
            'beneficiary_provider_id' => null,
            'accrued' => true,
            'accrual_date' => null,
            'ready_for_payment' => false,
        ]);

        $this->service->recordChanges($original, $modified, 3);

        $this->assertCount(1, $this->savedEntities);

        $byField = [];
        foreach ($this->savedEntities as $row) {
            $byField[$row['field_changed']] = $row;
        }

        $this->assertArrayHasKey('accrued', $byField);
        $this->assertSame(7, $byField['accrued']['refund_id']);
        $this->assertSame(3, $byField['accrued']['user_id']);
        $this->assertSame('', $byField['accrued']['old_value']);
        $this->assertSame('1', $byField['accrued']['new_value']);
    }

    public function testRecordChangesTracksBeneficiaryFields(): void
    {
        $original = new Refund([
            'id' => 9,
            'beneficiary_type' => 'employee',
            'beneficiary_employee_id' => 11,
            'beneficiary_provider_id' => null,
            'accrued' => false,
            'accrual_date' => null,
            'ready_for_payment' => false,
        ]);
        $modified = new Refund([
            'id' => 9,
            'beneficiary_type' => 'provider',
            'beneficiary_employee_id' => null,
            'beneficiary_provider_id' => 22,
            'accrued' => false,
            'accrual_date' => null,
            'ready_for_payment' => false,
        ]);

        $this->service->recordChanges($original, $modified, 3);

        $this->assertCount(3, $this->savedEntities);

        $byField = [];
        foreach ($this->savedEntities as $row) {
            $byField[$row['field_changed']] = $row;
        }

        $this->assertArrayHasKey('beneficiary_type', $byField);
        $this->assertSame('employee', $byField['beneficiary_type']['old_value']);
        $this->assertSame('provider', $byField['beneficiary_type']['new_value']);

        $this->assertArrayHasKey('beneficiary_employee_id', $byField);
        $this->assertSame('11', $byField['beneficiary_employee_id']['old_value']);
        $this->assertNull($byField['beneficiary_employee_id']['new_value']);

        $this->assertArrayHasKey('beneficiary_provider_id', $byField);
        $this->assertNull($byField['beneficiary_provider_id']['old_value']);
        $this->assertSame('22', $byField['beneficiary_provider_id']['new_value']);
    }

    public function testRecordChangesIgnoresUnchangedFields(): void
    {
        $original = new Refund([
            'id' => 7,
            'beneficiary_type' => 'employee',
            'beneficiary_employee_id' => 11,
            'beneficiary_provider_id' => null,
            'accrued' => true,
            'accrual_date' => '2026-05-01',
            'ready_for_payment' => true,
        ]);
        $modified = new Refund([
            'id' => 7,
            'beneficiary_type' => 'employee',
            'beneficiary_employee_id' => 11,
            'beneficiary_provider_id' => null,
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
        $original = new Refund([
            'id' => 7,
            'beneficiary_type' => '',
            'beneficiary_employee_id' => null,
            'beneficiary_provider_id' => null,
            'accrued' => false,
            'accrual_date' => null,
            'ready_for_payment' => false,
        ]);
        $modified = new Refund([
            'id' => 7,
            'beneficiary_type' => null,
            'beneficiary_employee_id' => null,
            'beneficiary_provider_id' => null,
            'accrued' => false,
            'accrual_date' => null,
            'ready_for_payment' => false,
        ]);

        $this->service->recordChanges($original, $modified, 3);

        $this->assertSame([], $this->savedEntities);
    }
}
