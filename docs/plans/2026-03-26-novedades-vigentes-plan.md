# Novedades Vigentes Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a "Vigentes" calendar view to the novelties sidebar showing currently active novelties (approved and within their date range) using FullCalendar.

**Architecture:** New `active()` action renders a FullCalendar month view. A separate `activeEvents()` JSON endpoint serves event data filtered by date range. The sidebar gets a new "Vigentes" link with a green badge counter computed in `AppController::_setSidebarCounters()`.

**Tech Stack:** CakePHP 5.3, FullCalendar 6.x (CDN), Select2 (already loaded), Bootstrap 5.

---

### Task 1: Add Routes

**Files:**
- Modify: `config/routes.php:122-131`

**Step 1: Add the two new routes before fallbacks**

Insert after the existing `/employee-novelties/rejected` route (line 130), before the advance route (line 133):

```php
        // Employee novelties active/calendar view
        $builder->connect(
            '/employee-novelties/active',
            ['controller' => 'EmployeeNovelties', 'action' => 'active']
        );
        $builder->connect(
            '/employee-novelties/active-events',
            ['controller' => 'EmployeeNovelties', 'action' => 'activeEvents']
        );
```

**Step 2: Register `active` and `activeEvents` as view-permission actions**

In `src/Controller/AppController.php:64`, add `'active'` and `'activeEvents'` to the view match arm:

```php
'index', 'view', 'export', 'all', 'rejected', 'exportPdf', 'preview', 'active', 'activeEvents' => 'view',
```

**Step 3: Verify** — Run `php bin/cake routes | grep active` and confirm both routes appear.

**Step 4: Commit**

```bash
git add config/routes.php src/Controller/AppController.php
git commit -m "feat: add routes for novedades vigentes calendar view"
```

---

### Task 2: Add Active Novelties Constants

**Files:**
- Modify: `src/Constants/NoveltyConstants.php`

**Step 1: Add the ACTIVE_STATUSES constant**

After `NOVELTY_STATUSES` (line 37), add:

```php
    // Statuses considered "active" (approved and processed)
    public const ACTIVE_STATUSES = [
        self::STATUS_RRHH,
        self::STATUS_CONTABILIDAD,
        self::STATUS_REVISION_FIRMAS,
        self::STATUS_GDP,
        self::STATUS_TESORERIA,
        self::STATUS_PAGADA,
    ];
```

**Step 2: Add a color palette for calendar events**

At the end of the class, before the closing `}`:

```php
    // Calendar event colors by novelty type ID (cycles for IDs > count)
    public const CALENDAR_COLORS = [
        '#469D61', // green
        '#CD6A15', // orange
        '#3B82F6', // blue
        '#8B5CF6', // purple
        '#EF4444', // red
        '#F59E0B', // amber
        '#06B6D4', // cyan
        '#EC4899', // pink
        '#10B981', // emerald
        '#6366F1', // indigo
    ];
```

**Step 3: Commit**

```bash
git add src/Constants/NoveltyConstants.php
git commit -m "feat: add ACTIVE_STATUSES and CALENDAR_COLORS constants"
```

---

### Task 3: Add Controller Actions (`active` and `activeEvents`)

**Files:**
- Modify: `src/Controller/EmployeeNoveltiesController.php`

**Step 1: Add the `active()` action**

Add this method to the controller class (after the `rejected()` method):

```php
    /**
     * Active — Calendar view of currently active novelties.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function active()
    {
        $noveltyTypes = $this->EmployeeNovelties->NoveltyTypes->find('list', [
            'keyField' => 'id',
            'valueField' => 'name',
        ])->toArray();

        $employees = $this->EmployeeNovelties->Employees->find('list', [
            'keyField' => 'id',
            'valueField' => 'full_name',
        ])->order(['full_name' => 'ASC'])->toArray();

        $this->set(compact('noveltyTypes', 'employees'));
    }
```

**Step 2: Add the `activeEvents()` JSON endpoint**

