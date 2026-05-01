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
| 1 | Quick Critical Fixes | C1, C2, C3 | XS (2–4 días) | — | ⬜ Pendiente |
| 2 | Outbox + Emails Confiables | C4, W8 | M (1–2 sem) | — | ⬜ Pendiente |
| 3 | DI Container | W3, W5 | S (3–5 días) | — | ⬜ Pendiente |
| 4 | Refactor del Pipeline | C5, W2, W9, W10 | M (1–2 sem) | Plan 3 (recomendado) | ⬜ Pendiente |
| 5 | Domain Events (romper ciclo) | C6 | S (~1 sem) | Plan 2 | ⬜ Pendiente |
| 6 | Resilience Hardening | W6, W13, W14, W7 | M (~1 sem) | Plan 2 | ⬜ Pendiente |
| 7 | Observability + Polish | W1, W4, W11, W12, W15, ADRs | M (~1 sem) | — | ⬜ Pendiente |

**Cobertura:** los 6 críticos (C1–C6) y los 15 warnings (W1–W15) sin items huérfanos.

---

## Mapa de dependencias

```
Plan 1 (sin deps, primero)
  ↓
Plan 2 ─────┐         Plan 3 (sin deps, paralelizable)
            ↓                 ↓
          Plan 5         Plan 4
            ↓                 ↓
          Plan 6 ←────────────┘
            ↓
          Plan 7 (último, polish)
```

**Reglas:**
- El **Plan 1** se hace primero siempre (estabilización antes de refactor).
- **Plan 2** y **Plan 3** son independientes entre sí — pueden hacerse en paralelo o en cualquier orden.
- **Plan 5** requiere la infraestructura del **Plan 2** (outbox + eventos).
- **Plan 4** se beneficia de tener el **Plan 3** primero (refactor más limpio con DI), pero no es bloqueante.
- **Plan 6** consume la infraestructura del **Plan 2** (worker async sobre outbox para bulkhead).
- **Plan 7** se deja al final — es polish y no desbloquea nada.

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

### Plan 2 — Outbox + Emails Confiables
**Items:** C4, W8
**Tamaño estimado:** 1–2 semanas
**Depende de:** nada
**Bloquea a:** Plan 5, Plan 6 (parcialmente)

**Alcance:**
- Migración: tabla `outbox` (`id`, `event_type`, `aggregate_type`, `aggregate_id`, `payload JSON`, `occurred_at`, `processed_at`, `attempts`, `last_error`).
- Servicio `OutboxService::publish(string $eventType, array $payload)`.
- CLI command `bin/cake outbox process` (worker que toma pendientes en lotes, los entrega con retry, marca processed/failed).
- Primera integración: `NotificationService::sendApprovalLinkNotification` deja de enviar inline; en su lugar publica `EmailQueued` al outbox dentro de la misma transacción del flujo origen. El worker entrega.
- Mismo tratamiento para `sendNoveltyApprovalEmail` y `sendTestEmail` queda fuera (se mantiene síncrono — es para diagnóstico).
- Documentación operativa: cómo correr el worker, alertas si la tabla crece.

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
| 1 | Quick Critical Fixes | 🟡 En progreso | [spec](../superpowers/specs/2026-04-30-quick-critical-fixes-design.md) | — | — | — |
| 2 | Outbox + Emails | ⬜ Pendiente | — | — | — | — |
| 3 | DI Container | ⬜ Pendiente | — | — | — | — |
| 4 | Refactor Pipeline | ⬜ Pendiente | — | — | — | — |
| 5 | Domain Events | ⬜ Pendiente | — | — | — | — |
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

---

## Referencias

- Auditoría origen: [`./architecture-audit-2026-04-30.md`](./architecture-audit-2026-04-30.md)
- Convenciones del proyecto: `CLAUDE.md` (raíz)
- Arquitectura actual: `ARCHITECTURE.md` (raíz)
- Estilo: `STYLES.md` (raíz)
