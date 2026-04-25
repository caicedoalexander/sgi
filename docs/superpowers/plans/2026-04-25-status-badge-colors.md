# Status Badge Colors Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Centralizar los colores de badges de estado de pipeline en `StatusColorConstants.php`, eliminando los arrays inline duplicados en 10 templates.

**Architecture:** Un solo archivo `src/Constants/StatusColorConstants.php` expone dos constantes: `PIPELINE_STATUS_BADGES` (colores por estado, compartidos entre módulos) y `READY_FOR_PAYMENT_BADGES` (colores para "Lista para pago" en facturas). Los templates reemplazan sus arrays locales con referencias a esta clase.

**Tech Stack:** PHP 8.2, CakePHP 5.3, Bootstrap 5 (clases `bg-*`).

---

## Archivos involucrados

| Acción | Archivo |
|--------|---------|
| Crear | `src/Constants/StatusColorConstants.php` |
| Modificar | `webroot/css/styles.css` (agregar `.bg-purple`) |
| Modificar | `templates/Invoices/index.php` |
| Modificar | `templates/Invoices/edit.php` |
| Modificar | `templates/Invoices/view.php` |
| Modificar | `templates/EmployeeNovelties/index.php` |
| Modificar | `templates/EmployeeNovelties/edit.php` |
| Modificar | `templates/EmployeeNovelties/view.php` |
| Modificar | `templates/NoveltyLiquidationDocs/index.php` |
| Modificar | `templates/NoveltyLiquidationDocs/edit.php` |
| Modificar | `templates/NoveltyLiquidationDocs/view.php` |
| Modificar | `templates/ExternalApprovals/review.php` |

---

## Task 1: Crear `StatusColorConstants.php` y agregar `.bg-purple` al CSS

**Files:**
- Create: `src/Constants/StatusColorConstants.php`
- Modify: `webroot/css/styles.css`

