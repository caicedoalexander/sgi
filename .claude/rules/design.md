# SGI — Sistema de Diseño

Referencia de diseño del proyecto para mantener coherencia visual entre vistas y conversaciones futuras.

---

## Fundamento visual

El lenguaje de diseño del SGI usa **bordes en lugar de sombras** como elemento diferenciador. Sin `box-shadow`, sin `border-radius` notable. La jerarquía visual se construye con:

- **Borde superior de 2px** en el color de acento → identifica tarjetas de datos (stat cards)
- **`box-shadow: inset 2px 0 0`** → identifica el ítem activo en el sidebar
- **Borde izquierdo 2px** en el título de la navbar → marca el contexto actual
- **Borde izquierdo 2px** separando paneles (login) → divisor estructural de marca

Todos los bordes de acento usan `var(--primary-color)` excepto cuando el módulo tiene su propio color asignado.

---

## Tipografía

**Fuente:** Inter Variable (local, `webroot/fonts/Inter-Variable.ttf`)

```css
@font-face {
    font-family: 'Inter';
    src: url('../fonts/Inter-Variable.ttf') format('truetype');
    font-weight: 100 900;
}
body { font-family: 'Inter', system-ui, sans-serif; }
```

### Escala tipográfica usada

| Uso | Tamaño | Peso | Notas |
|-----|--------|------|-------|
| Título principal (h1 dashboard) | `1.8rem` | 700 | `letter-spacing: -.03em` |
| Número stat card | `2.4rem` | 700 | `letter-spacing: -.05em`, `line-height: 1` |
| Título navbar / nav-link | `.875rem` | 600 | `letter-spacing: -.01em` |
| Nav-link sidebar | `.875rem` | 400/500 (active) | — |
| Etiqueta micro-caps | `.58–.65rem` | 600 | `text-transform: uppercase`, `letter-spacing: .12–.14em` |
| Subtítulo usuario footer | `.7rem` | 400 | `rgba(255,255,255,.35)` |
| Quick tile label | `.78rem` | 500 | `color: #444` |

---

## Paleta de colores

Definida en `:root` en `webroot/css/styles.css`:

```css
:root {
    --bg-dark:          #212529;   /* Fondo sidebar, fondo login */
    --primary-color:    #469D61;   /* Verde corporativo — acento principal */
    --secondary-color:  #CD6A15;   /* Naranja — módulo Proveedores */
    --accent-color:     #83542B;   /* Marrón — sin uso activo aún */
    --background-color: #f5f5f5;   /* Fondo del área de contenido */
    --border-color:     #e0e0e0;   /* Bordes neutros en contexto claro */
}
```

### Asignación de colores por módulo

| Módulo | Color | Variable |
|--------|-------|----------|
| Facturas, Empleados, Aprobadores | Verde | `--primary-color` |
| Proveedores | Naranja | `--secondary-color` |
| Usuarios, Roles, Catálogos | Oscuro | `--bg-dark` |

---

## Componentes

### Stat Card (`.sgi-stat-card`)

Tarjeta de contador en el dashboard. Borde superior de 2px como acento de color.

```css
.sgi-stat-card {
    background: #fff;
    border: 1px solid var(--border-color);
    border-top: 2px solid var(--primary-color);
    transition: border-color .2s ease;
}
.sgi-stat-card:hover { border-color: var(--primary-color); }
/* Variantes: .accent-secondary  .accent-dark */
```

**Estructura interna:**
- Etiqueta: `.6rem` micro-caps, `color: #aaa`
- Ícono: alineado derecha, `opacity: .85`
- Número: `2.4rem`, `fw-bold`, `letter-spacing: -.05em`, `color: #111`

### Quick Tile (`.sgi-quick-tile`)

Acceso rápido del dashboard. Borde superior neutral → verde al hover.

```css
.sgi-quick-tile {
    background: #fff;
    border: 1px solid var(--border-color);
    border-top: 2px solid var(--border-color);
    transition: border-top-color .15s ease, border-color .15s ease;
}
.sgi-quick-tile:hover {
    border-top-color: var(--primary-color);
    border-color: #ccc;
}
```

