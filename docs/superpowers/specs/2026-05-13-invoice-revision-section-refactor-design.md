# Refactor de la sección `revision` del editor de facturas

**Fecha:** 2026-05-13
**Autor:** Brainstorming con Claude
**Estado:** Aprobado (pendiente plan de implementación)
**Alcance:** Template + ViewModel (medio). NO toca policy ni constantes de pipeline.

> **Nota de revisión (2026-05-13):** la propuesta original movía el `<form id="sendApprovalLinksForm">` y el modal `modifyApproversModal` dentro de `revision/setup.php`. Self-review detectó que eso introduciría `<form>` anidados dentro del form principal (`Form->create($viewModel->invoice)`) y rompería el botón "Guardar". Se corrigió: ambos artefactos permanecen físicamente en `templates/Invoices/edit.php` (fuera del form principal), y solo se ajusta el condicional de inclusión del modal.

## Contexto

`templates/element/invoice_edit/sections/revision.php` mezcla tres responsabilidades en un único element:

1. **Setup de aprobación** — selector `approver_ids[]`, botón "Enviar links", botón "Modificar aprobadores", modal `modifyApproversModal`, botón "Reiniciar flujo".
2. **Status de aprobaciones** — campo `area_approval` (siempre disabled), fecha de aprobación, lista `currentApprovals` con respuestas externas, alerta de rechazo.
3. **Validación DIAN** — campo `dian_validation` (único campo del formulario principal en esta sección).

Hallazgos de la auditoría previa (`acc:explain` deep mode sobre `templates/Invoices/edit.php`) que motivan el refactor:

- **G3 — Mezcla de responsabilidades**: tres flujos independientes en un mismo archivo.
- **H4 — Campo huérfano `approver_id`**: `InvoiceEditViewModel::$sectionFieldMap['revision']` lista `approver_id`, pero `InvoiceFieldAccessPolicy::FIELDS_BY_STEP[STATUS_APROBACION]` no lo incluye. El campo real editable es `approver_ids[]` (multi-select que viaja por un `<form>` auxiliar). Consecuencia: el cálculo `array_intersect($sectionFieldMap['revision'], $editableFields)` depende solo de `dian_validation`. Si un rol pierde `dian_validation` pero conserva `canModifyApprovers`, la sección entera cae a `readOnlySectionKeys` y se omite del render — **el botón "Modificar aprobadores" desaparece aunque `canModifyApprovers` siga `true`**. Riesgo de fuga de funcionalidad.
- **H8 — Condicional inline duplicado**: `edit.php:358` chequea `currentStatus === STATUS_APROBACION && !empty(editableFields)` para mostrar el modal, en vez de usar `$viewModel->canModifyApprovers` que ya existe.
- **H9 — Duplicación de `$isRejected`**: `revision.php:16` recalcula `($invoice->area_approval ?? '') === APPROVAL_REJECTED`, duplicando `$viewModel->isRejected`.

## Objetivos

1. Partir `revision.php` en tres sub-elements con responsabilidad única.
2. Garantizar que cada sub-bloque decida su propio render por permiso, sin colapsar la sección entera.
3. Consolidar en `revision/setup.php` la **UI visible** del flujo de aprobación (selector, botones, chip de status, botón resetFlow). El `<form>` auxiliar y el modal permanecen fuera del form principal de `edit.php` para no romper el anidamiento HTML.
4. Mantener la matriz rol × visibilidad actual sin cambios para Factura, Anticipo y Legalización.

## No-objetivos

- **NO** se toca `InvoiceFieldAccessPolicy` ni `SECTIONS_BY_STEP`. `revision` sigue siendo una sola sección lógica.
- **NO** se modifica el comportamiento de `DocumentTypePolicy` (Anticipo seguirá filtrando `revision` fuera tal cual).
- **NO** se cambia `area_approval` hardcoded `disabled => true` (hallazgo H7 fuera de scope).
- **NO** se elimina `confirmed_by` huérfano de `FIELDS_BY_STEP` (hallazgo H5 fuera de scope).
- **NO** se desplaza la lógica `&& !$isAdvance` de `classification.php` (hallazgo H6 fuera de scope).
- **NO** se añaden tests automatizados (ver `CLAUDE.md` "Testing Policy"). Validación manual exclusiva.

## Diseño

### Estructura de archivos

```
templates/element/invoice_edit/sections/
├── revision.php                       (~30 líneas — coordinator)
└── revision/
    ├── setup.php                      (~90 líneas — flujo de aprobación)
    ├── status.php                     (~60 líneas — status read-only)
    └── dian.php                       (~15 líneas — campo DIAN)
```

