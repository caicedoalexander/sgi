# Migración del módulo PettyCashRecords — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) o superpowers:executing-plans para implementar este plan tarea por tarea. Los pasos usan checkbox (`- [ ]`).

**Goal:** Migrar las observaciones de `PettyCashRecords/edit.php` al drawer compartido.

**Architecture:** `PettyCashRecords/edit.php` reemplaza su tarjeta inline de observaciones por `element('observations/drawer', …)` fuera del `<form>` principal; la tarjeta de Soportes pasa a ancho completo.

**Tech Stack:** CakePHP 5.3 (template PHP en `templates/PettyCashRecords/`), JS `sgi-observation-chat.js`.

**Spec:** `docs/superpowers/specs/2026-05-20-migracion-modulos-flujo-design.md` (módulo PettyCashRecords).

**Política del proyecto:** sin tests automatizados. La tarea cierra con `php -l` y un commit. `composer cs-fix` NO corre en este entorno — no usarlo. La validación funcional (servidor + navegador) la hace el usuario.

---

## Contexto

`PettyCashRecords/index.php` y `view.php` ya están alineados al diseño de Facturas
(`view.php` adoptó `pipeline_sidebar` en la ronda de consolidación de elementos).
El único pendiente del módulo es `edit.php`, que aún muestra las observaciones con
el chat viejo (`element('observation_bubble', …)` + `element('observation_chat_init')`).

En `edit.php` el chat incluye un `<form id="obs-form">` **anidado dentro** del
`<form id="pettyCashEditForm">` (HTML inválido) — el drawer lo corrige porque va
fuera del form principal.

El element `templates/element/observations/drawer.php` ya existe y es estable:
drawer flotante autocontenido (emite su propio `<template>` e inicializa el chat),
con params `observations` / `count` / `formUrl` / `currentUserName`.

## Estructura de archivos

| Archivo | Cambio |
|---|---|
| `templates/PettyCashRecords/edit.php` | Tarjeta de observaciones inline → `observations/drawer`; Soportes a ancho completo. |

`PettyCashRecords/index.php`, `view.php` y `add.php` no se tocan.

---

## Task 1: `PettyCashRecords/edit.php` — observaciones al drawer

**Files:**
- Modify: `templates/PettyCashRecords/edit.php`

Lee primero `templates/PettyCashRecords/edit.php` completo para confirmar la
estructura descrita.

- [ ] **Step 1: Soportes a ancho completo; eliminar la columna de Observaciones**

En `templates/PettyCashRecords/edit.php` existe un bloque que empieza en el
comentario `<?php /* ── Soportes + Observaciones (grid 2 columnas) ──── */ ?>`
seguido de `<div class="row g-3">`. Ese `.row g-3` contiene dos columnas
`<div class="col-md-6">`: la primera con la card de **Soportes**, la segunda con
la card de **Observaciones**. El `.row` cierra con su `</div>` después de la
segunda columna.

Cambios:
1. Quitar el wrapper `<div class="row g-3">` y su `</div>` de cierre.
2. Quitar las dos etiquetas `<div class="col-md-6">` y sus `</div>` de cierre.
3. La card de **Soportes** queda como hijo directo a ancho completo. En su etiqueta
   de apertura, cambiar `class="sgi-card h-100 d-flex flex-column"` por
   `class="sgi-card d-flex flex-column"` (el `h-100` servía para igualar la altura
   de las dos columnas; ya no aplica). El resto del markup interno de Soportes
   (`#docs-empty-state`, `#docs-list`, las llamadas a `document_row`, el botón
   "Subir") NO cambia.
4. **Eliminar por completo** la card de **Observaciones**: el comentario
   `<?php /* ── Observaciones ──────────────────────────── */ ?>`, su columna
   `<div class="col-md-6">` y todo el `<div class="sgi-card h-100 d-flex flex-column">`
   de observaciones (que contiene `#obs-count`, `#obs-chat-scroll`, las llamadas a
   `element('observation_bubble', …)`, `#obs-empty-state` y el `<form id="obs-form">`
   anidado). Eliminar esa card resuelve además el `<form>` anidado inválido.

Tras este paso, el comentario `── Soportes + Observaciones (grid 2 columnas) ──`
queda obsoleto; reemplazarlo por `<?php /* ── Soportes (ancho completo) ──── */ ?>`.

- [ ] **Step 2: Quitar `observation_chat_init` y añadir el drawer**

1. Eliminar la línea `<?= $this->element('observation_chat_init') ?>` (el drawer
   es autocontenido).
2. Insertar el drawer **después de la línea `<?= $this->Form->end() ?>`** que
   cierra el formulario principal `pettyCashEditForm` — debe quedar FUERA de ese
   `<form>` (esa línea está justo antes del comentario `── MODALES ──`):

```php
<?= $this->element('observations/drawer', [
    'observations'    => $record->petty_cash_observations ?? [],
    'count'           => count($record->petty_cash_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $record->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>
```

NO toques los demás elementos del final del archivo (`link_invoices_modal`,
`document_row_template`, `regress_status_modal`).

- [ ] **Step 3: Limpieza de variable muerta**

La variable `$obsCount` (definida cerca del inicio del archivo como
`$obsCount = count($record->petty_cash_observations ?? []);`) solo se usaba en la
card de observaciones eliminada. Verifícalo con `grep -n 'obsCount' templates/PettyCashRecords/edit.php`:
si tras el Step 1 solo aparece en su línea de asignación, elimina esa línea. Si
tuviera otro uso, déjala.

- [ ] **Step 4: Verificar y commitear**

```bash
php -l templates/PettyCashRecords/edit.php
git add templates/PettyCashRecords/edit.php
git commit -m "refactor(view): PettyCashRecords/edit usa el drawer de observaciones compartido"
```
El mensaje de commit debe terminar con la línea:
`Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

- [ ] **Step 5: Validación manual**

`php bin/cake server`, abrir `PettyCashRecords/edit` de un registro de Caja Menor:
la card de Soportes se ve a ancho completo; el disparador del drawer aparece fijo
al borde derecho; abrir el drawer, publicar una observación y verificar que
aparece y el contador sube; subir/eliminar un soporte; recorrer las acciones del
pipeline (avanzar/regresar/pagos). Consola del navegador sin errores JS.

---

## Self-review (cobertura del spec)

- Módulo PettyCashRecords, spec: "solo `edit`: observaciones (`observation_bubble`)
  → drawer. (`index` y `view` ya alineados.)" → Task 1. ✔
- `index`, `view`, `add` → no se tocan (ya alineados / fuera de alcance). ✔

Nota: el chat viejo `observation_bubble*` no se elimina en este plan — se retira
al final de la migración completa, cuando ningún módulo lo use.
