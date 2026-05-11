<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Invoice> $invoices
 * @var string $roleName
 * @var string[] $visibleStatuses
 * @var \Cake\ORM\ResultSet $providers
 * @var \Cake\ORM\ResultSet $operationCenters
 * @var \Cake\ORM\ResultSet $expenseTypes
 */

use App\Constants\InvoiceConstants;
use App\Service\InvoicePipelineService;
use App\View\Presentation\InvoicePresentation;
use App\View\Presentation\SharedPresentation;
$isAllView      = $this->request->getParam('action') === 'all';
$isRejectedView = $this->request->getParam('action') === 'rejected';
$pageTitle = $isRejectedView ? 'Facturas Rechazadas'
           : ($isAllView     ? 'Todas las Facturas'
           :                   'Mis Facturas');
$this->assign('title', $pageTitle);


$query = $this->request->getQueryParams();
$hasFilters = !empty(array_filter($query, fn($v) => $v !== '' && $v !== null));
$pipelineOptions = InvoiceConstants::STATUS_LABELS;
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title"><?= $pageTitle ?></span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['dian_crosschecks']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Cruce',
            ['controller' => 'DianCrosschecks', 'action' => 'add'],
            ['class' => 'btn btn-outline-warning', 'escape' => false]
        ) ?>
        <?php endif; ?>
        <?php if (!empty($userPermissions['dian_crosschecks']['can_view'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-clipboard-data me-1" aria-hidden="true"></i>Cruces',
            ['controller' => 'DianCrosschecks', 'action' => 'index'],
            ['class' => 'btn btn-outline-secondary', 'escape' => false]
        ) ?>
        <?php endif; ?>
        <?= $this->element('excel_wizard/buttons', [
            'module' => 'Invoices',
            'importable' => false,
            'canCreate' => !empty($userPermissions['invoices']['can_create']),
        ]) ?>
        <?php if (!empty($userPermissions['invoices']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Factura',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filters -->
<div class="sgi-search-bar mb-3">
    <?= $this->Form->create(null, ['type' => 'get', 'url' => ['action' => $isRejectedView ? 'rejected' : ($isAllView ? 'all' : 'index')], 'valueSources' => ['query']]) ?>
    <div class="d-flex gap-2">
        <div class="flex-grow-1">
            <?= $this->Form->control('search', [
                'label' => false,
                'type' => 'text',
                'class' => 'form-control',
                'placeholder' => 'Buscar por número, orden de compra, detalle o proveedor…',
                'value' => $this->request->getQuery('search', ''),
            ]) ?>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-search" aria-hidden="true"></i></button>
        <button type="button" class="btn btn-outline-dark" data-bs-toggle="collapse" data-bs-target="#invoiceFilters" title="Filtros avanzados">
            <i class="bi bi-funnel" aria-hidden="true"></i>
        </button>
        <?php if ($hasFilters): ?>
            <?= $this->Html->link(
                '<i class="bi bi-x-lg" aria-hidden="true"></i> Limpiar',
                ['action' => $isRejectedView ? 'rejected' : ($isAllView ? 'all' : 'index')],
                ['class' => 'btn btn-outline-danger', 'escape' => false]
            ) ?>
        <?php endif; ?>
    </div>

    <div class="collapse <?= $hasFilters ? 'show' : '' ?>" id="invoiceFilters">
        <div class="sgi-filters-section mt-2">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="sgi-filter-label" for="filter-provider">Proveedor</label>
                    <?= $this->Form->select('provider_id', $providers, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('provider_id', ''),
                        'id'    => 'filter-provider',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <label class="sgi-filter-label" for="filter-opcenter">Centro Op.</label>
                    <?= $this->Form->select('operation_center_id', $operationCenters, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('operation_center_id', ''),
                        'id'    => 'filter-opcenter',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <label class="sgi-filter-label" for="filter-expense">Tipo Gasto</label>
                    <?= $this->Form->select('expense_type_id', $expenseTypes, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('expense_type_id', ''),
                        'id'    => 'filter-expense',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <label class="sgi-filter-label" for="filter-status">Estado</label>
                    <?= $this->Form->select('pipeline_status', $pipelineOptions, [
                        'empty' => 'Todos',
                        'class' => 'form-select form-select-sm',
                        'value' => $this->request->getQuery('pipeline_status', ''),
                        'id'    => 'filter-status',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="sgi-filter-label" for="filter-date-from">Desde</label>
                            <input type="text" name="date_from" id="filter-date-from"
                                   class="form-control form-control-sm flatpickr-date"
                                   value="<?= h($this->request->getQuery('date_from', '')) ?>"
                                   placeholder="Fecha desde">
                        </div>
                        <div class="col-6">
                            <label class="sgi-filter-label" for="filter-date-to">Hasta</label>
                            <input type="text" name="date_to" id="filter-date-to"
                                   class="form-control form-control-sm flatpickr-date"
                                   value="<?= h($this->request->getQuery('date_to', '')) ?>"
                                   placeholder="Fecha hasta">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?= $this->Form->end() ?>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th style="width:160px;">Factura</th>
                    <th>Proveedor</th>
                    <th style="width:110px;"><?= $this->Paginator->sort('issue_date', 'Emisión') ?></th>
                    <th style="width:120px;"><?= $this->Paginator->sort('due_date', 'Vencimiento') ?></th>
                    <th style="width:140px;" class="text-end"><?= $this->Paginator->sort('amount', 'Valor') ?></th>
                    <th style="width:180px;">Estado</th>
                    <th style="width:1%;white-space:nowrap;"><i class="bi bi-chat-left-text" title="Observaciones" aria-hidden="true"></i></th>
                </tr>
            </thead>
            <tbody>
                <?php $rowCount = 0; $today = new \DateTimeImmutable('today'); foreach ($invoices as $invoice): $rowCount++;
                    $row = InvoicePresentation::forRow($invoice, $today);
                ?>
                <tr class="clickable-row<?= $row->isRejected ? ' table-danger' : '' ?>"
                    data-href="<?= $this->Url->build(['action' => $isAllView ? 'view' : 'edit', $invoice->id]) ?>">

                    <!-- Número de factura + tipo -->
                    <td>
                        <div class="fw-semibold"
                             style="font-family:monospace;font-size:.8rem;color:#111;letter-spacing:-.01em;">
                            <?= h($invoice->invoice_number ?: '—') ?>
                        </div>
                        <div style="font-size:.7rem;color:#bbb;margin-top:.1rem;text-transform:uppercase;letter-spacing:.04em;">
                            <?= h($invoice->document_type) ?>
                        </div>
                    </td>

                    <!-- Proveedor + centro de operación -->
                    <td>
                        <div style="font-size:.8125rem;font-weight:500;color:#222;line-height:1.3;">
                            <?= $invoice->hasValue('provider') ? h($invoice->provider->name) : '<span class="text-muted">—</span>' ?>
                        </div>
                        <?php if ($invoice->hasValue('operation_center')): ?>
                        <div style="font-size:.7rem;color:#bbb;margin-top:.1rem;">
                            <?= h($invoice->operation_center->name) ?>
                        </div>
                        <?php endif; ?>
                    </td>

                    <!-- Fecha de emisión -->
                    <td style="font-size:.8125rem;color:#555;white-space:nowrap;">
                        <?= $invoice->issue_date?->format('d/m/Y') ?: '—' ?>
                    </td>

                    <!-- Fecha de vencimiento -->
                    <td style="white-space:nowrap;">
                        <span style="font-size:.8125rem;<?= $row->isOverdue ? 'color:#dc3545;font-weight:600;' : 'color:#555;' ?>">
                            <?= $invoice->due_date?->format('d/m/Y') ?: '—' ?>
                        </span>
                        <?php if ($row->isOverdue): ?>
                            <i class="bi bi-exclamation-circle-fill text-danger ms-1" style="font-size:.7rem;"
                               title="Vencida" aria-hidden="true"></i>
                        <?php endif; ?>
                    </td>

                    <!-- Valor -->
                    <td class="text-end fw-semibold" style="white-space:nowrap;color:var(--primary-color);font-size:.875rem;">
                        $ <?= number_format((float)$invoice->amount, 0, ',', '.') ?>
                    </td>

                    <!-- Estado pipeline + badges secundarios -->
                    <td>
                        <div class="d-flex flex-wrap align-items-center gap-1">
                            <?php if ($row->isRejected): ?>
                                <span class="badge bg-danger"><?= h($row->statusLabel) ?></span>
                                <span class="badge bg-danger">Rechazada</span>
                            <?php else: ?>
                                <span class="badge <?= h($row->statusBadgeClass) ?>"><?= h($row->statusLabel) ?></span>
                                <?php if (isset($approvalSummaries[$invoice->id]) && $approvalSummaries[$invoice->id]['total'] > 0):
                                    $s = $approvalSummaries[$invoice->id];
                                ?>
                                    <?php if ($s['rejected'] > 0): ?>
                                        <span class="badge bg-danger">Rechazada</span>
                                    <?php elseif ($s['approved'] === $s['total']): ?>
                                        <span class="badge bg-success">Aprobada</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= $s['approved'] ?>/<?= $s['total'] ?> aprobados</span>
                                    <?php endif; ?>
                                <?php elseif ($row->isApproved): ?>
                                    <span class="badge bg-success">Aprobada</span>
                                <?php endif; ?>
                                <?php if ($row->isPartialPay): ?>
                                    <span class="badge bg-warning text-dark">Pago Parcial</span>
                                <?php endif; ?>
                                <?php if ($row->isReadyForPay): ?>
                                    <span class="badge <?= h(SharedPresentation::READY_FOR_PAYMENT_BADGES[$invoice->ready_for_payment] ?? 'bg-secondary') ?>"><?= h($invoice->ready_for_payment) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>

                    <!-- Observaciones sin leer -->
                    <?php $unread = (int)($invoice->unread_observations ?? 0); ?>
                    <td class="text-center" style="white-space:nowrap;">
                        <?php if ($unread > 0): ?>
                            <span class="badge bg-danger"
                                  title="<?= $unread ?> observación<?= $unread > 1 ? 'es' : '' ?> sin leer">
                                <i class="bi bi-chat-left-text-fill me-1" style="font-size:.65rem;" aria-hidden="true"></i><?= $unread ?>
                            </span>
                        <?php else: ?>
                            <i class="bi bi-chat-left-text" style="color:#dee2e6;font-size:.85rem;" title="Sin observaciones nuevas" aria-hidden="true"></i>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if ($rowCount === 0): ?>
                <tr>
                    <td colspan="7">
                        <div class="sgi-doc-empty">
                            <i class="bi bi-inbox sgi-doc-empty-icon" aria-hidden="true"></i>
                            No hay facturas en tu bandeja actual.
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= $this->element('pagination') ?>
</div>

<?= $this->element('excel_wizard/modals', [
    'module' => 'Invoices',
    'entityName' => 'Facturas',
    'downloadSlug' => 'facturas',
    'importable' => false,
]) ?>
