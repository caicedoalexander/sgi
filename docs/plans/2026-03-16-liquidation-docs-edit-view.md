# Liquidation Docs: Edit/View Split Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Split the current monolithic `view.php` into a proper `edit/{id}` (two-column: form left, soportes+observations right) and a read-only `view/{id}` with history, following the same patterns as EmployeeNovelties.

**Architecture:** Create a new `edit()` action in the controller that handles the interactive workflow (stage forms, documents, observations, signatures). Refactor `view()` to be read-only with detailed info, signatures (display-only), documents grid, and aggregated change history from the novelty_histories of all linked novelties. Index links to `edit`, edit has "Ver" button to `view`.

**Tech Stack:** CakePHP 5 · PHP 8.2 · Bootstrap 5 · Existing NoveltyPipelineService/NoveltyHistoryService

---

### Task 1: Add `edit()` Action to Controller

**Files:**
- Modify: `src/Controller/NoveltyLiquidationDocsController.php`

**Step 1: Add the `edit()` method**

Add a new `edit()` method that loads the same data as `view()` plus `$currentUser` for the observations chat. The existing `view()` stays as-is for now.

```php
/**
 * Edit/advance a liquidation document.
 *
 * @param string|null $id Document ID.
 * @return \Cake\Http\Response|null|void
 */
public function edit(?string $id = null)
{
    $doc = $this->NoveltyLiquidationDocs->get($id, contain: [
        'PerformedByUsers',
        'CreatedByUsers',
        'EmployeeNovelties' => ['Employees', 'NoveltyTypes'],
        'NoveltyLiquidationSignatures' => ['SignedByUsers'],
        'NoveltyObservations' => [
            'Users',
            'sort' => ['NoveltyObservations.created' => 'ASC'],
        ],
        'NoveltyDocuments' => [
            'UploadedByUsers',
            'sort' => ['NoveltyDocuments.created' => 'DESC'],
        ],
    ]);

    $user = $this->Authentication->getIdentity()->getOriginalData();
    $this->observationService->markAsRead($user->id, liquidationDocId: $doc->id);

    $groupErrors = $this->pipelineService->validateGroupTransition($doc);
    $effectiveStatuses = $this->pipelineService->getEffectiveStatuses();
    $documentsByStatus = $this->documentService->getGroupDocumentsByStatus($doc->id);

    $currentUser = $user;
    $this->set(compact('doc', 'groupErrors', 'effectiveStatuses', 'documentsByStatus', 'currentUser'));
}
```

**Step 2: Update redirects in POST actions**

Change all `return $this->redirect(['action' => 'view', $id])` to `return $this->redirect(['action' => 'edit', $id])` in:
- `advanceGroup()`
- `addSignature()`
- `uploadDocument()`
- `deleteDocument()`
- `addObservation()`

**Step 3: Commit**

```bash
git add src/Controller/NoveltyLiquidationDocsController.php
git commit -m "feat(liquidation-docs): add edit() action and redirect POST actions to edit"
```

---

### Task 2: Create `edit.php` Template (Two-Column Layout)

**Files:**
- Create: `templates/NoveltyLiquidationDocs/edit.php`

**Step 1: Create the edit template**

This follows the exact same two-column pattern as `EmployeeNovelties/edit.php`:
- **Left column (flex:1):** Card header + pipeline progress + document info + novedades table + stage-specific forms + signatures (in firmas stage) + advance/action buttons
- **Right column (380px):** Soportes panel + Observations chat

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\NoveltyLiquidationDoc $doc
 * @var array $groupErrors
 * @var array $effectiveStatuses
 * @var array $documentsByStatus
 * @var \App\Model\Entity\User $currentUser
 */
use App\Constants\NoveltyConstants;

$this->assign('title', 'Editar Liquidación: ' . h($doc->liquidation_number));

$statusLabels = NoveltyConstants::STATUS_LABELS;
$statusIcons = NoveltyConstants::STATUS_ICONS;
$periodLabels = NoveltyConstants::PERIOD_LABELS;
$signerLabels = NoveltyConstants::SIGNER_LABELS;
$paymentLabels = NoveltyConstants::PAYMENT_LABELS;
$isRejected = $doc->pipeline_status === NoveltyConstants::STATUS_RECHAZADA;
$isPaid = $doc->pipeline_status === NoveltyConstants::STATUS_PAGADA;
$isFinal = $isRejected || $isPaid;
$currentStatus = $doc->pipeline_status;

$statusBadgeMap = [
    'contabilidad' => 'bg-primary',
    'firmas_aprobacion' => 'bg-warning text-dark',
    'gdp' => 'bg-dark',
    'tesoreria' => 'bg-info',
    'pagada' => 'bg-success',
    'rechazada' => 'bg-danger',
];
$ps = [$statusLabels[$currentStatus] ?? 'Desconocido', $statusBadgeMap[$currentStatus] ?? 'bg-dark'];

