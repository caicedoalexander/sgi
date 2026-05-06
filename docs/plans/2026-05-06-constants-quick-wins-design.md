# Plan — Quick wins de la auditoría de Constants (m2, m3, m4, m5)

**Fecha:** 2026-05-06
**Auditoría base:** [`docs/audits/constants-structure-audit-2026-05-06.md`](../audits/constants-structure-audit-2026-05-06.md)
**Plan precedente:** [`docs/plans/2026-05-06-constants-audit-plan-a-design.md`](2026-05-06-constants-audit-plan-a-design.md)
**Alcance:** ítems amarillos m2, m3, m4, m5 — limpiezas puntuales sin cambio de comportamiento.

---

## 1. Contexto

Tras cerrar C1, C2, M3, M4, M5, M6, m6 (ver plan precedente) y descartar C3, queda un grupo de menores postergados. Este plan ataca solo los que son cambios chicos, aditivos o reordenamientos sin riesgo:

- **m2** — orden de declaración en `InvoiceConstants` (uso antes de declaración).
- **m3** — extraer `DIAN_PENDING` como constante simbólica.
- **m4** — extraer las 3 opciones de `READY_FOR_PAYMENT_OPTIONS` como constantes.
- **m5** — agregar PHPDoc a `NoveltyConstants::ACTIVE_STATUSES` explicando exclusiones.

**Out of scope confirmado en este plan:**
- **m7** (`PipelineStepConstants::isValid()` estático) — descartado por decisión: es una línea trivial usada solo en `PipelineAuthorizationService`; mover crea un archivo nuevo o un método privado por purismo. No vale la pena.
- **m1, M1, M2 restante, S1, S3** — siguen postergados según el plan precedente.

---

## 2. Decisiones de brainstorming

| Ítem | Decisión | Razón |
|---|---|---|
| **m4** alcance | **Solo las 3 de OPTIONS** (no las 8 de BADGES) | Las otras 5 entradas de `READY_FOR_PAYMENT_BADGES` (`'No'`, `'Anticipo Empleado'`, `'Anticipo Proveedor'`, `'No Legalización'`, `'Reintegro'`) son valores derivados inyectados por código, no opciones del `<select>`. Promoverlas a constantes amplía alcance sin resolver un riesgo real. |
| **m5** alcance | **Solo PHPDoc, sin cambio de comportamiento** | La asimetría (incluye `STATUS_TESORERIA` y `STATUS_PAGADA` pero excluye `STATUS_AUTORIZACION_PAGO`) es decisión de dominio confirmada — autorización_pago es transitorio. Documentar evita que un futuro lector lo "arregle" creyendo que es bug. |
| **m3** alcance | **Solo extraer `DIAN_PENDING` como constante simbólica** | Inicialmente se exploró eliminar `'Pendiente'` del enum DIAN (volver columna nullable + migrar datos), pero el cambio de dominio era mucho más grande que un quick win y requiere un plan propio. Aquí solo se elimina el literal duplicado. |
| **PR único vs múltiples** | **Un solo PR con los 4 ítems** | Tocan archivos distintos o aditivos, validación común, evita overhead de 4 reviews para 30–45 min de trabajo. |

---

## 3. Plan de ejecución

PR único desde `main`. Riesgo: muy bajo. Esfuerzo estimado: 30–45 min.

### 3.1 m2 — Reordenar `DOCTYPE_*` y `DOCUMENT_TYPES` en `InvoiceConstants`

Hoy `DOCUMENT_TYPES` (líneas 9–19) referencia `self::DOCTYPE_FACTURA` que se declara en líneas 99–107. PHP lo permite pero confunde al leer.

**Cambio:** mover el bloque `DOCTYPE_*` (líneas 99–107) arriba, justo antes de `DOCUMENT_TYPES`. Cero impacto runtime.