```php
    /**
     * ActiveEvents — JSON endpoint for FullCalendar events.
     *
     * @return \Cake\Http\Response
     */
    public function activeEvents(): Response
    {
        $this->request->allowMethod(['get']);

        $start = $this->request->getQuery('start');
        $end = $this->request->getQuery('end');

        if (!$start || !$end) {
            return $this->response->withType('application/json')
                ->withStringBody(json_encode([]));
        }

        $conditions = [
            'EmployeeNovelties.pipeline_status IN' => NoveltyConstants::ACTIVE_STATUSES,
        ];

        // Date range overlap: novelty period overlaps with calendar visible range
        $conditions[] = function ($exp) use ($start, $end) {
            return $exp->or([
                // days: start_date..end_date overlaps with calendar range
                $exp->and([
                    'EmployeeNovelties.schedule_type' => NoveltyConstants::SCHEDULE_DAYS,
                    'EmployeeNovelties.start_date <=' => $end,
                    'EmployeeNovelties.end_date >=' => $start,
                ]),
                // hours: permission_date falls within calendar range
                $exp->and([
                    'EmployeeNovelties.schedule_type' => NoveltyConstants::SCHEDULE_HOURS,
                    'EmployeeNovelties.permission_date >=' => $start,
                    'EmployeeNovelties.permission_date <=' => $end,
                ]),
            ]);
        };

        // Optional filters
        $typeFilter = $this->request->getQuery('novelty_type_id');
        if ($typeFilter) {
            $conditions['EmployeeNovelties.novelty_type_id'] = $typeFilter;
        }

        $employeeFilter = $this->request->getQuery('employee_id');
        if ($employeeFilter) {
            $conditions['EmployeeNovelties.employee_id'] = $employeeFilter;
        }

        $novelties = $this->EmployeeNovelties->find()
            ->contain(['Employees', 'NoveltyTypes'])
            ->where($conditions)
            ->all();

        $colors = NoveltyConstants::CALENDAR_COLORS;
        $colorCount = count($colors);

        $events = [];
        foreach ($novelties as $novelty) {
            $employeeName = $novelty->employee ? $novelty->employee->full_name : ($novelty->custom_name ?? 'Sin empleado');
            $typeName = $novelty->novelty_type ? $novelty->novelty_type->name : 'Sin tipo';
            $color = $colors[($novelty->novelty_type_id - 1) % $colorCount];

            if ($novelty->schedule_type === NoveltyConstants::SCHEDULE_DAYS) {
                $eventStart = $novelty->start_date->format('Y-m-d');
                // FullCalendar end is exclusive, so add 1 day
                $eventEnd = $novelty->end_date->modify('+1 day')->format('Y-m-d');
            } else {
                $eventStart = $novelty->permission_date->format('Y-m-d');
                $eventEnd = $eventStart;
            }

            $events[] = [
                'id' => $novelty->id,
                'title' => $employeeName . ' - ' . $typeName,
                'start' => $eventStart,
                'end' => $eventEnd,
                'color' => $color,
                'url' => $this->Url ?? '/employee-novelties/view/' . $novelty->id,
            ];
        }

        return $this->response->withType('application/json')
            ->withStringBody(json_encode($events));
    }
```

**Important note:** The URL generation needs to use the Router. Replace the URL line with:

```php
'url' => \Cake\Routing\Router::url(['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id]),
```

**Step 3: Verify** — Start dev server and hit `GET /employee-novelties/active-events?start=2026-03-01&end=2026-03-31`. Should return JSON (possibly empty array if no matching data).

**Step 4: Commit**

```bash
git add src/Controller/EmployeeNoveltiesController.php
git commit -m "feat: add active() and activeEvents() controller actions"
```

---

### Task 4: Add Sidebar Counter in AppController

**Files:**
- Modify: `src/Controller/AppController.php:189-207`

**Step 1: Add activeNoveltiesCount calculation**

In `_setSidebarCounters()`, after the existing `noveltiesCount` calculation (after line 207), add:

```php
            // Active novelties count (vigentes hoy)
            $today = date('Y-m-d');
            $activeNoveltiesCount = $noveltiesTable->find()
                ->where([
                    'pipeline_status IN' => NoveltyConstants::ACTIVE_STATUSES,
                ])
                ->where(function ($exp) use ($today) {
                    return $exp->or([
                        $exp->and([
                            'schedule_type' => NoveltyConstants::SCHEDULE_DAYS,
                            'start_date <=' => $today,
                            'end_date >=' => $today,
                        ]),
                        $exp->and([
                            'schedule_type' => NoveltyConstants::SCHEDULE_HOURS,
                            'permission_date' => $today,
                        ]),
                    ]);
                })
                ->count();
            $this->set('activeNoveltiesCount', $activeNoveltiesCount);
```

**Step 2: Add default value `0` in the catch block (line ~216)**

Add to the catch block alongside the other zero defaults:

```php
$this->set('activeNoveltiesCount', 0);
```

**Step 3: Commit**

```bash
git add src/Controller/AppController.php
git commit -m "feat: add activeNoveltiesCount sidebar counter"
```

---

### Task 5: Add Sidebar Link

**Files:**
- Modify: `templates/layout/default.php:224-231`

**Step 1: Add "Vigentes" submenu item**

After the "Rechazadas" `</li>` (line 230) and before the closing `</ul>` (line 231), add:

