# Migración de los módulos de flujo al diseño de Facturas

**Fecha:** 2026-05-20
**Estado:** Diseño aprobado

## Contexto

El módulo de Facturas (`Invoices`) fue rediseñado al Sistema de Diseño v2. Una
auditoría de los demás módulos con flujo (pipeline) encontró que varias de sus
vistas no comparten la estructura HTML, clases ni estilos de Facturas.

En una ronda previa se consolidaron los **elementos compartidos** (ver
`docs/superpowers/specs/2026-05-20-consolidacion-elementos-compartidos-design.md`):
existen y están estables `templates/element/observations/drawer.php` (+ `chat_item`,
`chat_avatar`), `templates/element/documents_section.php`, `templates/element/pipeline_sidebar.php`
(reescrito a clases v2) y `templates/element/document_row.php`.

Este spec cubre la **migración de los módulos de flujo** para que consuman esos
elementos y adopten la estructura de Facturas.

## Objetivo

Cada módulo de flujo se ve y se estructura como Facturas: mismo dialecto de
listado, mismo drawer de observaciones, misma sección de soportes, sin markup
legacy en view/edit. Al terminar, se retira el chat de observaciones viejo.

## Hallazgos que fundamentan el alcance

- **Listados rotos.** Los `index` de Advances, Refunds y PaymentSchedulings usan
  el dialecto `.sgi-row-fact*` / `.sgi-status-tab*`; esas clases **no existen en
  ningún CSS** (`grep -rn "sgi-row-fact\|sgi-status-tab" webroot/css/` no devuelve
  nada) — renderizan sin estilos. `NoveltyLiquidationDocs/index` usa una `<table>`
  Bootstrap cruda. El dialecto canónico y funcional es el de `Invoices/index`:
  tabla con grid CSS inline dentro de `.sgi-card` + chips `.chip` (clases
  definidas en `components.css`). Migrar a esa estructura es corrección de un bug,
  no una elección de estilo.
- **Chat de observaciones viejo.** 10 vistas de módulo + `Employees/view` siguen
  usando `observation_bubble` (o markup ad-hoc) en lugar del `observations/drawer`
  compartido.
- **`add.php` fuera de alcance.** El patrón `card card-primary` + `card-header` +
  `sgi-icon-chip` de los formularios de creación lo usan 23 `add.php` de toda la
  app, incluido `Invoices/add.php`. No es una divergencia entre módulos de flujo y
  Facturas; rediseñar formularios de creación es un esfuerzo aparte. Los `add.php`
  no se tocan en esta migración.
- **`pipeline_sidebar` ya resuelto.** La ronda de consolidación reescribió el
  element; los 6 módulos que lo consumen quedaron estilizados. No se re-toca.

## Alcance

Incluye:

- **6 módulos de flujo:** Advances, EmployeeNovelties, PettyCashRecords, Refunds,
  PaymentSchedulings, NoveltyLiquidationDocs — vistas `index`, `view`, `edit`
  (y `legalization` en Advances).
- **`Employees/view`** — únicamente la migración de su chat de observaciones.
- Retiro de los elementos viejos `observation_bubble.php`,
  `observation_bubble_template.php`, `observation_chat_init.php` al final.

NO incluye:

- Los `add.php` de ningún módulo.
- `EmployeeNovelties/active.php` (vista de calendario, no marcada por la auditoría).
- El resto de `Employees/view` más allá de sus observaciones.
- Re-tocar `pipeline_sidebar` ni los demás elementos compartidos.

## Decisiones de diseño

- **Dialecto de listado:** canónico = la estructura de `Invoices/index` (tabla con
  grid CSS inline en `.sgi-card` + chips `.chip`). El dialecto `.sgi-row-fact*` /
  `.sgi-status-tab*` se elimina (clases sin CSS).
- **Observaciones:** todas las vistas adoptan `element('observations/drawer', …)`.
- **`add.php`:** fuera de alcance (ver hallazgos).
- **`Employees/view`:** se migra solo su chat de observaciones, para poder retirar
  el trío viejo `observation_bubble*`.
- **Decomposición:** un spec (este); la implementación se planifica y ejecuta
  **módulo por módulo**, cada uno con su plan propio.

## Enfoque uniforme por módulo

Cada módulo se alinea a Facturas aplicando los patrones ya establecidos:

1. **`index`** → estructura de `Invoices/index.php`: tabla con grid CSS inline en
   `.sgi-card`, chips `.chip`/`.dot`, search bar con `<label class="input">`,
   filtros en panel colapsable (`.sgi-card compact` + `.input-label`),
   `.pipeline-mini` y pills `pill-sm` soft, empty state `.empty-state`
   (`.es-icon`/`.es-title`/`.es-msg`).
2. **Observaciones** → reemplazar `observation_bubble` / `observation_chat_init`
   (o markup ad-hoc) por `element('observations/drawer', ['observations'=>…,
   'count'=>…, 'formUrl'=>…, 'currentUserName'=>…])`, incluido fuera del `<form>`
   principal de la vista. Referencia: `Invoices/edit.php`.
3. **Soportes** → reemplazar markup inline de la sección de documentos por
   `element('documents_section', ['groups'=>…, 'totalDocs'=>…, 'canUpload'=>…,
   'uploadModalId'=>…])`; las filas via `document_row`. Referencia: `Invoices/edit.php`.