- [ ] **Step 1: Crear `src/Constants/StatusColorConstants.php`**

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class StatusColorConstants
{
    public const PIPELINE_STATUS_BADGES = [
        'aprobacion'       => 'bg-warning text-dark',
        'contabilidad'     => 'bg-primary',
        'tesoreria'        => 'bg-info',
        'autorizacion_pago' => 'bg-info',
        'aut_pago'         => 'bg-info',
        'pagada'           => 'bg-success',
        'pagado'           => 'bg-success',
        'agrupacion'       => 'bg-secondary',
        'borrador'         => 'bg-secondary',
        'registro'         => 'bg-light text-dark',
        'rrhh'             => 'bg-purple',
        'revision_firmas'  => 'bg-warning text-dark',
        'gdp'              => 'bg-dark',
        'rechazada'        => 'bg-danger',
    ];

    public const READY_FOR_PAYMENT_BADGES = [
        'Si'                  => 'bg-success',
        'No'                  => 'bg-secondary',
        'Anticipo Empleado'   => 'bg-info text-dark',
        'Anticipo Proveedor'  => 'bg-primary',
        'Pago prioritario'    => 'bg-danger',
        'Pago PSE'            => 'bg-dark',
        'No Legalización'     => 'bg-warning text-dark',
        'Reintegro'           => 'bg-secondary',
    ];
}
```

- [ ] **Step 2: Agregar `.bg-purple` a `webroot/css/styles.css`**

Buscar el bloque `.badge-dark` (alrededor de la línea 382) y agregar inmediatamente después:

```css
/* Badge purple (novedades - RRHH) */
.badge-purple,
.bg-purple.badge {
    background-color: #6f42c1 !important;
    color: #fff;
}
```

- [ ] **Step 3: Verificar código style**

```bash
composer cs-check
```

Resultado esperado: sin errores.

- [ ] **Step 4: Commit**

```bash
git add src/Constants/StatusColorConstants.php webroot/css/styles.css
git commit -m "feat: add StatusColorConstants with centralized pipeline badge colors"
```

---

## Task 2: Actualizar `templates/Invoices/index.php`

Este template tiene un caso especial: usa un array `$pipelineBadges` que combina `[label, badge_class]` como tupla, y también tiene el badge "Lista para pago" hardcodeado.

**Files:**
- Modify: `templates/Invoices/index.php`

- [ ] **Step 1: Agregar `use` de `StatusColorConstants` al inicio del archivo**

En la línea 12, después de `use App\Constants\InvoiceConstants;`, agregar:

```php
use App\Constants\StatusColorConstants;
```

- [ ] **Step 2: Reemplazar el array `$pipelineBadges` (líneas 21-27)**

Eliminar el bloque:

```php
$pipelineBadges = [
    InvoiceConstants::STATUS_APROBACION        => ['Aprobación',    'bg-info text-dark'],
    InvoiceConstants::STATUS_CONTABILIDAD      => ['Contabilidad',  'bg-primary'],
    InvoiceConstants::STATUS_TESORERIA         => ['Tesorería',     'bg-warning text-dark'],
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => ['Aut. Pago',     'bg-info'],
    InvoiceConstants::STATUS_PAGADA            => ['Pagada',        'bg-success'],
];
```

- [ ] **Step 3: Actualizar la asignación de `$ps` dentro del `foreach` (aprox. línea 165)**

Cambiar:

```php
$ps = $pipelineBadges[$invoice->pipeline_status] ?? ['Desconocido', 'bg-dark'];
```

Por:

```php
$ps = [
    InvoicePipelineService::STATUS_LABELS[$invoice->pipeline_status] ?? 'Desconocido',
    StatusColorConstants::PIPELINE_STATUS_BADGES[$invoice->pipeline_status] ?? 'bg-dark',
];
```

- [ ] **Step 4: Actualizar el badge "Lista para pago" (aprox. línea 249)**

Cambiar:

```php
<span class="badge bg-info text-dark"><?= h($invoice->ready_for_payment) ?></span>
```

Por:

```php
<span class="badge <?= StatusColorConstants::READY_FOR_PAYMENT_BADGES[$invoice->ready_for_payment] ?? 'bg-secondary' ?>"><?= h($invoice->ready_for_payment) ?></span>
```

- [ ] **Step 5: Verificar**

```bash
composer cs-check
```

- [ ] **Step 6: Commit**

```bash
git add templates/Invoices/index.php
git commit -m "refactor(invoices): centralizar badge colors en index usando StatusColorConstants"
```

---

## Task 3: Actualizar `templates/Invoices/edit.php`

En este template `$badgeColors` se pasa a JS vía `json_encode`, por lo que se redefine como alias de la constante central en lugar de eliminarse.

**Files:**
- Modify: `templates/Invoices/edit.php`

- [ ] **Step 1: Agregar `use` de `StatusColorConstants` (línea 18)**

Después de `use App\Constants\InvoiceConstants;` agregar:

```php
use App\Constants\StatusColorConstants;
```

- [ ] **Step 2: Reemplazar la definición de `$badgeColors` (línea 140)**

Cambiar:

```php
$badgeColors  = ['aprobacion' => 'bg-info text-dark', 'contabilidad' => 'bg-primary', 'tesoreria' => 'bg-warning text-dark', 'autorizacion_pago' => 'bg-info', 'pagada' => 'bg-success'];
```

Por:

```php
$badgeColors = StatusColorConstants::PIPELINE_STATUS_BADGES;
```

No tocar ninguna otra línea: el resto del template y el JS (`json_encode($badgeColors)`) siguen funcionando igual.

- [ ] **Step 3: Verificar**

```bash
composer cs-check
```

- [ ] **Step 4: Commit**

```bash
git add templates/Invoices/edit.php
git commit -m "refactor(invoices): centralizar badge colors en edit usando StatusColorConstants"
```

---

## Task 4: Actualizar `templates/Invoices/view.php`

Aquí `$badgeColors` se define inline dentro de un bloque PHP embebido en HTML (aprox. línea 470).

**Files:**
- Modify: `templates/Invoices/view.php`

- [ ] **Step 1: Agregar `use` de `StatusColorConstants` (línea 12)**

Después de `use App\Constants\InvoiceConstants;` agregar:

```php
use App\Constants\StatusColorConstants;
```

- [ ] **Step 2: Eliminar la definición inline de `$badgeColors` (línea 470)**

Eliminar la línea:

```php
$badgeColors = [InvoiceConstants::STATUS_APROBACION => 'bg-info text-dark', InvoiceConstants::STATUS_CONTABILIDAD => 'bg-primary', InvoiceConstants::STATUS_TESORERIA => 'bg-warning text-dark', InvoiceConstants::STATUS_PAGADA => 'bg-success'];
```

- [ ] **Step 3: Actualizar la referencia a `$badgeColors` (línea 472, ahora 471)**

Cambiar:

```php
<span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>" style="font-size:.65rem;">
```

Por:

```php
<span class="badge <?= StatusColorConstants::PIPELINE_STATUS_BADGES[$status] ?? 'bg-secondary' ?>" style="font-size:.65rem;">
```

- [ ] **Step 4: Verificar**

```bash
composer cs-check
```

- [ ] **Step 5: Commit**

```bash
git add templates/Invoices/view.php
git commit -m "refactor(invoices): centralizar badge colors en view usando StatusColorConstants"
```

---

## Task 5: Actualizar `templates/ExternalApprovals/review.php`

**Files:**
- Modify: `templates/ExternalApprovals/review.php`

- [ ] **Step 1: Agregar `use` de `StatusColorConstants` al inicio del archivo**

Después del docblock de cierre (`*/`, línea 8) y antes de `$this->assign(...)`, agregar:

```php
use App\Constants\StatusColorConstants;
```

Quedará así:
```php
<?php
/**
 * @var ...
 */