### Responsabilidad por archivo

| Archivo | Renderiza | Variables que consume |
|---------|-----------|----------------------|
| `revision.php` | Header (`<span>Revisión</span>` + divisor) y wrapper `<div class="row g-3">`. Tres `$this->element(...)` secuenciales. | `$viewModel`, `$canEdit`, `$approvalOptions`, `$dianOptions` |
| `revision/setup.php` | (a) Selector `approver_ids[]` si `canSendLinks` (con HTML5 `form="sendApprovalLinksForm"`). (b) Chip status + botón "Modificar aprobadores" (con `data-bs-toggle="modal"`) si `canModifyApprovers`. (c) Chip "No editable" como fallback. (d) Botón `resetFlow` (en su propio `<form>` interno, igual que hoy) si `isRejected && !empty(editableFields) && currentStatus === STATUS_APROBACION`. | `$viewModel` |
| `revision/status.php` | (a) Alerta de rechazo si `isRejected` (usa `$viewModel->isRejected`, NO recalcula). (b) Campo `area_approval` siempre disabled. (c) Fecha de aprobación si existe. (d) Lista `currentApprovals` con badges de conteo. | `$viewModel`, `$approvalOptions` |
| `revision/dian.php` | Único `Form->control('dian_validation', ...)` con `$canEdit('dian_validation')`. | `$canEdit`, `$dianOptions` |

### Reglas estructurales

- Los sub-elements aportan **columnas** (`<div class="col-md-X">`) directamente al row del coordinator. **No** abren su propio `<div class="row">`.
- Los sub-elements **no** renderizan el header de sección.
- Las clases `.col-md-6` (setup) + `.col-md-3` (status: area_approval) + `.col-md-3` (status: fecha si existe) + `.col-12` (status: lista) + `.col-md-4` (dian) preservan la grilla actual.
- **Restricción HTML — anidamiento de `<form>`:** ningún sub-element abre un `<form>` que se renderizaría dentro del form principal, salvo el `<form action=".../resetFlow">` que ya existía antes del refactor (anidamiento preexistente, fuera de scope). El `<form id="sendApprovalLinksForm">` y el modal `modifyApproversModal` (que contiene su propio `<form>`) permanecen fuera del form principal — ver "Cambios en `templates/Invoices/edit.php`" más abajo.

### Cambios en el ViewModel

`src/ViewModel/InvoiceEditViewModel.php`:

**Línea 160** — eliminar campo huérfano:
```php
// Antes
'revision'       => ['approver_id', 'dian_validation'],
// Después
'revision'       => ['dian_validation'],
```

**Línea 164** — promover `revision` a sección funcional:
```php
// Antes
$functionalSections = ['treasury', 'payment_authorization'];
// Después
$functionalSections = ['treasury', 'payment_authorization', 'revision'];
```

Efecto combinado: cuando `revision` esté en `visibleSections`, siempre cae en `editableSectionKeys` y se renderiza. Cada sub-bloque decide internamente si tiene contenido que mostrar.

**Sin propiedad nueva**. Las propiedades existentes son suficientes: `canSendLinks`, `canModifyApprovers`, `hasPendingApprovals`, `isRejected`, `currentApprovals`, `editableFields`, `invoice->area_approval`, `invoice->area_approval_date`.

### Cambios en `templates/Invoices/edit.php`

| Líneas actuales | Acción | Razón |
|-----------------|--------|-------|
| 202-208 — `<form id="sendApprovalLinksForm">` declarado **antes** del form principal | **Sin cambios** | Permanece fuera del form principal. Mover dentro de `revision/setup.php` rompería `Form->create($viewModel->invoice)` por anidamiento HTML. |
| 358-360 — include del modal `modifyApproversModal` con condicional inline `currentStatus === STATUS_APROBACION && !empty($editableFields)` | **Reemplazar condicional** por `$viewModel->canModifyApprovers`. El `<?= $this->element('invoice_edit/modify_approvers_modal', [...]) ?>` se queda en su sitio (después de `Form->end()`). | Cierra H8 sin mover el modal — el modal contiene su propio `<form>` y mantenerlo fuera del form principal evita anidamiento. |
| 238-240 — include de `invoice_edit/sections/revision` | **Sin cambios** | El path resuelve `revision.php` (coordinator) |

**Nota:** `templates/element/invoice_edit/modify_approvers_modal.php` ya existe como element separado — no se modifica.

### Flujo de render resultante

