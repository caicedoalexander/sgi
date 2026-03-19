# Dashboard Improvements Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add advanced statistics, Chart.js charts, and a period selector to the SGI dashboard, with role-based visibility.

**Architecture:** Extend `DashboardController::index()` with period filtering and new aggregate queries. Add Chart.js via CDN in layout. New `dashboard-charts.js` initializes charts from `data-*` attributes on `<canvas>` elements. Period selector is a reusable element.

**Tech Stack:** CakePHP 5.3, Chart.js 4.x (CDN), Bootstrap 5, Flatpickr (existing)

---

### Task 1: Add Chart.js CDN to layout

**Files:**
- Modify: `templates/layout/default.php` (line ~413, JS section)

**Step 1: Add Chart.js CDN after AutoNumeric and before sgi-common.js**

In `templates/layout/default.php`, find line 419 (autonumeric script tag) and add Chart.js after it:

```php
<script src="https://cdn.jsdelivr.net/npm/autonumeric@4.10.5/dist/autoNumeric.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
```

**Step 2: Verify** — Load any page in browser, open console, type `Chart` and confirm it's defined.

---

### Task 2: Create period selector element

**Files:**
- Create: `templates/element/period_selector.php`

**Step 1: Create the element**

```php
<?php
/**
 * Period selector element for dashboard
 * @var \App\View\AppView $this
 * @var string $currentPeriod
 * @var string|null $dateFrom
 * @var string|null $dateTo
 */
$currentPeriod = $currentPeriod ?? 'month';
$dateFrom = $dateFrom ?? '';
$dateTo = $dateTo ?? '';

$periods = [
    'month' => 'Mes actual',
    'quarter' => 'Trimestre',
    'year' => 'Año actual',
    'all' => 'Todo',
    'custom' => 'Personalizado',
];
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-4" id="period-selector">
    <span style="font-size:.65rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6c757d;">Período:</span>
    <div class="btn-group btn-group-sm" role="group">
        <?php foreach ($periods as $key => $label): ?>
            <?php if ($key === 'custom') continue; ?>
            <a href="<?= $this->Url->build(['?' => ['period' => $key]]) ?>"
               class="btn <?= $currentPeriod === $key ? 'btn-dark' : 'btn-outline-secondary' ?>"
               style="font-size:.75rem;font-weight:500;">
                <?= $label ?>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="d-flex align-items-center gap-1">
        <input type="text" class="form-control form-control-sm flatpickr-date" id="period-from"
               placeholder="Desde" value="<?= h($dateFrom) ?>"
               style="width:110px;font-size:.75rem;">
        <span style="font-size:.75rem;color:#6c757d;">—</span>
        <input type="text" class="form-control form-control-sm flatpickr-date" id="period-to"
               placeholder="Hasta" value="<?= h($dateTo) ?>"
               style="width:110px;font-size:.75rem;">
        <button type="button" class="btn btn-sm btn-outline-dark" id="period-custom-btn"
                style="font-size:.75rem;font-weight:500;">
            <i class="bi bi-funnel"></i>
        </button>
    </div>
</div>
<script>
document.getElementById('period-custom-btn')?.addEventListener('click', function() {
    var from = document.getElementById('period-from').value;
    var to = document.getElementById('period-to').value;
    if (from && to) {
        window.location.href = '<?= $this->Url->build(['controller' => 'Dashboard', 'action' => 'index']) ?>' +
            '?period=custom&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
    }
});
</script>
```

---

### Task 3: Add period calculation to DashboardController

**Files:**
- Modify: `src/Controller/DashboardController.php`

**Step 1: Add `_getPeriodDates()` private method**

Add after `_safeQuery()` method:

```php
private function _getPeriodDates(): array
{
    $period = $this->request->getQuery('period', 'month');
    $now = new \DateTime();

    switch ($period) {
        case 'quarter':
            $quarterMonth = (int)(ceil((int)$now->format('n') / 3) - 1) * 3 + 1;
            $from = (new \DateTime($now->format('Y') . '-' . str_pad((string)$quarterMonth, 2, '0', STR_PAD_LEFT) . '-01'))->format('Y-m-d');
            $to = $now->format('Y-m-d');
            break;
        case 'year':
            $from = $now->format('Y') . '-01-01';
            $to = $now->format('Y-m-d');
            break;
        case 'all':
            $from = '2000-01-01';
            $to = $now->format('Y-m-d');
            break;
        case 'custom':
            $from = $this->request->getQuery('from', $now->format('Y-m-01'));
            $to = $this->request->getQuery('to', $now->format('Y-m-d'));
            break;
        case 'month':
        default:
            $period = 'month';
            $from = $now->format('Y-m-01');
            $to = $now->format('Y-m-d');
            break;
    }

    return [$period, $from, $to];
}
```

