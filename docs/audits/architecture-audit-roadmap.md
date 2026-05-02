# Architecture Audit Roadmap — SGI

**Origen:** Auditoría en [`./architecture-audit-2026-04-30.md`](./architecture-audit-2026-04-30.md)
**Creado:** 2026-04-30
**Estado global:** No iniciado

---

## Contexto (lee esto primero)

La auditoría de arquitectura encontró **6 issues críticos**, **15 warnings** y **6 conflictos cruzados** en el código de SGI (CakePHP 5.3 / PHP 8.2+).

Para no convertir la auditoría en un único PR gigante, el trabajo se descompuso en **7 planes** agrupados en **4 fases**, ordenados por dependencias y riesgo creciente.

**Cada plan se trabaja por separado**, en su propia sesión: brainstorming → spec → writing-plans → ejecución → merge → siguiente plan.

Este archivo es el contrato maestro. **No se modifica el alcance de los planes sin actualizar este archivo.**

---

## Cómo retomar (instrucciones para sesiones nuevas)

Cuando empieces una sesión nueva (con o sin contexto previo):

1. **Lee este archivo completo** y la auditoría origen para reconstruir el contexto.
2. **Identifica el siguiente plan** según la tabla de estado al final.
3. **Verifica que las dependencias del plan estén completadas** antes de empezarlo.
4. **Inicia el plan** con el comando `/superpowers:brainstorming` y dile explícitamente:

   > Quiero trabajar el **Plan N** del roadmap en `docs/audits/architecture-audit-roadmap.md`. Los items son [lista de IDs C/W del plan]. Sigamos el flujo brainstorming → spec → writing-plans.

5. **Al terminar un plan** (cuando se mergea a `main`), actualiza la tabla de estado al final de este archivo y escribe la fecha de cierre.
6. **Si surgen items nuevos** que no estaban en la auditoría, agrégalos al plan más relacionado o crea un Plan 8 explícito al final.

> **Importante:** Cualquier desviación de este roadmap (fusionar planes, saltarse uno, cambiar el orden) debe quedar registrada en la sección "Cambios al roadmap" al final.

---

## Resumen ejecutivo

| # | Plan | Items | Tamaño | Depende de | Estado |
|---|------|-------|--------|------------|--------|
| 1 | Quick Critical Fixes | C1, C2, C3 | XS (2–4 días) | — | 🟢 Completado |
| 2 | Email Audit Log + Reintento manual *(pivot, ver "Cambios al roadmap" 2026-05-01)* | W8 | S (4–6 días) | — | 🟢 Completado |
| 3 | DI Container | W3, W5 | S (3–5 días) | — | 🟢 Completado |
| 4 | Refactor del Pipeline | C5, W2, W9, W10 | M (1–2 sem) | Plan 3 (recomendado) | 🟢 Completado |
| 5 | Domain Events (romper ciclo) | C6 | S (~1 sem) | — *(originalmente Plan 2; ver "Cambios al roadmap")* | 🟢 Completado |
| 6 | Resilience Hardening | W6, W13, W14, W7 | M (~1 sem) | — *(originalmente Plan 2; ver "Cambios al roadmap")* | ⬜ Pendiente |
| 7 | Observability + Polish | W1, W4, W11, W12, W15, ADRs | M (~1 sem) | — | ⬜ Pendiente |

**Cobertura:** los 6 críticos (C1–C6) y los 15 warnings (W1–W15) sin items huérfanos. C4 quedó cubierto por el Plan 1 (transacción atómica en `authorizePayment`).

---

## Mapa de dependencias

> **Actualizado 2026-05-01** tras el pivot del Plan 2 (ver "Cambios al roadmap"). El outbox ya no existe; Plan 5 y Plan 6 dejaron de depender de Plan 2.

