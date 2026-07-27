<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\NoveltyConstants;
use App\Service\Dto\PendingItem;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
use App\View\Presentation\InvoiceBeneficiary;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;
use NumberFormatter;

/**
 * Bandeja "Mis Pendientes": agrega los ítems de los 8 módulos de flujo cuyo
 * estado actual el rol puede operar (espejo de SidebarCounterService), los
 * ordena por fecha desc y pagina. Estrategia two-track: COUNT para el total,
 * over-fetch acotado para la ventana de página (la agregación es por-rol y puede
 * ser grande). La lógica de orden/búsqueda es pura (sin DB) para ser testeable.
 */
class MyPendingService
{
    public const PER_PAGE = 15;

    /** Módulos en orden de dispatch/visualización. */
    public const MODULE_SLUGS = [
        'invoices', 'advances', 'legalizations', 'petty_cash',
        'refunds', 'novelties', 'liquidations', 'payment_schedulings',
    ];

    // Búsqueda: escanea hasta N ítems recientes por módulo. El total two-track
    // exacto solo aplica SIN búsqueda; con búsqueda el total puede truncarse a
    // este tope (tradeoff documentado; la búsqueda es de conveniencia).
    private const SEARCH_SCAN_LIMIT = 300;

    /**
     * @param \App\Service\InvoicePipelineService $invoicePipeline
     * @param \App\Service\NoveltyPipelineService $noveltyPipeline
     * @param \App\Service\PettyCashPipelineService $pettyCashService
     * @param \App\Service\RefundPipelineService $refundService
     * @param \App\Service\PaymentSchedulingPipelineService $paymentSchedulingService
     * @param \App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy $legalizationPolicy
     */
    public function __construct(
        private readonly InvoicePipelineService $invoicePipeline,
        private readonly NoveltyPipelineService $noveltyPipeline,
        private readonly PettyCashPipelineService $pettyCashService,
        private readonly RefundPipelineService $refundService,
        private readonly PaymentSchedulingPipelineService $paymentSchedulingService,
        private readonly AdvanceLegalizationActionPolicy $legalizationPolicy,
    ) {
    }

    /**
     * Ordena por fecha descendente (más reciente primero).
     *
     * @param array<\App\Service\Dto\PendingItem> $items
     * @return array<\App\Service\Dto\PendingItem>
     */
    public static function sortByDateDesc(array $items): array
    {
        usort(
            $items,
            static fn(PendingItem $a, PendingItem $b): int => $b->date <=> $a->date ?: ($b->entityId <=> $a->entityId),
        );

        return $items;
    }

    /**
     * Filtra por coincidencia en código + contraparte (case-insensitive).
     *
     * @param array<\App\Service\Dto\PendingItem> $items
     * @return array<\App\Service\Dto\PendingItem>
     */
    public static function filterBySearch(array $items, ?string $search): array
    {
        if ($search === null || trim($search) === '') {
            return array_values($items);
        }
        $needle = mb_strtolower(trim($search));

        return array_values(array_filter($items, static function (PendingItem $i) use ($needle): bool {
            return str_contains(mb_strtolower($i->code . ' ' . $i->counterparty), $needle);
        }));
    }

