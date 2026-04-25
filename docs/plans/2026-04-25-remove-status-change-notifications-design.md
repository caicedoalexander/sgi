# Eliminación de notificaciones de cambio de estado

**Fecha:** 2026-04-25
**Tipo:** Refactor / limpieza
**Alcance:** Facturas (único módulo con notificaciones de cambio de estado)

## Contexto

El sistema enviaba un email a los usuarios del rol destino cada vez que una factura
avanzaba de estado en el pipeline (`aprobacion → contabilidad → tesoreria → autorizacion_pago → pagada`).
Esta funcionalidad se elimina completamente. Los demás módulos (caja menor, novedades,
payment scheduling) nunca tuvieron notificaciones de cambio de estado, así que no se tocan.

El `NotificationService` se conserva: sigue enviando emails de **link de aprobación**
de facturas y de novedades, y mantiene `testSmtpConnection` para los ajustes del sistema.

## Cambios

### 1. `src/Service/NotificationService.php`

Eliminar:
- Método público `sendStatusChangeNotification(Invoice, string, string): array`
- Helper privado `getRecipientsForStatus(string): array`
- Helper privado `getStatusRoleMapping(): array`
- Imports `App\Constants\InvoiceConstants` y `App\Constants\RoleConstants` (quedan sin uso)

Conservar: `sendApprovalLinkNotification`, `sendNoveltyApprovalEmail`,
`testSmtpConnection`, `configureTransport`, `getApproverRecipient`, `CircuitBreaker`.

### 2. `src/Service/Interface/NotificationServiceInterface.php`

Eliminar la firma `sendStatusChangeNotification`.

### 3. `src/Service/InvoicePipelineService.php`

Eliminar:
- Método privado `trySendNotification()` completo
- En el método con `$saved` (alrededor de la línea 439): la variable `$notificationErrors`,
  el bloque `if ($advanceNextStatus) { … trySendNotification … }` y la key
  `'notificationErrors'` del retorno
- En `advance()`: la llamada a `trySendNotification` (línea 499) y la key
  `'notificationError'` del retorno
- La línea de docblock que documenta `notificationErrors`
- La dependencia `NotificationServiceInterface $notificationService` del constructor
  si tras los cambios no queda ningún uso (verificar grep antes de quitar)

### 4. `src/Controller/InvoicesController.php`

Eliminar:
- Líneas ~293-297: `foreach ($result['notificationErrors'] as $notifErr) { … }`
- Líneas ~369-371: `if (!empty($result['notificationError'])) { $this->Flash->warning(...); }`

### 5. Call-sites del constructor

Si se quita la dependencia del constructor de `InvoicePipelineService`, ajustar:
- `src/Service/Strategy/InvoiceApprovalStrategy.php:50` — `new InvoicePipelineService($this->historyService, $this->notificationService)` → `new InvoicePipelineService($this->historyService)`
- Cualquier otro `new InvoicePipelineService(...)` con 2+ args (verificar con grep)

### 6. Plantilla de correo

Eliminar:
- `templates/email/html/invoice_status_changed.php`

(No existe versión `text/`.)

## Riesgos

- **Bajo.** Sin tests afectados (grep en `tests/` no encuentra referencias).
- **Bajo.** Cambio de firma de constructor de `InvoicePipelineService` requiere ajustar
  todos los call-sites; resuelto con grep exhaustivo antes de commit.
- **Nulo** sobre los flujos vivos de email (links de aprobación) — no se tocan.

## Validación

1. `composer cs-check` — detectar imports huérfanos.
2. Smoke manual: avanzar una factura entre dos estados — guarda sin email ni warning.
3. Smoke manual: enviar link de aprobación de factura — sigue funcionando.
4. Smoke manual: enviar link de aprobación de novedad — sigue funcionando.

## YAGNI

Limpieza total: nada se conserva "por si acaso". La feature se reactiva con
`git revert` si fuera necesario.
