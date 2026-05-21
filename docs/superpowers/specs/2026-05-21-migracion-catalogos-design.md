# Migración de los catálogos al Sistema de Diseño

**Fecha:** 2026-05-21
**Estado:** Diseño aprobado

## Contexto

Los módulos de flujo (financiero) y de RRHH ya adoptaron el Sistema de Diseño v2.
Los **catálogos** (datos de referencia CRUD) quedaron atrás: las cabeceras ya están
migradas (`sgi-page-header`/`sgi-page-title`, commit `af4e469`), pero el **cuerpo**
de las vistas sigue en Bootstrap viejo — `card card-primary`, `table table-hover`,
`<dl class="row">`.

Auditoría de marcadores (2026-05-21): los 13 catálogos usan `card card-primary` en
todas sus vistas; ninguno usa `.row-fact` / `.field-row` para el cuerpo. Única
excepción ya migrada: `LeaveDocumentTemplates/edit.php` (editor de canvas custom).

## Objetivo

Migrar el cuerpo de las vistas `index` / `view` / `edit` / `add` de los 13
catálogos al dialecto del Sistema de Diseño, dejándolas visualmente consistentes
con el resto de la app. **Migración limpia**: sin tarjetas KPI ni buscador — el
dialecto del sistema, nada más.

## Catálogos en alcance (controlador → archivos a migrar)

| Catálogo | index | view | edit | add |
|---|---|---|---|---|
| Approvers | ✅ | — (no existe) | ✅ | ✅ |
| Providers | ✅ | ✅ | ✅ | ✅ |
| BankingEntities | ✅ | — | ✅ | ✅ |
| OperationCenters | ✅ | ✅ | ✅ | ✅ |
| ExpenseTypes | ✅ | ✅ | ✅ | ✅ |
| CostCenters | ✅ | ✅ | ✅ | ✅ |
| Positions | ✅ | ✅ | ✅ | ✅ |
| MaritalStatuses | ✅ | ✅ | ✅ | ✅ |
| EducationLevels | ✅ | ✅ | ✅ | ✅ |
| DefaultFolders | ✅ | ✅ | ✅ | ✅ |
| NoveltyTypes | ✅ | — | ✅ | ✅ |
| TemporaryOrganizations | ✅ | — | ✅ | ✅ |
| LeaveDocumentTemplates | ✅ | — | ⛔ ya migrado | ✅ |

Total: ~46 archivos. **No se crean vistas que no existan** (los catálogos sin
`view.php` siguen sin él). `LeaveDocumentTemplates/edit.php` **no se toca**.

## Reglas duras

1. **Solo markup de presentación.** No cambiar lógica de controladores, servicios,
   rutas ni nombres de variables de vista. No tocar `Form->control()` salvo el
   contenedor.
2. **Conservar toda la funcionalidad existente**: gates de permisos
   (`$userPermissions[...]`), `Paginator->sort()`, `element('pagination')`,
   botones/modales del Excel wizard (`element('excel_wizard/...')`), checks
   `hasValue()`, `confirm` de los `postLink`, JS embebido.
3. **No introducir clases nuevas ni CSS nuevo.** Solo clases ya definidas en
   `webroot/css/` (verificadas: `.sgi-card`, `.row-fact`, `.field-row`,
   `.empty-state`/`.es-*`, `.btn-icon`, `.pill*`, `.mono`, `.sgi-label`).
4. **Las cabeceras (`sgi-page-header` + `sgi-page-title`) ya están bien** — no
   tocarlas, salvo añadir botones de acción donde el diseño lo indique (view).
5. Validación por archivo: `php -l <archivo>` sin errores. Sin tests automatizados.
6. Slugs y copy en español; mantener los textos actuales (no reescribir labels).

## Patrón por tipo de vista

### A · `index.php` — listado