```
Plan 1 (✅ completado)
  ↓
Plan 2 (en progreso, sin bloquear a otros)   Plan 3 (sin deps, paralelizable)
                                                    ↓
                                                  Plan 4
                                                    ↓
Plan 5 (sin deps; se replantea su mecánica)  ──┐    │
Plan 6 (sin deps; se replantea su alcance)   ──┼────┘
                                                │
                                                ↓
                                              Plan 7 (último, polish)
```

**Reglas (actualizadas):**
- El **Plan 1** se hizo primero (estabilización antes de refactor). ✅
- **Plan 2** y **Plan 3** son independientes entre sí — pueden hacerse en paralelo o en cualquier orden.
- **Plan 5** ya no depende de Plan 2 (el outbox fue descartado). Su brainstorming debe abrir con la decisión sobre la mecánica de despacho de eventos (síncrono vía `EventManager` u otra).
- **Plan 4** se beneficia de tener el **Plan 3** primero (refactor más limpio con DI), pero no es bloqueante.
- **Plan 6** ya no depende de Plan 2. W14 (bulkhead) se replantea con timeouts agresivos en `NotificationService`/`WebhookService`.
- **Plan 7** se deja al final — es polish y no desbloquea nada. Su métrica de "outbox backlog" se sustituye por `email_logs_failed_count`.

---

## Fase 1 — Estabilizar (sin cambios arquitectónicos)

### Plan 1 — Quick Critical Fixes
**Items:** C1, C2, C3
**Tamaño estimado:** 2–4 días
**Depende de:** nada
**Bloquea a:** nada técnicamente; recomendado hacerlo primero para reducir riesgo

**Alcance:**
- **C1** Envolver `InvoicePaymentService::authorizePayment()` en `Connection::transactional(...)` y mover side effects (`closeOnRefundAuthorized`, `advanceLegalizationService->initialize`) a post-commit.
- **C2** Arreglar `RateLimitMiddleware`:
  - Cambiar a contador atómico (Redis `INCR`/`EXPIRE` o equivalente) — eliminar el read-modify-write actual.
  - Honrar `X-Forwarded-For` con lista de proxies confiables.
  - Añadir header `Retry-After` en respuestas 429.
  - Registrar el middleware en `Application::middleware()` con scope a `/login` y `/approve/*`.
- **C3** Refactor de `NotificationService` para inyectar `MailerInterface` y eliminar la configuración SMTP duplicada (`configureTransport`).

**Criterios de éxito:**
- Tests existentes pasan.
- Test nuevo que demuestra rollback transaccional en `authorizePayment` (simula fallo en segundo `save()`).
- Test nuevo que demuestra el rate limit bloqueando >N requests/minuto a `/login`.
- `NotificationService` puede recibir un mock de `MailerInterface` en tests.

---

## Fase 2 — Cimientos

### Plan 2 — Email Audit Log + Reintento manual

> **Pivot del 2026-05-01.** El alcance original (outbox + worker CLI por cron) se descartó por decisión operativa. Resumen del cambio en "Cambios al roadmap"; spec completo en [`docs/superpowers/specs/2026-05-01-email-log-design.md`](../superpowers/specs/2026-05-01-email-log-design.md).

**Items:** W8 *(C4 ya quedó cubierto por la transacción del Plan 1)*
**Tamaño estimado:** 4–6 días
**Depende de:** nada
**Bloquea a:** nada *(Plan 5 y Plan 6 dejaron de depender de Plan 2)*

**Alcance (revisado):**
- Migración: tabla `email_logs` (`id`, `event_type`, `entity_type`, `entity_id`, `to_email`, `subject`, `template`, `payload JSON`, `status`, `attempts`, `last_error`, `last_attempt_at`, `sent_at`, `created_by`, `created`, `modified`).
- Servicio `EmailLogService` con `recordPending`, `markSent`, `markFailed`, `retry`, `retryAllFailed`, `sweepOrphanPendings`.
- `NotificationService` integra el log en cada envío y propaga excepciones SMTP en lugar de tragarlas.
- UI: panel inline en `invoices/edit` y `employee_novelties/edit` + vista global `/email-logs` (admin only) con filtros y reintento individual + masivo.
- Sweep lazy de filas `pending` huérfanas al cargar la vista global o al ejecutar un reintento — sin cron.
- Sin worker async, sin cron, sin daemon.

