# Legalizations Pipeline Permissions — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add the `legalizations` pipeline to the role × step matrix configurable from `/roles/edit/{id}`, replacing hardcoded role checks in `AdvanceLegalizationActionPolicy` with `PipelineAuthorizationService` lookups.

**Architecture:** The repo already has the matrix infrastructure (`PipelinePermissions` table + `PipelineAuthorizationService` + `PipelineStepConstants` declarative catalogue). Five pipelines are already wired (`invoices`, `novelties`, `payment_schedulings`, `refunds`, `petty_cash`). This plan only declares a sixth pipeline (`legalizations`) and refactors `AdvanceLegalizationActionPolicy` to consult the matrix instead of hardcoding `Contabilidad/Tesorería`. UI is unchanged — `templates/Roles/edit.php` iterates `STEPS_BY_PIPELINE` and renders the new section automatically.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, league/container DI.

**Reference design:** `docs/plans/2026-05-06-legalizations-pipeline-permissions-design.md`

**Testing policy:** This project does **not** use automated tests (per `CLAUDE.md`). Each task ends with manual validation steps to run in the browser or via CLI. Do **not** add files to `tests/`.

---

## Task 1: Declare the `legalizations` pipeline

**Files:**
- Modify: `src/Constants/PipelineStepConstants.php`

**Step 1: Add the pipeline constant**

In `src/Constants/PipelineStepConstants.php`, add the constant alongside the existing five:

```php
public const PIPELINE_LEGALIZATIONS = 'legalizations';
```

Place it after `PIPELINE_PETTY_CASH`.

**Step 2: Add the import for `AdvanceConstants`**

At the top of the file, add the `use` statement if not present:

```php
// Already imports InvoiceConstants, NoveltyConstants, PaymentSchedulingConstants,
// RefundConstants, PettyCashConstants. Add the same way:
// (no use statement needed — same namespace App\Constants)
```

`AdvanceConstants` lives in the same namespace, so no import is needed — reference it directly.

**Step 3: Add label entry**

In the `PIPELINE_LABELS` array, add:

```php
self::PIPELINE_LEGALIZATIONS => 'Legalizaciones',
```

**Step 4: Add the steps array**

In `STEPS_BY_PIPELINE`, add an entry after `PIPELINE_PETTY_CASH`:

```php
self::PIPELINE_LEGALIZATIONS => [
    AdvanceConstants::STATUS_VALIDACION,
    AdvanceConstants::STATUS_REVISION_FIRMAS,
    AdvanceConstants::STATUS_CONTABILIDAD,
    AdvanceConstants::STATUS_TESORERIA,
    AdvanceConstants::STATUS_AUTORIZACION_PAGO,
],
```

Note: `STATUS_LEGALIZADA` is excluded (terminal state, no operable actions — same criterion as `pagada` in invoices).

**Step 5: Add the step labels**

In `STEP_LABELS`, add an entry after `PIPELINE_PETTY_CASH`:

```php
self::PIPELINE_LEGALIZATIONS => [
    AdvanceConstants::STATUS_VALIDACION        => 'Validación',
    AdvanceConstants::STATUS_REVISION_FIRMAS   => 'Revisión y Firmas',
    AdvanceConstants::STATUS_CONTABILIDAD      => 'Contabilidad',
    AdvanceConstants::STATUS_TESORERIA         => 'Tesorería',
    AdvanceConstants::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
],
```

**Step 6: Verify code style**

Run: `composer cs-check`
Expected: no errors. If style issues appear, run `composer cs-fix`.

**Step 7: Manual validation — UI shows the new pipeline**

Run: `php bin/cake server`
Visit: `http://localhost:8765/roles/edit/1` (or any role id)
Expected: a new section "Legalizaciones" appears alongside the others, with 5 unchecked checkboxes labeled: Validación, Revisión y Firmas, Contabilidad, Tesorería, Autorización de pago.

**Step 8: Commit**

```bash
git add src/Constants/PipelineStepConstants.php
git commit -m "feat(roles): declare legalizations pipeline in step constants"
```

