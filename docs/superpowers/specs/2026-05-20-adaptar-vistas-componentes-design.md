# Adaptar vistas a componentes v1.1 — arreglos puntuales · Diseño

**Fecha:** 2026-05-20
**Topic:** Arreglos puntuales en las vistas de facturas, empleados y novedades tras la integración de los componentes v1.1

---

## Contexto

Tras integrar los 17 componentes "Componentes Faltantes" (docs + CSS), se auditaron las vistas de facturas, empleados y novedades. Hallazgos clave:

1. **El estilado de Bootstrap y Select2 ya está hecho.** `webroot/css/components.css` (sección A "Bootstrap overrides") ya reestila `.form-select`, `.dropdown`, `.alert`, modales y paginación nativos. Existe `webroot/css/sgi-select2-overrides.css` (320 líneas) que alinea Select2 con el sistema. No hay trabajo visual pendiente ahí.
2. **Los 17 componentes v1.1 son CSS puro — sin JavaScript.** Adoptar los interactivos (`.menu`, `.toast`, `.drawer`, `.cmdk`, `.acc`) exigiría escribir JS que no existe; Bootstrap ya cubre esos casos. Fuera de alcance.
3. Esta fase se limita a **4 arreglos puntuales de alto valor y bajo riesgo, sin JS nuevo.**

## Alcance — 4 ítems

### Ítem 1 — Facturas: migrar el `confirm()` nativo

`templates/Invoices/edit.php` (~línea 779) tiene un formulario con `onsubmit="return confirm(...)"` — la única confirmación con `confirm()` nativo del área. El resto del proyecto usa el patrón `data-sgi-confirm="..."` (manejado por `SgiDialogs.confirm`, ya presente p. ej. en `edit.php:749`).

**Cambio:** reemplazar el `onsubmit="return confirm(...)"` por el atributo `data-sgi-confirm` con el mismo mensaje, sobre el mismo formulario. No se escribe JS — el manejador `data-sgi-confirm` ya existe.

### Ítem 2 — Empleados: Select2 en el formulario

`templates/element/Employees/form.php` tiene 11 `<select class="form-select">` nativos sin búsqueda. Los templates de Empleados **no cargan** `element('cdn_select2')`.

**Cambio:**
- Añadir la clase `select2-enable` a los **5 selects respaldados por catálogos** (potencialmente con muchas opciones): Cargo (`position`), Cargo del supervisor (`supervisor_position`), Centro de Operación (`operation_center`), Centro de Costos (`cost_center`), Organización temporal (`temporary_organization`).
- Dejar como `.form-select` plano los enum cortos de lista fija: estado civil (`marital_status`), nivel educativo (`education_level`), estado (`status`). Select2 no aporta (no alcanzan el umbral `minimumResultsForSearch:7`).
- Incluir `element('cdn_select2')` en el formulario de empleados para que jQuery + Select2 + `sgi-select2-overrides.css` se carguen en `add` y `edit`.
- El init es automático vía `sgi-common.js` (clase `.select2-enable`). No se escribe JS.

**Nota:** los nombres de campo exactos se confirman contra `form.php` al implementar; la regla es "catálogos de BD → Select2; enum corto fijo → plano".

### Ítem 3 — Novedades: limpieza segura de Select2

`templates/EmployeeNovelties/add.php` inicializa `#massive-employees` dos veces: vía la clase `.select2-enable` (auto-init de `sgi-common.js`) **y** vía un `.select2()` manual en un `<script>` inline (~línea 245).

**Cambio:**
- Eliminar **solo** el `.select2()` manual redundante de `add.php` (~245). Antes de eliminar, verificar que su configuración no aporte ninguna opción que el init de `sgi-common.js` no cubra; si aporta algo, trasladar esa diferencia a la convención estándar en vez de borrar a ciegas.
- **No se toca** `webroot/js/sgi-calendar.js` ni la clase `.select2` (sin `-enable`) de los filtros del calendario de Novedades — tienen su propio init y tocarlos arriesga regresión.
- Documentar la convivencia de las dos clases en `docs/design/formularios.md`: `.select2-enable` = auto-init global (`sgi-common.js`); `.select2` = filtros del calendario, init propio en `sgi-calendar.js`.

### Ítem 4 — Banner de factura rechazada

Añadir un `.banner danger` (componente v1.1, CSS puro) al inicio del contenido de `templates/Invoices/edit.php`, renderizado condicionalmente cuando la factura está rechazada (`area_approval = 'Rechazada'`, estado ya existente — ver `CLAUDE.md`). El banner muestra que la factura fue rechazada e incluye el motivo del rechazo si está disponible en la entidad.

Markup base (`docs/design/overlays.md` · Banner inline):
```html
<div class="banner danger">
  <div class="banner-icon"><i class="bi bi-exclamation-triangle"></i></div>
  <div class="banner-body">
    <div class="banner-title">Esta factura fue rechazada</div>
    <div class="banner-msg"><!-- motivo si existe --></div>
  </div>
</div>
```

CSS puro, sin JS. La condición se evalúa en PHP en el render de la vista.

## Fuera de alcance

- Escribir JavaScript para los componentes interactivos v1.1 (`.menu`, `.toast`, `.drawer`, `.cmdk`, `.acc`).
- Banners de "carpeta incompleta" (empleados) y "novedad rechazada" — sus condiciones de dominio no están confirmadas; se añaden después con el mismo patrón si se requiere.
- Tocar `sgi-calendar.js`, los modales Bootstrap, el dropdown "Acciones" de empleados o las flash messages — funcionan y ya están estilizados.
- Unificar las clases `.select2` / `.select2-enable` (solo se documenta la convivencia).

## Criterios de validación manual

Este proyecto no usa tests automatizados. Tras la implementación, levantar `php bin/cake server` y verificar:

1. **Facturas — confirmación:** en `Invoices/edit.php`, disparar el formulario antes migrado; aparece el diálogo `SgiDialogs.confirm` (no el `confirm()` del navegador) y cancelar/confirmar funciona.
2. **Empleados — Select2:** abrir `Employees/add` y `edit`; los 5 selects de catálogo muestran el widget Select2 con búsqueda; los 3 enum cortos siguen como select normal; guardar un empleado funciona (los `name` de los campos no cambiaron).
3. **Novedades — Select2:** abrir `EmployeeNovelties/add`; `#massive-employees` sigue funcionando como multi-select con búsqueda (una sola instancia, sin doble init); los filtros del calendario en `index`/`active` siguen operativos.
4. **Banner:** abrir en `edit` una factura con `area_approval='Rechazada'`; el `.banner danger` aparece arriba con el motivo. Abrir una factura no rechazada; el banner no aparece.
5. **Sin regresión visual:** `composer cs-check` pasa para los `.php` tocados (solo cambios de markup, no debería romper).