**Lo que NO entra (originalmente sí):**
- Outbox como mecanismo genérico de eventos.
- Worker CLI / cron / supervisor.
- `sendTestEmail`/`testSmtpConnection` se mantiene fuera (sigue síncrono, sin log — es diagnóstico).

**Criterios de éxito:**
- Caída prolongada del SMTP no pierde correos: la tabla `outbox` se llena y se drena cuando vuelve.
- Tests integran: aprobación → fila en outbox → worker corre → email enviado.

### Plan 3 — DI Container
**Items:** W3, W5
**Tamaño estimado:** 3–5 días
**Depende de:** nada
**Bloquea a:** Plan 4 recomendado, Plan 6 recomendado

**Alcance:**
- Implementar `Application::services(ContainerInterface $container)` en `src/Application.php`.
- Registrar todos los servicios en `src/Service/` con sus dependencias declaradas.
- Eliminar el patrón `?? new ServiceClass()` de los constructores de servicios.
- Eliminar instanciación manual en `InvoicesController::initialize()` y demás controladores que aún usen `new`.
- `AuthorizationService` queda single-instance por request (efecto colateral: la caché interna empieza a funcionar — resuelve W5).

**Criterios de éxito:**
- `grep -r "?? new " src/Service/` devuelve 0 resultados.
- Suite de tests pasa sin tocar fixtures de servicios (la inyección es real, no fallback).
- En una request, cualquier servicio se construye una sola vez.

---

## Fase 3 — Mejoras arquitectónicas

### Plan 4 — Refactor del Pipeline
**Items:** C5, W2, W9, W10
**Tamaño estimado:** 1–2 semanas
**Depende de:** Plan 3 recomendado (más limpio con DI ya en sitio)
**Bloquea a:** Plan 6 parcialmente

**Alcance:**
- **C5** Extraer de `InvoicePipelineService`:
  - `InvoiceLockPolicy` — encapsula `isLockedByPettyCash`, `isLockedByPaidScheduling`, `getEditLockMessage`, `getRegressionLockMessage`.
  - `InvoiceTransitionValidator` — encapsula `TRANSITION_REQUIREMENTS`, `validateTransitionRequirements`, `filterAdvanceErrorsForRole`.
- **W9** Convertir la state machine de array-based a polimórfica:
  - Interfaz `InvoicePipelineState` con `getName()`, `getNext()`, `getPrevious()`, `getEditableFields()`, `validateAdvance()`, `getRoleVisibility()`.
  - Implementaciones: `AprobacionState`, `ContabilidadState`, `TesoreriaState`, `AutorizacionPagoState`, `PagadaState`.
  - `InvoicePipelineService` se reduce a coordinador que delega al state actual.
- **W10** `DocumentTypePolicy` polimórfica para `DOCTYPE_ANTICIPO`, `DOCTYPE_LEGALIZACION`, `DOCTYPE_FACTURA` — los condicionales en `getNextStatus`, `validateTransitionRequirements`, `getVisibleSections`, `registerPayment` se sustituyen por delegación.
- **W2** Eliminar el método `Invoice::canAdvanceTo()` o hacer que delegue al servicio. Una sola fuente de verdad.

**Criterios de éxito:**
- `InvoicePipelineService` queda ≤ 300 LOC.
- Añadir un nuevo estado o tipo de documento toca ≤ 2 archivos nuevos, sin editar el coordinador.
- Tests cubren cada `*State` y cada `DocumentTypePolicy` por separado.