// Documents prep
$showUploadSection = !$isFinal;
$docIcon = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => 'bi-file-earmark-pdf',
    str_contains($mime ?? '', 'image') => 'bi-file-earmark-image',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => 'bi-file-earmark-word',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'bi-file-earmark-excel',
    default => 'bi-file-earmark',
};
$docIconColor = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => '#dc3545',
    str_contains($mime ?? '', 'image') => '#0dcaf0',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => '#0d6efd',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'var(--primary-color)',
    default => '#aaa',
};
$totalDocs = array_sum(array_map('count', $documentsByStatus));
$badgeColors = [
    'contabilidad' => 'bg-primary', 'firmas_aprobacion' => 'bg-warning text-dark',
    'gdp' => 'bg-dark', 'tesoreria' => 'bg-info', 'pagada' => 'bg-success',
];
?>

<!-- Page header -->
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Liquidación</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye me-1"></i>Ver',
            ['action' => 'view', $doc->id],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Advance warning -->
<?php if (!$isFinal && !empty($groupErrors)): ?>
<div class="alert alert-warning mb-4">
    <div class="d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
        <div>
            <strong>Para avanzar al siguiente estado complete:</strong>
            <ul class="mb-0 mt-1 ps-3">
                <?php foreach ($groupErrors as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Two-column layout -->
<div style="display:flex;gap:1.5rem;align-items:flex-start;">

<!-- Left column: main content -->
<div style="flex:1;min-width:0;">
<div class="card card-primary mb-4">

    <!-- Card header -->
    <div class="card-header d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
                <i class="bi bi-file-earmark-text"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#111;"><?= h($doc->liquidation_number) ?></div>
                <div style="font-size:.72rem;color:#aaa;margin-top:.1rem;"><?= $periodLabels[$doc->period] ?? h($doc->period) ?></div>
            </div>
        </div>
        <span class="badge <?= $ps[1] ?>"><?= $ps[0] ?></span>
    </div>

    <!-- Pipeline progress -->
    <div style="background:#fafafa;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <?= $this->element('pipeline_progress', [
            'pipelineStatuses' => $effectiveStatuses,
            'pipelineLabels' => $statusLabels,
            'currentStatus' => $currentStatus,
            'isRejected' => $isRejected,
            'statusIcons' => $statusIcons,
        ]) ?>
    </div>

    <div class="card-body p-4">

        <!-- Section: Información del Documento -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-file-text me-1"></i>Información
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">No. Liquidación</label>
                    <input type="text" class="form-control" disabled value="<?= h($doc->liquidation_number) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Período</label>
                    <input type="text" class="form-control" disabled value="<?= $periodLabels[$doc->period] ?? h($doc->period) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Fecha Documento</label>
                    <input type="text" class="form-control" disabled value="<?= $doc->document_date?->format('d/m/Y') ?: '—' ?>">
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <label class="form-label">Elaborado por</label>
                    <input type="text" class="form-control" disabled value="<?= h($doc->performed_by_user->full_name ?? '—') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Creado por</label>
                    <input type="text" class="form-control" disabled value="<?= h($doc->created_by_user->full_name ?? '—') ?>">
                </div>
            </div>
        </div>

        <!-- Section: Novedades Asociadas -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-people me-1"></i>Novedades Asociadas (<?= count($doc->employee_novelties) ?>)
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <?php if (!empty($doc->employee_novelties)): ?>
            <div style="max-height:250px;overflow-y:auto;">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Empleado</th><th>Tipo</th></tr></thead>
                    <tbody>
                        <?php foreach ($doc->employee_novelties as $novelty): ?>
                        <tr>
                            <td>
                                <?= $this->Html->link(
                                    h($novelty->custom_name ?: $novelty->employee->full_name ?? '—'),
                                    ['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id],
                                    ['class' => 'text-decoration-none']
                                ) ?>
                            </td>
                            <td style="font-size:.8125rem;"><?= h($novelty->novelty_type->name ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="text-muted small mb-0">No hay novedades asociadas.</p>
            <?php endif; ?>
        </div>

        <!-- Signatures Section (in firmas_aprobacion stage) -->
        <?php if ($doc->pipeline_status === NoveltyConstants::STATUS_FIRMAS_APROBACION || !empty($doc->novelty_liquidation_signatures)): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi bi-pen me-1"></i>Firmas
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
            </div>
            <div class="row g-3">
                <?php foreach ($doc->novelty_liquidation_signatures as $sig): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="border rounded p-3 text-center h-100">
                        <div class="fw-bold small mb-2"><?= $signerLabels[$sig->signer_type] ?? h($sig->signer_type) ?></div>
                        <?php if ($sig->signature_path): ?>
                            <img src="<?= $this->Url->build('/' . $sig->signature_path) ?>" alt="Firma"
                                 style="max-width:100%;max-height:100px;border:1px solid var(--border-color);">
                            <div class="mt-1 small text-muted">
                                <?= h($sig->signed_by_user->full_name ?? '') ?>
                                <?php if ($sig->approved_at): ?>
                                <br><?= $sig->approved_at->format('d/m/Y H:i') ?>
                                <?php endif; ?>
                            </div>
                            <span class="badge bg-success mt-1">Firmado</span>
                        <?php else: ?>
                            <?php if ($doc->pipeline_status === NoveltyConstants::STATUS_FIRMAS_APROBACION): ?>
                            <div style="border:1px solid var(--border-color);display:inline-block;" class="mb-2">
                                <canvas class="sig-canvas" data-signer="<?= h($sig->signer_type) ?>" width="250" height="100"
                                        style="cursor:crosshair;display:block;"></canvas>
                            </div>
                            <?= $this->Form->create(null, ['url' => ['action' => 'addSignature', $doc->id], 'class' => 'sig-form']) ?>
                            <input type="hidden" name="signer_type" value="<?= h($sig->signer_type) ?>">
                            <input type="hidden" name="signature_base64" class="sig-base64">
                            <div class="d-flex gap-1 justify-content-center">
                                <button type="button" class="btn btn-sm btn-outline-secondary sig-clear">Limpiar</button>
                                <button type="submit" class="btn btn-sm btn-primary sig-save">Firmar</button>
                            </div>
                            <?= $this->Form->end() ?>
                            <?php else: ?>
                            <span class="badge bg-secondary mt-2">Pendiente</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stage-specific action forms -->
        <?php if (!$isFinal): ?>
        <div class="pt-3" style="border-top:1px solid var(--border-color);">

            <?php if ($currentStatus === NoveltyConstants::STATUS_GDP): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id]]) ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Pasa para Pago</label>
                    <select name="passes_for_payment" class="form-select" required>
                        <option value="">-- Seleccione --</option>
                        <option value="1" <?= $doc->passes_for_payment === true ? 'selected' : '' ?>>Sí</option>
                        <option value="0" <?= $doc->passes_for_payment === false ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-arrow-right-circle me-1"></i>Guardar y Avanzar
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>

            <?php elseif ($currentStatus === NoveltyConstants::STATUS_TESORERIA): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id]]) ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Estado de Pago</label>
                    <select name="payment_status" class="form-select" required>
                        <option value="">-- Seleccione --</option>
                        <?php foreach ($paymentLabels as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($doc->payment_status ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fecha de Pago</label>
                    <input type="text" name="payment_date" class="form-control flatpickr-date"
                           value="<?= h($doc->payment_date?->format('Y-m-d') ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-arrow-right-circle me-1"></i>Guardar y Avanzar
                    </button>
                </div>
            </div>
            <?= $this->Form->end() ?>

            <?php elseif ($currentStatus === NoveltyConstants::STATUS_CONTABILIDAD): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id], 'class' => 'd-inline']) ?>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-arrow-right-circle me-1"></i>Avanzar a <?= $statusLabels[NoveltyConstants::STATUS_FIRMAS_APROBACION] ?? '' ?>
            </button>
            <?= $this->Form->end() ?>

            <?php elseif ($currentStatus === NoveltyConstants::STATUS_FIRMAS_APROBACION): ?>
                <?php if (empty($groupErrors)): ?>
                <?= $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id], 'class' => 'd-inline']) ?>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-arrow-right-circle me-1"></i>Avanzar Grupo
                </button>
                <?= $this->Form->end() ?>
                <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
