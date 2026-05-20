# Sistema de Diseño SGI · COPCSA — Reglas, copy y convenciones

Reglas duras (no negociables), tono y copy, excepciones permitidas, orden de carga de CSS, archivos clave del proyecto y convención de prefijos.

---

## Reglas duras

Las reglas que NO se rompen. Toda excepción debe documentarse explícitamente en este archivo bajo "Excepciones permitidas".

1. **Sin bordes** — la separación entre elementos se logra con `background-color` (cards blancas sobre fondo gris), barras de acento de 3px o líneas finas `var(--rule)`. Nunca `border: 1px solid` en cards, sidebar, navbar, modales, dropdowns.
2. **Sin sombras** — `box-shadow` está prohibido. La elevación se sugiere con jerarquía de fondo (`#fff` → `--bg-subtle` → `--bg-muted` → `--background-color`).
3. **Datos cuantitativos en mono** — `JetBrains Mono` es OBLIGATORIO para códigos de factura, fechas, montos, IDs, cédulas, nombres de archivo. Aplica con `.sgi-mono` o `.mono`.
4. **Labels en uppercase** — etiquetas de sección y columnas de tabla: 10–11px / 700 / `letter-spacing 0.6–0.8`. Usa `.sgi-label` o `.label-up`.
5. **Pills en variantes soft** — listados y tablas SIEMPRE con `.pill-*-soft`. Sólidos (`.pill-primary`, `.pill-orange`, `.pill-dark`) reservados a hero del sidebar y destacados puntuales.
6. **Sin emoji** — iconos de Bootstrap Icons o SVG inline estilo Lucide. Caracteres especiales (✓, →) solo si refuerzan estado.
7. **Sin admiraciones** salvo errores críticos. Nunca "¡Bienvenido!" ni "¡Ups!".
8. **Esquinas rectas** — cards y celdas de tabla `radius: 0`. Pills `3px`. Botones / inputs `4px`. Sin `border-radius` arbitrarios.
9. **Una `primary` por sección** — una sola acción `.btn-primary` visible por pantalla. Si necesitas más, conviértelas en `secondary` y eleva una.
10. **Inter + JetBrains Mono únicamente** — no introducir nuevas fuentes.

---

## Tono y copy

El SGI es una herramienta operativa para administrativos y supervisores. Tono **directo, profesional, español formal pero no rígido**.

- **Voz: tercera persona / impersonal.** "Aprobado por", "Registrado por", "Pendiente de aprobación".
- **Sin "tú" ni "usted"** directos en UI. Sí en mensajes de ayuda contextual ("Arrastra archivos aquí").
- **Casing por contexto:**
  | Contexto | Casing | Ejemplo |
  |----------|--------|---------|
  | Botones / acciones | Tipo oración | "Nueva Factura", "Agregar pago" |
  | Labels / encabezados | MAYÚSCULAS + tracking | "PIPELINE", "PROVEEDOR" |
  | Estados / pills | MAYÚSCULAS | "PAGADA", "VIGENTE" |
  | Códigos documento | MAYÚSCULAS mono | "CC", "HV", "ARL" |
- **Sin emoji** en producción. Sin admiraciones salvo errores críticos.
- **Valores monetarios:** `$ 120.000` (espacio tras `$`, separador miles con punto, sin decimales) usando `Intl.NumberFormat('es-CO')`.
- **Fechas:** `DD/MM/AAAA` en mono. Timestamps: `13/05/2026 09:35`.
- **Cuentas:** "8 documentos", "248 activos", "3 pendientes" — plurales correctos.

### Helper de formato de moneda

```js
// JS — Intl.NumberFormat es-CO
const fmtMoney = (n, { decimals = 0 } = {}) => {
  const s = n.toLocaleString('es-CO', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
  return '$ ' + s;
};
```

```php
// PHP — helper equivalente
function fmtMoney(float $n, int $decimals = 0): string {
    return '$ ' . number_format($n, $decimals, ',', '.');
}
```

Resultado: `$ 120.000` para enteros, `$ 1.250,50` con decimales. Siempre con clase `.mono` en el DOM.

---

## Excepciones permitidas

Estos casos se apartan deliberadamente de las reglas duras. **Antes de añadir una nueva excepción, documentarla aquí** con selector y justificación.

### Chat bubbles (`.sgi-obs-bubble-body`)

```css
.sgi-obs-bubble.is-mine  .sgi-obs-bubble-body { border-radius: 10px 10px 2px 10px; }
.sgi-obs-bubble.is-other .sgi-obs-bubble-body { border-radius: 10px 10px 10px 2px; }
```
Radio asimétrico 10/2 es convención universal (WhatsApp/Slack/iMessage). Mantiene 2px como ancla al sistema.

### Counter pills (`.sgi-folder-count`)

```css
.sgi-folder-count { border-radius: 10px; padding: .15em .5em; min-width: 1.5em; }
```
Badges numéricos pequeños como contadores; convención visual estándar.

### Signature overlay (`webroot/js/sgi-signature.js`)

Card modal temporal flotante sobre documento con `backdrop-filter: blur(3px)`. **Única excepción que permite `box-shadow`** porque flota sobre fondo borroso y el border sería insuficiente.

### Template editor

Editor visual de plantillas con borders funcionales (zona arrastrable, selección). Los borders son afordancia interactiva, no decoración.

---

## Carga de CSS

Orden de carga obligatorio:

```html
<!-- 1. Bootstrap base -->
<link href="bootstrap.min.css" rel="stylesheet">
<!-- 2. Bootstrap Icons -->
<link href="bootstrap-icons.min.css" rel="stylesheet">
<!-- 3. Flatpickr -->
<link href="flatpickr.min.css" rel="stylesheet">
<!-- 4. Tokens + utilidades (foundation) -->
<?= $this->Html->css('styles') ?>
<!-- 5. Componentes SGI (debe ir después de Bootstrap para overrides) -->
<?= $this->Html->css('components') ?>
```

---

## Archivos clave del proyecto

| Archivo | Contenido |
|---------|-----------|
| `webroot/css/styles.css` | Tokens (`:root`), `@font-face`, base, tipografía, utilidades |
| `webroot/css/components.css` | Catálogo completo de componentes + Bootstrap overrides |
| `webroot/fonts/Inter-Variable.woff2` | Inter Variable 100–900 |
| `webroot/fonts/JetBrainsMono-Variable.woff2` | JetBrains Mono Variable 100–800 |
| `webroot/js/sgi-common.js` | Auto-init: Flatpickr, AutoNumeric, Select2, clickable rows |
| `templates/layout/default.php` | Layout principal (sidebar + topbar) |
| `templates/layout/login.php` | Layout split-panel del login |

---

## Convención de prefijos

El sistema usa dos prefijos según el rol del CSS:

| Sin prefijo | `sgi-` prefijado |
|-------------|------------------|
| Componentes UI: `.btn`, `.pill`, `.input`, `.av`, `.doc`, `.cal`, `.row-fact`, `.pipeline-mini`, `.sb-item`, `.tab`, `.chip` | Utilidades / tipografía: `.sgi-card`, `.sgi-display`, `.sgi-title-page`, `.sgi-title-card`, `.sgi-label`, `.sgi-mono`, `.sgi-body-muted`, `.sgi-body-faint`, `.sgi-fg-*` |

La regla: **componentes** = sin prefijo (más legible en markup); **utilidades de marca/sistema** = `sgi-` para no chocar con Bootstrap o librerías de terceros.
