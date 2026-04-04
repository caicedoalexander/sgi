# Skip GDP State Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Skip the GDP pipeline state for novelty types that don't require employee signature (`requires_employee_signature_review = false`), moving the `passes_for_payment` field to `revision_firmas` when GDP is skipped.

**Architecture:** Extend the existing dynamic skip pattern (used for `aprobacion`/`requires_boss_approval`) to GDP. The `NoveltyPipelineService` handles transition logic and validation; the controller computes a `$skipsGdp` flag for the template; the template conditionally renders the `passes_for_payment` form in `revision_firmas`.

**Tech Stack:** CakePHP 5.3, PHP 8.2+, no new dependencies.

---

### Task 1: Add `_getFirstGroupMember()` helper to `NoveltyPipelineService`

**Files:**
- Modify: `src/Service/NoveltyPipelineService.php` (add method at end of class, before closing `}`)

- [ ] **Step 1: Add the helper method**

Add this private method at the end of `NoveltyPipelineService`, before the closing `}` on line 564:

```php
/**
 * Get the first novelty member of a liquidation document group with its type.
 */
private function _getFirstGroupMember(object $liquidationDoc): ?object
{
    return TableRegistry::getTableLocator()->get('EmployeeNovelties')
        ->find()
        ->contain(['NoveltyTypes'])
        ->where(['liquidation_doc_id' => $liquidationDoc->id])
        ->first();
}
```

- [ ] **Step 2: Verify no syntax errors**

Run: `php -l src/Service/NoveltyPipelineService.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Service/NoveltyPipelineService.php
git commit -m "refactor: add _getFirstGroupMember helper to NoveltyPipelineService"
```

---

### Task 2: Skip GDP in `getNextStatus()`

**Files:**
- Modify: `src/Service/NoveltyPipelineService.php:104-131`

- [ ] **Step 1: Add GDP skip logic after the existing aprobacion skip**

In `getNextStatus()`, after the existing skip block (lines 125-128), add the GDP skip. The result should look like this — the new code goes right after the `aprobacion` skip and before the `return`:

```php
// Skip aprobacion if type doesn't require boss approval
if ($nextStatus === NoveltyConstants::STATUS_APROBACION && $noveltyType && !$noveltyType->requires_boss_approval) {
    $nextStatus = NoveltyConstants::TRANSITIONS[$nextStatus] ?? null;
}

// Skip GDP if type doesn't require employee signature review
if ($nextStatus === NoveltyConstants::STATUS_GDP && $noveltyType && !$noveltyType->requires_employee_signature_review) {
    $nextStatus = NoveltyConstants::TRANSITIONS[$nextStatus] ?? null;
}

return $nextStatus;
```

- [ ] **Step 2: Verify no syntax errors**

Run: `php -l src/Service/NoveltyPipelineService.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Service/NoveltyPipelineService.php
git commit -m "feat: skip GDP state when novelty type does not require employee signature"
```

---

### Task 3: Filter GDP in `getEffectiveStatuses()`

**Files:**
- Modify: `src/Service/NoveltyPipelineService.php:136-147`

- [ ] **Step 1: Add GDP filtering**

Replace the `getEffectiveStatuses()` method (lines 136-147) with:

```php
/**
 * Get the effective pipeline statuses for a novelty type (full pipeline).
 */
public function getEffectiveStatuses(?object $noveltyType = null): array
{
    $statuses = NoveltyConstants::PIPELINE_STATUSES;

    if ($noveltyType && !$noveltyType->requires_boss_approval) {
        $statuses = array_filter($statuses, fn(string $s) => $s !== NoveltyConstants::STATUS_APROBACION);
    }
    if ($noveltyType && !$noveltyType->requires_employee_signature_review) {
        $statuses = array_filter($statuses, fn(string $s) => $s !== NoveltyConstants::STATUS_GDP);
    }

    return array_values($statuses);
}
```

- [ ] **Step 2: Verify no syntax errors**

Run: `php -l src/Service/NoveltyPipelineService.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Service/NoveltyPipelineService.php
git commit -m "feat: filter GDP from effective statuses when employee signature not required"
```

---

### Task 4: Validate `passes_for_payment` in `revision_firmas` when GDP is skipped

**Files:**
- Modify: `src/Service/NoveltyPipelineService.php:340-361` (the `revision_firmas` case in `validateGroupTransition()`)

- [ ] **Step 1: Add conditional validation after the existing signature check**

In the `case NoveltyConstants::STATUS_REVISION_FIRMAS:` block (lines 340-361), add the `passes_for_payment` validation after the existing signature count check (after line 360, before the `break;`). The full case should become:

```php
case NoveltyConstants::STATUS_REVISION_FIRMAS:
    $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');

    // Only validate non-worker signatures in revision_firmas
    $totalSlots = $signaturesTable->find()
        ->where([
            'liquidation_doc_id' => $liquidationDoc->id,
            'signer_type !=' => NoveltyConstants::SIGNER_TRABAJADOR,
        ])
        ->count();

    $signedCount = $signaturesTable->find()
        ->where([
            'liquidation_doc_id' => $liquidationDoc->id,
            'signer_type !=' => NoveltyConstants::SIGNER_TRABAJADOR,
            'signature_path IS NOT' => null,
        ])
        ->count();

    if ($signedCount < $totalSlots) {
        $errors[] = 'Todas las firmas requeridas (Contador y Coordinador) deben estar presentes para avanzar.';
    }

    // If GDP will be skipped, validate passes_for_payment here
    $firstMember = $this->_getFirstGroupMember($liquidationDoc);
    if ($firstMember && $firstMember->novelty_type && !$firstMember->novelty_type->requires_employee_signature_review) {
        if ($liquidationDoc->passes_for_payment === null) {
            $errors[] = 'Debe indicar si "Pasa para Pago".';
        }
    }
    break;
```