</div>
</div><!-- /left column -->

<!-- Right column: documents + observations -->
<div style="width:380px;flex-shrink:0;display:flex;flex-direction:column;gap:1rem;">

<!-- Documents panel -->
<div class="card card-primary">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" style="font-size:.85rem;"></i>
            <span style="font-size:.85rem;font-weight:600;">Soportes</span>
            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
        <?php if ($showUploadSection): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
            <i class="bi bi-upload me-1"></i>Subir
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($documentsByStatus)): ?>
        <div style="padding:2rem 1rem;text-align:center;color:#c8c8c8;">
            <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;"></i>
            <span style="font-size:.8rem;">Sin soportes adjuntos</span>
        </div>
    <?php else: ?>
        <div style="max-height:420px;overflow-y:auto;">
            <?php
            $multipleStatuses = count($documentsByStatus) > 1;
            foreach ($documentsByStatus as $status => $docs):
            ?>
            <?php if ($multipleStatuses): ?>
            <div style="padding:.3rem .875rem;background:#f8f9fa;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:.4rem;">
                <span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>" style="font-size:.6rem;"><?= $statusLabels[$status] ?? $status ?></span>
                <span style="font-size:.67rem;color:#aaa;"><?= count($docs) ?> archivo<?= count($docs) !== 1 ? 's' : '' ?></span>
            </div>
            <?php endif; ?>
            <?php foreach ($docs as $docFile): ?>
            <div style="display:flex;align-items:flex-start;gap:.75rem;padding:.8rem .875rem;border-bottom:1px solid var(--border-color);">
                <div style="width:34px;height:34px;flex-shrink:0;background:#f5f5f5;border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;">
                    <i class="bi <?= $docIcon($docFile->mime_type) ?>"
                       style="color:<?= $docIconColor($docFile->mime_type) ?>;font-size:1rem;"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.79rem;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;"
                         title="<?= h($docFile->file_name) ?>">
                        <?= h($docFile->file_name) ?>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem;margin-top:.35rem;flex-wrap:wrap;">
                        <?php if (!$multipleStatuses): ?>
                        <span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>" style="font-size:.58rem;"><?= $statusLabels[$status] ?? $status ?></span>
                        <?php endif; ?>
                        <span style="font-size:.65rem;color:#bbb;">
                            <i class="bi bi-clock" style="font-size:.6rem;"></i>
                            <?= $docFile->created?->format('d/m/Y H:i') ?>
                        </span>
                        <?php if ($docFile->file_size): ?>
                        <span style="font-size:.63rem;color:#ccc;"><?= $this->Number->toReadableSize($docFile->file_size) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display:flex;gap:.25rem;flex-shrink:0;align-self:center;">
                    <?= $this->Html->link(
                        '<i class="bi bi-box-arrow-up-right"></i>',
                        '/' . $docFile->file_path,
                        ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'padding:.25rem .45rem;font-size:.72rem;line-height:1;', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
                    ) ?>
                    <?php if ($showUploadSection && $docFile->pipeline_status === $currentStatus): ?>
                    <?= $this->Form->postLink(
                        '<i class="bi bi-trash"></i>',
                        ['action' => 'deleteDocument', $doc->id, $docFile->id],
                        ['confirm' => '¿Eliminar este soporte?', 'class' => 'btn btn-sm btn-outline-danger', 'style' => 'padding:.25rem .45rem;font-size:.72rem;line-height:1;', 'escape' => false, 'title' => 'Eliminar']
                    ) ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Observations chat -->
