<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\ApprovalInboxConstants;
use App\Constants\InvoiceConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\InvoiceApproval;
use App\Service\Dto\ApprovalInboxItem;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use NumberFormatter;

/**
 * Bandeja personal de aprobaciones: agrega y normaliza las aprobaciones de
 * facturas (invoice_approvals.user_id) y novedades (employee_novelties.approver_id)
 * de un aprobador, aplica filtros/orden/paginación y resuelve los gates de acceso.
 *
 * La lógica pura (normalización, filtros, orden, paginación) no toca DB para ser
 * unit-testeable con entidades en memoria. Las tablas se resuelven lazy solo en
 * los métodos de fetching.
 */
class ApprovalInboxService
{
    private ?Table $invoiceApprovalsTable;

    private ?Table $noveltiesTable;

    private ?Table $approversTable;

    private ?NumberFormatter $currencyFormatter = null;

    /**
     * @param \Cake\ORM\Table|null $invoiceApprovalsTable Tabla InvoiceApprovals (lazy si es null).
     * @param \Cake\ORM\Table|null $noveltiesTable Tabla EmployeeNovelties (lazy si es null).
     * @param \Cake\ORM\Table|null $approversTable Tabla Approvers (lazy si es null).
     */
    public function __construct(
        ?Table $invoiceApprovalsTable = null,
        ?Table $noveltiesTable = null,
        ?Table $approversTable = null,
    ) {
        $this->invoiceApprovalsTable = $invoiceApprovalsTable;
        $this->noveltiesTable = $noveltiesTable;
        $this->approversTable = $approversTable;
    }

    /**
     * Normaliza una fila de invoice_approvals (con Invoice→Provider contenido)
     * al DTO común.
     */
    public function normalizeInvoiceApproval(InvoiceApproval $approval): ApprovalInboxItem
    {
        $invoice = $approval->invoice;

        return new ApprovalInboxItem(
            module: ApprovalInboxConstants::MODULE_INVOICE,
            entityId: (int)$approval->invoice_id,
            code: (string)($invoice?->invoice_number ?? '#' . $approval->invoice_id),
            counterparty: (string)($invoice?->provider?->name ?? '—'),
            summary: $this->formatCurrency((float)($invoice?->amount ?? 0)),
            status: $this->mapStatus((string)$approval->status),
            assignedAt: $approval->created ?? new DateTime(),
            respondedAt: $approval->responded_at,
            observations: $approval->observations,
        );
    }

    /**
     * Normaliza una novedad (con Employee y NoveltyType contenidos) al DTO común.
     */
    public function normalizeNovelty(EmployeeNovelty $novelty): ApprovalInboxItem
    {
        return new ApprovalInboxItem(
            module: ApprovalInboxConstants::MODULE_NOVELTY,
            entityId: (int)$novelty->id,
            code: '#' . $novelty->id,
            counterparty: (string)($novelty->employee?->full_name ?? '—'),
            summary: (string)($novelty->novelty_type?->name ?? '—'),
            status: $this->mapStatus((string)($novelty->area_approval ?? 'Pendiente')),
            assignedAt: $novelty->created ?? new DateTime(),
            respondedAt: $novelty->approved_at,
            observations: $novelty->observations,
        );
    }

    /**
     * Traduce un estado persistido (español) al slug interno. Desconocido o
     * vacío → pending (estado más conservador para la bandeja).
     */
    private function mapStatus(string $persisted): string
    {
        return ApprovalInboxConstants::STATUS_MAP[$persisted]
            ?? ApprovalInboxConstants::STATUS_PENDING;
    }

    /**
     * Aplica filtros (estado, módulo, búsqueda) y ordena: pendientes primero,
     * luego por fecha de asignación descendente.
     *
     * @param array<\App\Service\Dto\ApprovalInboxItem> $items
     * @return array<\App\Service\Dto\ApprovalInboxItem> reindexado
     */
    public function filterAndSort(array $items, ?string $status, ?string $module, ?string $search): array
    {
        $filtered = array_filter($items, function (ApprovalInboxItem $i) use ($status, $module, $search) {
            if ($status !== null && $status !== '' && $i->status !== $status) {
                return false;
            }
            if ($module !== null && $module !== '' && $i->module !== $module) {
                return false;
            }
            if ($search !== null && trim($search) !== '') {
                $needle = mb_strtolower(trim($search));
                $haystack = mb_strtolower($i->code . ' ' . $i->counterparty);
                if (!str_contains($haystack, $needle)) {
                    return false;
                }
            }

            return true;
        });

        usort($filtered, function (ApprovalInboxItem $a, ApprovalInboxItem $b) {
            $aPending = $a->status === ApprovalInboxConstants::STATUS_PENDING ? 0 : 1;
            $bPending = $b->status === ApprovalInboxConstants::STATUS_PENDING ? 0 : 1;
            if ($aPending !== $bPending) {
                return $aPending <=> $bPending;
            }

            return $b->assignedAt <=> $a->assignedAt;
        });

        return array_values($filtered);
    }

