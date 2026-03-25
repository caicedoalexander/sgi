# Novelty Pipeline Views — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replicate the invoice pipeline view pattern (Mis/Todas/Rechazadas) in the novelties module with role-based field editing and section visibility.

**Architecture:** Add 4 new role constants, extend NoveltyPipelineService with ROLE_VISIBLE_STATUSES/EDITABLE_FIELDS/VISIBLE_SECTIONS matrices, refactor the controller to filter by visible statuses instead of subordinates, and update the index template for 3 dynamic views.

**Tech Stack:** CakePHP 5.3, PHP 8.2+, existing service/controller patterns from InvoicePipelineService.

---

### Task 1: Add New Role Constants

**Files:**
- Modify: `src/Constants/RoleConstants.php`

**Step 1: Add the 4 new role constants**

```php
final class RoleConstants
{
    public const ADMIN = 'Administrador';
    public const REGISTRO_REVISION = 'Registro/Revisión';
    public const CONTABILIDAD = 'Contabilidad';
    public const TESORERIA = 'Tesorería';
    public const AUXILIAR_PERSONAL = 'Auxiliar de Personal';
    public const ASISTENTE_PERSONAL = 'Asistente de Personal';
    public const CONTADOR = 'Contador';
    public const COORDINADOR_ADMIN = 'Coordinador Administrativo y Financiero';
}
```

**Step 2: Commit**

```bash
git add src/Constants/RoleConstants.php
git commit -m "feat: add 4 new role constants for novelty pipeline"
```

---

### Task 2: Add Role-Based Visibility and Editability to NoveltyPipelineService

**Files:**
- Modify: `src/Service/NoveltyPipelineService.php`

**Step 1: Add imports and constants at the top of the class (after line 11)**

Add `use App\Constants\RoleConstants;` to the imports (after the existing use statements at line 8).

Add these constants inside the class, before the `getNextStatus` method:

```php
    // Which statuses each role can see/work with in "Mis Novedades"
    private const ROLE_VISIBLE_STATUSES = [
        RoleConstants::AUXILIAR_PERSONAL  => [
            NoveltyConstants::STATUS_APROBACION,
            NoveltyConstants::STATUS_RRHH,
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
        ],
        RoleConstants::ASISTENTE_PERSONAL => [
            NoveltyConstants::STATUS_APROBACION,
            NoveltyConstants::STATUS_RRHH,
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
        ],
        RoleConstants::CONTABILIDAD       => [NoveltyConstants::STATUS_CONTABILIDAD],
        RoleConstants::CONTADOR           => [NoveltyConstants::STATUS_REVISION_FIRMAS],
        RoleConstants::COORDINADOR_ADMIN  => [NoveltyConstants::STATUS_REVISION_FIRMAS],
        RoleConstants::TESORERIA          => [NoveltyConstants::STATUS_TESORERIA],
        RoleConstants::ADMIN              => NoveltyConstants::PIPELINE_STATUSES,
    ];

    // All novelty fields (for Admin)
    private const ALL_FIELDS = [
        'approver_id', 'area_approval', 'passes_payroll',
        'rrhh_by', 'liquidation_doc_id',
    ];

    // Fields editable by role in each status
    private const EDITABLE_FIELDS = [
        RoleConstants::AUXILIAR_PERSONAL => [
            NoveltyConstants::STATUS_APROBACION => ['approver_id'],
            NoveltyConstants::STATUS_RRHH => ['passes_payroll'],
        ],
        RoleConstants::ASISTENTE_PERSONAL => [
            NoveltyConstants::STATUS_APROBACION => ['approver_id'],
            NoveltyConstants::STATUS_RRHH => ['passes_payroll'],
        ],
        RoleConstants::CONTABILIDAD => [
            NoveltyConstants::STATUS_CONTABILIDAD => ['liquidation_doc_id'],
        ],
    ];

    // Sections visible per role (non-Admin roles have fixed sections)
    private const VISIBLE_SECTIONS_BY_ROLE = [
        RoleConstants::AUXILIAR_PERSONAL  => ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'firmas'],
        RoleConstants::ASISTENTE_PERSONAL => ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'firmas'],
        RoleConstants::CONTABILIDAD       => ['informacion', 'fechas', 'contabilidad'],
        RoleConstants::CONTADOR           => ['informacion', 'fechas', 'firmas'],
        RoleConstants::COORDINADOR_ADMIN  => ['informacion', 'fechas', 'firmas'],
        RoleConstants::TESORERIA          => ['informacion'],
    ];

    // All sections in pipeline order (for Admin progressive display)
    private const ALL_SECTIONS = ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas'];

    // Map pipeline statuses to which sections are visible up to that point (for Admin)
    private const SECTIONS_BY_STATUS = [
        NoveltyConstants::STATUS_APROBACION     => ['informacion', 'fechas', 'motivo', 'aprobacion', 'firmas'],
        NoveltyConstants::STATUS_RRHH           => ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'firmas'],
        NoveltyConstants::STATUS_CONTABILIDAD   => ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas'],
        NoveltyConstants::STATUS_REVISION_FIRMAS => ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas'],
        NoveltyConstants::STATUS_GDP            => ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas'],
        NoveltyConstants::STATUS_TESORERIA      => ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas'],
        NoveltyConstants::STATUS_PAGADA         => ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas'],
    ];
```

