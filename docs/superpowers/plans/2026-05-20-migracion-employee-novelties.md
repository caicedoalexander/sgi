# Migración del módulo EmployeeNovelties — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) o superpowers:executing-plans para implementar este plan tarea por tarea. Los pasos usan checkbox (`- [ ]`).

**Goal:** Alinear el módulo EmployeeNovelties (Novedades) al diseño de Facturas: corregir el listado, migrar las observaciones al drawer compartido, los soportes a `documents_section`, y limpiar markup legacy de edit.

**Architecture:** `index.php` corrige un bug de etiquetas y pasa los filtros a panel colapsable. `view.php` y `edit.php` reemplazan las observaciones inline por `element('observations/drawer', …)`; `view.php` migra los soportes a `documents_section`; `edit.php` limpia divisores manuales y estilos inline.

**Tech Stack:** CakePHP 5.3 (templates PHP en `templates/EmployeeNovelties/`), CSS del Sistema de Diseño v2, JS `sgi-observation-chat.js`, Bootstrap collapse.

**Spec:** `docs/superpowers/specs/2026-05-20-migracion-modulos-flujo-design.md` (módulo EmployeeNovelties).

**Política del proyecto:** sin tests automatizados. Cada tarea cierra con `php -l` y un commit. `composer cs-fix` NO corre en este entorno — no usarlo. La validación funcional la hace el usuario.

---

## Contexto

- Elementos compartidos estables: `templates/element/observations/drawer.php` (params
  `observations`/`count`/`formUrl`/`currentUserName`), `templates/element/documents_section.php`
  (params `groups`/`totalDocs`/`canUpload`/`uploadModalId`/`emptyTitle`),
  `templates/element/document_row.php`, `templates/element/pipeline_sidebar.php`.
- `EmployeeNovelties/index.php` ya usa el dialecto canónico de listado (`.row-fact`,
  `.chip`); **no** se reescribe. Tiene dos defectos: (1) etiquetas basura
  `</content>` y `</invoke>` al final del archivo (líneas 510-511); (2) los filtros
  no son colapsables.
- `view.php` y `edit.php` consumen `pipeline_sidebar` (correcto). Las observaciones
  van inline con markup legacy; `view.php` muestra los soportes en una grilla de
  cards Bootstrap con bordes inline. `edit.php` tiene divisores de sección manuales
  y un `style` inline con `!important`.
- `$currentUser` está disponible globalmente en todas las vistas.

## Estructura de archivos

| Archivo | Cambio |
|---|---|
| `templates/EmployeeNovelties/index.php` | Eliminar bug de etiquetas; filtros a panel colapsable. |
| `templates/EmployeeNovelties/view.php` | Observaciones inline → `observations/drawer`; soportes a `documents_section`. |
| `templates/EmployeeNovelties/edit.php` | Observaciones → `observations/drawer`; limpiar markup legacy. |

`EmployeeNovelties/add.php` y `active.php` no se tocan (fuera de alcance).

**Fuera de alcance de este plan** (ver self-review): la tabla "Historial de Cambios"
de `view.php` se deja como está; el `<div class="card-body p-4">` de `edit.php` solo
se limpia su `!important` inline (no se reestructura la card Bootstrap completa).

---

## Task 1: `EmployeeNovelties/index.php` — corregir bug y filtros colapsables

**Files:**
- Modify: `templates/EmployeeNovelties/index.php`

Lee primero `templates/EmployeeNovelties/index.php` completo.

- [ ] **Step 1: Eliminar las etiquetas basura del final del archivo**

Al final de `templates/EmployeeNovelties/index.php` (líneas 510-511) hay dos
etiquetas que no son PHP/HTML válido y se colaron por error:

```
</content>
</invoke>
```

Eliminar ambas líneas por completo. El archivo debe terminar en la última línea
de markup real (antes de esas dos etiquetas).

- [ ] **Step 2: Filtros a panel colapsable**

El header tiene un botón "Filtros":

```php
<button type="button" class="btn btn-default" id="btn-toggle-filters">
    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtros
</button>
```

Y más abajo el formulario de filtros, hoy siempre visible:

```php
<form method="get" id="novelty-filters" class="d-flex gap-2 align-items-center" style="margin-bottom:14px;">
    … (dos <select> con onchange="this.form.submit()") …
</form>
```