<?php $obsCount = count($doc->novelty_observations ?? []); ?>
<div class="card card-primary" style="display:flex;flex-direction:column;">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-chat-left-text" style="font-size:.85rem;color:var(--primary-color);"></i>
        <span style="font-size:.85rem;font-weight:600;">Observaciones</span>
        <?php if ($obsCount > 0): ?>
        <span class="sgi-folder-count ms-auto"><?= $obsCount ?></span>
        <?php endif; ?>
    </div>

    <div id="obs-chat-scroll" style="min-height:100px;max-height:340px;overflow-y:auto;padding:1rem .875rem;background:#f9fafb;display:flex;flex-direction:column;gap:.875rem;">
        <?php if (empty($doc->novelty_observations)): ?>
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1.5rem 0;color:#c5c5c5;gap:.5rem;">
            <i class="bi bi-chat-square-dots" style="font-size:1.75rem;"></i>
            <span style="font-size:.78rem;">Sin observaciones aún</span>
        </div>
        <?php else: ?>
        <?php foreach ($doc->novelty_observations as $obs):
            $isMine = $currentUser && $obs->user_id === $currentUser->id;
            $names = explode(' ', trim($obs->user->full_name ?? ''));
            $initials = strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[array_key_last($names)] ?? '', 0, 1));
        ?>
        <div style="display:flex;flex-direction:column;align-items:<?= $isMine ? 'flex-end' : 'flex-start' ?>;gap:.2rem;">
            <div style="font-size:.63rem;color:#aaa;font-weight:500;letter-spacing:.01em;
                        <?= $isMine ? 'padding-right:.3rem' : 'padding-left:.3rem' ?>">
                <?= $isMine ? 'Tú' : h($obs->user->full_name ?? '') ?>
            </div>
            <div style="max-width:92%;padding:.55rem .8rem;font-size:.81rem;line-height:1.5;word-break:break-word;
                        background:<?= $isMine ? 'var(--primary-color)' : '#fff' ?>;
                        color:<?= $isMine ? '#fff' : '#2d2d2d' ?>;
                        border:1px solid <?= $isMine ? 'var(--primary-color)' : 'var(--border-color)' ?>;
                        border-radius:<?= $isMine ? '10px 10px 2px 10px' : '10px 10px 10px 2px' ?>;">
                <?= nl2br(h($obs->message)) ?>
            </div>
            <div style="font-size:.61rem;color:#c0c0c0;
                        <?= $isMine ? 'padding-right:.3rem' : 'padding-left:.3rem' ?>">
                <?= $obs->created?->format('d/m/Y H:i') ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (!$isFinal): ?>
    <div style="border-top:1px solid var(--border-color);padding:.75rem .875rem;background:#fff;">
        <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $doc->id]]) ?>
        <div class="d-flex gap-2 align-items-end">
            <textarea name="message" class="form-control auto-resize" rows="1"
                      style="font-size:.82rem;background:#f9fafb;border-color:var(--border-color);"
                      placeholder="Escriba una observación..." required></textarea>
            <button type="submit" class="btn btn-primary flex-shrink-0"
                    style="padding:.5rem .75rem;align-self:flex-end;" title="Enviar">
                <i class="bi bi-send" style="font-size:.85rem;"></i>
            </button>
        </div>
        <?= $this->Form->end() ?>
    </div>
    <?php endif; ?>