**Step 2: Add new public methods at the end of the class (before the closing `}`)**

```php
    /**
     * Get the statuses visible to a role in "Mis Novedades".
     */
    public function getVisibleStatuses(string $roleName): array
    {
        return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
    }

    /**
     * Get editable fields for a role in a given status.
     */
    public function getEditableFields(string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::ALL_FIELDS;
        }

        return self::EDITABLE_FIELDS[$roleName][$status] ?? [];
    }

    /**
     * Get visible sections for a role in a given status.
     */
    public function getVisibleSections(string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::SECTIONS_BY_STATUS[$status] ?? self::ALL_SECTIONS;
        }

        return self::VISIBLE_SECTIONS_BY_ROLE[$roleName] ?? [];
    }

    /**
     * Check if a role can advance from a given status.
     */
    public function canAdvanceFromStatus(string $roleName, string $status): bool
    {
        $visible = self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];

        return in_array($status, $visible, true);
    }

    /**
     * Filter entity data to only allowed fields for the role/status.
     */
    public function filterEntityData(array $data, string $roleName, string $status): array
    {
        $allowed = $this->getEditableFields($roleName, $status);

        return array_intersect_key($data, array_flip($allowed));
    }
```

**Step 3: Commit**

```bash
git add src/Service/NoveltyPipelineService.php
git commit -m "feat: add role-based visibility, editable fields, and sections to NoveltyPipelineService"
```

---

### Task 3: Add Routes for all() and rejected()

**Files:**
- Modify: `config/routes.php`

**Step 1: Add two routes before the existing employee-novelties routes (before line 122)**

```php
        // Employee novelties views
        $builder->connect(
            '/employee-novelties/all',
            ['controller' => 'EmployeeNovelties', 'action' => 'all']
        );
        $builder->connect(
            '/employee-novelties/rejected',
            ['controller' => 'EmployeeNovelties', 'action' => 'rejected']
        );
```

**Step 2: Commit**

```bash
git add config/routes.php
git commit -m "feat: add routes for employee-novelties/all and employee-novelties/rejected"
```

---

### Task 4: Refactor EmployeeNoveltiesController — index(), all(), rejected()

**Files:**
- Modify: `src/Controller/EmployeeNoveltiesController.php`

**Step 1: Replace the index() method (lines 50-85) with the new role-filtered version**