```php
                            <li class="nav-item">
                                <?= $this->Html->link(
                                    '<i class="bi bi-calendar-check me-2"></i>Vigentes' .
                                    ($activeNoveltiesCount > 0 ? ' <span class="badge bg-success sidebar-badge ms-auto">' . $activeNoveltiesCount . '</span>' : ''),
                                    ['controller' => 'EmployeeNovelties', 'action' => 'active'],
                                    ['class' => $navLink('EmployeeNovelties', 'active') . ' d-flex align-items-center', 'escape' => false]
                                ) ?>
                            </li>
```

**Step 2: Verify** — Load any page in the app. The sidebar should now show "Vigentes" under "Rechazadas" with a green badge (if there are active novelties) or no badge (if count is 0).

**Step 3: Commit**

```bash
git add templates/layout/default.php
git commit -m "feat: add Vigentes link with badge to sidebar"
```

---

### Task 6: Create Calendar Template

**Files:**
- Create: `templates/EmployeeNovelties/active.php`

**Step 1: Create the template file**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var array $noveltyTypes
 * @var array $employees
 */
$this->assign('title', 'Novedades Vigentes');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Novedades Vigentes</span>
</div>

<!-- Filters -->
<div class="card border-0 shadow-none mb-3" style="border-top: 2px solid #469D61 !important;">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Tipo de Novedad</label>
                <select id="filter-novelty-type" class="form-select select2" data-placeholder="Todos los tipos">
                    <option value="">Todos los tipos</option>
                    <?php foreach ($noveltyTypes as $id => $name): ?>
                        <option value="<?= $id ?>"><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Empleado</label>
                <select id="filter-employee" class="form-select select2" data-placeholder="Todos los empleados">
                    <option value="">Todos los empleados</option>
                    <?php foreach ($employees as $id => $name): ?>
                        <option value="<?= $id ?>"><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="button" id="btn-clear-filters" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-circle me-1"></i>Limpiar filtros
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Calendar -->
<div class="card border-0 shadow-none" style="border-top: 2px solid #212529 !important;">
    <div class="card-body">
        <div id="calendar"></div>
    </div>
</div>

<!-- FullCalendar CDN -->
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('calendar');
    const filterType = document.getElementById('filter-novelty-type');
    const filterEmployee = document.getElementById('filter-employee');
    const btnClear = document.getElementById('btn-clear-filters');

    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        locale: 'es',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: ''
        },
        buttonText: {
            today: 'Hoy'
        },
        height: 'auto',
        events: function(info, successCallback, failureCallback) {
            const params = new URLSearchParams({
                start: info.startStr,
                end: info.endStr
            });

            if (filterType.value) {
                params.append('novelty_type_id', filterType.value);
            }
            if (filterEmployee.value) {
                params.append('employee_id', filterEmployee.value);
            }

            fetch('/employee-novelties/active-events?' + params.toString())
                .then(response => response.json())
                .then(data => successCallback(data))
                .catch(error => failureCallback(error));
        },
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            if (info.event.url) {
                window.location.href = info.event.url;
            }
        },
        eventDisplay: 'block',
        displayEventTime: false
    });

    calendar.render();

    // Filter change handlers
    filterType.addEventListener('change', function() {
        calendar.refetchEvents();
    });
    filterEmployee.addEventListener('change', function() {
        calendar.refetchEvents();
    });

    // Clear filters
    btnClear.addEventListener('click', function() {
        $(filterType).val('').trigger('change');
        $(filterEmployee).val('').trigger('change');
        calendar.refetchEvents();
    });
});
</script>
```

**Step 2: Verify** — Navigate to `/employee-novelties/active`. The calendar should render with navigation controls. Filters should appear above the calendar with Select2 styling. Changing filters should reload events.

**Step 3: Commit**

```bash
git add templates/EmployeeNovelties/active.php
git commit -m "feat: add FullCalendar template for novedades vigentes"
```

---

### Task 7: Manual Verification & Fixes

**Step 1:** Navigate to `/employee-novelties/active` and verify:
- [ ] Calendar renders correctly in Spanish
- [ ] Prev/Next navigation works
- [ ] "Hoy" button works
- [ ] Filters render as Select2 dropdowns
- [ ] Changing filter reloads events
- [ ] "Limpiar filtros" resets both selects

**Step 2:** If there are active novelties in the DB, verify:
- [ ] Events appear as colored bars
- [ ] Multi-day novelties span correctly
- [ ] Clicking an event navigates to the view page
- [ ] Different novelty types have different colors

**Step 3:** Verify sidebar:
- [ ] "Vigentes" link appears below "Rechazadas"
- [ ] Green badge shows correct count
- [ ] Active state highlights when on the calendar page

**Step 4:** Fix any issues found during verification.

**Step 5: Final commit** (if fixes were needed)

```bash
git add -A
git commit -m "fix: polish novedades vigentes calendar view"
```