Cambios:
1. Convertir el botón "Filtros" en un toggle de Bootstrap collapse: añadirle
   `data-bs-toggle="collapse" data-bs-target="#noveltyFiltersPanel"` y
   `aria-expanded="<?= $hasFilters ? 'true' : 'false' ?>"`. Mantener su clase y su
   contenido (icono + "Filtros").
2. Envolver el `<form id="novelty-filters">` en un panel colapsable: justo antes
   del `<form …>` abrir `<div class="collapse <?= $hasFilters ? 'show' : '' ?>" id="noveltyFiltersPanel" style="margin-bottom:14px;">`
   con dentro un `<div class="sgi-card compact">`; y tras el `</form>` cerrar
   `</div></div>`. Quitar del `<form>` el `style="margin-bottom:14px;"` (el margen
   ahora lo lleva el `.collapse`). El contenido interno del `<form>` (los dos
   `<select>` con su `onchange`, el link "Limpiar filtros") NO cambia.
3. Si en `index.php` hay un bloque `<script>` con un manejador JS que muestre/oculte
   `#novelty-filters` al hacer click en `#btn-toggle-filters` (toggle manual),
   eliminar ese manejador — ahora lo gestiona Bootstrap collapse. Si no existe tal
   manejador, no hay nada que quitar.

- [ ] **Step 3: Verificar y commitear**