```php
final class InvoiceConstants
{
    // Document types
    public const DOCTYPE_FACTURA = 'Factura';
    public const DOCTYPE_NOTA_DEBITO = 'Nota Debito';
    public const DOCTYPE_CAJA_MENOR = 'Caja menor';
    public const DOCTYPE_TARJETA_CREDITO = 'Tarjeta de Crédito';
    public const DOCTYPE_REINTEGRO = 'Reintegro';
    public const DOCTYPE_LEGALIZACION = 'Legalización';
    public const DOCTYPE_RECIBO = 'Recibo';
    public const DOCTYPE_RECIBO_CAJA = 'Recibo de Caja';
    public const DOCTYPE_ANTICIPO = 'Anticipo';

    public const DOCUMENT_TYPES = [
        self::DOCTYPE_FACTURA,
        self::DOCTYPE_NOTA_DEBITO,
        // ...
    ];

    // Estados de aprobacion de area
    public const APPROVAL_PENDING = 'Pendiente';
    // ... resto sin cambios
}
```

### 3.2 m3 — Agregar `DIAN_PENDING` como constante simbólica

Hoy:
```php
public const DIAN_APPROVED = 'Aprobada';
public const DIAN_REJECTED = 'Rechazado';
public const DIAN_STATUSES = ['Pendiente', self::DIAN_APPROVED, self::DIAN_REJECTED];
```

Cambio:
```php
public const DIAN_PENDING = 'Pendiente';
public const DIAN_APPROVED = 'Aprobada';
public const DIAN_REJECTED = 'Rechazado';
public const DIAN_STATUSES = [self::DIAN_PENDING, self::DIAN_APPROVED, self::DIAN_REJECTED];
```

**Verificación previa:** `Grep "'Pendiente'"` en `src/` y `templates/`. Solo reemplazar el literal por `InvoiceConstants::DIAN_PENDING` cuando el contexto sea claramente DIAN. **No tocar:**
- `InvoiceConstants::APPROVAL_PENDING`, `APPROVER_STATUS_PENDING` (área approval, dominio distinto que casualmente comparte el string).
- `NoveltyConstants::APPROVAL_PENDING`.
- `EmailLogConstants::STATUS_PENDING => 'Pendiente'` (label).
- `templates/Invoices/view.php:296` (`?? 'Pendiente'` como fallback defensivo cuando `dian_validation` viene null) — aquí sí tiene sentido reemplazar por `?? InvoiceConstants::DIAN_PENDING`.

### 3.3 m4 — Extraer constantes simbólicas para `READY_FOR_PAYMENT_OPTIONS`

**En `InvoiceConstants.php`:**
```php
// ANTES
public const READY_FOR_PAYMENT_OPTIONS = ['Si', 'Pago PSE', 'Pago prioritario'];

// DESPUÉS
public const READY_FOR_PAYMENT_SI = 'Si';
public const READY_FOR_PAYMENT_PSE = 'Pago PSE';
public const READY_FOR_PAYMENT_PRIORITARIO = 'Pago prioritario';

public const READY_FOR_PAYMENT_OPTIONS = [
    self::READY_FOR_PAYMENT_SI,
    self::READY_FOR_PAYMENT_PSE,
    self::READY_FOR_PAYMENT_PRIORITARIO,
];
```

**En `StatusColorConstants.php`:** referenciar las 3 constantes en las claves del dict de badges; el resto queda literal.
```php
public const READY_FOR_PAYMENT_BADGES = [
    InvoiceConstants::READY_FOR_PAYMENT_SI         => 'bg-success',
    'No'                                            => 'bg-secondary',
    'Anticipo Empleado'                             => 'bg-info text-dark',
    'Anticipo Proveedor'                            => 'bg-primary',
    InvoiceConstants::READY_FOR_PAYMENT_PRIORITARIO => 'bg-danger',
    InvoiceConstants::READY_FOR_PAYMENT_PSE         => 'bg-dark',
    'No Legalización'                               => 'bg-warning text-dark',
    'Reintegro'                                     => 'bg-secondary',
];
```