### Input Group (`.sgi-input-group`)

Campos de formulario (login y otros). Borde verde al recibir foco.

```css
.sgi-input-group {
    border: 1px solid var(--border-color);
    transition: border-color .15s ease;
}
.sgi-input-group:focus-within { border-color: var(--primary-color); }
```

Inputs internos: `border-0 shadow-none` (Bootstrap), el borde lo maneja `.sgi-input-group`.

### Botón primario (`.sgi-btn-primary`)

```css
.sgi-btn-primary {
    background-color: var(--primary-color);
    color: #fff;
    border: 1px solid var(--primary-color);
    border-radius: 0;
    font-weight: 500;
    font-size: .875rem;
}
.sgi-btn-primary:hover { background-color: #3a8752; }
```

---

## Sidebar

- **Fondo:** `bg-dark` Bootstrap (`#212529`)
- **Logo:** cuadrado `36×36px`, fondo `--primary-color`, ícono `bi-building`
- **Nav-link activo:** `box-shadow: inset 2px 0 0 var(--primary-color)` — sin fondo
- **Nav-link hover:** `box-shadow: inset 2px 0 0 rgba(255,255,255,.18)` + fondo `rgba(255,255,255,.04)`
- **Nav headings:** `.58rem`, `letter-spacing: .14em`, `rgba(255,255,255,.25)`
- **Divisores:** `height:1px; background: rgba(255,255,255,.07)`
- **Avatar:** cuadrado `32×32px`, fondo `--primary-color`
- **Botón logout:** clase `.sgi-sidebar-logout`, borde `rgba(255,255,255,.1)`
- **Active state:** detectado por `$currentController` en el layout

## Navbar superior (`.sgi-topbar`)

```css
.sgi-topbar { background: #fff; border-bottom: 1px solid var(--border-color); min-height: 52px; }
.sgi-topbar-title { border-left: 2px solid var(--primary-color); padding-left: .6rem; }
```

## Login

Layout de dos paneles full-height:
- **Panel izquierdo** (45%, `d-none d-lg-flex`): fondo `--bg-dark`, branding centrado
- **Panel derecho** (flex-grow-1): fondo `#fff`, `border-left: 2px solid var(--primary-color)`, formulario centrado

El borde verde que divide los dos paneles es la expresión más directa del lenguaje de bordes del sistema.

---

## Reglas generales

| ✅ Usar | ❌ Evitar |
|---------|----------|
| `border-radius: 0` o `2px` máximo | `rounded-3`, `rounded-circle` en contenedores |
| Bordes como jerarquía visual | `box-shadow`, `shadow-sm`, `shadow-lg` |
| Micro-caps para etiquetas de sección | Subtítulos en tamaño normal |
| `--primary-color` para acentos | `btn-success`, `text-success`, `border-success` Bootstrap |
| `letter-spacing` negativo en títulos grandes | Tipografía con tracking positivo en títulos |
| Inter Variable local | Fuentes del sistema como fallback principal |

---

## Carga de CSS (orden importante)

```html
<!-- 1. Bootstrap (base) -->
<link href="bootstrap.min.css" rel="stylesheet">
<!-- 2. Bootstrap Icons -->
<link href="bootstrap-icons.min.css" rel="stylesheet">
<!-- 3. Flatpickr -->
<link href="flatpickr.min.css" rel="stylesheet">
<!-- 4. Nuestros estilos — deben ir DESPUÉS de Bootstrap para sobreescribir -->
<?= $this->Html->css('styles') ?>
<!-- 5. Inline <style> — solo CSS estructural (posicionamiento, layout) -->
```

---

## Archivos clave

| Archivo | Contenido |
|---------|-----------|
| `webroot/css/styles.css` | Variables, tipografía, todos los componentes SGI |
| `webroot/fonts/Inter-Variable.ttf` | Fuente Inter (100–900) |
| `webroot/js/sgi-common.js` | Clickable rows, Flatpickr, AutoNumeric |
| `templates/layout/default.php` | Layout principal con sidebar + topbar |
| `templates/layout/login.php` | Layout split-panel del login |
