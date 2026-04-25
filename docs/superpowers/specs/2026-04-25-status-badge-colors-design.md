# Status Badge Colors — Design Spec
Date: 2026-04-25

## Problem

Los colores de los badges de estado del pipeline están definidos como arrays PHP inline dispersos en 10+ archivos de templates. No existe una fuente única de verdad: cambiar el color de un estado requiere editar múltiples archivos, y existen inconsistencias entre templates del mismo módulo.

## Solución

Crear una clase central `src/Constants/StatusColorConstants.php` que contenga todos los mapeos de color como constantes PHP. Los templates dejan de definir arrays locales y referencian directamente esta clase.

## Principios de diseño

- Colores **compartidos entre módulos**: el estado `tesoreria` tiene el mismo color en facturas, caja menor, novedades y programación de pagos.
- Colores **modificables como variables**: cambiar un color requiere editar únicamente `StatusColorConstants.php`.
- Convención existente respetada: sigue el patrón `STATUS_LABELS` / `STATUS_ICONS` ya presente en las constantes del proyecto.

---

## Clase central: `src/Constants/StatusColorConstants.php`

### `PIPELINE_STATUS_BADGES`

Mapa compartido para todos los módulos. Las claves son los valores de `pipeline_status` en base de datos.

| Clave | Label visible | Clase CSS provisional |
|---|---|---|
| `aprobacion` | Aprobación | `bg-warning text-dark` |
| `contabilidad` | Contabilidad | `bg-primary` |
| `tesoreria` | Tesorería | `bg-info` |
| `autorizacion_pago` | Aut. Pago | `bg-info` |
| `aut_pago` | Aut. Pago | `bg-info` |
| `pagada` | Pagada | `bg-success` |
| `pagado` | Pagado | `bg-success` |
| `agrupacion` | Agrupación | `bg-secondary` |
| `borrador` | Borrador | `bg-secondary` |
| `registro` | Registro | `bg-light text-dark` |
| `rrhh` | RRHH | `bg-purple` |
| `revision_firmas` | Revisión y Firmas | `bg-warning text-dark` |
| `gdp` | GDP | `bg-dark` |
| `rechazada` | Rechazada | `bg-danger` |

> Nota: `autorizacion_pago` (facturas) y `aut_pago` (caja menor, novedades, programación) son alias del mismo estado — comparten color.
> Nota: `bg-purple` es una clase custom existente en `styles.css`. Si no existe, se agrega.

### `READY_FOR_PAYMENT_BADGES`

Mapa para el badge "Lista para pago" en el listado de facturas (`invoice.ready_for_payment`).

| Valor | Clase CSS provisional |
|---|---|
| `Si` | `bg-success` |
| `No` | `bg-secondary` |
| `Anticipo Empleado` | `bg-info text-dark` |
| `Anticipo Proveedor` | `bg-primary` |
| `Pago prioritario` | `bg-danger` |
| `Pago PSE` | `bg-dark` |
| `No Legalización` | `bg-warning text-dark` |
| `Reintegro` | `bg-secondary` |

---

## Templates a modificar

Todos los archivos dejan de definir `$badgeColors` / `$statusBadges` inline y pasan a usar `StatusColorConstants::PIPELINE_STATUS_BADGES`.

| Archivo | Variable eliminada | Línea aprox. |
|---|---|---|
| `templates/Invoices/index.php` | array `[label, class]` inline | 22 |
| `templates/Invoices/edit.php` | `$badgeColors` | 140 |
| `templates/Invoices/view.php` | `$badgeColors` | 470 |
| `templates/EmployeeNovelties/index.php` | `$statusBadges` | 23 |
| `templates/EmployeeNovelties/edit.php` | `$badgeColors` | 55 |
| `templates/EmployeeNovelties/view.php` | `$badgeColors` | 47 |
| `templates/NoveltyLiquidationDocs/index.php` | `$statusBadges` | 11 |
| `templates/NoveltyLiquidationDocs/edit.php` | `$badgeColors` | 56 |
| `templates/NoveltyLiquidationDocs/view.php` | `$badgeColors` | 53 |
| `templates/ExternalApprovals/review.php` | `$badgeColors` | 121 |

### Caso especial: `Invoices/index.php`

Actualmente usa un formato `[status => [label, badge_class]]` combinado. Se separa en dos referencias independientes:
- Label: `InvoicePipelineService::STATUS_LABELS[$status]`
- Clase: `StatusColorConstants::PIPELINE_STATUS_BADGES[$status]`

### Badge "Lista para pago"

El badge en `templates/Invoices/index.php` (actualmente hardcodeado `bg-info text-dark`) pasa a usar:
```php
StatusColorConstants::READY_FOR_PAYMENT_BADGES[$invoice->ready_for_payment] ?? 'bg-secondary'
```

---

## Patrón de uso en templates

```php
use App\Constants\StatusColorConstants;

// Badge de estado de pipeline
$badgeClass = StatusColorConstants::PIPELINE_STATUS_BADGES[$entity->pipeline_status] ?? 'bg-secondary';
echo '<span class="badge ' . $badgeClass . '">' . $label . '</span>';

// Badge "Lista para pago"
$rfpClass = StatusColorConstants::READY_FOR_PAYMENT_BADGES[$invoice->ready_for_payment] ?? 'bg-secondary';
echo '<span class="badge ' . $rfpClass . '">' . h($invoice->ready_for_payment) . '</span>';
```

---

## Fuera de alcance

- No se modifican `STATUS_LABELS` ni `STATUS_ICONS` en los Constants existentes.
- No se cambia el sistema de badges de aprobación de área (`area_approval`) ni de validación DIAN.
- No se introducen CSS custom properties (puede ser una mejora futura).
- No se toca `PettyCashRecords` ni `PaymentSchedulings` templates si no tienen badge de estado inline actualmente.
