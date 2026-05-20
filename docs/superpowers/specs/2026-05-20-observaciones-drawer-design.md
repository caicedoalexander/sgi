# Observaciones como drawer flotante en `Invoices/edit` · Diseño

**Fecha:** 2026-05-20
**Topic:** Mover el chat de Observaciones de la vista de edición de facturas a un panel lateral (drawer) abierto desde un disparador flotante

---

## Contexto

En `templates/Invoices/edit.php` la sección **Observaciones** es hoy una tarjeta inline
(`.sgi-card.sgi-obs-card`) que comparte una `.row g-3` al 50/50 con la tarjeta de **Soportes**
(dos `col-md-6`). Esto tiene dos inconvenientes:

- El chat queda en una columna corta y angosta — incómodo para conversar y leer historial.
- Soportes queda artificialmente comprimido a media columna.

El chat de Observaciones es un componente compartido por 6 templates. Su inicialización vive en
`templates/element/observation_chat_init.php`, que arranca `SgiObservationChat`
(`webroot/js/sgi-observation-chat.js`) contra los selectores estándar `#obs-form`,
`#obs-chat-scroll`, `#obs-empty-state`, `#obs-count`.

El sistema de diseño documenta el patrón **Side drawer** en `docs/design/overlays.md`
("Panel lateral para vista previa rápida sin perder el contexto") — 440px desde la derecha,
alto completo, franja `border-left: 3px` primary, sin sombra, slide-in.

**Decisión de form factor (aprobada):** side drawer, no overlay full-screen ni modal centrado.

## Solución

El mecanismo del drawer es un **Bootstrap Offcanvas** (`offcanvas offcanvas-end`) restilizado
para coincidir con el spec `.drawer`. Bootstrap 5 ya está cargado; su componente `offcanvas`
aporta gratis el slide-in, backdrop, cierre con Esc / click afuera, bloqueo de scroll y manejo
de foco — el mismo criterio por el que el modal de Soportes usa un componente nativo de
Bootstrap. No se escribe JS propio de apertura/cierre.

### 1. Disparador flotante — `.sgi-obs-trigger`

Nueva clase de componente en `webroot/css/components.css`. Pestaña fija al borde derecho del
viewport, centrada verticalmente:

- `position: fixed; right: 0; top: 50%; transform: translateY(-50%); z-index: 1030`
  (debajo del backdrop del offcanvas — `z-index 1040` — para que quede cubierto al abrir).
- Fondo `var(--primary-color)`, ícono `bi-chat-left-text` blanco, sin sombra, esquina izquierda
  redondeada (`var(--radius-sm)`), padding cómodo.
- Incluye un badge con el conteo de observaciones. Ese badge lleva `id="obs-count"` —
  `SgiObservationChat` ya actualiza ese selector al publicar, así que el conteo se mantiene vivo
  sin tocar el JS. El badge se oculta cuando el conteo es 0 (igual que hoy).
- Atributos `data-bs-toggle="offcanvas" data-bs-target="#obsDrawer"` — sin JS adicional.

Es un patrón no documentado en `overlays.md` (no hay FAB); se mantiene minimalista y on-brand
(primary, sin sombra, esquinas del sistema).

### 2. Panel drawer — Bootstrap Offcanvas restilizado

Nuevo elemento `templates/element/invoice_edit/observations_drawer.php`, renderizado al final de
`edit.php` junto a los modales (`uploadInvoiceDocModal`, `regress_status_modal`), **fuera** del
`#invoiceEditForm`.

Markup: `<div class="offcanvas offcanvas-end sgi-obs-drawer" id="obsDrawer" tabindex="-1">` con
tres zonas:

- **Header** — título "Observaciones" y botón de cierre (`.btn-icon` con `data-bs-dismiss="offcanvas"`).
- **Body** — el chat: `#obs-chat-scroll` (burbujas `observation_bubble`) + `#obs-empty-state`.
- **Footer** — la barra de input `.sgi-obs-input-bar` con `#obs-form`, el textarea `#obs-message`
  y el botón de envío.