**Step 2: Call it in `index()` and pass to view**

At the top of `index()`, after the auth check:

```php
[$currentPeriod, $dateFrom, $dateTo] = $this->_getPeriodDates();
```

Add to the `compact()` call at the end:

```php
$this->set(compact(
    'invoiceStats', 'recentInvoices',
    'invoiceFinancialStats', 'invoiceChartData',
    'rrhhStats', 'recentNovelties',
    'rrhhExtendedStats', 'rrhhChartData',
    'catalogStats',
    'adminStats',
    'currentPeriod', 'dateFrom', 'dateTo'
));
```

---

### Task 4: Add invoice financial stats to DashboardController

**Files:**
- Modify: `src/Controller/DashboardController.php`

**Step 1: Add `_getInvoiceFinancialStats()` method**

```php
private function _getInvoiceFinancialStats(string $from, string $to): array
{
    return $this->_safeQuery(function () use ($from, $to) {
        $table = $this->fetchTable('Invoices');
        $dateConditions = ['Invoices.created >=' => $from, 'Invoices.created <=' => $to . ' 23:59:59'];

        $totalPaid = $table->find()
            ->where(array_merge(['pipeline_status' => 'pagada'], $dateConditions))
            ->select(['total' => $table->find()->func()->sum('amount')])
            ->first();

        $totalInProcess = $table->find()
            ->where(array_merge([
                'pipeline_status IN' => ['aprobacion', 'contabilidad', 'tesoreria'],
                'OR' => [
                    'area_approval IS' => null,
                    'area_approval !=' => 'Rechazada',
                ],
            ], $dateConditions))
            ->select(['total' => $table->find()->func()->sum('amount')])
            ->first();

        $avgAmount = $table->find()
            ->where($dateConditions)
            ->select(['avg' => $table->find()->func()->avg('amount')])
            ->first();

        $overdue = $table->find()
            ->where([
                'due_date <' => date('Y-m-d'),
                'pipeline_status !=' => 'pagada',
                'OR' => [
                    'area_approval IS' => null,
                    'area_approval !=' => 'Rechazada',
                ],
            ])
            ->count();

        return [
            'total_paid' => (float)($totalPaid->total ?? 0),
            'total_in_process' => (float)($totalInProcess->total ?? 0),
            'avg_amount' => (float)($avgAmount->avg ?? 0),
            'overdue' => $overdue,
        ];
    });
}
```

**Step 2: Add `_getInvoiceChartData()` method**

```php
private function _getInvoiceChartData(string $from, string $to): array
{
    return $this->_safeQuery(function () use ($from, $to) {
        $table = $this->fetchTable('Invoices');
        $dateConditions = ['Invoices.created >=' => $from, 'Invoices.created <=' => $to . ' 23:59:59'];

        // Donut: amount by pipeline status
        $statusAmounts = [];
        foreach (['aprobacion', 'contabilidad', 'tesoreria', 'pagada'] as $status) {
            $result = $table->find()
                ->where(array_merge(['pipeline_status' => $status], $dateConditions))
                ->select(['total' => $table->find()->func()->sum('amount')])
                ->first();
            $statusAmounts[$status] = (float)($result->total ?? 0);
        }
        // Rejected
        $rejected = $table->find()
            ->where(array_merge(['area_approval' => 'Rechazada'], $dateConditions))
            ->select(['total' => $table->find()->func()->sum('amount')])
            ->first();
        $statusAmounts['rechazada'] = (float)($rejected->total ?? 0);

        // Bar: invoices per month (count + amount)
        $connection = $table->getConnection();
        $monthlyData = $connection->execute(
            "SELECT DATE_FORMAT(created, '%Y-%m') as month,
                    COUNT(*) as count,
                    COALESCE(SUM(amount), 0) as total
             FROM invoices
             WHERE created >= ? AND created <= ?
             GROUP BY DATE_FORMAT(created, '%Y-%m')
             ORDER BY month ASC",
            [$from, $to . ' 23:59:59']
        )->fetchAll('assoc');

        return [
            'donut_status' => $statusAmounts,
            'monthly' => $monthlyData,
        ];
    });
}
```

**Step 3: Call both in `index()` inside the invoices section**

After the existing `$recentInvoices` block, inside `if ($canView('invoices'))`:

