# ADR 0003 — Email log síncrono con reintento manual; descartar outbox

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

La auditoría 2026-04-30 marcó dos issues relacionados:

- **C4** — flujos saga-shaped (`authorizePayment` con varios pasos en services
  distintos) sin compensación.
- **W8** — correos perdidos silenciosamente cuando SMTP falla y `NotificationService`
  trababa la excepción.

El roadmap original proponía un **outbox** + worker CLI corrido por cron como
columna vertebral de integraciones (resolvía C4, W8, y dejaba la base para domain
events).

## Decisión

**Descartar el outbox y el worker.** Sustituir por:

1. **Email log síncrono.** Tabla `email_logs` registra cada intento de envío con
   `status` (pending/sent/failed), `attempts`, `last_error`, `payload` snapshot.
   `NotificationService` envía síncronamente y propaga excepciones SMTP al
   controller (la UI muestra el error al usuario que disparó el flujo).
2. **Recuperación manual** desde la UI. Panel inline en `invoices/edit` y
   `employee_novelties/edit` + vista global `/email-logs` con reintento individual y
   masivo.
3. **Sweep lazy de huérfanos** (filas `pending` interrumpidas por crash de PHP) al
   cargar la vista global o ejecutar un reintento. Sin cron.

Para C4 — la transacción atómica del Plan 1 (`authorizePayment` con todos los pasos
dentro de `Connection::transactional`) cubre ese flujo sin necesitar outbox.

## Consecuencias

**Positivas:**
- Cero infraestructura nueva en el servidor. No hay cron que cuidar, monitorear,
  reiniciar.
- El usuario ve los fallos de email en tiempo real (no descubre 30 min después que
  el correo nunca salió).
- Toda recuperación queda en la UI; auditable, con permisos, con motivo registrado.

**Negativas:**
- Recovery es **manual**, no automático. Si nadie revisa `/email-logs`, los failed
  quedan ahí.
- Mitigación: `/health` reporta `email_logs_failed_count` (Plan 6 — W7); cualquier
  monitor externo lo levanta como alerta.
- El usuario espera un poco más en flujos que disparan email (envío síncrono) — pero
  la espera es rara (el SMTP responde rápido cuando funciona) y el `CircuitBreaker`
  de `NotificationService` ya da fallback rápido cuando el SMTP está caído.

**Lo que se pierde respecto al outbox:**
- No hay despacho diferido genérico (no se reusa para webhooks o eventos futuros).
- Domain events del Plan 5 tuvieron que apoyarse en `EventManager` síncrono en lugar
  de en el outbox. Ver ADR 0007.

## Alternativas consideradas

### Outbox con worker CLI por cron (propuesta original)
Descartado por decisión operativa: un nuevo cron es un componente más al que cuidar
(monitoreo, restart, ownership de logs). Para una app interna con un equipo pequeño,
el costo operativo no se compensa.

### Outbox con worker daemon supervisor
Descartado por el mismo motivo (más complejo aún que el cron).

### Tragar las excepciones SMTP a `Log::error` (status quo previo)
Descartado: era el bug original W8. Usuarios veían "éxito" en pantalla y los correos
nunca llegaban.