use App\Constants\StatusColorConstants;
$this->assign('title', 'Revisión de Aprobación');
```

- [ ] **Step 2: Eliminar la definición de `$badgeColors` (línea 121)**

Eliminar la línea:

```php
$badgeColors  = ['aprobacion' => 'bg-info text-dark', 'contabilidad' => 'bg-primary', 'tesoreria' => 'bg-warning text-dark', 'pagada' => 'bg-success'];
```

- [ ] **Step 3: Actualizar la referencia a `$badgeColors` (línea 142)**

Cambiar:

```php
<span class="badge <?= $badgeColors[$doc->pipeline_status] ?? 'bg-secondary' ?>" style="font-size:.65rem;">
```

Por:

```php
<span class="badge <?= StatusColorConstants::PIPELINE_STATUS_BADGES[$doc->pipeline_status] ?? 'bg-secondary' ?>" style="font-size:.65rem;">
```

- [ ] **Step 4: Verificar**

```bash
composer cs-check
```

- [ ] **Step 5: Commit**

```bash
git add templates/ExternalApprovals/review.php
git commit -m "refactor(external-approvals): centralizar badge colors usando StatusColorConstants"
```

---

## Task 6: Actualizar templates de `EmployeeNovelties`

**Files:**
- Modify: `templates/EmployeeNovelties/index.php`
- Modify: `templates/EmployeeNovelties/edit.php`
- Modify: `templates/EmployeeNovelties/view.php`

### `index.php`

- [ ] **Step 1: Agregar `use` de `StatusColorConstants` (línea 10)**

Después de `use App\Constants\NoveltyConstants;` agregar:

```php
use App\Constants\StatusColorConstants;
```

- [ ] **Step 2: Eliminar el array `$statusBadges` (líneas 23-32)**

Eliminar el bloque:

```php
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
```

- [ ] **Step 3: Actualizar la referencia a `$statusBadges` (línea 133)**

Cambiar:

```php
<span class="badge <?= $statusBadges[$novelty->pipeline_status] ?? 'bg-secondary' ?>"><?= $statusLabels[$novelty->pipeline_status] ?? ucfirst(h($novelty->pipeline_status)) ?></span>
```

Por:

```php
<span class="badge <?= StatusColorConstants::PIPELINE_STATUS_BADGES[$novelty->pipeline_status] ?? 'bg-secondary' ?>"><?= $statusLabels[$novelty->pipeline_status] ?? ucfirst(h($novelty->pipeline_status)) ?></span>
```

### `edit.php`

- [ ] **Step 4: Agregar `use` de `StatusColorConstants` (línea 14)**

Después de `use App\Constants\NoveltyConstants;` agregar:

```php
use App\Constants\StatusColorConstants;
```

- [ ] **Step 5: Eliminar el array `$badgeColors` (líneas 55-58)**

Eliminar el bloque:

```php
$badgeColors = [
    'registro' => 'bg-secondary', 'aprobacion' => 'bg-warning text-dark', 'rrhh' => 'bg-info text-dark', 'contabilidad' => 'bg-primary',
    'revision_firmas' => 'bg-warning text-dark', 'gdp' => 'bg-dark', 'tesoreria' => 'bg-info', 'pagada' => 'bg-success',
];
```

- [ ] **Step 6: Actualizar referencias a `$badgeColors` (líneas 499 y 516)**

Cambiar ambas ocurrencias de:

```php
<span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>"
```

Por:

```php
<span class="badge <?= StatusColorConstants::PIPELINE_STATUS_BADGES[$status] ?? 'bg-secondary' ?>"
```

### `view.php`

- [ ] **Step 7: Agregar `use` de `StatusColorConstants` (línea 10)**

Después de `use App\Constants\NoveltyConstants;` agregar:

```php
use App\Constants\StatusColorConstants;
```

- [ ] **Step 8: Eliminar el array `$badgeColors` (líneas 47-50)**

Eliminar el bloque:

```php
$badgeColors = [
    'aprobacion' => 'bg-warning text-dark', 'rrhh' => 'bg-info text-dark', 'contabilidad' => 'bg-primary',
    'revision_firmas' => 'bg-warning text-dark', 'gdp' => 'bg-dark', 'tesoreria' => 'bg-info', 'pagada' => 'bg-success',
];
```

- [ ] **Step 9: Actualizar la referencia a `$badgeColors` (línea 365)**

Cambiar:

```php
<span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>" style="font-size:.65rem;">
```

Por:

```php
<span class="badge <?= StatusColorConstants::PIPELINE_STATUS_BADGES[$status] ?? 'bg-secondary' ?>" style="font-size:.65rem;">
```

- [ ] **Step 10: Verificar**

```bash
composer cs-check
```

- [ ] **Step 11: Commit**

```bash
git add templates/EmployeeNovelties/index.php templates/EmployeeNovelties/edit.php templates/EmployeeNovelties/view.php
git commit -m "refactor(novelties): centralizar badge colors usando StatusColorConstants"
```

---

## Task 7: Actualizar templates de `NoveltyLiquidationDocs`

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/index.php`
- Modify: `templates/NoveltyLiquidationDocs/edit.php`
- Modify: `templates/NoveltyLiquidationDocs/view.php`