```bash
php -l templates/EmployeeNovelties/index.php
git add templates/EmployeeNovelties/index.php
git commit -m "fix(view): EmployeeNovelties/index — quitar tags basura y filtros colapsables"
```
El mensaje de commit debe terminar con la línea:
`Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

- [ ] **Step 4: Validación manual**

`php bin/cake server`, abrir `Novedades`: el listado se ve correcto (sin texto
basura `</content>` al final de la página); el botón "Filtros" muestra/oculta el
panel de filtros; los filtros y la paginación funcionan. Consola sin errores.

---

## Task 2: `EmployeeNovelties/view.php` — observaciones al drawer y soportes a `documents_section`

**Files:**
- Modify: `templates/EmployeeNovelties/view.php`

Lee primero `templates/EmployeeNovelties/view.php` completo.

- [ ] **Step 1: Migrar las observaciones al drawer**

`view.php` muestra las observaciones del chat con markup ad-hoc: un bloque
`<!-- Observations (read-only, like invoices/view) -->` seguido de
`<?php if (!empty($novelty->novelty_observations)): ?>` … `<div style="border-bottom:1px solid var(--border-color);">`
con la etiqueta "Observaciones" y un `foreach` sobre `$novelty->novelty_observations`
que pinta avatares y mensajes a mano.

**Eliminar por completo** ese bloque (desde el comentario `<!-- Observations …-->`
y su `<?php if …?>` hasta el `<?php endif; ?>` que lo cierra).

> NO tocar el bloque siguiente `<!-- General observations (legacy field) -->`
> (`$novelty->observations`, "Observaciones de Rechazo") — es un campo de texto
> distinto, no el chat; se conserva intacto.

`view.php` no tiene `<form>` propio. Insertar la llamada al drawer como **última
instrucción del markup** (al final del archivo, tras `</div><!-- /sgi-invoice-view-grid -->`):

```php
<?= $this->element('observations/drawer', [
    'observations'    => $novelty->novelty_observations ?? [],
    'count'           => count($novelty->novelty_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $novelty->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>
```

- [ ] **Step 2: Migrar los soportes a `documents_section`**

`view.php` tiene un bloque `<!-- Documents (read-only, grid layout like invoices/view) -->`
seguido de `<div class="card" style="padding:18px 20px;">` con un header "Soportes"
y, dentro, una grilla `row row-cols-1 row-cols-md-3` de cards con `border:1px solid`
inline (una card por documento, agrupadas por `$documentsByStatus`).

**Reemplazar** todo ese `<div class="card" style="padding:18px 20px;">…</div>`
(incluido el comentario que lo precede) por la construcción de `$docGroups` + la
llamada a `documents_section`:

```php
<?php /* ── Soportes ──────────────────────────────────── */ ?>
<?php
$docGroups = [];
$multipleDocStatuses = count($documentsByStatus) > 1;
foreach ($documentsByStatus as $status => $docs) {
    $rows = [];
    foreach ($docs as $doc) {
        $rows[] = [
            'doc'          => $doc,
            'canDelete'    => false,
            'deleteUrl'    => null,
            'showBadge'    => !$multipleDocStatuses,
            'badgeColors'  => $badgeColors,
            'statusLabels' => $statusLabels,
        ];
    }
    $docGroups[] = [
        'label'    => $multipleDocStatuses ? ($statusLabels[$status] ?? $status) : null,
        'pillKind' => $multipleDocStatuses ? ($badgeColors[$status] ?? 'pill-muted') : null,
        'rows'     => $rows,
    ];
}
?>
<?= $this->element('documents_section', [
    'groups'        => $docGroups,
    'totalDocs'     => $totalDocs,
    'canUpload'     => false,
    'uploadModalId' => null,
    'emptyTitle'    => 'Sin soportes adjuntos',
]) ?>
```

Notas:
- `$documentsByStatus`, `$badgeColors`, `$statusLabels`, `$totalDocs` ya existen en
  `view.php` (los usaba el bloque inline original).
- `view.php` es de solo lectura: `canUpload => false` (la sección muestra empty
  state `.empty-state`, sin dropzone) y `canDelete => false` por documento.
- `document_row` no muestra el nombre del usuario que subió el documento; esa línea
  (`uploaded_by_user`) del markup viejo se pierde — es aceptable por consistencia
  con `documents_section` en todo el sistema.

- [ ] **Step 3: Verificar y commitear**

```bash
php -l templates/EmployeeNovelties/view.php
git add templates/EmployeeNovelties/view.php
git commit -m "refactor(view): EmployeeNovelties/view usa drawer de observaciones y documents_section"
```
El mensaje debe terminar con:
`Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

- [ ] **Step 4: Validación manual**

`EmployeeNovelties/view` de una novedad: la sección "Soportes" se ve con la card
del sistema (filas `document_row`, empty state si no hay); el disparador del drawer
fijo al borde derecho; abrir el drawer muestra las observaciones. La sección
"Observaciones de Rechazo" (campo legacy) sigue visible si la novedad la tiene.
Consola sin errores.

---

## Task 3: `EmployeeNovelties/edit.php` — observaciones al drawer y limpieza de markup legacy

**Files:**
- Modify: `templates/EmployeeNovelties/edit.php`

Lee primero `templates/EmployeeNovelties/edit.php` completo.

- [ ] **Step 1: Migrar las observaciones al drawer**

`edit.php` tiene un bloque `<div class="sgi-edit-side-grid">` con dos cards:
**Soportes** (`<div class="card" …>` que ya usa `element('document_row', …)`) y
**Observaciones** (`<div class="card sgi-obs-card" …>`, precedida de
`<?php $obsCount = count($novelty->novelty_observations ?? []); ?>`).

Cambios:
1. Quitar el wrapper `<div class="sgi-edit-side-grid">` y su `</div>` de cierre
   (`<!-- /sgi-edit-side-grid -->`), dejando la card de **Soportes** como hijo
   directo a ancho completo. El markup interno de Soportes NO cambia.
2. **Eliminar por completo** la card de **Observaciones** (`<div class="card sgi-obs-card" …>…</div>`,
   el comentario `<!-- Observations chat -->` y la línea `<?php $obsCount = … ?>`).
3. Eliminar la línea `<?= $this->element('observation_chat_init') ?>` (cerca del
   final del archivo) y **en su lugar** poner la llamada al drawer:

```php
<?= $this->element('observations/drawer', [
    'observations'    => $novelty->novelty_observations ?? [],
    'count'           => count($novelty->novelty_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $novelty->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>
```

`edit.php` no tiene un `<form>` principal único (usa formularios pequeños sueltos
por etapa, todos cerrados antes del final); la posición de `observation_chat_init`
está fuera de cualquier `<form>`, así que es ubicación segura para el drawer.
NO tocar `document_row_template` ni otros elementos del final.

- [ ] **Step 2: Quitar el borde inline de la alerta**

Cerca del inicio del archivo hay una alerta:

```php
<div class="alert alert-info d-flex align-items-center gap-2 mb-4" style="border-left:3px solid var(--info-color);">
```

Quitar el atributo `style="border-left:3px solid var(--info-color);"` por completo
(el sistema de diseño no usa bordes; `.alert alert-info` ya aporta el estilo). La
etiqueta queda:

```php
<div class="alert alert-info d-flex align-items-center gap-2 mb-4">
```

- [ ] **Step 3: Divisores de sección manuales → `.sgi-label` + `.hr`**

`edit.php` tiene 4 divisores de sección con este patrón manual (icono + label en
mayúsculas + barra de 1px):

```php
<div class="d-flex align-items-center gap-3 mb-3">
    <span class="text-uppercase fw-semibold flex-shrink-0"
          style="font-size:var(--fs-micro);letter-spacing:.14em;color:var(--text-disabled);">
        <i class="bi bi-XXXX me-1" aria-hidden="true"></i>ETIQUETA
    </span>
    <div style="flex:1;height:1px;background:var(--border-color);"></div>
</div>
```

Reemplazar **cada una** de las 4 instancias por el patrón v2 del sistema —
`.sgi-label` (conservando el icono y el texto) seguido de `.hr`:

```php
<span class="sgi-label"><i class="bi bi-XXXX me-1" aria-hidden="true"></i>ETIQUETA</span>
<div class="hr" style="margin:8px 0 14px;"></div>
```

Conservar en cada caso el icono (`bi-gear`, `bi-pen`, `bi-person-check`,
`bi-file-earmark-text`) y el texto exactos de la instancia original (Gestión,
Firmas, Aprobación, Asignar a Documento de Liquidación).

- [ ] **Step 4: Quitar el `!important` inline del `card-body`**

Hay un `<div class="card-body p-4" style="padding-top:0 !important;">`. Quitar el
atributo `style="padding-top:0 !important;"` por completo. La etiqueta queda
`<div class="card-body p-4">`. (No se reestructura la card Bootstrap; solo se
elimina el `!important` inline señalado por la auditoría.)

- [ ] **Step 5: Verificar y commitear**

```bash
php -l templates/EmployeeNovelties/edit.php
git add templates/EmployeeNovelties/edit.php
git commit -m "refactor(view): EmployeeNovelties/edit usa drawer de observaciones + limpieza markup v2"
```
El mensaje debe terminar con:
`Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

- [ ] **Step 6: Validación manual**

`EmployeeNovelties/edit` de una novedad: Soportes a ancho completo; abrir el drawer,
publicar una observación (verificar que aparece y el contador sube); subir/eliminar
un soporte; las secciones de etapa (Gestión, Firmas, Aprobación, etc.) muestran sus
divisores `.hr`; recorrer las acciones del pipeline. Consola sin errores JS.

---

## Self-review (cobertura del spec)

Spec, módulo EmployeeNovelties:
- `index` → filtros colapsables + bug de tags → Task 1. ✔
- `view` → observaciones ad-hoc → drawer (Task 2 Step 1); soportes en cards Bootstrap
  → `documents_section` (Task 2 Step 2). ✔
- `edit` → quitar `card-body p-4 !important` (Task 3 Step 4) y divisores manuales
  (Task 3 Step 3); observaciones → drawer (Task 3 Step 1); alert con `border-left`
  inline → sin borde inline (Task 3 Step 2). ✔

Desviaciones del spec, justificadas:
- **Historial de cambios (`view.php`)**: el spec mencionaba "historial en `<table>`
  → filas del sistema". Se **difiere**: la `<table class="table table-sm table-hover">`
  es funcional y no hay un patrón v2 de tabla de referencia claro; migrarla sin
  referencia arriesga un peor resultado. Queda como ítem menor posterior.
- **`card-body p-4` (`edit.php`)**: el spec decía "quitar `card-body p-4 !important`".
  Se interpreta como quitar el `!important` inline (Task 3 Step 4); NO se reestructura
  la card Bootstrap completa (`.card` + `.card-header` + `.card-body`), que es un
  cambio mayor de bajo valor y alto riesgo, fuera del alcance de esta ronda.
- Los "footers de acción ad-hoc" que mencionaba el spec para `edit` son los botones
  de avance dentro de cada formulario de etapa; usan `.btn`/`.btn-primary` del
  sistema y `border-top` de separación — se consideran aceptables y no se tocan.

Nota: el chat viejo `observation_bubble*` no se elimina en este plan — se retira al
final de la migración completa.