---

## Task 2: Refactor `AdvanceLegalizationActionPolicy` to use the matrix

**Files:**
- Modify: `src/Service/Pipeline/Policy/AdvanceLegalizationActionPolicy.php`
- Modify: `src/Application.php`

**Step 1: Update the policy class**

Replace the entire body of `AdvanceLegalizationActionPolicy.php` with:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Policy;

use App\Constants\PipelineStepConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Service\PipelineAuthorizationService;

/**
 * Decide whether a given role is allowed to execute a mutating action on an
 * advance legalization in its current pipeline state.
 *
 * Audit MA-010 — la regla de **estado** vive en los predicates `canXxx()` de
 * `AdvanceLegalization`. Este policy compone solo la dimensión de **rol×paso**,
 * delegando esa decisión a `PipelineAuthorizationService` (matriz configurable
 * desde /roles/edit). El chequeo de estado sigue delegado a la entidad.
 */
final class AdvanceLegalizationActionPolicy
{
    public function __construct(
        private PipelineAuthorizationService $pipelineAuth,
    ) {
    }

    public function canLinkInvoices(AdvanceLegalization $leg, int $roleId, string $roleName): bool
    {
        return $this->_canOperate($roleId, $roleName, $leg->status) && $leg->canLinkInvoices();
    }

    public function canUnlinkInvoice(AdvanceLegalization $leg, int $roleId, string $roleName): bool
    {
        return $this->_canOperate($roleId, $roleName, $leg->status) && $leg->canUnlinkInvoice();
    }

    public function canUploadRelationDocument(AdvanceLegalization $leg, int $roleId, string $roleName): bool
    {
        return $this->_canOperate($roleId, $roleName, $leg->status) && $leg->canUploadRelationDocument();
    }

    public function canMoveToRevision(AdvanceLegalization $leg, int $roleId, string $roleName): bool
    {
        return $this->_canOperate($roleId, $roleName, $leg->status) && $leg->canMoveToRevision();
    }

    public function canMarkSigned(AdvanceLegalization $leg, int $roleId, string $roleName): bool
    {
        return $this->_canOperate($roleId, $roleName, $leg->status) && $leg->canMarkSigned();
    }

    public function canReturnToValidacion(AdvanceLegalization $leg, int $roleId, string $roleName): bool
    {
        return $this->_canOperate($roleId, $roleName, $leg->status) && $leg->canReturnToValidacion();
    }

    public function canMarkExact(AdvanceLegalization $leg, int $roleId, string $roleName): bool
    {
        return $this->_canOperate($roleId, $roleName, $leg->status) && $leg->canMarkExact();
    }

    public function canRegisterShortage(AdvanceLegalization $leg, int $roleId, string $roleName): bool
    {
        return $this->_canOperate($roleId, $roleName, $leg->status) && $leg->canRegisterShortage();
    }

    public function canRegisterSurplus(AdvanceLegalization $leg, int $roleId, string $roleName): bool
    {
        return $this->_canOperate($roleId, $roleName, $leg->status) && $leg->canRegisterSurplus();
    }

    public function canConfirmShortage(AdvanceLegalization $leg, int $roleId, string $roleName): bool
    {
        return $this->_canOperate($roleId, $roleName, $leg->status) && $leg->canConfirmShortage();
    }

    public function canRegisterRefund(AdvanceLegalization $leg, int $roleId, string $roleName): bool
    {
        return $this->_canOperate($roleId, $roleName, $leg->status) && $leg->canRegisterRefund();
    }

    private function _canOperate(int $roleId, string $roleName, string $step): bool
    {
        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_LEGALIZATIONS,
            $step,
        );
    }
}
```

Key changes:
- New constructor injects `PipelineAuthorizationService`.
- Removed helpers `_isAccountingOrAdmin()` and `_isTreasuryOrAdmin()` (use `RoleConstants` import is no longer needed — remove it).
- All 11 `canXxx()` methods receive an extra `int $roleId` parameter.
- New private helper `_canOperate()` consults the matrix.

**Step 2: Register the dependency in `Application.php`**

In `src/Application.php`, find line 226:

```php
$container->addShared(AdvanceLegalizationActionPolicy::class);
```

Replace with:

```php
$container->addShared(AdvanceLegalizationActionPolicy::class)
    ->addArgument(PipelineAuthorizationService::class);
