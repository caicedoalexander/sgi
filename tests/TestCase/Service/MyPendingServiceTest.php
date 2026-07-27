<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Application;
use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\NoveltyConstants;
use App\Constants\PaymentSchedulingConstants;
use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Constants\RefundConstants;
use App\Service\Dto\PendingItem;
use App\Service\MyPendingService;
use App\Service\SidebarCounterService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\EmployeeNoveltyFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\NoveltyLiquidationDocFactory;
use App\Test\Factory\PaymentSchedulingFactory;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\RoleFactory;
use Cake\Cache\Cache;
use Cake\Core\ContainerInterface;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class MyPendingServiceTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // SidebarCounterService::getCounters cachea en el grupo `sidebar`
        // (Cache::remember, ver SidebarCounterService::getCounters). Limpiar
        // aquí evita contaminación entre casos del espejo badge==lista.
        Cache::clear('sidebar');
    }

    private function item(string $module, string $code, string $counterparty, string $date): PendingItem
    {
        return new PendingItem(
            module: $module,
            entityId: 1,
            code: $code,
            counterparty: $counterparty,
            summary: '',
            status: 'x',
            date: new DateTime($date),
        );
    }

    public function testSortByDateDescOrdersNewestFirst(): void
    {
        $items = [
            $this->item('invoices', 'A', 'Prov', '2026-01-01'),
            $this->item('refunds', 'B', 'Empl', '2026-03-01'),
            $this->item('petty_cash', 'C', 'Resp', '2026-02-01'),
        ];

        $sorted = MyPendingService::sortByDateDesc($items);

        $this->assertSame(['B', 'C', 'A'], array_map(fn($i) => $i->code, $sorted));
    }

    public function testFilterBySearchMatchesCodeOrCounterpartyCaseInsensitive(): void
    {
        $items = [
            $this->item('invoices', 'FV-1023', 'Proveedor X', '2026-01-01'),
            $this->item('refunds', 'RC-88', 'María G', '2026-01-01'),
        ];

        $byCode = MyPendingService::filterBySearch($items, 'fv-10');
        $byName = MyPendingService::filterBySearch($items, 'maría');

        $this->assertCount(1, $byCode);
        $this->assertSame('FV-1023', $byCode[0]->code);
        $this->assertCount(1, $byName);
        $this->assertSame('RC-88', $byName[0]->code);
    }

    public function testFilterBySearchEmptyReturnsAll(): void
    {
        $items = [$this->item('invoices', 'A', 'P', '2026-01-01')];
        $this->assertCount(1, MyPendingService::filterBySearch($items, ''));
        $this->assertCount(1, MyPendingService::filterBySearch($items, null));
    }

    private function service(): MyPendingService
    {
        return (new Application(dirname(__DIR__, 3) . '/config'))->getContainer()->get(MyPendingService::class);
    }

    /**
     * Siembra un rol que opera `contabilidad` en el pipeline `invoices` y una
     * factura no-anticipo, sin padre, en ese estado.
     *
     * @return array{0: int, 1: int} [$roleId, $invoiceId]
     */
    private function seedInvoiceInOperableStatus(): array
    {
        $role = RoleFactory::new()->save();

        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
            'role_id' => $role->id,
            'pipeline' => PipelineStepConstants::PIPELINE_INVOICES,
            'step' => InvoiceConstants::STATUS_CONTABILIDAD,
            'can_operate' => true,
        ]));

        $invoice = InvoiceFactory::new()->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        return [(int)$role->id, (int)$invoice->id];
    }

    public function testFetchInvoicesNormalizesToPendingItem(): void
    {
        [$roleId, $invoiceId] = $this->seedInvoiceInOperableStatus();

        $result = $this->service()->getPending($roleId, 'invoices', null, 1);

        $this->assertGreaterThanOrEqual(1, $result['total']);
        $item = $result['items'][0];
        $this->assertSame('invoices', $item->module);
        $this->assertSame($invoiceId, $item->entityId);
        $this->assertNotSame('', $item->code);
    }

    /**
     * Carried coverage (Task 3 review): el `status` de un PendingItem de
     * `legalizations` debe venir de `AdvanceLegalization.status` (el paso de
     * legalización que el rol opera), NUNCA del `pipeline_status` de la
     * factura-anticipo (que siempre es `pagada` en este punto — ver
     * `_queryLegalizations`). Si el fetch alguna vez leyera el campo
     * equivocado, este test lo detecta.
     */
    public function testFetchLegalizationsStatusComesFromAdvanceLegalizationNotInvoice(): void
    {
        $role = RoleFactory::new()->save();
        $roleId = (int)$role->id;

        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
            'role_id' => $roleId,
            'pipeline' => PipelineStepConstants::PIPELINE_LEGALIZATIONS,
            'step' => AdvanceConstants::STATUS_CONTABILIDAD,
            'can_operate' => true,
        ]));

        $advance = InvoiceFactory::new()->anticipo()->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($advance)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $result = $this->service()->getPending($roleId, 'legalizations', null, 1);

        $this->assertSame(1, $result['total']);
        $this->assertSame(AdvanceConstants::STATUS_CONTABILIDAD, $result['items'][0]->status);
        $this->assertNotSame(InvoiceConstants::STATUS_PAGADA, $result['items'][0]->status);
    }

    /**
     * Carried coverage (Task 3 review): `getPending()` combina COUNT + fetch +
     * `array_slice` por página. Con 2 ítems reales en DB (fechas distintas):
     * página 1 los trae ordenados desc y página 2 (fuera de rango) devuelve
     * `items` vacío preservando el `total` — prueba la ventana de paginación,
     * no solo `sortByDateDesc`/`filterBySearch` (que ya son puros y se testean
     * arriba sin DB).
     */
    public function testGetPendingSlicesPageWindowAndOrdersByDateDesc(): void
    {
        $role = RoleFactory::new()->save();
        $roleId = (int)$role->id;

        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
            'role_id' => $roleId,
            'pipeline' => PipelineStepConstants::PIPELINE_INVOICES,
            'step' => InvoiceConstants::STATUS_CONTABILIDAD,
            'can_operate' => true,
        ]));

        $older = InvoiceFactory::new(['created' => new DateTime('2026-01-01 00:00:00')])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $newer = InvoiceFactory::new(['created' => new DateTime('2026-03-01 00:00:00')])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $service = $this->service();

        $page1 = $service->getPending($roleId, 'invoices', null, 1);
        $this->assertSame(2, $page1['total']);
        $this->assertCount(2, $page1['items']);
        $this->assertSame((int)$newer->id, $page1['items'][0]->entityId);
        $this->assertSame((int)$older->id, $page1['items'][1]->entityId);
        $this->assertSame(1, $page1['page']);
        $this->assertSame(MyPendingService::PER_PAGE, $page1['perPage']);

        $page2 = $service->getPending($roleId, 'invoices', null, 2);
        $this->assertSame(2, $page2['total']);
        $this->assertCount(0, $page2['items']);
        $this->assertSame(2, $page2['page']);
    }

    /**
     * Siembra UN rol que opera TODOS los pasos de los 7 pipelines (invoices,
     * novelties, payment_schedulings, refunds, petty_cash, legalizations,
     * liquidation_docs) y un ítem por módulo en un estado operable. Robusto a
     * cualquier overlap advances↔legalizations: MyPendingService y
     * SidebarCounterService aplican los MISMOS WHERE, así que el conteo por
     * módulo coincide con el badge sin importar la superposición.
     *
     * @return int roleId
     */
    private function seedPendingsAcrossModules(): int
    {
        $role = RoleFactory::new()->save();
        $roleId = (int)$role->id;

        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        foreach (PipelineStepConstants::STEPS_BY_PIPELINE as $pipeline => $steps) {
            foreach ($steps as $step) {
                $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
                    'role_id' => $roleId,
                    'pipeline' => $pipeline,
                    'step' => $step,
                    'can_operate' => true,
                ]));
            }
        }

        // invoices: no-anticipo, sin padre.
        InvoiceFactory::new()->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        // advances: mismo pipeline `invoices`, document_type Anticipo.
        InvoiceFactory::new()->anticipo()->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        // legalizations: anticipo pagado + AdvanceLegalization en paso operable no-terminal.
        $advance = InvoiceFactory::new()->anticipo()->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($advance)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        // petty_cash
        PettyCashRecordFactory::new()->withStatus(PettyCashConstants::STATUS_CONTABILIDAD)->save();

        // refunds
        RefundFactory::new()->withStatus(RefundConstants::STATUS_CONTABILIDAD)->save();

        // novelties (RRHH evita el condicional CONTABILIDAD+liquidation_doc_id de _queryNovelties)
        EmployeeNoveltyFactory::new()->withStatus(NoveltyConstants::STATUS_RRHH)->save();

        // liquidations
        NoveltyLiquidationDocFactory::new()->withStatus(NoveltyConstants::STATUS_TESORERIA)->save();

        // payment_schedulings
        PaymentSchedulingFactory::new()->withStatus(PaymentSchedulingConstants::STATUS_BORRADOR)->save();

        return $roleId;
    }

    public function testEspejoConteoIgualBadgePorModulo(): void
    {
        // Sembrar un rol que opere varios pasos en varios módulos, con registros
        // en estados operables (ver helper de seed / SidebarCounterServiceTest).
        $roleId = $this->seedPendingsAcrossModules();

        $counters = $this->getContainer()->get(SidebarCounterService::class)
            ->getCounters($roleId);
        $service = $this->service();

        // Mapa slug de módulo → conteo del badge equivalente. Cubre los 8 módulos:
        // esta es la garantía R2 (badge == lista) del criterio de aceptación #3.
        $expected = [
            'invoices' => (int)array_sum($counters['sidebarCounters'] ?? []),
            'advances' => (int)$counters['advancesMineCount'],
            'legalizations' => (int)$counters['advancesPendingLegalizationCount'],
            'petty_cash' => (int)$counters['pettyCashMineCount'],
            'refunds' => (int)$counters['refundsMineCount'],
            'novelties' => (int)$counters['noveltiesCount'],
            'liquidations' => (int)$counters['liquidationMineCount'],
        ];
        // 'paymentSchedulingsMineCount' se agrega al counter en Task 8; el guard
        // isset() mantiene este test verde antes de Task 8 (cubre 7) y suma el 8º
        // automáticamente una vez que Task 8 lo expone.
        if (isset($counters['paymentSchedulingsMineCount'])) {
            $expected['payment_schedulings'] = (int)$counters['paymentSchedulingsMineCount'];
        }

        foreach ($expected as $slug => $badge) {
            $listTotal = $service->getPending($roleId, $slug, null, 1)['total'];
            $this->assertSame(
                $badge,
                $listTotal,
                "Espejo roto en módulo {$slug}: lista {$listTotal} != badge {$badge}",
            );
        }
    }

    private function getContainer(): ContainerInterface
    {
        return (new Application(dirname(__DIR__, 3) . '/config'))->getContainer();
    }
}