4. **Markup legacy en `view`/`edit`** → eliminar:
   - `<table class="table …">` Bootstrap crudas → filas/cards del sistema (`.field-row`,
     `.col-flex`, `document_row`, etc. según el caso).
   - Bordes y estilos inline (`border:1px solid`, `background:…`, `font-size` en px/rem) →
     tokens y clases del sistema; regla dura "sin bordes".
   - Divisores de sección manuales (`text-uppercase fw-semibold` + barra `height:1px`)
     → `.sgi-label` + `.hr`.
   - `.form-label` → `.input-label`; footers de acción ad-hoc → `.sgi-edit-footer`
     donde la vista tenga footer de edición.

`pipeline_sidebar` no se re-toca (ya consumido por todos los view/edit de flujo).

## Desglose por módulo

Trabajo conocido por la auditoría; el plan de cada módulo confirmará el detalle
contra el código antes de implementar.

### Refunds
- `index` → dialecto `.sgi-row-fact`/`.sgi-status-tabs`/`.sgi-search-bar` → canónico.
- `view` → observaciones (`observation_bubble`) → drawer.
- `edit` → observaciones → drawer; cosmética `.sgi-flex-divider`→`.hr`,
  `.form-label`→`.input-label`.

### PaymentSchedulings
- `index` → dialecto `.sgi-row-fact` → canónico.
- `view` → observaciones → drawer.
- `edit` → observaciones → drawer.

### PettyCashRecords
- `index`, `view` → ya alineados (no se tocan).
- `edit` → observaciones (`observation_bubble`) → drawer.

### EmployeeNovelties
- `index` → ya usa `.row-fact`/`.chip`; pasar filtros a panel colapsable;
  **corregir bug**: etiquetas basura `</content>` / `</invoke>` al final del archivo.
- `view` → observaciones ad-hoc → drawer; soportes en cards Bootstrap con bordes →
  `documents_section`; historial en `<table>` → filas del sistema.
- `edit` → quitar `card-body p-4 !important` y divisores manuales; observaciones
  → drawer; footers de acción ad-hoc → patrón del sistema; alerts con `border-left`
  inline → componente del sistema.

### Advances
- `index` → dialecto `.sgi-row-fact` → canónico; añadir search bar.
- `view` → ligero; observaciones si las tuviera → drawer.
- `legalization.php` → el más pesado: quitar `border:1px solid` inline (regla dura),
  tablas Bootstrap crudas, modales con markup inline, ~150 líneas de estilos inline
  en la sección de soportes → `documents_section`; observaciones → drawer;
  divisores manuales → `.sgi-label`+`.hr`; variantes de botón Bootstrap crudas →
  botones del sistema.

### NoveltyLiquidationDocs
- `index` → reescritura completa: `<table>` Bootstrap dentro de `.card-primary` →
  estructura de `Invoices/index` (`.row-fact`/`.chip`/search/filtros/empty state).
- `view` → varias `<table>` Bootstrap crudas → filas del sistema; bloques de
  soporte con estilos inline → `documents_section`/`document_row`; cards de firma
  ad-hoc → patrón del sistema; observaciones → drawer.
- `edit` → cards de firma ad-hoc → patrón del sistema; `.form-label`→`.input-label`;
  bloque "D. Liquidación" con estilos inline pesados → tokens/clases del sistema;
  observaciones → drawer; footer de acciones → `.sgi-edit-footer` si aplica.

### Employees/view
- Solo: observaciones ad-hoc (`observation_bubble`) → `observations/drawer`.

## Orden de ejecución

1. **Refunds** — ejercita los 3 concerns (index, observaciones, limpieza) con
   dificultad media; valida el patrón de migración.
2. **PaymentSchedulings** — casi gemelo de Refunds; reutiliza el patrón.
3. **PettyCashRecords** — solo `edit`/observaciones; rápido.
4. **EmployeeNovelties** — index (bug) + view/edit con legacy.
5. **Advances** — incluye `legalization.php`, el panel más pesado.
6. **NoveltyLiquidationDocs** — index reescrito + view/edit; el más pesado.
7. **Employees/view** — observaciones únicamente; plan mínimo de cierre.

## Cierre de la migración

Tras migrar el último consumidor (en el orden de arriba, `Employees/view` es el
último que usa el chat viejo):

- Eliminar `templates/element/observation_bubble.php`,
  `templates/element/observation_bubble_template.php`,
  `templates/element/observation_chat_init.php`.
- Verificar que no quedan referencias a `.sgi-row-fact*` / `.sgi-status-tab*` ni a
  `observation_bubble*` en `templates/`.

Este cierre se ejecuta como parte del último plan (Employees/view) o como un paso
final de verificación.

## Criterios de validación manual

Sin tests automatizados (política del proyecto). Tras el plan de cada módulo,
levantar `php bin/cake server` y verificar, sin errores en consola del navegador:

1. **`index`** del módulo — filas con `.row-fact`, chips por estado, search bar y
   filtros colapsables; `.pipeline-mini` y pills se ven estilizados (antes del
   cambio se veían sin estilo en Advances/Refunds/PaymentSchedulings); paginación
   y click en fila funcionan.
2. **`view`** — paneles izquierdo (hero/pipeline/registro) y derecho consistentes
   con Facturas; soportes via `documents_section`; sin tablas Bootstrap crudas ni
   bordes inline.
3. **`edit`** — secciones, soportes y drawer de observaciones; publicar una
   observación desde el drawer y verificar que aparece y el contador sube;
   subir/eliminar un soporte.
4. Recorrer las acciones del pipeline propias del módulo (avanzar/regresar/pagos)
   y confirmar que siguen operando.

## Flujo de trabajo

Un spec (este) → por cada módulo, en el orden de ejecución: `writing-plans` genera
el plan del módulo → ejecución con subagentes (implementador + revisor de spec por
tarea; revisión de calidad una vez al final del plan) → validación manual del
usuario → siguiente módulo.
