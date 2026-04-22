# Fix: Error 413 "Content Too Large" al subir documentos

**Fecha:** 2026-04-22
**Autor:** Alexander (con Claude)
**Estado:** Diseño aprobado, listo para implementar

## Problema

Al subir documentos desde el módulo de facturas (y otros), en ocasiones aparece en consola:

```
POST https://sgi.alexandercaicedo.dev/invoices/upload-document/15 413 (Content Too Large)
```

## Causa raíz

`docker/nginx/default.conf` no define `client_max_body_size`, por lo que nginx usa el default de **1 MB**. Cualquier archivo >1 MB es rechazado antes de llegar a PHP. Mientras tanto, PHP está configurado para aceptar hasta 20 MB (`upload_max_filesize`) / 25 MB (`post_max_size`).

Además, el trait `DocumentUploadTrait` tiene un tope de 10 MB distinto al de PHP, y el frontend no valida el tamaño antes de hacer el `fetch`.

## Decisiones

- **Tamaño máximo efectivo:** 20 MB por archivo (alineado con PHP actual).
- **Nginx:** 25 MB de margen para el overhead multipart.
- **Alcance:** todos los endpoints de upload de documentos que usan el patrón común.
- **Fuera de alcance:** `LeaveDocumentService` (5 MB, política distinta), firmas, `DianCrosscheckService` (tiene flujo propio), `PaymentSchedulingsController::uploadAttachment` (carece de validación server-side — se anota como hallazgo separado).

## Cambios

### 1) Nginx

`docker/nginx/default.conf`: añadir dentro de `server { ... }`:

```nginx
client_max_body_size 25m;
```

### 2) Servicios (3 archivos)

Subir `MAX_DOC_SIZE` de 10 MB a 20 MB y ajustar mensaje de error:

- `src/Service/Trait/DocumentUploadTrait.php` (cubre Invoices, Legalizations, PettyCash)
- `src/Service/EmployeeDocumentService.php` (documentos de empleados)
- `src/Service/NoveltyDocumentService.php` (documentos de novedades)

### 3) JS común

`webroot/js/sgi-common.js`: exponer `window.SGI_MAX_UPLOAD_BYTES` y `window.SGI_MAX_UPLOAD_LABEL` para reutilizar en los modales.

### 4) Templates (7 archivos)

Actualizar texto "Máximo 10 MB" → "Máximo 20 MB" y añadir validación JS pre-`fetch`:

- `templates/Invoices/edit.php`
- `templates/LegalizationRecords/edit.php`
- `templates/PettyCashRecords/edit.php`
- `templates/NoveltyLiquidationDocs/edit.php`
- `templates/EmployeeNovelties/edit.php`
- `templates/Employees/view.php`
- `templates/PaymentSchedulings/edit.php` (solo copy — el controller no tiene validación)

## Orden de despliegue

1. Merge del código
2. Rebuild/redeploy del contenedor nginx (si la config no está como volumen)
3. Verificar upload de archivo ~15 MB (debe pasar)
4. Verificar upload de archivo ~25 MB (debe mostrar mensaje JS, no 413)

## Hallazgos para después

- `PaymentSchedulingsController::uploadAttachment` no valida tamaño ni tipo MIME server-side.
- `LeaveDocumentService` tiene límite propio de 5 MB; considerar unificar política si se quiere.