**Lo que NO cambia:**
- Los 5 strings derivados (`'No'`, `'Anticipo Empleado'`, `'Anticipo Proveedor'`, `'No Legalización'`, `'Reintegro'`) quedan literales.
- Templates `Invoices/edit.php`, `Refunds/edit.php`, `PettyCashRecords/edit.php` siguen usando `InvoiceConstants::READY_FOR_PAYMENT_OPTIONS` sin cambios.
- `InvoicesTable.php:218` validación `inList` sin cambios.

### 3.4 m5 — PHPDoc en `NoveltyConstants::ACTIVE_STATUSES`

Solo agregar comentario; sin cambio de valores.

```php
/**
 * Estados considerados "activos" para conteos del sidebar, filtros del listado
 * de empleados y estadísticas de novedades.
 *
 * Excluye intencionalmente:
 *  - STATUS_REGISTRO, STATUS_APROBACION: la novedad aún no fue procesada por RRHH.
 *  - STATUS_AUTORIZACION_PAGO: estado transitorio de autorización del Contador;
 *    se considera "en flujo de pago", no "activa" en el sentido operativo.
 *  - STATUS_RECHAZADA: terminal, no cuenta como activa.
 *
 * Si la semántica de "activa" cambia (p.ej. incluir AUTORIZACION_PAGO),
 * revisar los 3 call-sites: EmployeeNoveltiesController::index,
 * SidebarCounterService, EmployeeStatisticsService.
 */
public const ACTIVE_STATUSES = [
    self::STATUS_RRHH,
    self::STATUS_CONTABILIDAD,
    self::STATUS_REVISION_FIRMAS,
    self::STATUS_GDP,
    self::STATUS_TESORERIA,
    self::STATUS_PAGADA,
];
```

---

## 4. Validación manual

1. `composer cs-check` limpio.
2. `php bin/cake server`.
3. **Smoke test facturas:**
   - `Invoices/add` → crear factura → `<select>` de Validación DIAN muestra 3 opciones (Pendiente, Aprobada, Rechazado).
   - `Invoices/edit` → `<select>` "Listo para pago" muestra 3 opciones (Si, Pago PSE, Pago prioritario).
   - `Invoices/index` → badges DIAN y "Listo para pago" se renderizan con los colores correctos.
   - `Invoices/view` → badges idem.
4. **Smoke test reintegros y caja menor:**
   - `Refunds/edit` y `PettyCashRecords/edit` → mismo `<select>` "Listo para pago" funcional.
5. **Smoke test novedades:**
   - Sidebar counter de novedades muestra el mismo número que antes (sin cambio numérico esperado).
   - Listado de empleados con filtros por estado de novedad sigue funcionando.

---

## 5. Cierre y documentación

Tras merge en `main`:

- Actualizar `docs/audits/constants-structure-audit-2026-05-06.md` tabla de remediación:
  - **m2, m3, m4, m5** → ✅ Resuelto (PR `<sha>` 2026-05-06).
  - **m7** → 📌 Descartado (decisión 2026-05-06): `isValid()` se queda en `PipelineStepConstants` por simplicidad; mover crea overhead innecesario.
- Agregar nota breve en la tabla apuntando a este plan.

**Commit único:** `refactor(constants): minor cleanups (m2, m3, m4, m5)`

---

## 6. Out of scope (sigue postergado)

| Ítem | Razón |
|---|---|
| **m1** mezcla idiomas inglés/español | Requiere brainstorming propio (decidir convención global). |
| **M1** mezcla dominio/presentación | Refactor arquitectónico; sin dolor productivo concreto hoy. |
| **M2 restante** god-array `PIPELINE_STATUS_BADGES` | Atado a M1/S3. |
| **m7** `PipelineStepConstants::isValid()` estático | Descartado en este plan — ver §1. |
| **S1** migración a enums PHP 8.1+ | Esfuerzo alto, beneficio difuso. |
| **S3** subdirectorios `Domain/`+`Presentation/` | Atado a M1. |
| **Eliminar `'Pendiente'` de `dian_validation`** | Cambio de dominio (columna nullable, migración de datos, cambio de UX); requiere plan propio si se decide hacer. |