```
edit.php
├─ (líneas 202-207) <form id="sendApprovalLinksForm">         [FUERA del form principal — sin cambios]
├─ (línea 209) Form->create($viewModel->invoice)              [abre form principal]
│  └─ foreach $renderOrder:
│     └─ if section === 'revision' && in_array('revision', $visibleSections):
│        element('invoice_edit/sections/revision', [...])
│        ├─ header "Revisión" + divisor
│        └─ <div class="row g-3">
│           ├─ element('invoice_edit/sections/revision/setup', [...])
│           │  ├─ if canSendLinks:            selector con form="sendApprovalLinksForm" + botón "Enviar links"
│           │  ├─ elseif canModifyApprovers: chip status + botón "Modificar aprobadores"
│           │  ├─ else:                       chip "No editable"
│           │  └─ if isRejected && editableFields && currentStatus===STATUS_APROBACION:
│           │                                 <form action=".../resetFlow/..."> + botón
│           │
│           ├─ element('invoice_edit/sections/revision/status', [...])
│           │  ├─ if viewModel->isRejected:   alerta rechazo
│           │  ├─                             campo area_approval (disabled)
│           │  ├─ if area_approval_date:     fecha
│           │  └─ if currentApprovals:        lista + badges
│           │
│           └─ element('invoice_edit/sections/revision/dian', [...])
│              └─                             Form->control('dian_validation')
├─ (línea 341) Form->end()                                    [cierra form principal]
└─ (líneas 358-360, condicional reescrito)
   if $viewModel->canModifyApprovers:
      element('invoice_edit/modify_approvers_modal', [...])   [FUERA del form principal — sin cambios físicos]
```

### Comportamiento por `document_type`

- **Factura / doctypes estándar**: `revision` en `visibleSections` → coordinator renderiza los 3 sub-elements. **Sin cambio aparente**.
- **Anticipo**: `AnticipoDocumentTypePolicy::filterVisibleSections()` retira `'revision'` antes de llegar al ViewModel → ninguno de los 3 sub-elements se incluye. **Sin cambio aparente**.
- **Legalización**: `LegalizacionDocumentTypePolicy` no filtra `revision` → comportamiento idéntico a Factura.

### Comportamiento por rol (matriz)

| Rol | Setup | Status | DIAN | Cambio vs. hoy |
|-----|-------|--------|------|----------------|
| Registro/Revisión (estándar) | Selector + botones | Visible | Editable | Sin cambio aparente |
| Registro/Revisión (rechazada) | Botón "Reiniciar" | Alerta rechazo + lista | Disabled (post-rechazo) | Sin cambio aparente |
| Administrador con permisos completos en `aprobacion` | Igual a Registro/Revisión | Igual | Igual | Sin cambio |
| **Rol hipotético con `canModifyApprovers=true` y SIN `dian_validation` editable** | Botón "Modificar aprobadores" visible | Visible | Disabled | **Hoy: invisible (bug). Después: visible.** |
| Cualquier rol sobre Anticipo en `aprobacion` | — | — | — | Sin cambio |

### Caso límite cubierto

El bug latente que el refactor cierra: cuando `canModifyApprovers && !canEdit('dian_validation')`, hoy la sección entera cae a read-only y se omite. Tras el refactor, la sección siempre se renderiza si está en `visibleSections`, y cada sub-bloque ejerce su propio gate.

## Criterios de validación manual

Ejercicio en navegador con `php bin/cake server`:

1. **Factura nueva en `aprobacion` como Registro/Revisión, sin aprobaciones**:
   - Selector `approver_ids[]` visible, botón "Enviar links" visible.
   - Campo `area_approval` visible con valor "Pendiente" y atributo `disabled`.
   - Campo `dian_validation` editable (select habilitado).
   - El DOM **no** contiene `id="modifyApproversModal"` (porque `canModifyApprovers = false`).

2. **Factura con aprobaciones enviadas** (`currentApprovals` no vacía):
   - Chip "Aprobaciones en curso" o "Aprobaciones registradas" visible.
   - Botón "Modificar aprobadores" visible y abre el modal correctamente.
   - Lista de aprobadores con icono de status (✓/✗/⏳) y observaciones.
   - Badges de conteo (`N aprobada/s`, `N pendiente/s`, `N rechazada/s`) correctos.

3. **Factura rechazada** (`area_approval = 'Rechazada'`):
   - Alerta naranja con nombre del rechazador y observación.
   - Botón "Reiniciar flujo" visible (solo si `editableFields` no vacío).
   - POST a `resetFlow` funciona y limpia aprobaciones.

4. **`document_type = 'Anticipo'`**:
   - Ninguno de los 3 sub-elements se renderiza.
   - El HTML **no** contiene `id="approver-ids"`, `id="sendApprovalLinksForm"`, ni `id="modifyApproversModal"`.
   - Tampoco el header "Revisión".