### Plan 5 — Domain Events (romper el ciclo)
**Items:** C6
**Tamaño estimado:** ~1 semana
**Depende de:** Plan 2 (necesita la infraestructura de outbox/eventos)

**Alcance:**
- Definir eventos: `InvoicePaidEvent`, `InvoiceRefundAuthorizedEvent`, `InvoiceRefundRejectedEvent`, `AdvanceLegalizedEvent`.
- En vez de `InvoicePaymentService::authorizePayment` llamar directo a `AdvanceLegalizationService::initialize` y `closeOnRefundAuthorized`, emitir el evento al outbox.
- Suscriptores en `AdvanceLegalizationService` que reaccionan a los eventos del pipeline (y viceversa).
- Eliminar el lazy-init `_getPipelineService()` en `AdvanceLegalizationService`.

**Criterios de éxito:**
- No quedan llamadas directas Pipeline ↔ Payment ↔ Legalization. Toda comunicación cruza el bus de eventos.
- Tests demuestran que un evento publicado dispara el subscriptor correcto.
- Conflictos cruzados 1, 2 y 3 de la auditoría se resuelven.

---

## Fase 4 — Resiliencia y calidad

### Plan 6 — Resilience Hardening
**Items:** W6, W13, W14, W7
**Tamaño estimado:** ~1 semana
**Depende de:** Plan 2 (W14 consume el outbox)

**Alcance:**
- **W6** Idempotency:
  - Tabla `idempotency_keys` con `key`, `request_hash`, `response`, `expires_at`.
  - Aplicar a `registerPayment` (form token) y endpoints de `advance` del pipeline.
  - Índice único como red de seguridad: `(invoice_id, payment_date, amount, status)` en `invoice_payments`.
- **W13** Extraer `RetryPolicy` y `Retryer` desde `WebhookService`. Aplicar en `NotificationService::deliver()` (cuando está en modo síncrono).
- **W14** Bulkhead:
  - Worker independiente del Plan 2 para procesar el outbox — webhooks y emails no comparten workers PHP-FPM con peticiones de usuario.
  - Concurrencia limitada por tipo de evento.
- **W7** Endpoint `/health`:
  - Ping a DB.
  - Ping a cache.
  - Estado de los Circuit Breakers (n8n, SMTP).
  - Backlog del outbox (cantidad pendiente).

**Criterios de éxito:**
- Doble click en "Registrar Pago" no produce dos pagos.
- SMTP intermitente no produce correos perdidos (se reintenta vía outbox).
- `/health` devuelve 503 cuando un componente está caído.

### Plan 7 — Observability + Polish
**Items:** W1, W4, W11, W12, W15, ADRs
**Tamaño estimado:** ~1 semana
**Depende de:** nada (último por convención)

**Alcance:**
- **W1** Sustituir `Cake\Log\Log::*` directos en servicios por `StructuredLogger` inyectado. Garantizar que el correlation ID se propaga.
- **W12** Reemplazar `try { ... } catch (Exception) { return 0; }` por captura específica + log estructurado + re-throw o fallback explícito según el caso.
- **W11** Caché de contadores del sidebar:
  - Opción A: caché de 30s por rol (rápido, simple).
  - Opción B: tabla `sidebar_counters` materializada, actualizada en write-side (más complejo, más eficiente). Decidir en el spec del plan.
- **W15** Estandarizar todos los servicios en `ServiceResult`. Eliminar returns de `array` (`saveAndAdvance` en particular).
- **W4** Vista 403 dedicada en lugar de redirect-a-referer en `_enforcePermission()`.
- **ADRs** en `docs/adr/` documentando decisiones deliberadas:
  - "Usamos Layered Architecture, no DDD."
  - "Usamos `ServiceResult` en lugar de excepciones para errores de dominio."
  - "Outbox como columna vertebral de integraciones; Saga implícita."
  - "Caché del sidebar: opción elegida y por qué."