    /**
     * Slice manual de la colección combinada (no se puede usar el Paginator de
     * CakePHP sobre dos fuentes unidas en memoria).
     *
     * @param array<\App\Service\Dto\ApprovalInboxItem> $items
     * @return array{items: \App\Service\Dto\ApprovalInboxItem[], total: int, page: int, perPage: int}
     */
    public function paginateItems(array $items, int $page, int $perPage = 15): array
    {
        $page = max(1, $page);
        $total = count($items);
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * Formatea un monto como pesos colombianos sin decimales.
     */
    private function formatCurrency(float $amount): string
    {
        if ($this->currencyFormatter === null) {
            $this->currencyFormatter = new NumberFormatter('es_CO', NumberFormatter::CURRENCY);
            $this->currencyFormatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
        }

        return $this->currencyFormatter->formatCurrency($amount, 'COP')
            ?: '$ ' . number_format($amount, 0, ',', '.');
    }

    /**
     * Accessor lazy de la tabla InvoiceApprovals.
     *
     * @return \Cake\ORM\Table
     */
    private function invoiceApprovals(): Table
    {
        return $this->invoiceApprovalsTable ??= TableRegistry::getTableLocator()->get('InvoiceApprovals');
    }

    /**
     * Accessor lazy de la tabla EmployeeNovelties.
     *
     * @return \Cake\ORM\Table
     */
    private function novelties(): Table
    {
        return $this->noveltiesTable ??= TableRegistry::getTableLocator()->get('EmployeeNovelties');
    }

    /**
     * Accessor lazy de la tabla Approvers.
     *
     * @return \Cake\ORM\Table
     */
    private function approvers(): Table
    {
        return $this->approversTable ??= TableRegistry::getTableLocator()->get('Approvers');
    }

    /**
     * Gate de acceso a la bandeja: el usuario debe estar en el catálogo approvers
     * (active = true). Sin cache: un EXISTS indexado es barato y evita autorización
     * obsoleta si un aprobador es revocado.
     */
    public function isApprover(int $userId): bool
    {
        return $this->approvers()->exists(['user_id' => $userId, 'active' => true]);
    }

    /**
     * Construye la bandeja completa del usuario: agrega ambas fuentes, normaliza,
     * filtra/ordena y pagina.
     *
     * @return array{items: \App\Service\Dto\ApprovalInboxItem[], total: int, page: int, perPage: int}
     */
    public function getInbox(int $userId, ?string $status, ?string $module, ?string $search, int $page): array
    {
        $items = [];
        foreach ($this->fetchInvoiceApprovals($userId) as $approval) {
            $items[] = $this->normalizeInvoiceApproval($approval);
        }
        foreach ($this->fetchNovelties($userId) as $novelty) {
            $items[] = $this->normalizeNovelty($novelty);
        }

        $items = $this->filterAndSort($items, $status, $module, $search);

        return $this->paginateItems($items, $page);
    }

    /** @return array<\App\Model\Entity\InvoiceApproval>*/
    public function fetchInvoiceApprovals(int $userId): array
    {
        return $this->invoiceApprovals()->find()
            ->where([
                'InvoiceApprovals.user_id' => $userId,
                'InvoiceApprovals.status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE,
            ])
            ->contain(['Invoices' => ['Providers']])
            ->all()
            ->toArray();
    }

    /** @return array<\App\Model\Entity\EmployeeNovelty>*/
    public function fetchNovelties(int $userId): array
    {
        // A diferencia de facturas (que excluyen 'Reemplazada'), las novedades no
        // tienen estado "superseded": todas las asignadas al aprobador (pendiente/
        // aprobada/rechazada) son su historial y deben mostrarse en la bandeja.
        return $this->novelties()->find()
            ->where(['EmployeeNovelties.approver_id' => $userId])
            ->contain(['Employees', 'NoveltyTypes'])
            ->all()
            ->toArray();
    }

    /**
     * Gate de propiedad + carga de la entidad para `view`. Devuelve un array con
     * la entidad (Invoice o EmployeeNovelty), el estado normalizado (slug) y las
     * observaciones, todo POR-APROBADOR; o null si la aprobación no pertenece al
     * usuario, no existe, el módulo es desconocido o (facturas) está reemplazada.
     *
     * @return array{entity: object, status: string, observations: ?string}|null
     */
    public function getItemForUser(int $userId, string $module, int $entityId): ?array
    {
        if ($module === ApprovalInboxConstants::MODULE_INVOICE) {
            $approval = $this->invoiceApprovals()->find()
                ->where([
                    'invoice_id' => $entityId,
                    'user_id' => $userId,
                    'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE,
                ])
                ->first();
            if ($approval === null) {
                return null;
            }

            $invoice = $this->invoiceApprovals()->Invoices->find()
                ->where(['Invoices.id' => $entityId])
                ->contain(['Providers', 'InvoiceDocuments'])
                ->first();
            if ($invoice === null) {
                return null;
            }

            return [
                'entity' => $invoice,
                'status' => $this->mapStatus((string)$approval->status),
                'observations' => $approval->observations,
            ];
        }

        if ($module === ApprovalInboxConstants::MODULE_NOVELTY) {
            $novelty = $this->novelties()->find()
                ->where(['EmployeeNovelties.id' => $entityId, 'EmployeeNovelties.approver_id' => $userId])
                ->contain(['Employees', 'NoveltyTypes'])
                ->first();
            if ($novelty === null) {
                return null;
            }

            return [
                'entity' => $novelty,
                'status' => $this->mapStatus((string)($novelty->area_approval ?? 'Pendiente')),
                'observations' => $novelty->observations,
            ];
        }

        return null;
    }
}