- [ ] **Step 2: Verify no syntax errors**

Run: `php -l src/Service/NoveltyPipelineService.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Service/NoveltyPipelineService.php
git commit -m "feat: validate passes_for_payment in revision_firmas when GDP is skipped"
```

---

### Task 5: Pass `$skipsGdp` and novelty type from controller to templates

**Files:**
- Modify: `src/Controller/NoveltyLiquidationDocsController.php:57-106` (`view()` method)
- Modify: `src/Controller/NoveltyLiquidationDocsController.php:114-148` (`edit()` method)

- [ ] **Step 1: Update `view()` method**

In `view()`, after line 78 (`$effectiveStatuses = $this->pipelineService->getEffectiveStatuses();`), replace that line and add the `$skipsGdp` computation. Change lines 78-79 to:

```php
$firstNovelty = $doc->employee_novelties[0] ?? null;
$noveltyType = $firstNovelty?->novelty_type;
$skipsGdp = $noveltyType && !$noveltyType->requires_employee_signature_review;
$effectiveStatuses = $this->pipelineService->getEffectiveStatuses($noveltyType);
```

No need to pass `$skipsGdp` to `view.php` since the view template is read-only and doesn't need it.

- [ ] **Step 2: Update `edit()` method**

In `edit()`, replace line 135 (`$effectiveStatuses = $this->pipelineService->getEffectiveStatuses();`) and add `$skipsGdp`. Change lines 135-136 to:

```php
$firstNovelty = $doc->employee_novelties[0] ?? null;
$noveltyType = $firstNovelty?->novelty_type;
$skipsGdp = $noveltyType && !$noveltyType->requires_employee_signature_review;
$effectiveStatuses = $this->pipelineService->getEffectiveStatuses($noveltyType);
```

Then add `$skipsGdp` to the `compact()` call on line 141. Change:

```php
$this->set(compact(
    'doc',
    'groupErrors',
    'effectiveStatuses',
    'documentsByStatus',
    'liquidationDocument',
    'currentUser',
));
```

To:

```php
$this->set(compact(
    'doc',
    'groupErrors',
    'effectiveStatuses',
    'documentsByStatus',
    'liquidationDocument',
    'currentUser',
    'skipsGdp',
));
```

- [ ] **Step 3: Verify no syntax errors**

Run: `php -l src/Controller/NoveltyLiquidationDocsController.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add src/Controller/NoveltyLiquidationDocsController.php
git commit -m "feat: compute skipsGdp flag and pass novelty type to getEffectiveStatuses"
```

---

### Task 6: Update `edit.php` template — `revision_firmas` action form

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/edit.php:344-351`

- [ ] **Step 1: Add `$skipsGdp` variable declaration at top of template**

After line 23 (`$currentStatus = $doc->pipeline_status;`), add:

```php
$skipsGdp = $skipsGdp ?? false;
```

- [ ] **Step 2: Replace the `revision_firmas` action form**

Replace lines 344-351 (the `elseif ($currentStatus === NoveltyConstants::STATUS_REVISION_FIRMAS):` block):

```php
<?php elseif ($currentStatus === NoveltyConstants::STATUS_REVISION_FIRMAS): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'advanceGroup', $doc->id]]) ?>
            <div class="d-flex flex-wrap gap-3 align-items-end">
                <?php if ($skipsGdp): ?>
                <div style="min-width:200px;">
                    <label class="form-label">Pasa para Pago</label>
                    <select name="passes_for_payment" class="form-select" required>
                        <option value="">-- Seleccione --</option>
                        <option value="1" <?= $doc->passes_for_payment === true ? 'selected' : '' ?>>Sí</option>
                        <option value="0" <?= $doc->passes_for_payment === false ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-success flex-shrink-0"<?= empty($groupErrors) ? '' : ' disabled' ?>>
                    <i class="bi bi-arrow-right-circle me-1"></i>Guardar y Avanzar
                </button>
            </div>
            <?= $this->Form->end() ?>
```

- [ ] **Step 3: Verify no syntax errors**

Run: `php -l templates/NoveltyLiquidationDocs/edit.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add templates/NoveltyLiquidationDocs/edit.php
git commit -m "feat: show passes_for_payment in revision_firmas when GDP is skipped"
```

---

### Task 7: Manual smoke test

- [ ] **Step 1: Start the dev server**

Run: `php bin/cake server`

- [ ] **Step 2: Test novelty type WITH employee signature (GDP should appear)**

1. Find or create a novelty type with `requires_employee_signature_review = true`
2. Create a novelty of that type and advance it through the pipeline to `revision_firmas`
3. Verify the pipeline stepper shows GDP as a step
4. Verify the `revision_firmas` action form does NOT show `passes_for_payment`
5. Advance to GDP — verify the GDP form shows `passes_for_payment` and worker signature as before

- [ ] **Step 3: Test novelty type WITHOUT employee signature (GDP should be skipped)**

1. Find or create a novelty type with `requires_employee_signature_review = false`
2. Create a novelty of that type and advance it through the pipeline to `revision_firmas`
3. Verify the pipeline stepper does NOT show GDP
4. Verify the `revision_firmas` action form DOES show the `passes_for_payment` select
5. Fill in `passes_for_payment` and advance — verify it goes directly to `tesoreria` (skipping GDP)

- [ ] **Step 4: Test validation**

1. With a GDP-skipped novelty in `revision_firmas`, try to advance without selecting `passes_for_payment`
2. Verify the validation error appears: "Debe indicar si 'Pasa para Pago'."
