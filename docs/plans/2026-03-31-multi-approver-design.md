# Diseño: Múltiples Aprobadores por Factura

**Fecha:** 2026-03-31
**Estado:** Aprobado

## Resumen

Actualmente cada factura permite un solo aprobador (`approver_id` en `invoices`). Se requiere permitir N aprobadores por factura, con notificaciones simultáneas y la validación de que todos deben aprobar para que la factura avance de `aprobacion` a `contabilidad`.

## Decisiones de Diseño

| Decisión | Resultado |
|----------|-----------|
| Selección de aprobadores | Manual libre (multi-select) |
| Rechazo | Un rechazo bloquea todo inmediatamente |
| Modificación post-envío | No permitida. Si hay rechazo se reinicia todo |
| Envío de links | Todos a la vez al guardar |
| Visibilidad | Detalle completo por aprobador (tabla con estado, fecha, observaciones) |

## 1. Modelo de Datos

### Nueva tabla `invoice_approvals`

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | int, PK, auto | — |
| `invoice_id` | int, FK → invoices | Factura asociada |
| `user_id` | int, FK → users | El aprobador |
| `token` | string(64), unique, nullable | Token SHA256 para aprobación externa |
| `token_expires_at` | datetime, nullable | Expiración del token (48h) |
| `status` | string(20), default 'Pendiente' | Pendiente / Aprobada / Rechazada |
| `responded_at` | datetime, nullable | Cuándo respondió |
| `observations` | text, nullable | Comentario del aprobador |
| `ip_address` | string(45), nullable | IP al responder |
| `user_agent` | text, nullable | User-agent al responder |
| `created` | datetime | — |
| `modified` | datetime | — |

### Cambios en `invoices`

- `approver_id` se mantiene como nullable/legacy pero deja de usarse para la lógica de aprobación.

### Tabla `approval_tokens` existente

No se modifica. Los tokens de aprobación multi-aprobador viven directamente en `invoice_approvals`.

## 2. Lógica de Negocio

### Flujo completo

1. **Asignación:** Usuario de Registro/Revisión selecciona N aprobadores en multi-select y guarda.
2. **Envío:** Al guardar, se crea un registro en `invoice_approvals` por aprobador, se genera un token por cada uno, y se envían emails simultáneamente.
3. **Respuesta:** Cada aprobador hace clic en su link único (`/approve/{token}`). Puede aprobar o rechazar con observaciones.
4. **Rechazo inmediato:** Si cualquier aprobador rechaza → `area_approval` = 'Rechazada'. Los tokens pendientes se invalidan.
5. **Aprobación completa:** Cuando el último pendiente aprueba → `area_approval` = 'Aprobada', `area_approval_date` = now. Si DIAN aprobada → auto-avance a `contabilidad`.
6. **Reinicio tras rechazo:** El usuario corrige, re-selecciona aprobadores, se repite el ciclo. Los registros anteriores quedan como historial.

### Validación para avanzar

- **Antes:** `approver_id` not empty + `area_approval` = 'Aprobada'
- **Ahora:** Al menos un registro en `invoice_approvals` + todos con `status` = 'Aprobada'
- **Se mantiene:** `dian_validation` = 'Aprobada'

## 3. Interfaz de Usuario

### Formulario edit (sección revisión)

- Multi-select con Select2 para seleccionar aprobadores.
- Editable solo cuando: estado es `aprobacion` y no hay tokens activos (o factura rechazada para reinicio).
- Tabla de estado de aprobadores debajo del multi-select:

| Aprobador | Estado | Fecha | Observaciones |
|-----------|--------|-------|---------------|
| Juan Pérez | Aprobada (verde) | 2026-03-28 14:30 | — |
| María López | Pendiente (gris) | — | — |
| Carlos Ruiz | Rechazada (rojo) | 2026-03-28 15:10 | Monto incorrecto |

- Alert-warning si rechazada: "Rechazada por [nombre]. Corrija y re-asigne aprobadores."
- Envío automático al guardar (sin botón individual de enviar link).

### Index de facturas

- Badge resumen: "2/3 aprobados" o "Rechazada" en lugar del nombre del aprobador único.

## 4. Impacto en Archivos

### Archivos nuevos

- `config/Migrations/XXXX_CreateInvoiceApprovals.php`
- `src/Model/Entity/InvoiceApproval.php`
- `src/Model/Table/InvoiceApprovalsTable.php`
- `src/Service/InvoiceApprovalService.php`

### Archivos a modificar

| Archivo | Cambio |
|---------|--------|
| `InvoicePipelineService.php` | Validación contra `invoice_approvals` en vez de `approver_id` |
| `ApprovalTokenService.php` | Adaptar `consumeToken()` para `invoice_approvals` o delegar al nuevo servicio |
| `ExternalApprovalsController.php` | Buscar token en `invoice_approvals`, delegar al nuevo servicio |
| `NotificationService.php` | Nuevo método `sendBulkApprovalNotifications()` |
| `InvoicesController.php` | Recibir array de approver IDs, pasar a `InvoiceApprovalService` |
| `InvoicesTable.php` | `hasMany('InvoiceApprovals')`, deprecar `belongsTo('ApproverUsers')` |
| `templates/Invoices/edit.php` | Multi-select + tabla de estado de aprobadores |
| `templates/Invoices/index.php` | Badge resumen |
| `InvoiceConstants.php` | Constantes de estado de aprobación individual |

### Archivos sin cambios

- Migraciones existentes
- Layout, CSS base, rutas existentes (solo agregar ruta si cambia patrón)