```php
    /**
     * Index — "Mis Novedades" filtered by role's visible statuses.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function index()
    {
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $roleName = $this->_getUserRoleName($user);
        $visibleStatuses = $this->pipelineService->getVisibleStatuses($roleName);

        $conditions = [];
        if (!empty($visibleStatuses)) {
            $conditions['EmployeeNovelties.pipeline_status IN'] = $visibleStatuses;
        }
        // Exclude rejected from "Mis Novedades"
        $conditions['EmployeeNovelties.pipeline_status !='] = NoveltyConstants::STATUS_RECHAZADA;

        $query = $this->EmployeeNovelties->find()
            ->contain(['Employees', 'NoveltyTypes', 'RegisteredByUsers'])
            ->where($conditions)
            ->order(['EmployeeNovelties.created' => 'DESC']);

        $statusFilter = $this->request->getQuery('pipeline_status');
        if ($statusFilter) {
            $query->where(['EmployeeNovelties.pipeline_status' => $statusFilter]);
        }

        $typeFilter = $this->request->getQuery('novelty_type_id');
        if ($typeFilter) {
            $query->where(['EmployeeNovelties.novelty_type_id' => $typeFilter]);
        }

        $novelties = $this->paginate($query);

        $noveltyTypes = $this->EmployeeNovelties->NoveltyTypes->find('list')
            ->order(['name' => 'ASC'])
            ->toArray();

        $this->set(compact('novelties', 'statusFilter', 'typeFilter', 'noveltyTypes', 'visibleStatuses'));
    }
```

**Step 2: Add all() method right after index()**

```php
    /**
     * All — "Todas las Novedades" (read-only, all statuses).
     *
     * @return \Cake\Http\Response|null|void
     */
    public function all()
    {
        $query = $this->EmployeeNovelties->find()
            ->contain(['Employees', 'NoveltyTypes', 'RegisteredByUsers'])
            ->order(['EmployeeNovelties.created' => 'DESC']);

        $statusFilter = $this->request->getQuery('pipeline_status');
        if ($statusFilter) {
            $query->where(['EmployeeNovelties.pipeline_status' => $statusFilter]);
        }

        $typeFilter = $this->request->getQuery('novelty_type_id');
        if ($typeFilter) {
            $query->where(['EmployeeNovelties.novelty_type_id' => $typeFilter]);
        }

        $novelties = $this->paginate($query);

        $noveltyTypes = $this->EmployeeNovelties->NoveltyTypes->find('list')
            ->order(['name' => 'ASC'])
            ->toArray();

        $visibleStatuses = [];

        $this->set(compact('novelties', 'statusFilter', 'typeFilter', 'noveltyTypes', 'visibleStatuses'));
        $this->render('index');
    }
```

**Step 3: Add rejected() method right after all()**

```php
    /**
     * Rejected — "Novedades Rechazadas".
     *
     * @return \Cake\Http\Response|null|void
     */
    public function rejected()
    {
        $query = $this->EmployeeNovelties->find()
            ->contain(['Employees', 'NoveltyTypes', 'RegisteredByUsers'])
            ->where(['EmployeeNovelties.pipeline_status' => NoveltyConstants::STATUS_RECHAZADA])
            ->order(['EmployeeNovelties.created' => 'DESC']);

        $typeFilter = $this->request->getQuery('novelty_type_id');
        if ($typeFilter) {
            $query->where(['EmployeeNovelties.novelty_type_id' => $typeFilter]);
        }

        $novelties = $this->paginate($query);

        $noveltyTypes = $this->EmployeeNovelties->NoveltyTypes->find('list')
            ->order(['name' => 'ASC'])
            ->toArray();

        $statusFilter = null;
        $visibleStatuses = [];

        $this->set(compact('novelties', 'statusFilter', 'typeFilter', 'noveltyTypes', 'visibleStatuses'));
        $this->render('index');
    }
```

**Step 4: Remove the `_getSubordinateEmployeeIds()` private method (lines 647-665) and the `EmployeeStatusConstants` import (line 6) if no longer used elsewhere in the controller**

Check first: grep the controller for other uses of `EmployeeStatusConstants` and `_getSubordinateEmployeeIds`. If only used in the old index(), remove both.

**Step 5: Commit**

```bash
git add src/Controller/EmployeeNoveltiesController.php
git commit -m "feat: refactor novelties index to role-based views (Mis/Todas/Rechazadas)"
```

---

### Task 5: Update the index.php Template

**Files:**
- Modify: `templates/EmployeeNovelties/index.php`

**Step 1: Replace the entire template with the dynamic version**

Key changes:
- Dynamic title based on `$this->request->getParam('action')`
- Navigation buttons for the 3 views
- Clickable rows go to `edit` (index) or `view` (all/rejected)
- Rejected rows get `table-danger` class
- Filter "Limpiar" link uses current action, not hardcoded `index`

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\EmployeeNovelty> $novelties
 * @var string|null $statusFilter
 * @var string|null $typeFilter
 * @var array $noveltyTypes
 * @var array $visibleStatuses
 */
