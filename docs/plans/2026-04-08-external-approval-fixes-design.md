# Fix: Panel de Aprobacion Externa + Observaciones AJAX

**Fecha:** 2026-04-08
**Estado:** Aprobado

## Problemas identificados

### Bug 1 — Soportes no visibles en panel externo
- **Causa:** `InvoiceApprovalService::validateToken()` (linea 129) hace `contain(['Invoices' => ['Providers'], 'Users'])` sin incluir `InvoiceDocuments`
- **Impacto:** El template `review.php` muestra seccion de soportes vacia porque `$entity->invoice_documents` es null/vacio
- **Nota:** El sistema legacy (`ApprovalTokenService`) si incluye `InvoiceDocuments` en su contain (linea 216)

### Bug 2 — Observaciones del panel externo no aparecen en chat de factura
- **Causa:** Las observaciones se guardan solo en `invoice_approvals.observations`, no en `invoice_observations`
- **Impacto:** Los comentarios del aprobador externo no son visibles en la vista edit de la factura

### Bug 3 — Multiples observaciones por click repetido
- **Causa:** Formulario POST estandar sin proteccion contra doble-click
- **Impacto:** Cada click crea una observacion duplicada en la base de datos

## Solucion

### Fix 1 — Agregar InvoiceDocuments al contain
- **Archivo:** `src/Service/InvoiceApprovalService.php` linea 129
- **Cambio:** Agregar `'InvoiceDocuments'` al contain de `Invoices`
- **Template:** Sin cambios — `review.php` ya tiene el renderizado de documentos

### Fix 2 — Crear invoice_observation desde panel externo
- **Archivo:** `src/Controller/ExternalApprovalsController.php` en `process()`
- **Cambio:** Despues de guardar aprobacion exitosamente, si `observations` no esta vacio:
  - Crear registro en `invoice_observations` con mensaje prefijado: `"[Aprobacion externa - {Aprobado|Rechazado}] {texto}"`
  - Se mantiene el guardado en `invoice_approvals.observations` como respaldo/auditoria
- **Aplica a:** Flujo multi-aprobador y flujo legacy

### Fix 3 — Observaciones via AJAX
- **Archivos:** `src/Controller/InvoicesController.php` + `templates/Invoices/edit.php`
- **Controller:** Detectar request AJAX, retornar JSON `{success, observation: {message, user_name, created}}`
- **Template JS:**
  - Interceptar submit con `e.preventDefault()`
  - `fetch()` POST con header `X-Requested-With: XMLHttpRequest`
  - Insertar burbuja HTML en chat on success
  - Deshabilitar boton durante envio
  - Validar mensaje no vacio antes de enviar
  - Mantener redirect como fallback para requests no-AJAX
