# Diseño — Extracción de presentación fuera de `Constants/` (M1 + M2)

**Fecha:** 2026-05-06
**Hallazgos:** M1 (mezcla dominio/presentación en `StatusColorConstants` + `*Constants::STATUS_ICONS`) y M2 (god-array `PIPELINE_STATUS_BADGES`) de [`docs/audits/constants-structure-audit-2026-05-06.md`](../audits/constants-structure-audit-2026-05-06.md).
**Verdicto auditor:** ⚠️ Mayor — la duplicación entre capas y el god-array cross-domain dificultan agregar/modificar pipelines.
**Estrategia:** mover toda la configuración de UI a `src/View/Presentation/` con una clase final por pipeline + una `SharedPresentation` para lo cross-domain. `Constants/` queda como vocabulario de dominio puro.

---

## Decisiones de diseño

1. **Destino:** `src/View/Presentation/` (carpeta nueva, paralela a `Constants/`).
   - Razón: marca claramente la separación de capas sin cambiar el patrón mental del proyecto (datos puros accesibles estáticamente). Una migración a service/helper se puede hacer después si surge necesidad real.
2. **Granularidad:** una clase por pipeline (`InvoicePresentation`, `NoveltyPresentation`, etc.) + `SharedPresentation` para lo verdaderamente cross-domain.
   - Razón: rompe el acoplamiento eferente máximo del god-array. Cada controller/template importa solo lo que renderiza.
3. **Eliminamos `StatusColorConstants`** por completo (su contenido se reparte).
4. **Aceptamos duplicación de `STATUS_ICONS` entre `PettyCashPresentation` y `RefundPresentation`** (son idénticos hoy vía trait): 5 entradas × 2 archivos no justifica un trait de presentación, y la divergencia futura es plausible. Si en el futuro queremos compartir, se extrae *entonces*, no preventivamente (YAGNI).
5. **Mover todo lo de presentación menor:** `CALENDAR_COLORS` y `DATE_FORMAT` también migran. Cierra M1 por completo.

---

## Estructura final

```
src/View/Presentation/
├── InvoicePresentation.php
├── NoveltyPresentation.php
├── AdvancePresentation.php
├── PaymentSchedulingPresentation.php
├── PettyCashPresentation.php
├── RefundPresentation.php
└── SharedPresentation.php
```

### Contenido por clase

| Clase | Constantes |
|---|---|
| `InvoicePresentation` | `STATUS_BADGES` (estados del pipeline de facturas), `STATUS_ICONS` (movido desde `InvoicePipelineService::STATUS_ICONS`) |
| `NoveltyPresentation` | `STATUS_BADGES` (incluye `rrhh`, `revision_firmas`, `gdp`), `STATUS_ICONS` (movido desde `NoveltyConstants`), `CALENDAR_COLORS` (movido desde `NoveltyConstants`) |
| `AdvancePresentation` | `STATUS_BADGES`, `STATUS_ICONS` (movido desde `AdvanceConstants`) |
| `PaymentSchedulingPresentation` | `STATUS_BADGES`, `STATUS_ICONS` (movido desde `PaymentSchedulingConstants`) |
| `PettyCashPresentation` | `STATUS_BADGES`, `STATUS_ICONS` (copiado desde `GroupingPipelineConstantsTrait::STATUS_ICONS`) |
| `RefundPresentation` | `STATUS_BADGES`, `STATUS_ICONS` (copiado desde `GroupingPipelineConstantsTrait::STATUS_ICONS`) |
| `SharedPresentation` | `READY_FOR_PAYMENT_BADGES` (movido desde `StatusColorConstants`), `DATE_FORMAT` (movido desde `ObservationConstants`) |

### Reparto del god-array `PIPELINE_STATUS_BADGES`

| Estado actual | Pipeline destino |
|---|---|
| `aprobacion`, `contabilidad`, `tesoreria`, `autorizacion_pago`, `pagada`, `legalizada` | `InvoicePresentation::STATUS_BADGES` |
| `aprobacion`, `rrhh`, `revision_firmas`, `gdp`, `tesoreria`, `autorizacion_pago`, `pagada`, `rechazada` | `NoveltyPresentation::STATUS_BADGES` |
| `agrupacion`, `contabilidad`, `tesoreria`, `autorizacion_pago`, `pagada` | `PettyCashPresentation::STATUS_BADGES` y `RefundPresentation::STATUS_BADGES` |
| `borrador`, `tesoreria`, `autorizacion_pago`, `pagada` | `PaymentSchedulingPresentation::STATUS_BADGES` |
| Estados del pipeline de Advance | `AdvancePresentation::STATUS_BADGES` |

Cada pipeline mantiene exactamente las clases Bootstrap que el god-array tenía hoy (no rediseño visual).

---

## Eliminaciones