</div>

</div><!-- /right column -->
</div><!-- /two-column layout -->

<!-- Upload Document Modal -->
<?php if ($showUploadSection): ?>
<div class="modal fade" id="uploadDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= $this->Form->create(null, ['url' => ['action' => 'uploadDocument', $doc->id], 'type' => 'file']) ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Subir Soporte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Archivo</label>
                    <input type="file" name="document" class="form-control" required
                           accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
                    <div class="form-text">Máximo 10 MB — PDF, imágenes, Word o Excel.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Subir</button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Signature canvas JS -->
<script>
(function() {
    document.querySelectorAll('.sig-canvas').forEach(function(canvas) {
        var ctx = canvas.getContext('2d');
        var drawing = false;
        var hasDrawn = false;
        var form = canvas.closest('.col-md-6, .col-lg-3').querySelector('.sig-form');
        var base64Input = form ? form.querySelector('.sig-base64') : null;

        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.strokeStyle = '#000';
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';

        function getPos(e) {
            var rect = canvas.getBoundingClientRect();
            var cx = e.touches ? e.touches[0].clientX : e.clientX;
            var cy = e.touches ? e.touches[0].clientY : e.clientY;
            return { x: cx - rect.left, y: cy - rect.top };
        }

        canvas.addEventListener('mousedown', function(e) { drawing = true; var p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); });
        canvas.addEventListener('mousemove', function(e) { if (!drawing) return; hasDrawn = true; var p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); });
        canvas.addEventListener('mouseup', function() { drawing = false; });
        canvas.addEventListener('mouseleave', function() { drawing = false; });
        canvas.addEventListener('touchstart', function(e) { e.preventDefault(); drawing = true; var p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); });
        canvas.addEventListener('touchmove', function(e) { e.preventDefault(); if (!drawing) return; hasDrawn = true; var p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); });
        canvas.addEventListener('touchend', function() { drawing = false; });

        var clearBtn = canvas.closest('.col-md-6, .col-lg-3').querySelector('.sig-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
                hasDrawn = false;
                if (base64Input) base64Input.value = '';
            });
        }

        if (form) {
            form.addEventListener('submit', function() {
                if (hasDrawn && base64Input) {
                    base64Input.value = canvas.toDataURL('image/png');
                }
            });
        }
    });
})();
</script>

<?php $this->append('script') ?>
<script>
(function(){
    var chat = document.getElementById('obs-chat-scroll');
    if (chat) chat.scrollTop = chat.scrollHeight;

    function syncHeight(el) {
        el.style.height = '0px';
        el.style.height = (el.scrollHeight + 2) + 'px';
    }
    document.querySelectorAll('textarea.auto-resize').forEach(function(el) {
        el.style.overflow  = 'hidden';
        el.style.resize    = 'none';
        el.style.minHeight = '0px';
        syncHeight(el);
        el.addEventListener('input', function() { syncHeight(this); });
    });
})();
</script>
<?php $this->end() ?>
```

**Step 2: Commit**

```bash
git add templates/NoveltyLiquidationDocs/edit.php
git commit -m "feat(liquidation-docs): create edit.php two-column template with soportes and observations on right"
```

---

### Task 3: Refactor `view.php` to Read-Only with Aggregated History

**Files:**
- Modify: `src/Controller/NoveltyLiquidationDocsController.php` (update `view()` to load history)
- Rewrite: `templates/NoveltyLiquidationDocs/view.php`

**Step 1: Update `view()` in controller to load aggregated novelty history**

Add to the `view()` method: query `novelty_histories` for all novelties in the group. Also load `$fieldLabels` from `NoveltyHistoryService`.

```php
// In view() method, after existing code, before $this->set():
use App\Service\NoveltyHistoryService;

// Aggregate change history from all novelties in this group
$noveltyIds = array_map(fn($n) => $n->id, $doc->employee_novelties);
$groupHistories = [];
$fieldLabels = NoveltyHistoryService::FIELD_LABELS;
if (!empty($noveltyIds)) {
    $historiesTable = $this->fetchTable('NoveltyHistories');
    $groupHistories = $historiesTable->find()
        ->contain(['Users', 'EmployeeNovelties'])
        ->where(['NoveltyHistories.novelty_id IN' => $noveltyIds])
        ->order(['NoveltyHistories.created' => 'DESC'])
        ->all()
        ->toArray();
}

