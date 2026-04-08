# Secciones colapsables para Registro/Revision en edicion de facturas

**Fecha:** 2026-04-08
**Problema:** Al editar una factura en estado aprobacion, el rol Registro/Revision ve secciones editables (Documento, Fechas, Clasificacion) que duplican la info del ledger y distraen del trabajo principal: la seccion Revision.

## Decisiones

- Secciones Documento, Fechas, Clasificacion y Valor se colapsan por defecto en edit para Registro/Revision en estado aprobacion
- Cada seccion tiene toggle individual (acordeon `<details>/<summary>`)
- La seccion Revision se muestra abierta y prominente (primero en orden visual)
- Solo aplica a Registro/Revision en aprobacion — otros roles no cambian
- El ledger sigue visible como referencia rapida

## Orden visual

```
[Pipeline progress]
[Ledger]
[Seccion Revision — ABIERTA]
[-- Documento ----------- >]  colapsado
[-- Fechas --------------- >]  colapsado
[-- Clasificacion -------- >]  colapsado
[Boton Guardar/Avanzar]
```

## Cambios

1. `InvoicePipelineService.php` — nueva constante `COLLAPSIBLE_SECTIONS_BY_ROLE` + metodo `getCollapsibleSections()`
2. `InvoicesController.php` — pasar `$collapsibleSections` a la vista
3. `templates/Invoices/edit.php` — envolver secciones colapsables en `<details>/<summary>`, reordenar para que revision aparezca primero