```php
$invoiceFinancialStats = $this->_getInvoiceFinancialStats($dateFrom, $dateTo);
$invoiceChartData = $this->_getInvoiceChartData($dateFrom, $dateTo);
```

Initialize defaults before the if:

```php
$invoiceFinancialStats = [];
$invoiceChartData = [];
```

---

### Task 5: Add RRHH extended stats to DashboardController

**Files:**
- Modify: `src/Controller/DashboardController.php`

**Step 1: Add `_getRrhhExtendedStats()` method**

```php
private function _getRrhhExtendedStats(string $from, string $to): array
{
    return $this->_safeQuery(function () use ($from, $to) {
        $empTable = $this->fetchTable('Employees');
        $activeCondition = ['employee_status_id' => \App\Constants\EmployeeStatusConstants::ACTIVO];

        // Average age
        $avgAge = $empTable->getConnection()->execute(
            "SELECT AVG(TIMESTAMPDIFF(YEAR, birth_date, CURDATE())) as avg_age
             FROM employees
             WHERE employee_status_id = ? AND birth_date IS NOT NULL",
            [\App\Constants\EmployeeStatusConstants::ACTIVO]
        )->fetch('assoc');

        // Average tenure (years)
        $avgTenure = $empTable->getConnection()->execute(
            "SELECT AVG(TIMESTAMPDIFF(YEAR, hire_date, CURDATE())) as avg_tenure
             FROM employees
             WHERE employee_status_id = ? AND hire_date IS NOT NULL",
            [\App\Constants\EmployeeStatusConstants::ACTIVO]
        )->fetch('assoc');

        // New hires in period
        $newHires = $empTable->find()
            ->where(array_merge($activeCondition, [
                'hire_date >=' => $from,
                'hire_date <=' => $to,
            ]))
            ->count();

        // Terminations in period
        $terminations = $empTable->find()
            ->where([
                'termination_date >=' => $from,
                'termination_date <=' => $to,
            ])
            ->count();

        return [
            'avg_age' => round((float)($avgAge['avg_age'] ?? 0), 1),
            'avg_tenure' => round((float)($avgTenure['avg_tenure'] ?? 0), 1),
            'new_hires' => $newHires,
            'terminations' => $terminations,
        ];
    });
}
```

**Step 2: Add `_getRrhhChartData()` method**

```php
private function _getRrhhChartData(string $from, string $to): array
{
    return $this->_safeQuery(function () use ($from, $to) {
        $empTable = $this->fetchTable('Employees');

        // Donut: employees by contract type
        $contractTypes = [];
        foreach (\App\Constants\ContractTypeConstants::ALL as $type) {
            $count = $empTable->find()
                ->where([
                    'employee_status_id' => \App\Constants\EmployeeStatusConstants::ACTIVO,
                    'contract_type' => $type,
                ])
                ->count();
            $contractTypes[$type] = $count;
        }

        // Bar: novelties per month
        $novTable = $this->fetchTable('EmployeeNovelties');
        $monthlyNovelties = $novTable->getConnection()->execute(
            "SELECT DATE_FORMAT(created, '%Y-%m') as month,
                    COUNT(*) as count
             FROM employee_novelties
             WHERE created >= ? AND created <= ?
             GROUP BY DATE_FORMAT(created, '%Y-%m')
             ORDER BY month ASC",
            [$from, $to . ' 23:59:59']
        )->fetchAll('assoc');

        return [
            'donut_contract' => $contractTypes,
            'monthly_novelties' => $monthlyNovelties,
        ];
    });
}
```

**Step 3: Call both in `index()` inside the RRHH section**

After existing RRHH stats block, inside the `if ($canView('employees') || $canView('employee_novelties'))`:

```php
$rrhhExtendedStats = $this->_getRrhhExtendedStats($dateFrom, $dateTo);
$rrhhChartData = $this->_getRrhhChartData($dateFrom, $dateTo);
```

Initialize defaults before the if:

```php
$rrhhExtendedStats = [];
$rrhhChartData = [];
```

---

### Task 6: Update Dashboard template — period selector + invoice stats

**Files:**
- Modify: `templates/Dashboard/index.php`

**Step 1: Add period selector after the welcome section (line 48)**

After `</div>` of welcome section, before `<div class="d-flex flex-column gap-5">`:

```php
<?= $this->element('period_selector', compact('currentPeriod', 'dateFrom', 'dateTo')) ?>
```

**Step 2: Add invoice financial stat cards**