$this->set(compact('doc', 'groupErrors', 'effectiveStatuses', 'documentsByStatus', 'groupHistories', 'fieldLabels'));
```

**Step 2: Rewrite `view.php` to be read-only**

Follow the pattern from `EmployeeNovelties/view.php`: main card with header + pipeline + two-column data display + signatures (display-only) + observations (read-only) + contact bar + documents grid card + **aggregated change history table**.

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\NoveltyLiquidationDoc $doc
 * @var array $groupErrors
 * @var array $effectiveStatuses
 * @var array $documentsByStatus
 * @var array $groupHistories
 * @var array $fieldLabels
 */
use App\Constants\NoveltyConstants;

$this->assign('title', 'Liquidación: ' . h($doc->liquidation_number));

$statusLabels = NoveltyConstants::STATUS_LABELS;
$statusIcons = NoveltyConstants::STATUS_ICONS;
$periodLabels = NoveltyConstants::PERIOD_LABELS;
$signerLabels = NoveltyConstants::SIGNER_LABELS;
$paymentLabels = NoveltyConstants::PAYMENT_LABELS;
$isRejected = $doc->pipeline_status === NoveltyConstants::STATUS_RECHAZADA;
$isPaid = $doc->pipeline_status === NoveltyConstants::STATUS_PAGADA;
$currentStatus = $doc->pipeline_status;

$statusBadgeMap = [
    'contabilidad' => 'bg-primary',
    'firmas_aprobacion' => 'bg-warning text-dark',
    'gdp' => 'bg-dark',
    'tesoreria' => 'bg-info',
    'pagada' => 'bg-success',
    'rechazada' => 'bg-danger',
];

// Documents prep
$docIcon = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => 'bi-file-earmark-pdf',
    str_contains($mime ?? '', 'image') => 'bi-file-earmark-image',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => 'bi-file-earmark-word',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'bi-file-earmark-excel',
    default => 'bi-file-earmark',
};
$docIconColor = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => '#dc3545',
    str_contains($mime ?? '', 'image') => '#0dcaf0',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => '#0d6efd',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'var(--primary-color)',
    default => '#aaa',
};
$totalDocs = array_sum(array_map('count', $documentsByStatus));
$badgeColors = [
    'contabilidad' => 'bg-primary', 'firmas_aprobacion' => 'bg-warning text-dark',
    'gdp' => 'bg-dark', 'tesoreria' => 'bg-info', 'pagada' => 'bg-success',
];
?>

<!-- Page header -->
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Ver Liquidación</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?php if (!empty($userPermissions['novelty_liquidation_docs']['can_edit'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil me-1"></i>Editar',
            ['action' => 'edit', $doc->id],
            ['class' => 'btn btn-warning btn-sm', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Main card -->
<div class="card card-primary mb-4">

    <!-- Header -->
    <div class="card-header d-flex align-items-start justify-content-between gap-3" style="padding:1rem 1.25rem;">
        <div class="d-flex align-items-start gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:52px;height:52px;background:var(--primary-color);color:#fff;font-size:1.35rem;">
                <i class="bi bi-file-earmark-text"></i>
            </div>
            <div>
                <div style="font-size:1.25rem;font-weight:700;letter-spacing:-.03em;color:#111;line-height:1.15;">
                    <?= h($doc->liquidation_number) ?>
                </div>
                <div class="mt-1 d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge bg-secondary"><?= $periodLabels[$doc->period] ?? h($doc->period) ?></span>
                    <span class="badge <?= $statusBadgeMap[$currentStatus] ?? 'bg-dark' ?>">
                        <?= $statusLabels[$currentStatus] ?? ucfirst($currentStatus) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Pipeline progress -->
    <div style="background:#fafafa;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <?= $this->element('pipeline_progress', [
            'pipelineStatuses' => $effectiveStatuses,
            'pipelineLabels' => $statusLabels,
            'currentStatus' => $currentStatus,
            'isRejected' => $isRejected,
            'statusIcons' => $statusIcons,
        ]) ?>
    </div>

    <!-- Two-column data: Información | Novedades -->
    <div class="row g-0" style="border-bottom:1px solid var(--border-color);">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Información del Documento</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">No. Liquidación</span>
                <span class="sgi-data-value"><?= h($doc->liquidation_number) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Período</span>
                <span class="sgi-data-value"><?= $periodLabels[$doc->period] ?? h($doc->period) ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Documento</span>
                <span class="sgi-data-value"><?= $doc->document_date?->format('d/m/Y') ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Elaborado por</span>
                <span class="sgi-data-value"><?= h($doc->performed_by_user->full_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Creado por</span>
                <span class="sgi-data-value"><?= h($doc->created_by_user->full_name ?? '—') ?></span>
            </div>
            <?php if ($doc->passes_for_payment !== null): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Pasa para Pago</span>
                <span class="sgi-data-value">
                    <span class="badge bg-<?= $doc->passes_for_payment ? 'success' : 'secondary' ?>"><?= $doc->passes_for_payment ? 'Sí' : 'No' ?></span>
                </span>
            </div>
            <?php endif; ?>
            <?php if ($doc->payment_status): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Estado de Pago</span>
                <span class="sgi-data-value"><?= $paymentLabels[$doc->payment_status] ?? h($doc->payment_status) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($doc->payment_date): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha de Pago</span>
                <span class="sgi-data-value"><?= $doc->payment_date->format('d/m/Y') ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <div class="sgi-section-title">Novedades Asociadas (<?= count($doc->employee_novelties) ?>)</div>
            <?php if (!empty($doc->employee_novelties)): ?>
            <div style="padding:0 1.25rem .875rem;max-height:300px;overflow-y:auto;">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Empleado</th><th>Tipo</th></tr></thead>
                    <tbody>
                        <?php foreach ($doc->employee_novelties as $novelty): ?>
                        <tr>
                            <td>
                                <?= $this->Html->link(
                                    h($novelty->custom_name ?: $novelty->employee->full_name ?? '—'),
                                    ['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id],
                                    ['class' => 'text-decoration-none']
                                ) ?>
                            </td>
                            <td style="font-size:.8125rem;"><?= h($novelty->novelty_type->name ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="padding:.25rem 1.25rem .875rem;">
                <p class="text-muted small mb-0">No hay novedades asociadas.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Signatures (read-only display) -->
    <?php if (!empty($doc->novelty_liquidation_signatures)): ?>
    <div style="border-bottom:1px solid var(--border-color);">
        <div class="sgi-section-title">Firmas</div>
        <div style="padding:0 1.25rem .875rem;">
            <div class="row g-3">
                <?php foreach ($doc->novelty_liquidation_signatures as $sig): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="border rounded p-3 text-center h-100">
                        <div class="fw-bold small mb-2"><?= $signerLabels[$sig->signer_type] ?? h($sig->signer_type) ?></div>
                        <?php if ($sig->signature_path): ?>
                            <img src="<?= $this->Url->build('/' . $sig->signature_path) ?>" alt="Firma"
                                 style="max-width:100%;max-height:100px;border:1px solid var(--border-color);">
                            <div class="mt-1 small text-muted">
                                <?= h($sig->signed_by_user->full_name ?? '') ?>
                                <?php if ($sig->approved_at): ?>
                                <br><?= $sig->approved_at->format('d/m/Y H:i') ?>
                                <?php endif; ?>
                            </div>
                            <span class="badge bg-success mt-1">Firmado</span>
                        <?php else: ?>
                            <span class="badge bg-secondary mt-2">Pendiente</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Observations (read-only) -->
    <?php if (!empty($doc->novelty_observations)): ?>
    <div style="border-bottom:1px solid var(--border-color);">
        <div class="sgi-section-title">Observaciones</div>
        <div style="padding:.5rem 1.25rem .875rem;max-height:400px;overflow-y:auto;">
            <?php foreach ($doc->novelty_observations as $obs): ?>
            <div class="d-flex align-items-start gap-2 mb-3">
                <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:32px;height:32px;background:var(--primary-color);color:#fff;font-size:.7rem;font-weight:700;">
                    <?php
                    $names = explode(' ', $obs->user->full_name ?? '');
                    echo strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[1] ?? '', 0, 1));
                    ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="d-flex align-items-center gap-2">
                        <span style="font-size:.8rem;font-weight:600;color:#222;">
                            <?= h($obs->user->full_name ?? '') ?>
                        </span>
                        <span style="font-size:.7rem;color:#aaa;">
                            <?= $obs->created ? $obs->created->format('d/m/Y H:i') : '' ?>
                        </span>
                    </div>
                    <div style="font-size:.84rem;color:#444;line-height:1.5;margin-top:.15rem;">
                        <?= nl2br(h($obs->message)) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Contact bar -->
    <div class="sgi-contact-bar">
        <?php if ($doc->performed_by_user): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-person"></i>
            <span>Elaborado por <?= h($doc->performed_by_user->full_name) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($doc->created): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-calendar3"></i>
            <span>Creado: <?= $doc->created->format('d/m/Y') ?></span>
        </div>
        <?php endif; ?>
        <?php if ($doc->modified): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-pencil-square"></i>
            <span>Modificado: <?= $doc->modified->format('d/m/Y') ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Documents (read-only grid) -->
<div class="card card-primary mb-4">
    <div class="card-header">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip"></i>
            Soportes
            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
    </div>

    <?php if (empty($documentsByStatus)): ?>
        <div class="p-3 text-center text-muted" style="font-size:.875rem">
            <i class="bi bi-file-earmark-x me-1"></i>Sin soportes adjuntos
        </div>
    <?php else: ?>
        <div class="p-3">
            <div class="row row-cols-1 row-cols-md-3 g-3">
                <?php foreach ($documentsByStatus as $status => $docs): ?>
                    <?php foreach ($docs as $docFile): ?>
                    <div class="col">
                        <div style="border:1px solid var(--border-color);height:100%;display:flex;flex-direction:column;">
                            <div style="padding:.6rem .875rem;border-bottom:1px solid var(--border-color);background:#fafafa;display:flex;align-items:center;gap:.5rem;min-width:0;">
                                <i class="bi <?= $docIcon($docFile->mime_type) ?> flex-shrink-0"
                                   style="color:<?= $docIconColor($docFile->mime_type) ?>;font-size:1.1rem;"></i>
                                <div style="min-width:0;flex:1;overflow:hidden;">
                                    <span style="font-size:.78rem;font-weight:600;color:#222;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;" title="<?= h($docFile->file_name) ?>">
                                        <?= h($docFile->file_name) ?>
                                    </span>
                                </div>
                            </div>
                            <div style="padding:.6rem .875rem;flex:1;font-size:.78rem;color:#555;display:flex;flex-direction:column;gap:.3rem;">
                                <div>
                                    <span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>" style="font-size:.65rem;">
                                        <?= $statusLabels[$status] ?? $status ?>
                                    </span>
                                </div>
                                <div style="display:flex;align-items:center;gap:.35rem;color:#666;">
                                    <i class="bi bi-person" style="font-size:.8rem;"></i>
                                    <span><?= $docFile->has('uploaded_by_user') ? h($docFile->uploaded_by_user->full_name) : '—' ?></span>
                                </div>
                                <div style="display:flex;align-items:center;gap:.35rem;color:#888;">
                                    <i class="bi bi-clock" style="font-size:.75rem;"></i>
                                    <span><?= $docFile->created?->format('d/m/Y H:i') ?></span>
                                </div>
                                <?php if ($docFile->file_size): ?>
                                <div style="color:#aaa;font-size:.72rem;"><?= $this->Number->toReadableSize($docFile->file_size) ?></div>
                                <?php endif; ?>
                            </div>
                            <div style="padding:.5rem .875rem;border-top:1px solid var(--border-color);text-align:right;">
                                <?= $this->Html->link(
                                    '<i class="bi bi-box-arrow-up-right me-1"></i>Abrir',
                                    '/' . $docFile->file_path,
                                    ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false, 'target' => '_blank']
                                ) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Aggregated Change History -->
<?php if (!empty($groupHistories)): ?>
<div class="card">
    <div class="card-header">Historial de Cambios del Grupo</div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Novedad</th>
                    <th>Usuario</th>
                    <th>Campo</th>
                    <th>Valor Anterior</th>
                    <th>Valor Nuevo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groupHistories as $history): ?>
                <tr>
                    <td><?= $history->created ? $history->created->format('d/m/Y H:i') : '' ?></td>
                    <td style="font-size:.8125rem;">
                        <?php if ($history->has('employee_novelty')): ?>
                            <?= $this->Html->link(
                                '#' . $history->employee_novelty->id,
                                ['controller' => 'EmployeeNovelties', 'action' => 'view', $history->employee_novelty->id],
                                ['class' => 'text-decoration-none']
                            ) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $history->hasValue('user') ? h($history->user->full_name) : '' ?></td>
                    <td><?= h($fieldLabels[$history->field_changed] ?? $history->field_changed) ?></td>
                    <td class="text-muted"><?= h($history->old_value) ?: '—' ?></td>
                    <td class="fw-semibold"><?= h($history->new_value) ?: '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
```