5. **`document_type = 'Legalización'`**:
   - Comportamiento idéntico al de Factura estándar.
   - Alerta superior con link al anticipo padre (no afectada).

6. **DIAN editable solo** (rol con `canSendLinks=false`, `canModifyApprovers=false`, `canEdit('dian_validation')=true`):
   - Chip "No editable en este estado" visible en setup.
   - Lista de aprobaciones visible si `currentApprovals` no vacía.
   - Campo `dian_validation` editable.

7. **JS smoke test**:
   - Select2 inicializa sobre `#approver-ids` (clase `select2-enable` preservada).
   - `data-sgi-confirm` del botón "Enviar links" dispara modal de confirmación.
   - Modal `modifyApproversModal` abre y cierra normalmente.

8. **Diff visual**:
   - Comparar pantallazos antes/después del refactor para los 3 casos típicos (sin aprobaciones, con aprobaciones en curso, rechazada). No debe haber diferencias visibles.

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|--------|-----------|
| Mover el `<form id="sendApprovalLinksForm">` o el modal dentro del form principal rompería `Form->create($viewModel->invoice)` por anidamiento HTML inválido (el navegador cerraría el form principal al parsear el inner). | **Diseño los mantiene fuera del form principal.** El selector y los botones siguen referenciando el form auxiliar vía el atributo HTML5 `form="sendApprovalLinksForm"`, que funciona sin restricción de DOM. |
| El botón "Reiniciar flujo" usa su propio `<form action=".../resetFlow">` que se renderiza dentro del form principal (anidamiento). | **Pre-existente, fuera de scope.** Los navegadores manejan este caso asociando el botón submit al form padre inmediato (el de resetFlow). El form principal recibe stray `</form>` pero el flujo funciona en producción. Documentado como deuda pero no se aborda aquí. |
| Algún rol pierde acceso a una funcionalidad. | Matriz comparativa en sección "Comportamiento por rol": ningún rol pierde acceso; el caso hipotético `canModifyApprovers && !canEdit('dian_validation')` gana acceso correcto. |
| El header "Revisión" se duplica o desaparece. | El header se renderiza EXCLUSIVAMENTE en el coordinator. Los sub-elements no lo emiten. |
| Cambios en `sectionFieldMap` y `$functionalSections` afectan otras secciones. | Los cambios son aditivos (`'revision'` se añade a `$functionalSections`) o restrictivos a `revision` (quitar `'approver_id'` de `sectionFieldMap`). Ninguna otra sección referencia ninguno de los dos. |
| JS Select2 / Bootstrap modal pierden inicialización al cambiar la jerarquía de DOM. | Los `id` de elementos (`approver-ids`, `sendApprovalLinksForm`, `modifyApproversModal`) se preservan y la clase `select2-enable` continúa siendo auto-inicializada por `sgi-common.js`. |

## Plan de archivos (resumen)

**Crear:**
- `templates/element/invoice_edit/sections/revision/setup.php`
- `templates/element/invoice_edit/sections/revision/status.php`
- `templates/element/invoice_edit/sections/revision/dian.php`

**Modificar:**
- `templates/element/invoice_edit/sections/revision.php` — reducir a coordinator (header + 3 `$this->element(...)`).
- `templates/Invoices/edit.php` — reemplazar el condicional inline de las líneas 358-360 (`currentStatus === STATUS_APROBACION && !empty($editableFields)`) por `$viewModel->canModifyApprovers`.
- `src/ViewModel/InvoiceEditViewModel.php` — línea 160 (quitar `'approver_id'`) y línea 164 (añadir `'revision'` a `$functionalSections`).

**Sin tocar:**
- `templates/Invoices/edit.php` líneas 202-208 (el `<form id="sendApprovalLinksForm">` permanece donde está).
- `templates/element/invoice_edit/modify_approvers_modal.php` (sin cambios).
- Ningún archivo de `src/Service/Pipeline/Invoice/Policy/`.

## Referencias

- Auditoría previa: ejecución de `acc:explain` deep mode sobre `templates/Invoices/edit.php` (2026-05-13).
- `CLAUDE.md` — sección "Invoice Pipeline" y "Testing Policy".
- `.claude/rules/design.md` — sistema visual.
- `src/Service/Pipeline/Invoice/Policy/InvoiceFieldAccessPolicy.php`
- `src/Service/Pipeline/Invoice/Policy/AnticipoDocumentTypePolicy.php`
- `src/ViewModel/InvoiceEditViewModel.php`