```

This matches the explicit-DI pattern used by other policies in the same file (e.g. `InvoiceFieldAccessPolicy` at line 201-202).

**Step 3: Verify code style**

Run: `composer cs-check`
Expected: no errors. If style issues appear, run `composer cs-fix`.

**Step 4: Manual validation — DI wires correctly**

Run: `php bin/cake server`
Expected: server starts without errors. Visit any URL that loads `AdvancesController` (e.g. `/advances`) and verify no 500 error related to DI.

Note: at this point the controller still calls the policy with the old 2-arg signature, so the app will fail when reaching the legalization flow. That's fixed in Task 3. Just verify the index page loads (no DI error during boot).

**Step 5: Commit**

```bash
git add src/Service/Pipeline/Policy/AdvanceLegalizationActionPolicy.php src/Application.php
git commit -m "refactor(advances): make legalization policy use pipeline matrix"
```

---

## Task 3: Update controller call sites with `roleId`

**Files:**
- Modify: `src/Controller/AdvancesController.php`

**Step 1: Locate the call sites**

There are 12 call sites (verify with: `grep -n "actionPolicy->can" src/Controller/AdvancesController.php`):

| Line | Method | Endpoint |
|---|---|---|
| ~298 | `canLinkInvoices` | `linkCandidates()` |
| ~368 | `canLinkInvoices` | `linkInvoices()` |
| ~397 | `canUnlinkInvoice` | `unlinkInvoice()` |
| ~421 | `canUploadRelationDocument` | `uploadRelationDocument()` |
| ~471 | `canMoveToRevision` | `moveToRevision()` |
| ~495 | `canMarkSigned` | `markSigned()` |
| ~519 | `canReturnToValidacion` | `returnToValidacion()` |
| ~544 | `canMarkExact` | `markExact()` |
| ~568 | `canRegisterShortage` | `registerShortage()` |
| ~596 | `canConfirmShortage` | `confirmShortage()` |
| ~642 | `canRegisterSurplus` | `registerSurplus()` |
| ~670 | `canRegisterRefund` | `registerRefund()` |

**Step 2: Add `roleId` argument to each call**

For each call site, change:

```php
if (!$this->actionPolicy->canXxx($leg, $roleName)) {
```

to:

```php
if (!$this->actionPolicy->canXxx($leg, (int)$this->_getCurrentUser()->id, $roleName)) {
```

Use Edit tool — there are two distinct patterns. The simple one (most cases):

```
Old: $this->actionPolicy->canLinkInvoices($leg, $roleName)
New: $this->actionPolicy->canLinkInvoices($leg, (int)$this->_getCurrentUser()->id, $roleName)
```

Note: `canLinkInvoices` appears twice (lines ~298 and ~368) — use `replace_all: true` for that one method, or edit each occurrence individually.

**Step 3: Verify all 12 call sites updated**

Run: `grep -n "actionPolicy->can" src/Controller/AdvancesController.php`
Expected: every line includes `_getCurrentUser()->id` between `$leg` and `$roleName`. None should have the old 2-argument signature.

**Step 4: Verify code style**

Run: `composer cs-check`
Expected: no errors. If style issues appear, run `composer cs-fix`.

**Step 5: Manual validation — flow blocks actions when matrix is empty**

Pre-condition: no rows in `pipeline_permissions` for `pipeline = 'legalizations'` (default state after Task 1, before manual configuration).

Run: `php bin/cake server`

Log in as **Administrador**. Navigate to an advance whose legalization is in `validacion` status (e.g. an advance that has been paid and triggered `LegalizationInitializerSubscriber`).

Visit `/advances/legalization/{id}`.

Expected:
- The page loads (read access controlled by `advances.view`, unchanged).
- Action buttons are visible (UI doesn't yet check pipeline permissions).
- Clicking any action button (e.g. "Vincular facturas", "Marcar firmado") triggers a flash error: **"No tienes permiso para esta acción en el estado actual."**

This confirms the matrix is being consulted and correctly returns `false` for an empty matrix.

**Step 6: Commit**

```bash
git add src/Controller/AdvancesController.php
git commit -m "refactor(advances): pass roleId to legalization action policy"
```

---

## Task 4: Manual configuration and end-to-end validation

This task is **operational, not code**. It must be performed in the deployed environment (or local dev DB) after merge.

**Step 1: Configure Administrador**

Visit `/roles/edit/{id_administrador}`. In the "Legalizaciones" section, mark all 5 checkboxes:
- [x] Validación
- [x] Revisión y Firmas
- [x] Contabilidad
- [x] Tesorería
- [x] Autorización de pago

Save.

**Step 2: Configure Contabilidad**

Visit `/roles/edit/{id_contabilidad}`. In "Legalizaciones", mark:
- [x] Validación
- [x] Revisión y Firmas
- [x] Contabilidad
- [ ] Tesorería
- [ ] Autorización de pago

Save.

**Step 3: Configure Tesorería**

Visit `/roles/edit/{id_tesoreria}`. In "Legalizaciones", mark:
- [ ] Validación
- [ ] Revisión y Firmas
- [ ] Contabilidad
- [x] Tesorería
- [ ] Autorización de pago

Save.

**Step 4: Validation as Administrador**

Pre-condition: an advance in `validacion`, another in `tesoreria` (e.g. one with shortage declared).

Log in as **Administrador**.

Verify successful operations on a legalization in `validacion`:
- Link an invoice via "Vincular facturas" modal → flash success.
- Upload relation document → flash success.
- Mark exact (`markExact`) on an unlinked legalization → status moves to `legalizada`.

Verify successful operations on a legalization in `tesoreria`:
- `confirmShortage` → flash success.
- `registerRefund` → flash success.

**Step 5: Validation as Contabilidad**

Log in as **Contabilidad**.

On a legalization in `validacion`: all link/upload/markExact/registerShortage/registerSurplus actions succeed.

On a legalization in `tesoreria`: clicking `confirmShortage` or `registerRefund` triggers flash error **"No tienes permiso para esta acción en el estado actual."**

**Step 6: Validation as Tesorería**

Log in as **Tesorería**.

On a legalization in `tesoreria`: `confirmShortage` and `registerRefund` succeed.

On a legalization in `validacion`: clicking any link/sign/exact/shortage/surplus action triggers the same flash error.

**Step 7: Validation as a role with empty matrix**

Pick a role that has `advances.view` but no `legalizations` checkboxes (e.g. Registro/Revisión, if applicable).

Log in as that role. Visit `/advances/legalization/{id}` — page loads. Click any action — flash error.

**No commit for this task** (configuration changes, not code).

---

## Rollback strategy

If anything misbehaves post-deploy:

1. Revert the three commits in reverse order:
   ```bash
   git revert <task3-commit> <task2-commit> <task1-commit>
   ```
2. The `pipeline_permissions` rows for `pipeline = 'legalizations'` can stay in the DB — they become inert (no code reads them). Optionally clean up:
   ```sql
   DELETE FROM pipeline_permissions WHERE pipeline = 'legalizations';
   ```

---

## Files touched (summary)

- **Modified:** `src/Constants/PipelineStepConstants.php` (Task 1)
- **Modified:** `src/Service/Pipeline/Policy/AdvanceLegalizationActionPolicy.php` (Task 2)
- **Modified:** `src/Application.php` (Task 2)
- **Modified:** `src/Controller/AdvancesController.php` (Task 3)
- **Database:** rows added to `pipeline_permissions` via UI (Task 4 — manual)

No new files. No migrations. No template changes. No test files (per project policy).