De `card card-primary` + `table table-hover` a `.sgi-card` (padding 0) con tabla
`.row-fact` (grid CSS). Cada catálogo fija sus columnas con `grid-template-columns`
inline; **la misma plantilla de columnas en la fila `head` y en las filas de
datos**. Última columna = acciones.

Ejemplo completo (ExpenseTypes — columnas `#`, `Nombre`, `Creado`, `Acciones`):

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\ExpenseType> $expenseTypes
 */
$this->assign('title', 'Tipos de Gasto');

$canEdit   = !empty($userPermissions['expense_types']['can_edit']);
$canDelete = !empty($userPermissions['expense_types']['can_delete']);
$gridCols  = '80px 1fr 200px 96px';
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Tipos de Gasto</span>
    <?php if (!empty($userPermissions['expense_types']['can_create'])): ?>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuevo Tipo',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false]
    ) ?>
    <?php endif; ?>
</div>

<div class="sgi-card" style="padding:0;">
    <div class="row-fact head" style="grid-template-columns:<?= $gridCols ?>;" role="row">
        <span><?= $this->Paginator->sort('id', '#') ?></span>
        <span><?= $this->Paginator->sort('name', 'Nombre') ?></span>
        <span><?= $this->Paginator->sort('created', 'Creado') ?></span>
        <span style="text-align:right;">Acciones</span>
    </div>

    <?php $rowCount = 0; foreach ($expenseTypes as $expenseType): $rowCount++; ?>
    <div class="row-fact clickable-row" style="grid-template-columns:<?= $gridCols ?>;"
         data-href="<?= $this->Url->build(['action' => 'view', $expenseType->id]) ?>" role="row">
        <span class="mono" style="color:var(--text-faint);"><?= $this->Number->format($expenseType->id) ?></span>
        <span style="font-weight:600;color:var(--text-strong);"><?= h($expenseType->name) ?></span>
        <span class="mono" style="color:var(--text-muted);"><?= $expenseType->created?->format('d/m/Y H:i') ?></span>
        <span class="d-flex justify-content-end" style="gap:4px;">
            <?= $this->Html->link('<i class="bi bi-eye" aria-hidden="true"></i>',
                ['action' => 'view', $expenseType->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Ver']) ?>
            <?php if ($canEdit): ?>
            <?= $this->Html->link('<i class="bi bi-pencil" aria-hidden="true"></i>',
                ['action' => 'edit', $expenseType->id],
                ['class' => 'btn-icon', 'escape' => false, 'title' => 'Editar']) ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <?= $this->Form->postLink('<i class="bi bi-trash" aria-hidden="true"></i>',
                ['action' => 'delete', $expenseType->id],
                ['confirm' => '¿Está seguro de eliminar este tipo de gasto?',
                 'class' => 'btn-icon', 'escape' => false, 'title' => 'Eliminar']) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?php if ($rowCount === 0): ?>
    <div class="empty-state">
        <div class="es-icon es-icon-neutral"><i class="bi bi-tags" aria-hidden="true"></i></div>
        <div class="es-title">Sin tipos de gasto</div>
        <div class="es-msg">No hay registros para mostrar todavía.</div>
    </div>
    <?php endif; ?>
</div>

<?= $this->element('pagination') ?>
```

**Reglas del index:**

- Mover `element('pagination')` **fuera** del `.sgi-card`, justo debajo.
- `data-href` apunta a `view` si el catálogo tiene `view.php`; si no (Approvers,
  BankingEntities, NoveltyTypes, TemporaryOrganizations, LeaveDocumentTemplates),
  apunta a `edit`. Si no hay permiso de edición y no hay view, omitir
  `clickable-row` y `data-href`.
- La fila de acciones **no** necesita protección anti-click: `.clickable-row`
  ignora automáticamente clicks sobre `a`, `button` y `form` (`postLink` genera
  un form). Mantener un `<span class="d-flex justify-content-end">` solo por
  layout.
- Acciones: `.btn-icon` (no `btn btn-sm btn-outline-*`). Iconos: `bi-eye` ver,
  `bi-pencil` editar, `bi-trash` eliminar.
- `#`/id, fechas, códigos, NITs → clase `.mono`.
- Booleano (p. ej. `active`) → renderizar como pill. **Conservar exactamente el
  mapeo de pills del archivo original** (hoy: `pill-primary-soft` Activo /
  `pill-secondary-soft` Inactivo). No cambiar las variantes de pill.
- Asociaciones: conservar los checks `hasValue()` y el texto de fallback
  (`'<span style="color:var(--text-disabled);">Todos</span>'`, etc.).
- Conservar `Paginator->sort()` dentro de los `<span>` de la fila `head`. Si una
  columna no era ordenable en el original, dejar el texto plano.
- **Excel wizard** (Providers): conservar `element('excel_wizard/buttons', ...)`
  en la cabecera y `element('excel_wizard/modals', ...)` al final del archivo,
  sin cambios.
- Elegir el icono del `empty-state` acorde al catálogo (el mismo de su nav-link
  en `element/sidebar/catalogos.php` es buena elección).

### B · `view.php` — detalle

De `card card-primary` + `<dl class="row">` a `.sgi-card` + lista `.field-row`.
Las acciones (Editar / Eliminar) suben a la cabecera junto a "Volver".

Ejemplo completo (ExpenseTypes):

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\ExpenseType $expenseType
 */
$this->assign('title', 'Tipo de Gasto: ' . $expenseType->name);
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Detalle del Tipo de Gasto</span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['expense_types']['can_edit'])): ?>
        <?= $this->Html->link('<i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $expenseType->id],
            ['class' => 'btn btn-primary btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?php if (!empty($userPermissions['expense_types']['can_delete'])): ?>
        <?= $this->Form->postLink('<i class="bi bi-trash me-1" aria-hidden="true"></i>Eliminar',
            ['action' => 'delete', $expenseType->id],
            ['confirm' => '¿Está seguro?', 'class' => 'btn btn-danger btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
    </div>
</div>

<div class="sgi-card">
    <div class="field-row">
        <span class="k">ID</span>
        <span class="v mono"><?= $this->Number->format($expenseType->id) ?></span>
    </div>
    <div class="field-row">
        <span class="k">Nombre</span>
        <span class="v"><?= h($expenseType->name) ?></span>
    </div>
    <div class="field-row">
        <span class="k">Creado</span>
        <span class="v mono"><?= $expenseType->created?->format('d/m/Y H:i') ?></span>
    </div>
    <div class="field-row is-last">
        <span class="k">Modificado</span>
        <span class="v mono"><?= $expenseType->modified?->format('d/m/Y H:i') ?></span>
    </div>
</div>
```

**Reglas del view:**

- Un `.field-row` por campo del `<dl>` original, en el mismo orden. `.k` = label,
  `.v` = valor. Última fila lleva `is-last`.
- Valores mono: id, fechas, códigos, NIT/documento.
- Pills (booleanos, tipos) → dentro del `.v` tal cual el original los renderiza.
- El botón "Volver" pasa de `btn-outline-dark` a `btn-ghost-card` (variante de
  cabecera del sistema). "Editar" pasa de `btn-warning` a `btn-primary`.
- Eliminar el `card-footer` (sus acciones ya viven en la cabecera).
- **Tablas de registros asociados** (p. ej. Providers/view → "Facturas del
  Proveedor"): migrar también a `.sgi-card` (con `padding:0` si lleva tabla) +
  `.row-fact`, precedida de un encabezado `.sgi-label` o `card-head`. Conservar
  el `<?php if (!empty(...)): ?>` que la envuelve.

### C · `edit.php` y `add.php` — formularios

De `card card-primary` + `card-body`/`card-footer` a `.sgi-card`. Los
`Form->control()` se mantienen con `'class' => 'form-control'` y
`'label' => ['class' => 'form-label']` — el CSS ya los estiliza como el sistema.

Ejemplo completo (ExpenseTypes/edit; `add` es idéntico salvo título y texto del
botón):

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\ExpenseType $expenseType
 */
$this->assign('title', 'Editar Tipo de Gasto');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Tipo de Gasto</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
</div>

<div class="sgi-card">
    <?= $this->Form->create($expenseType) ?>
    <div class="mb-3">
        <?= $this->Form->control('name', [
            'class' => 'form-control',
            'label' => ['text' => 'Nombre', 'class' => 'form-label'],
        ]) ?>
    </div>
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-save me-1" aria-hidden="true"></i>Actualizar
    </button>
    <?= $this->Form->end() ?>
</div>
```

**Reglas de edit/add:**

- Reemplazar `<div class="card card-primary"><div class="card-body">…</div></div>`
  por `<div class="sgi-card">…</div>`. Eliminar los `<div class="card-body">` /
  `card-footer` envolventes.
- **No** cambiar los `Form->control()`: campos, validación, grids `row`/`col-*`,
  `placeholder`, todo se mantiene.
- Botón "Volver" de la cabecera → `btn-ghost-card`.
- Botón submit: `btn btn-primary` (sin `btn-sm`), texto y `bi-save` como en el
  original ("Guardar" en add, "Actualizar" en edit).
- Si el form ya está dentro de la `.sgi-card` y tiene varias secciones, conservar
  la estructura interna; solo cambia el contenedor externo.

## Catálogos con vistas ricas — atención extra

- **NoveltyTypes** (`index` 96 líneas, `edit` 225, `add` 210) y
  **LeaveDocumentTemplates** (`index` 93, `add` 80): formularios con múltiples
  campos/secciones y posible JS. Aplicar el **mismo dialecto** (contenedor
  `.sgi-card`, tabla `.row-fact` en el index), pero **leer el archivo completo**
  y conservar el 100 % de la funcionalidad: campos dinámicos, secciones,
  `<script>` embebido, data-attributes. Ante cualquier estructura que no encaje
  limpiamente en el patrón, conservarla in-situ dentro de la `.sgi-card` (no
  forzar) y dejar nota en el reporte.
- **Providers**: `index` y `view` llevan integración con el Excel wizard y, en
  `view`, una tabla secundaria de facturas asociadas. Ver reglas A y B.

## Criterios de validación manual

Tras la migración, el usuario recorre en el navegador (`php bin/cake server`):

1. Cada catálogo: `index` muestra la tabla `.row-fact`, hover de fila, click de
   fila navega, paginación funciona, ordenamiento por columna funciona.
2. `index` sin registros: aparece el `empty-state`.
3. `view`: campos en `.field-row`; botones Editar/Eliminar/Volver en la cabecera
   operan.
4. `edit`/`add`: el formulario guarda; "Volver" regresa al index.
5. Providers: botones e import del Excel wizard siguen operando; la tabla de
   facturas asociadas se ve correctamente.
6. Permisos: un rol sin `can_edit`/`can_delete` no ve esos botones.
7. Verificación técnica: `grep` de `card card-primary` y `table table-hover` en
   `templates/{catálogos}` → 0 resultados (salvo `LeaveDocumentTemplates/edit`).
   `php -l` limpio en cada archivo tocado.

## Decisiones de alcance

- **Sin KPI ni buscador** — elección del usuario: migración limpia al dialecto.
- **No se crean `view.php` faltantes** — los 5 catálogos sin view siguen igual.
- **`LeaveDocumentTemplates/edit.php` intacto** — ya migrado (editor de canvas).
- **Sin CSS nuevo** — todas las clases necesarias ya existen en `webroot/css/`.
- **Ejecución en paralelo** — un agente por catálogo (13 agentes), cada uno migra
  sus 3-4 vistas siguiendo este spec. Tareas independientes, sin estado
  compartido.