After the existing 6 stat cards row (line ~106, after `</div>` of `row g-3`), add a new row:

```php
<?php if (!empty($invoiceFinancialStats)): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-sm-4 col-xl-3">
        <div class="sgi-stat-card p-3 h-100">
            <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Monto Pagado</div>
            <div style="font-size:1.5rem;font-weight:700;line-height:1.1;color:var(--primary-color);">$<?= $this->Number->format($invoiceFinancialStats['total_paid'] ?? 0, ['places' => 0]) ?></div>
            <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">En el período</div>
        </div>
    </div>
    <div class="col-6 col-sm-4 col-xl-3">
        <div class="sgi-stat-card p-3 h-100" style="border-top-color:#0dcaf0;">
            <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Monto en Proceso</div>
            <div style="font-size:1.5rem;font-weight:700;line-height:1.1;color:#212529;">$<?= $this->Number->format($invoiceFinancialStats['total_in_process'] ?? 0, ['places' => 0]) ?></div>
            <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Sin pagar</div>
        </div>
    </div>
    <div class="col-6 col-sm-4 col-xl-3">
        <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--secondary-color);">
            <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Promedio/Factura</div>
            <div style="font-size:1.5rem;font-weight:700;line-height:1.1;color:#212529;">$<?= $this->Number->format($invoiceFinancialStats['avg_amount'] ?? 0, ['places' => 0]) ?></div>
            <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Media del período</div>
        </div>
    </div>
    <div class="col-6 col-sm-4 col-xl-3">
        <div class="sgi-stat-card p-3 h-100" style="border-top-color:#dc3545;">
            <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Vencidas</div>
            <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#dc3545;"><?= $this->Number->format($invoiceFinancialStats['overdue'] ?? 0) ?></div>
            <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Requieren atención</div>
        </div>
    </div>
</div>
<?php endif; ?>
```

**Step 3: Add invoice charts**

After the financial stat cards, before the recent invoices table:

```php
<?php if (!empty($invoiceChartData)): ?>
<div class="row g-3 mb-3">
    <div class="col-md-5">
        <div style="background:#fff;border:1px solid var(--border-color);padding:1rem;">
            <div style="font-size:.65rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6c757d;margin-bottom:.75rem;">Distribución por estado</div>
            <canvas id="invoiceDonutChart"
                    data-chart-donut='<?= json_encode($invoiceChartData['donut_status'] ?? []) ?>'
                    style="max-height:280px;"></canvas>
        </div>
    </div>
    <div class="col-md-7">
        <div style="background:#fff;border:1px solid var(--border-color);padding:1rem;">
            <div style="font-size:.65rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6c757d;margin-bottom:.75rem;">Facturas por mes</div>
            <canvas id="invoiceBarChart"
                    data-chart-monthly='<?= json_encode($invoiceChartData['monthly'] ?? []) ?>'
                    style="max-height:280px;"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>
```

---

### Task 7: Update Dashboard template — RRHH extended stats + charts

**Files:**
- Modify: `templates/Dashboard/index.php`

**Step 1: Add RRHH extended stat cards**

After the existing RRHH stat cards row (after `<?php endif; ?>` of `$rrhhStats`), add:

```php
<?php if (!empty($rrhhExtendedStats)): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-sm-4 col-xl-3">
        <div class="sgi-stat-card p-3 h-100">
            <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Edad Media</div>
            <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $rrhhExtendedStats['avg_age'] ?? 0 ?></div>
            <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Años</div>
        </div>
    </div>
    <div class="col-6 col-sm-4 col-xl-3">
        <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--secondary-color);">
            <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Antigüedad Media</div>
            <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#212529;"><?= $rrhhExtendedStats['avg_tenure'] ?? 0 ?></div>
            <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">Años</div>
        </div>
    </div>
    <div class="col-6 col-sm-4 col-xl-3">
        <div class="sgi-stat-card p-3 h-100" style="border-top-color:var(--primary-color);">
            <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Nuevos Ingresos</div>
            <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:var(--primary-color);"><?= $this->Number->format($rrhhExtendedStats['new_hires'] ?? 0) ?></div>
            <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">En el período</div>
        </div>
    </div>
    <div class="col-6 col-sm-4 col-xl-3">
        <div class="sgi-stat-card p-3 h-100" style="border-top-color:#dc3545;">
            <div style="font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;font-weight:600;">Retiros</div>
            <div style="font-size:1.9rem;font-weight:700;line-height:1.1;color:#dc3545;"><?= $this->Number->format($rrhhExtendedStats['terminations'] ?? 0) ?></div>
            <div style="font-size:.72rem;color:#6c757d;margin-top:2px;">En el período</div>
        </div>
    </div>
</div>
<?php endif; ?>
```

