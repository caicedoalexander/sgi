# Migración: ApprovalTokenService → InvoiceApprovalService

## Estado actual

Existen dos mecanismos de aprobación externa para facturas:

- **`ApprovalTokenService`** (legacy): usa la tabla `approval_tokens`. Maneja un token por entidad (factura o novedad). Basado en Strategy pattern.
- **`InvoiceApprovalService`**: usa la tabla `invoice_approvals`. Soporta múltiples aprobadores por factura, con historial de reemplazos y estados individuales.

El flujo multi-aprobador de facturas ya usa `InvoiceApprovalService` como mecanismo principal. `ApprovalTokenService` sigue activo para el flujo de novedades y como respaldo legacy de facturas con un solo aprobador.

## Qué bloquea la migración completa

1. **Novedades**: `ApprovalTokenService` sigue siendo el único mecanismo para `EmployeeNoveltiesController`. Migrar novedades requiere un plan separado (tabla `novelty_approvals` equivalente a `invoice_approvals`).
2. **Tokens activos en BD**: la tabla `approval_tokens` puede tener tokens vigentes. Retirar el servicio sin migrar esos tokens dejaría links de aprobación inválidos.
3. **Rutas externas**: los endpoints `/approve-token` y similares dependen de esta tabla.

## Criterio de éxito para retirar el legacy

- `EmployeeNoveltiesController` migrado a su propio servicio de aprobación multi-aprobador.
- Tabla `approval_tokens` sin registros activos (o migrados).
- Rutas de aprobación externa actualizadas para apuntar a los nuevos endpoints.
- `ApprovalTokenService` eliminado y sus estrategias desconectadas.