| Archivo / símbolo | Acción |
|---|---|
| `src/Constants/StatusColorConstants.php` | **Borrar archivo completo** |
| `AdvanceConstants::STATUS_ICONS` | Borrar |
| `NoveltyConstants::STATUS_ICONS` | Borrar |
| `NoveltyConstants::CALENDAR_COLORS` | Borrar |
| `PaymentSchedulingConstants::STATUS_ICONS` | Borrar |
| `GroupingPipelineConstantsTrait::STATUS_ICONS` | Borrar |
| `ObservationConstants::DATE_FORMAT` | Borrar |
| `InvoicePipelineService::STATUS_ICONS` | Borrar (la clase del service mantiene su responsabilidad de pipeline; la presentación sale a `InvoicePresentation`) |

---

## Migración de callsites

### Patrón de reemplazo

| Antes | Después |
|---|---|
| `StatusColorConstants::PIPELINE_STATUS_BADGES[$status]` | `<Pipeline>Presentation::STATUS_BADGES[$status]` |
| `StatusColorConstants::READY_FOR_PAYMENT_BADGES[$x]` | `SharedPresentation::READY_FOR_PAYMENT_BADGES[$x]` |
| `NoveltyConstants::STATUS_ICONS` | `NoveltyPresentation::STATUS_ICONS` |
| `AdvanceConstants::STATUS_ICONS` | `AdvancePresentation::STATUS_ICONS` |
| `PaymentSchedulingConstants::STATUS_ICONS` | `PaymentSchedulingPresentation::STATUS_ICONS` |
| `PettyCashConstants::STATUS_ICONS` (vía trait) | `PettyCashPresentation::STATUS_ICONS` |
| `RefundConstants::STATUS_ICONS` (vía trait) | `RefundPresentation::STATUS_ICONS` |
| `InvoicePipelineService::STATUS_ICONS` | `InvoicePresentation::STATUS_ICONS` |
| `NoveltyConstants::CALENDAR_COLORS` | `NoveltyPresentation::CALENDAR_COLORS` |
| `ObservationConstants::DATE_FORMAT` | `SharedPresentation::DATE_FORMAT` |

### Callsites concretos

**Invoices**
- `src/Controller/InvoicesController.php:593` — `$badgeColors = InvoicePresentation::STATUS_BADGES;`
- `templates/Invoices/index.php:161` — badge mapping
- `templates/Invoices/index.php:246` — `SharedPresentation::READY_FOR_PAYMENT_BADGES`
- `templates/Invoices/edit.php:145` — `$badgeColors = InvoicePresentation::STATUS_BADGES;`
- `templates/Invoices/view.php:498` — badge mapping
- `templates/element/pipeline_progress.php:18` — default `$statusIcons` apunta a `InvoicePresentation::STATUS_ICONS`

**Novelties**
- `src/Controller/NoveltyDocumentsController.php:117` — devuelve `NoveltyPresentation::STATUS_BADGES`
- `src/Controller/NoveltyLiquidationDocsController.php:538` — idem
- `src/Controller/EmployeeNoveltiesController.php:255,343` — `NoveltyPresentation::CALENDAR_COLORS`
- `templates/EmployeeNovelties/edit.php:9,29,65` — imports + `STATUS_ICONS` + `STATUS_BADGES`
- `templates/EmployeeNovelties/index.php:11,26,124` — imports + `CALENDAR_COLORS` + badge inline
- `templates/EmployeeNovelties/view.php:12,17,49` — imports + `STATUS_ICONS` + `STATUS_BADGES`
- `templates/NoveltyLiquidationDocs/edit.php:8,25,65` — idem
- `templates/NoveltyLiquidationDocs/index.php:9,80` — imports + badge inline
- `templates/NoveltyLiquidationDocs/view.php:14,19,55` — idem

**ExternalApprovals**
- `templates/ExternalApprovals/review.php:142` — el documento aprobado puede ser invoice o novelty. **Cambio:** pasar el mapa correcto desde el controller en una variable `$badgeMap` en lugar de leer `StatusColorConstants` global. Determinar el pipeline correspondiente al documento del token y enviar `InvoicePresentation::STATUS_BADGES` o `NoveltyPresentation::STATUS_BADGES`.

**PettyCash**
- `templates/PettyCashRecords/view.php:211` — muestra el `pipeline_status` de la **factura** asociada (`$inv->pipeline_status`), por lo tanto usa `InvoicePresentation::STATUS_BADGES`.
- `templates/element/petty_cash_progress.php:11` — `PettyCashPresentation::STATUS_ICONS`.

**Refund**
- `templates/element/refund_progress.php:11` — `RefundPresentation::STATUS_ICONS`.

**Advance**
- `templates/Advances/legalization.php:121` — `AdvancePresentation::STATUS_ICONS`.
- `templates/element/advance_legalization_progress.php:10` — idem.

