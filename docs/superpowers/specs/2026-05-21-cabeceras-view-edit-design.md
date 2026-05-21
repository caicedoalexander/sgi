# Cabeceras de view/edit consistentes con Facturas

**Fecha:** 2026-05-21
**Estado:** Diseño aprobado

## Contexto

La migración de los módulos de flujo al diseño de Facturas
(`docs/superpowers/specs/2026-05-20-migracion-modulos-flujo-design.md`) dejó
fuera de alcance, deliberadamente, las **cabeceras de las vistas** — la franja
superior con breadcrumb + título + chip de id + botones de acción. El doc de
progreso de aquella migración la registró como pendiente de decisión del usuario.

Esa decisión ya se tomó: abordar las cabeceras para que se vean igual que las de
las vistas de Facturas.

## Hallazgo que fundamenta el enfoque

Una auditoría del código encontró que **no es un problema de markup divergente**:

- **80 archivos** de plantilla usan exactamente la misma cabecera, copiada:
  `<div class="sgi-page-header d-flex justify-content-between align-items-center|start">`
  con `sgi-breadcrumb`, `sgi-page-title`, `sgi-edit-id-chip` y `btn-ghost-card`.
  El patrón es idéntico en los 80 archivos (verificado con `grep`).
- Esas 5 clases **no tienen ninguna regla CSS** en `webroot/css/` — por eso las
  cabeceras renderizan sin estilo (título como texto plano, breadcrumb sin
  formato, chip de id sin fondo).
- Las vistas de Facturas (`Invoices/view.php`, `Invoices/edit.php`) **no usan**
  esas clases: tienen una cabecera hecha a mano con estilos inline y la clase
  `sgi-title-page` (esta sí definida, en `styles.css`).

Conclusión: el arreglo no son ~85 reescrituras de markup. Es **definir las 5
clases CSS que faltan**, con los valores tomados de la cabecera de Facturas. Un
solo cambio de CSS deja las ~85 vistas estilizadas, sin tocar ningún template.

## Objetivo

Las cabeceras de `view`/`edit` de todos los módulos se ven igual que las de
Facturas: título, breadcrumb, chip de id y botones con el estilo del Sistema de
Diseño v2.

## Alcance

Incluye:

- Definir 5 clases CSS: `sgi-page-header`, `sgi-breadcrumb`, `sgi-page-title`,
  `sgi-edit-id-chip` (en `webroot/css/styles.css`) y `btn-ghost-card` (en
  `webroot/css/components.css`).

NO incluye:

- Ningún cambio de markup en ningún template.
- Migrar `Invoices/view.php` / `Invoices/edit.php` a las clases compartidas
  (conservan su cabecera propia; se ve idéntica). Decisión del usuario: enfoque
  solo-CSS.
- Limpieza o consolidación de otras clases sin CSS fuera de estas 5.

## Decisiones de diseño

- **Enfoque solo-CSS.** Las 5 clases ya están escritas de forma consistente en los
  80 archivos; solo les falta la definición. Definirlas una vez es la corrección
  de mínimo cambio y mínimo riesgo (frente a reescribir markup en 80 archivos).
- **Los valores se toman de la cabecera de Facturas:**
  - `sgi-page-title` reproduce `.sgi-title-page` (la clase que Facturas ya usa
    para el título de página).
  - `sgi-edit-id-chip` reproduce el chip de id que Facturas renderiza hoy con
    estilos inline (`mono`, `font-size:var(--fs-body-lg)`, `padding:3px 8px`,
    `background:var(--bg-subtle)`, `border-radius:var(--radius-sm)`).
  - `btn-ghost-card` reproduce `.btn-default` (la variante que Facturas usa para
    el botón "Volver" de la cabecera).
- **El layout no se define en `sgi-page-header`.** Cada archivo ya escribe las
  utilidades `d-flex justify-content-between align-items-*` junto a la clase;
  `sgi-page-header` solo aporta el espaciado inferior.
- **Ubicación del CSS por responsabilidad:** las 4 clases de cabecera van en
  `styles.css` (junto a `.sgi-title-page`, la familia de títulos de página);
  `btn-ghost-card` va en `components.css` (junto a `.btn-default` / `.btn-ghost`,
  la familia de botones).
- **Nota de alcance — `index`/`add`:** la clase `sgi-page-header` la usan también
  páginas `index` y `add`, no solo `view`/`edit`. Como el arreglo es a nivel CSS
  global, esas páginas también quedan con la cabecera estilizada. Es una mejora
  neta y consistente — no hay forma (ni razón) de acotar el CSS solo a view/edit.

## CSS a definir

### `webroot/css/styles.css` — junto a `.sgi-title-page`

```css
.sgi-page-header { margin-bottom: 16px; }

.sgi-breadcrumb {
    display: flex; align-items: center; flex-wrap: wrap; gap: 4px;
    font-size: var(--fs-body-sm); color: var(--text-faint); margin-bottom: 6px;
}
.sgi-breadcrumb a { color: inherit; text-decoration: none; }
.sgi-breadcrumb a:hover { color: var(--text-default); }
.sgi-breadcrumb .current { color: var(--text-default); }

.sgi-page-title {
    font-size: var(--fs-title-page); font-weight: 700; letter-spacing: -0.2px;
    color: var(--text-strong); margin: 0; line-height: 1.15;
}

.sgi-edit-id-chip {
    display: inline-flex; align-items: center;
    font-family: var(--font-mono); font-size: var(--fs-body-lg);
    color: var(--text-muted); padding: 3px 8px;
    background: var(--bg-subtle); border-radius: var(--radius-sm);
    white-space: nowrap;
}
```

### `webroot/css/components.css` — junto a `.btn-default` / `.btn-ghost`

```css
.btn-ghost-card { background: #fff; color: var(--text-default);
                  outline: 1px solid var(--border-color); outline-offset: -1px; }
.btn-ghost-card:hover { background: var(--bg-subtle); }
```

## Criterios de validación manual

Sin tests automatizados (política del proyecto). Tras el cambio, levantar
`php bin/cake server` y verificar visualmente, sin errores en consola del
navegador:

1. Una vista `view` de un módulo de flujo (p. ej. `Advances/view`): el título de
   página se ve con el tamaño/peso correcto, el breadcrumb se ve formateado
   (texto tenue, enlaces sin subrayado, crumb actual más oscuro), el chip de id
   tiene fondo gris y fuente monoespaciada, el botón "Volver" se ve como botón de
   card (fondo blanco + outline gris).
2. Una vista `edit` (p. ej. `Refunds/edit`): misma comprobación.
3. Una vista de un módulo de catálogo/admin (p. ej. `Users/view`,
   `OperationCenters/view`): la cabecera también quedó estilizada.
4. Comparar contra `Invoices/view`: las cabeceras se ven equivalentes.
5. Una página `index` que usa `sgi-page-header` (p. ej. `Providers/index`): la
   cabecera se ve estilizada, sin romper el layout existente del listado.

## Flujo de trabajo

Spec (este) → `writing-plans` genera el plan → ejecución con
`subagent-driven-development` → validación manual del usuario.