**Step 3: Commit**

```bash
git add src/Controller/NoveltyLiquidationDocsController.php templates/NoveltyLiquidationDocs/view.php
git commit -m "feat(liquidation-docs): refactor view.php to read-only with aggregated history"
```

---

### Task 4: Update Index to Link to Edit

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/index.php`

**Step 1: Change clickable row target from `view` to `edit`**

Line 59: change `'action' => 'view'` to `'action' => 'edit'`.

```php
// Before:
<tr class="clickable-row" data-href="<?= $this->Url->build(['action' => 'view', $doc->id]) ?>">

// After:
<tr class="clickable-row" data-href="<?= $this->Url->build(['action' => 'edit', $doc->id]) ?>">
```

**Step 2: Commit**

```bash
git add templates/NoveltyLiquidationDocs/index.php
git commit -m "feat(liquidation-docs): index rows now link to edit instead of view"
```

---

### Task 5: Verify with CS Check

**Step 1: Run code style check**

```bash
composer cs-check src/Controller/NoveltyLiquidationDocsController.php
```

**Step 2: Fix any issues**

```bash
composer cs-fix src/Controller/NoveltyLiquidationDocsController.php
```

**Step 3: Final commit if fixes needed**

```bash
git add src/Controller/NoveltyLiquidationDocsController.php
git commit -m "style(liquidation-docs): fix CS errors"
```
