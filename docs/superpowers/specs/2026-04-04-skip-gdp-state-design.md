# Skip GDP State for Novelty Types Without Employee Signature

**Date:** 2026-04-04
**Status:** Approved

## Problem

The novelty liquidation pipeline has a fixed sequence: `contabilidad` -> `revision_firmas` -> `gdp` -> `tesoreria` -> `pagada`. The GDP state exists to collect the employee's signature and the `passes_for_payment` decision. However, some novelty types (`requires_employee_signature_review = false`) don't need the employee's signature, making GDP an unnecessary step that slows down processing.

## Decision

Skip the GDP state dynamically when the novelty type does not require employee signature review, following the same pattern already used to skip `aprobacion` when `requires_boss_approval = false`.

When GDP is skipped, the `passes_for_payment` field moves to the `revision_firmas` stage so it is still captured before advancing to `tesoreria`.

## Design

### 1. Transition Logic (`NoveltyPipelineService::getNextStatus()`)

Add GDP skip after the existing `aprobacion` skip:

```php
if ($nextStatus === NoveltyConstants::STATUS_GDP && $noveltyType && !$noveltyType->requires_employee_signature_review) {
    $nextStatus = NoveltyConstants::TRANSITIONS[$nextStatus] ?? null;
}
```

When `requires_employee_signature_review = false`, the transition goes directly from `revision_firmas` to `tesoreria`.

### 2. Effective Statuses (`NoveltyPipelineService::getEffectiveStatuses()`)

Filter GDP from the visual pipeline when the type doesn't require it:

```php
if ($noveltyType && !$noveltyType->requires_employee_signature_review) {
    $statuses = array_filter($statuses, fn($s) => $s !== NoveltyConstants::STATUS_GDP);
}
```

This ensures the pipeline stepper in the UI doesn't show GDP as a step.

### 3. Group Transition Validation (`NoveltyPipelineService::validateGroupTransition()`)

In the `revision_firmas` case, when GDP will be skipped, also validate `passes_for_payment`:

```php
case NoveltyConstants::STATUS_REVISION_FIRMAS:
    // ... existing signature validation ...

    // If GDP will be skipped, validate passes_for_payment here
    $firstMember = $this->_getFirstGroupMember($liquidationDoc);
    if ($firstMember && $firstMember->novelty_type && !$firstMember->novelty_type->requires_employee_signature_review) {
        if ($liquidationDoc->passes_for_payment === null) {
            $errors[] = 'Debe indicar si "Pasa para Pago".';
        }
    }
    break;
```

A helper method `_getFirstGroupMember()` fetches the first novelty in the group with its type contained:

```php
private function _getFirstGroupMember(object $liquidationDoc): ?object
{
    return TableRegistry::getTableLocator()->get('EmployeeNovelties')
        ->find()
        ->contain(['NoveltyTypes'])
        ->where(['liquidation_doc_id' => $liquidationDoc->id])
        ->first();
}
```

### 4. Controller (`NoveltyLiquidationDocsController`)

In `edit()` and `view()`, compute `$skipsGdp` and pass the novelty type to `getEffectiveStatuses()`:

```php
$firstNovelty = $doc->employee_novelties[0] ?? null;
$noveltyType = $firstNovelty?->novelty_type;
$skipsGdp = $noveltyType && !$noveltyType->requires_employee_signature_review;
$effectiveStatuses = $this->pipelineService->getEffectiveStatuses($noveltyType);
$this->set(compact('skipsGdp', ...));
```

### 5. Template (`NoveltyLiquidationDocs/edit.php`)

In the `revision_firmas` action form, conditionally show the `passes_for_payment` select when `$skipsGdp` is true:

```php
<?php if ($currentStatus === NoveltyConstants::STATUS_REVISION_FIRMAS): ?>
    <?= $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id]]) ?>
    <div class="d-flex flex-wrap gap-3 align-items-end">
        <?php if ($skipsGdp): ?>
        <div style="min-width:200px;">
            <label class="form-label">Pasa para Pago</label>
            <select name="passes_for_payment" class="form-select" required>
                <option value="">-- Seleccione --</option>
                <option value="1">Si</option>
                <option value="0">No</option>
            </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-success">Guardar y Avanzar</button>
    </div>
    <?= $this->Form->end() ?>
<?php endif; ?>
```

## Assumptions

- Liquidation document groups are homogeneous: all novelties in a group share the same `requires_employee_signature_review` value. Mixed groups do not occur in practice.
- The `passes_for_payment` field is visible and editable by any role that can operate in `revision_firmas` (when GDP is skipped).

## Files to Modify

| File | Change |
|------|--------|
| `src/Service/NoveltyPipelineService.php` | Skip GDP in `getNextStatus()`, filter GDP in `getEffectiveStatuses()`, validate `passes_for_payment` in `revision_firmas` in `validateGroupTransition()`, add `_getFirstGroupMember()` helper |
| `src/Controller/NoveltyLiquidationDocsController.php` | Compute `$skipsGdp`, pass novelty type to `getEffectiveStatuses()` in `edit()` and `view()` |
| `templates/NoveltyLiquidationDocs/edit.php` | Show `passes_for_payment` field in `revision_firmas` when `$skipsGdp` is true |

## Not Needed

- No database migrations
- No new constants
- No new files
- No changes to `view.php` (already displays `passes_for_payment` as read-only)