Restilizado con CSS (`webroot/css/components.css`, clase puente `.sgi-obs-drawer`):
`--bs-offcanvas-width: 440px`, sin `box-shadow`, `border-left: 3px solid var(--primary-color)`,
header/footer con `border` reemplazado por línea fina `var(--rule)`.

### 3. Reubicación del contenido del chat

El contenido actual de la tarjeta Observaciones (lista de burbujas, empty state, barra de input
con su `Form->create(... addObservation ...)`) se **mueve** tal cual al body/footer del nuevo
elemento `observations_drawer.php`. Se conservan idénticos los IDs `#obs-form`,
`#obs-chat-scroll`, `#obs-empty-state`, `#obs-count` y la clase `auto-resize` del textarea, de
modo que `observation_chat_init.php` y `SgiObservationChat` siguen funcionando **sin cambios**.

`observation_chat_init.php` se sigue incluyendo en `edit.php` como hoy.

Beneficio colateral: hoy `#obs-form` (un `<form>`) está anidado dentro de `#invoiceEditForm`
(otro `<form>`) — HTML inválido. Al mover el drawer fuera del form principal, la anidación
desaparece.

### 4. Impacto en el layout — Soportes a ancho completo

La `.row g-3` que hoy contiene Soportes + Observaciones se elimina como grid de dos columnas:
la tarjeta de **Soportes** pasa a ancho completo (sin `col-md-6`, ocupa el ancho del `<main>`).
La segunda `col-md-6` (Observaciones) se retira de ese punto del template.

### 5. JS

- Apertura/cierre: 100% Bootstrap Offcanvas vía `data-bs-toggle` / `data-bs-dismiss`. Sin JS propio.
- Único ajuste: hacer scroll de `#obs-chat-scroll` al fondo en el evento `shown.bs.offcanvas`
  del drawer — el panel está oculto al cargar la página, así que el scroll-al-fondo inicial de
  `SgiObservationChat` puede no aplicar hasta que el panel se muestre. Este listener mínimo se
  agrega en el bloque `script` de `observations_drawer.php` (no en `observation_chat_init.php`,
  que es compartido por 6 templates y no debe cargar lógica específica del offcanvas).

## Fuera de alcance

- Los otros 5 templates que usan el chat de observaciones (`PaymentSchedulings/edit`,
  `EmployeeNovelties/edit`, `Employees/view`, `PettyCashRecords/edit`, `Refunds/edit`)
  conservan la tarjeta inline. Migrarlos al drawer es un follow-up separado.
- Cambios en `SgiObservationChat` o en el backend de `addObservation`.
- Notificaciones / indicador de "observaciones sin leer" — el badge solo muestra el conteo total.

## Criterios de validación manual

Este proyecto no usa tests automatizados. Tras implementar, levantar `php bin/cake server` y
abrir la edición de una factura:

1. **Disparador:** se ve una pestaña flotante con ícono de chat pegada al borde derecho,
   centrada verticalmente; si la factura tiene observaciones, muestra el conteo en un badge.
2. **Apertura:** al hacer clic, el panel entra deslizando desde la derecha (440px, alto
   completo, franja primary a la izquierda, sin sombra) con backdrop; la factura queda atenuada
   detrás.
3. **Cierre:** el panel cierra con el botón ×, con Esc y con click en el backdrop.
4. **Chat funcional:** publicar una observación la agrega a la lista sin recargar; el conteo del
   badge del disparador se actualiza; el empty state desaparece al haber la primera observación.
5. **Scroll:** al abrir el panel, la lista de observaciones aparece desplazada al último mensaje.
6. **Layout:** la tarjeta de Soportes ocupa el ancho completo de la columna de contenido; ya no
   hay una tarjeta de Observaciones inline.
7. **Sin form anidado:** inspeccionar el DOM — `#obs-form` ya no está dentro de `#invoiceEditForm`.
8. `composer cs-check` no introduce errores nuevos en los archivos tocados.