### `index.php`

- [ ] **Step 1: Agregar `use` de `StatusColorConstants` (línea 7)**

Después de `use App\Constants\NoveltyConstants;` agregar:

```php
use App\Constants\StatusColorConstants;
```

- [ ] **Step 2: Eliminar el array `$statusBadges` (líneas 11-20)**

Eliminar el bloque:

```php
$statusBadges = [
    'aprobacion' => 'bg-warning text-dark',
    'rrhh' => 'bg-secondary',
    'contabilidad' => 'bg-primary',
    'revision_firmas' => 'bg-warning text-dark',
    'gdp' => 'bg-dark',
    'tesoreria' => 'bg-info',
    'pagada' => 'bg-success',
    'rechazada' => 'bg-danger',
];
```

- [ ] **Step 3: Actualizar la referencia a `$statusBadges` (línea 64)**

Cambiar:

```php
<span class="badge <?= $statusBadges[$doc->pipeline_status] ?? 'bg-secondary' ?>"><?= $statusLabels[$doc->pipeline_status] ?? ucfirst(h($doc->pipeline_status)) ?></span>
```

Por:

```php
<span class="badge <?= StatusColorConstants::PIPELINE_STATUS_BADGES[$doc->pipeline_status] ?? 'bg-secondary' ?>"><?= $statusLabels[$doc->pipeline_status] ?? ucfirst(h($doc->pipeline_status)) ?></span>
```