**Criterios de éxito:**
- `grep -r "Cake\\\\Log\\\\Log::" src/Service/` devuelve 0.
- `grep -r "return 0;" src/Service/Dashboard/` y similares revisados.
- Sidebar genera <= 1 query (no 13).
- Todo servicio público devuelve `ServiceResult`.
- 4 ADRs creados.

---

## Tabla de estado (actualizar al cerrar cada plan)

| # | Plan | Estado | Spec | Plan | PR | Cerrado |
|---|------|--------|------|------|----|---------|
| 1 | Quick Critical Fixes | 🟢 Completado | [spec](../superpowers/specs/2026-04-30-quick-critical-fixes-design.md) | [plan](../superpowers/plans/2026-04-30-quick-critical-fixes-plan.md) | [#3](https://github.com/caicedoalexander/sgi/pull/3) | 2026-04-30 |
| 2 | Email Audit Log + Reintento manual (W8) | 🟢 Completado | [spec](../superpowers/specs/2026-05-01-email-log-design.md) | [plan](../superpowers/plans/2026-05-01-email-log-plan.md) | — | 2026-05-01 |
| 3 | DI Container | 🟢 Completado | [spec](../superpowers/specs/2026-05-01-di-container-design.md) | [plan](../superpowers/plans/2026-05-01-di-container-plan.md) | — | 2026-05-01 |
| 4 | Refactor Pipeline | 🟢 Completado | [spec](../superpowers/specs/2026-05-01-pipeline-refactor-design.md) | [plan](../superpowers/plans/2026-05-01-pipeline-refactor-plan.md) | — | 2026-05-01 |
| 5 | Domain Events | 🟢 Completado | [spec](../superpowers/specs/2026-05-01-domain-events-design.md) | [plan](../superpowers/plans/2026-05-01-domain-events-plan.md) | — | 2026-05-01 |
| 6 | Resilience Hardening | ⬜ Pendiente | — | — | — | — |
| 7 | Observability + Polish | ⬜ Pendiente | — | — | — | — |

**Leyenda:**
- ⬜ Pendiente — aún no se ha empezado
- 🟡 En progreso — spec escrito o plan en marcha
- 🟢 Completado — mergeado a `main`
- ⚫ Descartado — decisión explícita de no hacerlo (justificar en "Cambios al roadmap")

Columnas:
- **Spec** — ruta al archivo `docs/superpowers/specs/...-design.md`
- **Plan** — ruta al archivo `docs/plans/...-plan.md`
- **PR** — número o link al PR
- **Cerrado** — fecha de merge

---

## Cambios al roadmap

Si en algún momento se decide modificar el roadmap (fusionar planes, dividir uno, cambiar prioridades, descartar items), registrar aquí con fecha y razón:

### 2026-04-30 — Plan 1: dos desviaciones intencionales (acordadas durante brainstorming)

Spec resultante: [`docs/superpowers/specs/2026-04-30-quick-critical-fixes-design.md`](../superpowers/specs/2026-04-30-quick-critical-fixes-design.md)

1. **C1 — side effects permanecen dentro de la transacción.**
   El roadmap original sugería envolver pasos 1–4 en `transactional()` y diferir los side effects (`closeOnRefundAuthorized`, `advanceLegalizationService->initialize`) a post-commit. Sin outbox aún (Plan 2), diferir post-commit reintroduciría inconsistencia silenciosa. Decisión: envolver TODO (1–6) dentro de `transactional()` ahora, refactorizar a outbox+post-commit cuando se ejecute Plan 5 (Domain Events) sobre la base del Plan 2.

2. **C2 — registro vía route-scope middleware, no `Application::middleware()`.**
   El roadmap (y el audit origen) decían "registrar en `Application::middleware()` con scope a `/login` y `/approve/*`". Realidad encontrada: el middleware ya estaba aplicado a `/approve/*` vía route-scope en `config/routes.php`. Mantener ese patrón y agregar `/login` por route-scope es más selectivo y consistente. Functionally equivalente al registro global con scoping condicional.

3. **Adiciones al alcance de Plan 1 (necesarias para no romper funcionalidad):**
   - Fix del bug SSL en `CakeMailerAdapter::_ensureTransport()` — sin él, migrar `NotificationService` al adapter rompería cuentas SMTP que usan puerto 465.
   - Migrar `testSmtpConnection()` al adapter — para no dejar `configureTransport()` huérfano solo para el diagnóstico.
   - Nuevo template `templates/email/html/smtp_test.php` (1 línea) — requerido por el punto anterior.

### 2026-05-01 — Plan 2: pivot de outbox async a email log síncrono con reintento manual

Spec resultante: [`docs/superpowers/specs/2026-05-01-email-log-design.md`](../superpowers/specs/2026-05-01-email-log-design.md)

El plan original proponía outbox + worker CLI ejecutado por cron (`bin/cake outbox process`). Decisión operativa del usuario: **descartar el worker** para no introducir un cron en el servidor (otro componente del que cuidarse, monitorear, reiniciar si cae). Sustituir por:

1. Tabla `email_logs` que registra cada intento de envío con `status` (pending/sent/failed), `attempts`, `last_error`, `payload` snapshot.
2. Envío sigue **síncrono** desde `NotificationService` (con CircuitBreaker existente). Las excepciones SMTP ya no se tragan — se propagan al controller para que la UI muestre el error al usuario que disparó el flujo.
3. Recuperación **manual** desde la UI: panel inline en `invoices/edit` y `employee_novelties/edit`, más vista global `/email-logs` (admin only) con reintento individual y masivo.
4. Sweep lazy de huérfanos (filas `pending` interrumpidas por crash de PHP) al cargar la vista global o al ejecutar un reintento — sin cron.

Trade-off: cero infraestructura nueva en el servidor a cambio de recovery humano en lugar de automático. Aceptado.

**Lo que sigue cubierto:**
- ✅ **W8** (correos perdidos silenciosamente): cada intento queda registrado y los fallos son visibles + recuperables desde la UI.

**Lo que ya estaba cubierto antes de Plan 2 y queda confirmado:**
- ✅ **C4** (saga-shaped flows sin compensación): resuelto por la transacción atómica del Plan 1 (`authorizePayment` con side effects dentro de `transactional()`). El outbox ya no era necesario para C4.

**Impacto en planes futuros:**

- **Plan 5 — Domain Events (C6).** Ya no puede apoyarse en la mecánica del outbox de Plan 2. Tendrá que usar `Cake\Event\EventManager` (despacho síncrono in-process) o reintroducir un mecanismo de despacho diferido específico del Plan 5. **A re-evaluar al iniciar Plan 5.** El brainstorming de Plan 5 debe abrir con esa decisión.

- **Plan 6 — W14 (Bulkhead).** La idea original de usar un worker async como bulkhead para webhooks/emails se descarta. Plan 6 abordará W14 con **timeouts agresivos** en `NotificationService` y `WebhookService` (HTTP client timeout, SMTP socket timeout) para que peticiones lentas a integraciones externas no bloqueen al usuario más de N segundos. **A definir el N específico en el spec del Plan 6.**

- **Plan 7 — W7 (`/health`).** El "backlog del outbox" como métrica deja de existir. En su lugar `/health` puede reportar `email_logs_failed_count` (correos en `failed` pendientes de reintento manual) como indicador de salud del SMTP/notificaciones. **Ajustar el alcance de Plan 7 cuando se inicie su brainstorming.**

---

## Referencias

- Auditoría origen: [`./architecture-audit-2026-04-30.md`](./architecture-audit-2026-04-30.md)
- Convenciones del proyecto: `CLAUDE.md` (raíz)
- Arquitectura actual: `ARCHITECTURE.md` (raíz)
- Estilo: `STYLES.md` (raíz)