    /**
     * Bandeja completa del rol: agrega, filtra, ordena y pagina.
     *
     * @return array{items: array<\App\Service\Dto\PendingItem>, total: int, page: int, perPage: int}
     */
    public function getPending(int $roleId, ?string $module, ?string $search, int $page): array
    {
        $page = max(1, $page);
        $modules = $module !== null && in_array($module, self::MODULE_SLUGS, true)
            ? [$module]
            : self::MODULE_SLUGS;
        $hasSearch = $search !== null && trim($search) !== '';

        if ($hasSearch) {
            $items = [];
            foreach ($modules as $m) {
                $items = array_merge($items, $this->_fetch($m, $roleId, self::SEARCH_SCAN_LIMIT));
            }
            $items = self::sortByDateDesc(self::filterBySearch($items, $search));
            $total = count($items);
        } else {
            $total = 0;
            foreach ($modules as $m) {
                $total += $this->_count($m, $roleId);
            }
            $fetchLimit = $page * self::PER_PAGE;
            $items = [];
            foreach ($modules as $m) {
                $items = array_merge($items, $this->_fetch($m, $roleId, $fetchLimit));
            }
            $items = self::sortByDateDesc($items);
        }

        $window = array_slice($items, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return ['items' => $window, 'total' => $total, 'page' => $page, 'perPage' => self::PER_PAGE];
    }

    /**
     * @return array<\App\Service\Dto\PendingItem>
     */
    private function _fetch(string $module, int $roleId, int $limit): array
    {
        return match ($module) {
            'invoices' => $this->_fetchInvoices($roleId, $limit),
            'advances' => $this->_fetchAdvances($roleId, $limit),
            'legalizations' => $this->_fetchLegalizations($roleId, $limit),
            'petty_cash' => $this->_fetchPettyCash($roleId, $limit),
            'refunds' => $this->_fetchRefunds($roleId, $limit),
            'novelties' => $this->_fetchNovelties($roleId, $limit),
            'liquidations' => $this->_fetchLiquidations($roleId, $limit),
            'payment_schedulings' => $this->_fetchPaymentSchedulings($roleId, $limit),
            default => [],
        };
    }

    /**
     * @param string $module
     * @param int $roleId
     * @return int
     */
    private function _count(string $module, int $roleId): int
    {
        return match ($module) {
            'invoices' => $this->_countInvoices($roleId),
            'advances' => $this->_countAdvances($roleId),
            'legalizations' => $this->_countLegalizations($roleId),
            'petty_cash' => $this->_countPettyCash($roleId),
            'refunds' => $this->_countRefunds($roleId),
            'novelties' => $this->_countNovelties($roleId),
            'liquidations' => $this->_countLiquidations($roleId),
            'payment_schedulings' => $this->_countPaymentSchedulings($roleId),
            default => 0,
        };
    }

    /**
     * @param int $roleId
     * @return \Cake\ORM\Query\SelectQuery|null
     */
    private function _queryInvoices(int $roleId): ?SelectQuery
    {
        $statuses = array_values(array_filter(
            $this->invoicePipeline->getVisibleStatuses($roleId),
            static fn(string $s): bool => $s !== InvoiceConstants::STATUS_LEGALIZADA,
        ));
        if ($statuses === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('Invoices')->find('withoutParent')
            ->where([
                'Invoices.document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'Invoices.pipeline_status IN' => $statuses,
            ]);
    }

    /**
     * @param int $roleId
     * @return int
     */
    private function _countInvoices(int $roleId): int
    {
        $q = $this->_queryInvoices($roleId);

        return $q === null ? 0 : $q->count();
    }

    /**
     * @return array<\App\Service\Dto\PendingItem>
     */
    private function _fetchInvoices(int $roleId, int $limit): array
    {
        $q = $this->_queryInvoices($roleId);
        if ($q === null) {
            return [];
        }
        $rows = $q->contain(['Providers', 'Employees'])
            ->orderBy(['Invoices.created' => 'DESC', 'Invoices.id' => 'DESC'])
            ->limit($limit)
            ->all();
        $items = [];
        foreach ($rows as $inv) {
            $items[] = new PendingItem(
                module: 'invoices',
                entityId: (int)$inv->id,
                code: (string)($inv->invoice_number ?: '#' . $inv->id),
                counterparty: InvoiceBeneficiary::label($inv),
                summary: self::_formatCurrency((float)$inv->amount),
                status: (string)$inv->pipeline_status,
                date: $inv->created ?? new DateTime(),
            );
        }

        return $items;
    }

    /**
     * @param int $roleId
     * @return \Cake\ORM\Query\SelectQuery|null
     */
    private function _queryAdvances(int $roleId): ?SelectQuery
    {
        $statuses = $this->invoicePipeline->getVisibleStatuses($roleId);
        if ($statuses === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('Invoices')->find()
            ->where([
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'Invoices.pipeline_status IN' => $statuses,
            ]);
    }

    /**
     * @param int $roleId
     * @return int
     */
    private function _countAdvances(int $roleId): int
    {
        $q = $this->_queryAdvances($roleId);

        return $q === null ? 0 : $q->count();
    }

    /**
     * @return array<\App\Service\Dto\PendingItem>
     */
    private function _fetchAdvances(int $roleId, int $limit): array
    {
        $q = $this->_queryAdvances($roleId);
        if ($q === null) {
            return [];
        }
        $rows = $q->contain(['Providers', 'Employees'])
            ->orderBy(['Invoices.created' => 'DESC', 'Invoices.id' => 'DESC'])
            ->limit($limit)
            ->all();
        $items = [];
        foreach ($rows as $inv) {
            $items[] = new PendingItem(
                module: 'advances',
                entityId: (int)$inv->id,
                code: (string)($inv->invoice_number ?: '#' . $inv->id),
                counterparty: InvoiceBeneficiary::label($inv),
                summary: self::_formatCurrency((float)$inv->amount),
                status: (string)$inv->pipeline_status,
                date: $inv->created ?? new DateTime(),
            );
        }

        return $items;
    }

    /**
     * @param int $roleId
     * @return \Cake\ORM\Query\SelectQuery|null
     */
    private function _queryLegalizations(int $roleId): ?SelectQuery
    {
        $steps = $this->legalizationPolicy->getVisibleStatuses($roleId);
        if ($steps === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('Invoices')->find()
            ->where([
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'Invoices.pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            ])
            ->innerJoinWith('AdvanceLegalization', static function ($q) use ($steps) {
                return $q->where([
                    'AdvanceLegalization.status IN' => $steps,
                    'AdvanceLegalization.status !=' => AdvanceConstants::STATUS_LEGALIZADA,
                ]);
            });
    }

    /**
     * @param int $roleId
     * @return int
     */
    private function _countLegalizations(int $roleId): int
    {
        $q = $this->_queryLegalizations($roleId);

        return $q === null ? 0 : $q->count();
    }

    /**
     * @return array<\App\Service\Dto\PendingItem>
     */
    private function _fetchLegalizations(int $roleId, int $limit): array
    {
        $q = $this->_queryLegalizations($roleId);
        if ($q === null) {
            return [];
        }
        $rows = $q->contain(['Providers', 'Employees', 'AdvanceLegalization'])
            ->orderBy(['Invoices.created' => 'DESC', 'Invoices.id' => 'DESC'])
            ->limit($limit)
            ->all();
        $items = [];
        foreach ($rows as $inv) {
            $items[] = new PendingItem(
                module: 'legalizations',
                entityId: (int)$inv->id,
                code: (string)($inv->invoice_number ?: '#' . $inv->id),
                counterparty: InvoiceBeneficiary::label($inv),
                summary: self::_formatCurrency((float)$inv->amount),
                status: (string)($inv->advance_legalization->status ?? ''),
                date: $inv->created ?? new DateTime(),
            );
        }

        return $items;
    }

    /**
     * @param int $roleId
     * @return \Cake\ORM\Query\SelectQuery|null
     */
    private function _queryPettyCash(int $roleId): ?SelectQuery
    {
        $statuses = $this->pettyCashService->getVisibleStatuses($roleId);
        if ($statuses === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('PettyCashRecords')->find()
            ->where(['PettyCashRecords.status IN' => $statuses]);
    }

    /**
     * @param int $roleId
     * @return int
     */
    private function _countPettyCash(int $roleId): int
    {
        $q = $this->_queryPettyCash($roleId);

        return $q === null ? 0 : $q->count();
    }

    /** @return array<\App\Service\Dto\PendingItem> */
    private function _fetchPettyCash(int $roleId, int $limit): array
    {
        $q = $this->_queryPettyCash($roleId);
        if ($q === null) {
            return [];
        }
        $rows = $q->contain(['CreatedByUsers'])
            ->orderBy(['PettyCashRecords.created' => 'DESC', 'PettyCashRecords.id' => 'DESC'])
            ->limit($limit)
            ->all();
        $items = [];
        foreach ($rows as $r) {
            $items[] = new PendingItem(
                module: 'petty_cash',
                entityId: (int)$r->id,
                code: (string)($r->code ?: '#' . $r->id),
                counterparty: (string)($r->created_by_user->full_name ?? '—'),
                summary: self::_formatCurrency((float)$r->total_amount),
                status: (string)$r->status,
                date: $r->created ?? new DateTime(),
            );
        }

        return $items;
    }

    /**
     * @param int $roleId
     * @return \Cake\ORM\Query\SelectQuery|null
     */
    private function _queryRefunds(int $roleId): ?SelectQuery
    {
        $statuses = $this->refundService->getVisibleStatuses($roleId);
        if ($statuses === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('Refunds')->find()
            ->where(['Refunds.status IN' => $statuses]);
    }

    /**
     * @param int $roleId
     * @return int
     */
    private function _countRefunds(int $roleId): int
    {
        $q = $this->_queryRefunds($roleId);

        return $q === null ? 0 : $q->count();
    }

    /** @return array<\App\Service\Dto\PendingItem> */
    private function _fetchRefunds(int $roleId, int $limit): array
    {
        $q = $this->_queryRefunds($roleId);
        if ($q === null) {
            return [];
        }
        $rows = $q->contain(['CreatedByUsers'])
            ->orderBy(['Refunds.created' => 'DESC', 'Refunds.id' => 'DESC'])
            ->limit($limit)
            ->all();
        $items = [];
        foreach ($rows as $r) {
            $items[] = new PendingItem(
                module: 'refunds',
                entityId: (int)$r->id,
                code: (string)($r->code ?: '#' . $r->id),
                counterparty: (string)($r->created_by_user->full_name ?? '—'),
                summary: self::_formatCurrency((float)$r->total_amount),
                status: (string)$r->status,
                date: $r->created ?? new DateTime(),
            );
        }

        return $items;
    }

    /**
     * @param int $roleId
     * @return \Cake\ORM\Query\SelectQuery|null
     */
    private function _queryNovelties(int $roleId): ?SelectQuery
    {
        $statuses = $this->noveltyPipeline->getVisibleStatuses($roleId);
        if ($statuses === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('EmployeeNovelties')->find()
            ->where([
                'EmployeeNovelties.pipeline_status IN' => $statuses,
                'EmployeeNovelties.pipeline_status !=' => NoveltyConstants::STATUS_RECHAZADA,
            ])
            ->where(static function ($exp) {
                return $exp->or([
                    'EmployeeNovelties.pipeline_status !=' => NoveltyConstants::STATUS_CONTABILIDAD,
                    'EmployeeNovelties.liquidation_doc_id IS' => null,
                ]);
            });
    }

    /**
     * @param int $roleId
     * @return int
     */
    private function _countNovelties(int $roleId): int
    {
        $q = $this->_queryNovelties($roleId);

        return $q === null ? 0 : $q->count();
    }

    /** @return array<\App\Service\Dto\PendingItem> */
    private function _fetchNovelties(int $roleId, int $limit): array
    {
        $q = $this->_queryNovelties($roleId);
        if ($q === null) {
            return [];
        }
        $rows = $q->contain(['Employees', 'NoveltyTypes'])
            ->orderBy(['EmployeeNovelties.created' => 'DESC', 'EmployeeNovelties.id' => 'DESC'])
            ->limit($limit)
            ->all();
        $items = [];
        foreach ($rows as $n) {
            $items[] = new PendingItem(
                module: 'novelties',
                entityId: (int)$n->id,
                code: 'NV-' . str_pad((string)$n->id, 4, '0', STR_PAD_LEFT),
                counterparty: (string)($n->custom_name ?: ($n->employee->full_name ?? '—')),
                summary: (string)($n->novelty_type->name ?? '—'),
                status: (string)$n->pipeline_status,
                date: $n->created ?? new DateTime(),
            );
        }

        return $items;
    }

    /**
     * @param int $roleId
     * @return \Cake\ORM\Query\SelectQuery|null
     */
    private function _queryLiquidations(int $roleId): ?SelectQuery
    {
        $statuses = $this->noveltyPipeline->getVisibleLiquidationStatuses($roleId);
        if ($statuses === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs')->find()
            ->where(['NoveltyLiquidationDocs.pipeline_status IN' => $statuses]);
    }

    /**
     * @param int $roleId
     * @return int
     */
    private function _countLiquidations(int $roleId): int
    {
        $q = $this->_queryLiquidations($roleId);

        return $q === null ? 0 : $q->count();
    }

    /** @return array<\App\Service\Dto\PendingItem> */
    private function _fetchLiquidations(int $roleId, int $limit): array
    {
        $q = $this->_queryLiquidations($roleId);
        if ($q === null) {
            return [];
        }
        $rows = $q->contain(['PerformedByUsers'])
            ->orderBy(['NoveltyLiquidationDocs.created' => 'DESC', 'NoveltyLiquidationDocs.id' => 'DESC'])
            ->limit($limit)
            ->all();
        $items = [];
        foreach ($rows as $d) {
            $items[] = new PendingItem(
                module: 'liquidations',
                entityId: (int)$d->id,
                code: 'LQ-' . str_pad((string)$d->id, 4, '0', STR_PAD_LEFT),
                counterparty: (string)($d->performed_by_user->full_name ?? '—'),
                summary: '—',
                status: (string)$d->pipeline_status,
                date: $d->created ?? new DateTime(),
            );
        }

        return $items;
    }

    /**
     * @param int $roleId
     * @return \Cake\ORM\Query\SelectQuery|null
     */
    private function _queryPaymentSchedulings(int $roleId): ?SelectQuery
    {
        $statuses = $this->paymentSchedulingService->getVisibleStatuses($roleId);
        if ($statuses === []) {
            return null;
        }

        return TableRegistry::getTableLocator()->get('PaymentSchedulings')->find()
            ->where(['PaymentSchedulings.pipeline_status IN' => $statuses]);
    }

    /**
     * @param int $roleId
     * @return int
     */
    private function _countPaymentSchedulings(int $roleId): int
    {
        $q = $this->_queryPaymentSchedulings($roleId);

        return $q === null ? 0 : $q->count();
    }

    /** @return array<\App\Service\Dto\PendingItem> */
    private function _fetchPaymentSchedulings(int $roleId, int $limit): array
    {
        $q = $this->_queryPaymentSchedulings($roleId);
        if ($q === null) {
            return [];
        }
        $rows = $q->contain(['CreatedByUsers'])
            ->orderBy(['PaymentSchedulings.created' => 'DESC', 'PaymentSchedulings.id' => 'DESC'])
            ->limit($limit)
            ->all();
        $items = [];
        foreach ($rows as $p) {
            $items[] = new PendingItem(
                module: 'payment_schedulings',
                entityId: (int)$p->id,
                code: (string)($p->code ?: '#' . $p->id),
                counterparty: (string)($p->created_by_user->full_name ?? '—'),
                summary: '—',
                status: (string)$p->pipeline_status,
                date: $p->created ?? new DateTime(),
            );
        }

        return $items;
    }

    /**
     * @param float $amount
     * @return string
     */
    private static function _formatCurrency(float $amount): string
    {
        $f = new NumberFormatter('es_CO', NumberFormatter::CURRENCY);
        $f->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);

        return $f->formatCurrency($amount, 'COP') ?: '$ ' . number_format($amount, 0, ',', '.');
    }
}