### `edit.php`

- [ ] **Step 4: Agregar `use` de `StatusColorConstants` (línea 11)**

Después de `use App\Constants\NoveltyConstants;` agregar:

```php
use App\Constants\StatusColorConstants;
```

- [ ] **Step 5: Eliminar el array `$badgeColors` (líneas 56-60)**

Eliminar el bloque:

```php
$badgeColors = [
    'rrhh' => 'bg-secondary', 'contabilidad' => 'bg-primary',
    'aprobacion' => 'bg-warning text-dark', 'revision_firmas' => 'bg-warning text-dark',
    'gdp' => 'bg-dark', 'tesoreria' => 'bg-info', 'aut_pago' => 'bg-info', 'pagada' => 'bg-success',
];
```

- [ ] **Step 6: Actualizar referencias a `$badgeColors` (líneas 502 y 519)**

Cambiar ambas ocurrencias de:

```php
<span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>"
```

Por:

```php
<span class="badge <?= StatusColorConstants::PIPELINE_STATUS_BADGES[$status] ?? 'bg-secondary' ?>"
```

### `view.php`

- [ ] **Step 7: Agregar `use` de `StatusColorConstants` (línea 12)**

Después de `use App\Constants\NoveltyConstants;` agregar:

```php
use App\Constants\StatusColorConstants;
```

- [ ] **Step 8: Eliminar el array `$badgeColors` (líneas 53-57)**

Eliminar el bloque:

```php
$badgeColors = [
    'aprobacion' => 'bg-warning text-dark', 'rrhh' => 'bg-secondary',
    'contabilidad' => 'bg-primary', 'revision_firmas' => 'bg-warning text-dark',
    'gdp' => 'bg-dark', 'tesoreria' => 'bg-info', 'aut_pago' => 'bg-info', 'pagada' => 'bg-success',
];
```

- [ ] **Step 9: Actualizar la referencia a `$badgeColors` (línea 388)**

Cambiar:

```php
<span class="badge <?= $badgeColors[$status] ?? 'bg-secondary' ?>" style="font-size:.65rem;">
```

Por:

```php
<span class="badge <?= StatusColorConstants::PIPELINE_STATUS_BADGES[$status] ?? 'bg-secondary' ?>" style="font-size:.65rem;">
```

- [ ] **Step 10: Verificar**

```bash
composer cs-check
```

- [ ] **Step 11: Commit**

```bash
git add templates/NoveltyLiquidationDocs/index.php templates/NoveltyLiquidationDocs/edit.php templates/NoveltyLiquidationDocs/view.php
git commit -m "refactor(liquidation-docs): centralizar badge colors usando StatusColorConstants"
```

---

## Task 8: Verificación final

- [ ] **Step 1: Ejecutar suite de tests completa**

```bash
composer check
```

Resultado esperado: tests pasan, sin errores de code style.

- [ ] **Step 2: Verificar en el servidor de desarrollo**

```bash
php bin/cake server
```

Abrir en el navegador:
- `http://localhost:8765/invoices` — verificar badges de pipeline y "Lista para pago"
- `http://localhost:8765/employee-novelties` — verificar badges de novedades
- `http://localhost:8765/novelty-liquidation-docs` — verificar badges de liquidaciones

- [ ] **Step 3: Confirmar que los colores son modificables**

Para cambiar el color de cualquier estado (ej: "Autorización de Pago"), editar únicamente `src/Constants/StatusColorConstants.php`:

```php
'autorizacion_pago' => 'bg-purple',  // cambiar a cualquier clase bg-* o bg-* text-*
'aut_pago'         => 'bg-purple',
```

El cambio se refleja automáticamente en todos los módulos.