**PaymentScheduling**
- `templates/PaymentSchedulings/edit.php:95` — `PaymentSchedulingPresentation::STATUS_ICONS`.
- `templates/PaymentSchedulings/view.php:82` — idem.

**Observations**
- `src/Controller/Trait/ObservationControllerTrait.php:65` — `SharedPresentation::DATE_FORMAT`.

### Conteo total de cambios

- **Archivos creados:** 7 (`src/View/Presentation/*.php`)
- **Archivos eliminados:** 1 (`src/Constants/StatusColorConstants.php`)
- **Archivos modificados:** ~22 (4 controllers + 1 trait + 11 templates + 4 elements + 2 archivos de constantes que pierden iconos/calendario/format + 1 service)

---

## Riesgo y mitigación

- **Riesgo bajo**: refactor mecánico (mover constantes + reescribir imports). No hay lógica nueva ni cambios de comportamiento.
- **Riesgo identificado**: que algún callsite quede con un mapeo incorrecto y muestre fallback `bg-secondary`. Mitigación: smoke test por vista (lista abajo).
- **Sin migración de BD**: cambio puramente de capa PHP.
- **Sin cambio de API pública**: las constantes movidas no se exponen fuera del proyecto.

---

## Validación manual (criterios de aceptación)

Sin tests automatizados (política del proyecto, ver `CLAUDE.md`). Tras el refactor:

1. `composer cs-check` → debe pasar sin errores de estilo.
2. `git grep -n "StatusColorConstants"` → debe devolver 0 resultados (clase eliminada).
3. `git grep -n "::STATUS_ICONS"` → solo resultados dentro de `src/View/Presentation/` y los templates/elements que las consumen.
4. `git grep -n "CALENDAR_COLORS"` → solo en `NoveltyPresentation` y los 3 callsites de novelties.
5. `git grep -n "ObservationConstants::DATE_FORMAT"` → 0 resultados.
6. Levantar `php bin/cake server` y verificar visualmente:
   - `/invoices` index — badges de pipeline + columna ready_for_payment.
   - `/invoices/edit/{id}` — pipeline progress con iconos correctos.
   - `/invoices/view/{id}` — historial con badges.
   - `/employee-novelties` index — calendario con colores + badges.
   - `/employee-novelties/edit/{id}` y `/view/{id}` — pipeline progress + badges.
   - `/novelty-liquidation-docs` (index/edit/view) — idem.
   - `/petty-cash-records/{id}` — badge de factura asociada + progress de petty cash.
   - `/advances/legalization` y elementos legalization progress.
   - `/payment-schedulings/edit/{id}` y `/view/{id}` — progress.
   - Probar un link de aprobación externa (token) con un invoice y con una novelty — badges correctos en ambos casos.

Si alguna vista muestra badge `bg-secondary` cuando antes tenía color, es señal inmediata de mapeo incorrecto: revisar el pipeline destino del callsite.

---

## Plan de ejecución (PR único)

1. Crear las 7 clases en `src/View/Presentation/` con el contenido movido literalmente desde sus orígenes.
2. Reemplazar callsites uno a uno (controllers, templates, elements, service).
3. Eliminar las constantes/clase obsoletas (`StatusColorConstants.php` + `STATUS_ICONS` de Constants/Service + `CALENDAR_COLORS` + `DATE_FORMAT`).
4. Ajustar `templates/ExternalApprovals/review.php` y su(s) controller(s) para recibir `$badgeMap` por contexto del documento.
5. `composer cs-check` y corregir si hace falta.
6. Smoke test manual completo (lista de validación).
7. Commit y merge a `main`.

**Mensaje de commit propuesto:**
```
refactor(constants): extract presentation layer to src/View/Presentation/

Resuelve M1 + M2 de constants-structure-audit-2026-05-06.
- Crea 7 clases en src/View/Presentation/ (una por pipeline + Shared)
- Elimina StatusColorConstants y disuelve PIPELINE_STATUS_BADGES (god-array
  cross-domain) en mapas por pipeline
- Migra STATUS_ICONS desde *Constants y InvoicePipelineService
- Migra CALENDAR_COLORS y DATE_FORMAT
- Constants/ queda como vocabulario de dominio puro
```

---

## Próximos pasos tras este PR

Pendientes de la auditoría que quedan:
- **m1** — Mezcla inglés/español en valores (`'pending'`, `'sent'`). Decisión + documentación.
- **S1** — Migración a enums PHP 8.1+, empezando por `InvoicePipelineStatus`. Refactor mayor, evaluar caso a caso.
- **S3** — Subdivisión de `Constants/` en `Domain/`. Tras este PR, `Constants/` ya queda libre de presentación; subdividir más es discutible.
