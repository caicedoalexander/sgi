<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\PaymentSchedulingConstants;
use App\Service\PaymentSchedulingHistoryService;
use Cake\ORM\Entity;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para PaymentSchedulingHistoryService.
 *
 * La suite es pura (sin DB). Se inyecta un TableLocator real con un mock de
 * Table cuyos newEntity()/save() se interceptan, evitando cualquier acceso a
 * la base de datos. Así se cubren tanto el short-circuit (old===new no
 * persiste) como la persistencia con los datos esperados.
 */
final class PaymentSchedulingHistoryServiceTest extends TestCase
{
    private PaymentSchedulingHistoryService $service;

    /**
     * Datos capturados de cada save() invocado durante el test.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $savedEntities;

    protected function setUp(): void
    {
        $this->service = new PaymentSchedulingHistoryService();
        $this->savedEntities = [];

        $table = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['newEntity', 'save'])
            ->getMock();

        $table->method('newEntity')->willReturnCallback(fn(array $data) => new Entity($data));
        $table->method('save')->willReturnCallback(function (Entity $entity) {
            $this->savedEntities[] = $entity->toArray();

            return $entity;
        });

        $locator = new TableLocator();
        $locator->set('PaymentSchedulingHistories', $table);
        TableRegistry::setTableLocator($locator);
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    public function testRecordStatusChangeShortCircuitsWhenStatusUnchanged(): void
    {
        $this->service->recordStatusChange(
            10,
            PaymentSchedulingConstants::STATUS_TESORERIA,
            PaymentSchedulingConstants::STATUS_TESORERIA,
            5,
        );

        $this->assertSame([], $this->savedEntities);
    }

    public function testRecordStatusChangePersistsWithLabels(): void
    {
        $this->service->recordStatusChange(
            10,
            PaymentSchedulingConstants::STATUS_BORRADOR,
            PaymentSchedulingConstants::STATUS_TESORERIA,
            5,
        );

        $this->assertCount(1, $this->savedEntities);
        $saved = $this->savedEntities[0];
        $this->assertSame(10, $saved['payment_scheduling_id']);
        $this->assertSame(5, $saved['user_id']);
        $this->assertSame('status', $saved['field_changed']);
        $this->assertSame('Borrador', $saved['old_value']);
        $this->assertSame('Tesorería', $saved['new_value']);
    }

    public function testRecordFieldChangeShortCircuitsWhenValuesEqual(): void
    {
        $this->service->recordFieldChange(10, 'title', 'Pago Enero', 'Pago Enero', 5);

        $this->assertSame([], $this->savedEntities);
    }

    public function testRecordFieldChangeShortCircuitsWhenNullEqualsEmptyString(): void
    {
        // (string)null === (string)'' → '' === '' → short-circuit.
        $this->service->recordFieldChange(10, 'notes', null, '', 5);

        $this->assertSame([], $this->savedEntities);
    }

    public function testRecordFieldChangePersistsWhenValuesDiffer(): void
    {
        $this->service->recordFieldChange(10, 'title', 'Pago Enero', 'Pago Febrero', 5);

        $this->assertCount(1, $this->savedEntities);
        $saved = $this->savedEntities[0];
        $this->assertSame(10, $saved['payment_scheduling_id']);
        $this->assertSame(5, $saved['user_id']);
        $this->assertSame('title', $saved['field_changed']);
        $this->assertSame('Pago Enero', $saved['old_value']);
        $this->assertSame('Pago Febrero', $saved['new_value']);
    }
}
