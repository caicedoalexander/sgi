# Layout de `Invoices/edit.php` — panel izquierdo y footer · Diseño

**Fecha:** 2026-05-20
**Topic:** Corregir dos defectos de layout en la vista de edición de facturas

---

## Contexto

La vista `templates/Invoices/edit.php` (rediseñada en commit `962be2f` para usar un grid Bootstrap `.row > col-lg-4 + col-lg-8`) tiene dos defectos respecto al diseño de referencia de Claude Design:

- **Problema A — panel izquierdo demasiado alto.** La `.row` de Bootstrap aplica `align-items: stretch` por defecto, así que la columna izquierda (`col-lg-4`) se estira a la altura de la columna derecha (el formulario, mucho más largo). Las cards del panel izquierdo quedan apiladas arriba con un gran hueco vacío debajo.
- **Problema B — footer no es una barra fija.** El footer de acciones (Rol, última modificación, "cambios sin guardar", Cancelar, botón Guardar) **existe** (`edit.php:1019-1051`) pero es una `.sgi-card` normal al final de la columna derecha — scrollea con el contenido y solo ocupa el ancho de esa columna. El CSS de la clase correcta (`.sgi-edit-footer`, barra `position:fixed`) fue borrado en el commit `962be2f`.

**Alcance:** solo `Invoices/edit.php`. El commit `962be2f` también borró otro CSS de layout (`sgi-invoice-view-grid`, `sgi-hero-*`, `sgi-section-head`, `sgi-pipeline-v`, `sgi-edit-side-grid`) que otras vistas de edición (`Refunds`, `PaymentSchedulings`, `EmployeeNovelties`) aún referencian — esa rot previa queda **fuera de alcance** (decisión del usuario). Restaurar el CSS de `.sgi-edit-footer*` arregla de paso el footer de esas otras vistas, pero no su layout general.

## Solución

### Problema A — `align-items-start` en la `.row`

En `templates/Invoices/edit.php` (~línea 241), la `.row` que abre el grid de dos columnas:

```
<div class="row g-3 view-anim">
```

pasa a:

```
<div class="row g-3 view-anim align-items-start">
```

`align-items-start` es una utilidad de Bootstrap (`align-items: flex-start`). La columna izquierda deja de estirarse y se ajusta a la altura de su contenido; las cards quedan compactas arriba. No se toca ningún archivo CSS para este problema.

### Problema B — footer como barra fija de ancho completo

**B.1 — Restaurar el CSS de `.sgi-edit-footer*` en `webroot/css/components.css`.** Las clases no tienen CSS hoy; se recupera el bloque borrado en `962be2f` (obtenible con `git show 962be2f^:webroot/css/components.css`). El bloque define:
- `.sgi-edit-footer` — `position: fixed; left: var(--sidebar-width, 260px); right: 0; bottom: 0;` fondo blanco, padding `12px 24px`, `border-top: 1px solid var(--rule)`, `z-index: 20`, flex con `justify-content: space-between`.
- `.sgi-edit-footer-meta` — flex, `font-size: 11.5px`, `color: var(--text-muted)`, gap; incluye `.sep` (separador vertical 1px).
- `.sgi-edit-footer-actions` — flex, gap, `flex-shrink: 0`.
- Media query `max-width: 767.98px` — el footer pasa a columna.

**B.2 — Ajustar la reserva de espacio inferior.** La regla original era `.content-wrapper main:has(.sgi-edit-footer) { padding-bottom: 88px; }`. Como el footer se mueve **fuera** de `<main>` (paso B.3), ese selector ya no casaría. Se reemplaza por una regla que reserve el espacio en el contenedor de contenido independientemente de dónde esté el footer dentro de él: `.content-wrapper:has(.sgi-edit-footer) { padding-bottom: 88px; }`. El nombre exacto del contenedor (`.content-wrapper` u otro) se confirma contra `templates/layout/default.php` al implementar; si difiere, se usa el real.

**B.3 — Mover el bloque del footer.** El footer (`edit.php:1019-1051`) está dentro de `<main class="col-lg-8">`. Se mueve a **después** del cierre de la `.row` (`</div>`, ~línea 1054) y **antes** de `Form->end()` (~línea 1056) — fuera del grid pero dentro del formulario, para que el `<button type="submit">` siga posteando el formulario.

**B.4 — Recambiar las clases del footer.** El contenedor pasa de `class="sgi-card d-flex align-items-center justify-content-between flex-wrap gap-3"` a `class="sgi-edit-footer"`. Sus dos divs internos pasan a `class="sgi-edit-footer-meta"` (la zona Rol / última modificación / "cambios sin guardar") y `class="sgi-edit-footer-actions"` (Cancelar + botón Guardar). El contenido textual, los enlaces, el `data-dirty-indicator` y el `<button type="submit">` se preservan idénticos; solo cambian las clases contenedoras. La condición de render (`<?php if (!empty($viewModel->editableFields) || $canRegress): ?>`) se mantiene.

## Fuera de alcance

- El botón "Guardar borrador" del mockup de Claude Design — es una acción nueva sin ruta de backend; el footer mantiene Cancelar + Guardar como hoy.
- Restaurar el resto del CSS borrado en `962be2f` (`sgi-invoice-view-grid`, `sgi-hero-*`, `sgi-section-head`, `sgi-pipeline-v`, `sgi-edit-side-grid`) y arreglar el layout de `Refunds/edit.php`, `PaymentSchedulings/edit.php`, `EmployeeNovelties/edit.php` — rot previa, tarea separada.
- Migrar `Invoices/edit.php` al patrón compartido `pipeline_sidebar.php` / `sgi-invoice-view-grid`.

## Criterios de validación manual

Este proyecto no usa tests automatizados. Tras la implementación, levantar `php bin/cake server` y abrir la edición de una factura:

1. **Panel izquierdo:** las cards del panel izquierdo (hero, pipeline, registro) quedan compactas arriba, sin un hueco vacío grande debajo — la altura del panel corresponde a su contenido, no a la del formulario.
2. **Footer:** el footer de acciones es una barra fija al fondo del viewport, de ancho completo (desde el borde derecho del sidebar hasta el borde derecho de la ventana), visible sin hacer scroll.
3. **Footer no tapa contenido:** al hacer scroll hasta el final del formulario, la última sección/card queda completamente visible por encima del footer (no oculta detrás).
4. **Submit funciona:** el botón Guardar del footer sigue enviando el formulario correctamente; Cancelar sigue navegando a la vista.
5. **Responsive:** en viewport angosto (<768px) el footer pasa a disposición vertical y sigue usable.
6. `composer cs-check` no introduce errores nuevos en `edit.php`.