use App\Constants\NoveltyConstants;

$action = $this->request->getParam('action');

$pageTitles = [
    'all' => 'Todas las Novedades',
    'rejected' => 'Novedades Rechazadas',
];
$pageTitle = $pageTitles[$action] ?? 'Mis Novedades';
$this->assign('title', $pageTitle);

$linkAction = ($action === 'index') ? 'edit' : 'view';

$statusBadges = [
    'aprobacion' => 'bg-warning text-dark',
    'rrhh' => 'bg-info text-dark',
    'contabilidad' => 'bg-primary',
    'revision_firmas' => 'bg-warning text-dark',
    'gdp' => 'bg-dark',
    'tesoreria' => 'bg-info',
    'pagada' => 'bg-success',
    'rechazada' => 'bg-danger',
];
$scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;
$statusLabels = NoveltyConstants::STATUS_LABELS;
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title"><?= $pageTitle ?></span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['employee_novelties']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1"></i>Nueva Novedad',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- View navigation -->
<div class="d-flex gap-2 mb-3">
    <?= $this->Html->link('Mis Novedades', ['action' => 'index'],
        ['class' => 'btn btn-sm ' . ($action === 'index' ? 'btn-dark' : 'btn-outline-dark')]) ?>
    <?= $this->Html->link('Todas las Novedades', ['action' => 'all'],
        ['class' => 'btn btn-sm ' . ($action === 'all' ? 'btn-dark' : 'btn-outline-dark')]) ?>
    <?= $this->Html->link('Rechazadas', ['action' => 'rejected'],
        ['class' => 'btn btn-sm ' . ($action === 'rejected' ? 'btn-danger' : 'btn-outline-danger')]) ?>
</div>