**Step 2: Add RRHH charts**

After the extended stats, before the recent novelties table:

```php
<?php if (!empty($rrhhChartData)): ?>
<div class="row g-3 mb-3">
    <div class="col-md-5">
        <div style="background:#fff;border:1px solid var(--border-color);padding:1rem;">
            <div style="font-size:.65rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6c757d;margin-bottom:.75rem;">Distribución por contrato</div>
            <canvas id="employeeDonutChart"
                    data-chart-contract='<?= json_encode($rrhhChartData['donut_contract'] ?? []) ?>'
                    style="max-height:280px;"></canvas>
        </div>
    </div>
    <div class="col-md-7">
        <div style="background:#fff;border:1px solid var(--border-color);padding:1rem;">
            <div style="font-size:.65rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6c757d;margin-bottom:.75rem;">Novedades por mes</div>
            <canvas id="noveltyBarChart"
                    data-chart-novelties='<?= json_encode($rrhhChartData['monthly_novelties'] ?? []) ?>'
                    style="max-height:280px;"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>
```

---

### Task 8: Create dashboard-charts.js (use frontend-design skill for styling)

**Files:**
- Create: `webroot/js/dashboard-charts.js`

**Step 1: Create the JS file with Chart.js initialization**

This file reads `data-*` attributes from canvas elements and creates the charts. Uses the SGI color palette from CSS variables. Font family matches Inter.

**NOTE:** Use the `frontend-design` skill when implementing this task to ensure charts have polished, production-grade styling that matches the SGI design system.

The JS must handle:
1. `#invoiceDonutChart` — Doughnut chart reading `data-chart-donut` JSON
2. `#invoiceBarChart` — Bar chart reading `data-chart-monthly` JSON (two datasets: count + amount)
3. `#employeeDonutChart` — Doughnut chart reading `data-chart-contract` JSON
4. `#noveltyBarChart` — Bar chart reading `data-chart-novelties` JSON

Color palette to use:
- Aprobación: `#ffc107` (warning)
- Contabilidad: `#0dcaf0` (info)
- Tesorería: `#0d6efd` (primary)
- Pagada: `#469D61` (SGI primary green)
- Rechazada: `#dc3545` (danger)
- Contract types: `#469D61`, `#CD6A15`, `#83542B`
- Novelties bars: `#469D61`

Chart.js global defaults:
- `Chart.defaults.font.family = "'Inter', system-ui, sans-serif"`
- `Chart.defaults.font.size = 12`
- No legend box for bar charts
- Doughnut cutout at 65%
- Responsive, maintainAspectRatio false

**Step 2: Include script only on dashboard page**

In `templates/Dashboard/index.php`, at the bottom, add:

```php
<?php $this->Html->script('dashboard-charts', ['block' => 'script']); ?>
```

This uses CakePHP's script block which is rendered in the layout via `$this->fetch('script')`.

---

### Task 9: Final integration and testing

**Step 1: Verify all new variables are declared with defaults in the template**

At the top of `templates/Dashboard/index.php`, add:

```php
$invoiceFinancialStats = $invoiceFinancialStats ?? [];
$invoiceChartData      = $invoiceChartData ?? [];
$rrhhExtendedStats     = $rrhhExtendedStats ?? [];
$rrhhChartData         = $rrhhChartData ?? [];
$currentPeriod         = $currentPeriod ?? 'month';
$dateFrom              = $dateFrom ?? '';
$dateTo                = $dateTo ?? '';
```

**Step 2: Run code style check**

```bash
composer cs-check
```

Fix any issues with `composer cs-fix`.

**Step 3: Manual testing checklist**

- [ ] Dashboard loads without errors
- [ ] Period selector shows with "Mes actual" active by default
- [ ] Clicking "Trimestre", "Año", "Todo" reloads with correct data
- [ ] Custom date range works with Flatpickr inputs
- [ ] Invoice financial stat cards show correct COP amounts
- [ ] Invoice donut chart renders with correct colors per status
- [ ] Invoice bar chart shows monthly data with two series
- [ ] RRHH stat cards show age, tenure, hires, terminations
- [ ] Employee donut chart shows contract type distribution
- [ ] Novelty bar chart shows monthly counts
- [ ] Non-admin roles see only their permitted sections
- [ ] Charts are responsive on mobile