<!-- Filters -->
<div class="card card-primary mb-3">
    <div class="card-body py-2 px-3">
        <form method="get" class="d-flex gap-3 align-items-center flex-wrap">
            <?php if ($action !== 'rejected'): ?>
            <select name="pipeline_status" class="form-select form-select-sm" style="max-width:200px;" onchange="this.form.submit()">
                <option value="">Estado: Todos</option>
                <?php foreach (NoveltyConstants::ALL_STATUSES as $s): ?>
                <option value="<?= $s ?>" <?= ($statusFilter ?? '') === $s ? 'selected' : '' ?>><?= $statusLabels[$s] ?? ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="novelty_type_id" class="form-select form-select-sm" style="max-width:200px;" onchange="this.form.submit()">
                <option value="">Tipo: Todos</option>
                <?php foreach ($noveltyTypes as $id => $name): ?>
                <option value="<?= $id ?>" <?= ($typeFilter ?? '') == $id ? 'selected' : '' ?>><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($statusFilter || $typeFilter): ?>
            <a href="<?= $this->Url->build(['action' => $action]) ?>" class="btn btn-sm btn-outline-secondary">Limpiar</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Empleado</th>
                    <th>Tipo de Novedad</th>
                    <th>Fecha Permiso</th>
                    <th>Horario</th>
                    <th>Remunerado</th>
                    <th>Estado</th>
                    <th>Registrado por</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($novelties as $novelty): ?>
                <tr class="clickable-row <?= $novelty->isRejected() ? 'table-danger' : '' ?>"
                    data-href="<?= $this->Url->build(['action' => $linkAction, $novelty->id]) ?>">
                    <td><?= h($novelty->custom_name ?: $novelty->employee->full_name ?? '—') ?></td>
                    <td><?= h($novelty->novelty_type->name ?? '—') ?></td>
                    <td><?= $novelty->permission_date?->format('d/m/Y') ?: '—' ?></td>
                    <td><?= $scheduleLabels[$novelty->schedule_type] ?? '—' ?></td>
                    <td>
                        <?php if ($novelty->is_paid): ?>
                            <span class="badge bg-success">Sí</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">No</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $statusBadges[$novelty->pipeline_status] ?? 'bg-secondary' ?>"><?= $statusLabels[$novelty->pipeline_status] ?? ucfirst(h($novelty->pipeline_status)) ?></span></td>
                    <td style="font-size:.8125rem;color:#888"><?= h($novelty->registered_by_user->full_name ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= $this->element('pagination') ?>
</div>
```

**Step 2: Commit**

```bash
git add templates/EmployeeNovelties/index.php
git commit -m "feat: update novelties index template with dynamic views and navigation"
```

---

### Task 6: Update edit() to Pass Role-Based Data to Template

**Files:**
- Modify: `src/Controller/EmployeeNoveltiesController.php`

**Step 1: In the edit() method, after getting `$user` (line 113), add role-based variables**

After line 113 (`$user = $this->Authentication->getIdentity()->getOriginalData();`), add:

```php
        $roleName = $this->_getUserRoleName($user);
        $editableFields = $this->pipelineService->getEditableFields($roleName, $novelty->pipeline_status);
        $visibleSections = $this->pipelineService->getVisibleSections($roleName, $novelty->pipeline_status);
```

**Step 2: Add `roleName`, `editableFields`, `visibleSections` to the `set(compact(...))` call (around line 158)**

Update the compact call to include the new variables:

```php
        $this->set(compact(
            'novelty',
            'effectiveStatuses',
            'nextStatus',
            'transitionErrors',
            'canAdvance',
            'isApprovalRejected',
            'approversList',
            'documentsByStatus',
            'liquidationDocs',
            'roleName',
            'editableFields',
            'visibleSections',
        ));
```

**Step 3: Commit**

```bash
git add src/Controller/EmployeeNoveltiesController.php
git commit -m "feat: pass role-based editableFields and visibleSections to edit template"
```

---

### Task 7: Update edit.php Template to Use visibleSections

**Files:**
- Modify: `templates/EmployeeNovelties/edit.php`

**Step 1: Add section visibility check variable at the top of the template (after line 22)**

After `$currentStatus = $novelty->pipeline_status;`, add:

```php
$sections = $visibleSections ?? ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas'];
```

**Step 2: Wrap each section in `if (in_array('sectionName', $sections))` checks**

- Wrap "Información de la Novedad" section (lines 147-184) with: `<?php if (in_array('informacion', $sections)): ?>` ... `<?php endif; ?>`
- Wrap "Fechas y Horario" section (lines 186-241) with: `<?php if (in_array('fechas', $sections)): ?>` ... `<?php endif; ?>`
- Wrap "Motivo" section (lines 243-257) with: `<?php if (in_array('motivo', $sections)): ?>` ... `<?php endif; ?>`
- Wrap "Firmas" section (lines 308-328) with: `<?php if (in_array('firmas', $sections)): ?>` ... `<?php endif; ?>`
- Wrap "Aprobación" section (lines 330-355) with: `<?php if (in_array('aprobacion', $sections)): ?>` ... `<?php endif; ?>`
- Wrap "Gestión/RRHH" section (lines 259-306) with: `<?php if (in_array('rrhh', $sections)): ?>` ... `<?php endif; ?>`
- Wrap "Asignar a Documento de Liquidación" section (lines 380-423) with: `<?php if (in_array('contabilidad', $sections)): ?>` ... `<?php endif; ?>`

**Step 3: Commit**

```bash
git add templates/EmployeeNovelties/edit.php
git commit -m "feat: conditionally show edit sections based on role visibility"
```

---

### Task 8: Verify and Test

**Step 1: Run code style check**

```bash
composer cs-check
```

Fix any issues with `composer cs-fix` if needed.

**Step 2: Run tests**

```bash
composer test
```

**Step 3: Manual verification**

- Start dev server: `php bin/cake server`
- Log in as Admin — should see all novelties in "Mis Novedades", all in "Todas"
- Log in as a user with "Auxiliar de Personal" role — should only see aprobacion, rrhh, revision_firmas, gdp states in "Mis Novedades"
- Log in as Contabilidad — should only see contabilidad state
- Log in as Tesorería — should only see tesoreria state
- Check "Todas las Novedades" — should show all, rows link to view (read-only)
- Check "Rechazadas" — should show only rejected, rows link to view
- Check edit form shows correct sections per role

**Step 4: Final commit**

```bash
git add -A
git commit -m "feat: complete novelty pipeline role-based views implementation"
```
