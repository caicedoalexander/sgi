# Architecture Decision Records

Este directorio contiene las decisiones arquitectónicas del proyecto SGI. Cada ADR
documenta el **por qué** de una decisión, no su implementación (eso vive en specs,
plans y código).

## Cómo añadir un ADR

1. Copia `template.md` a `NNNN-titulo-en-kebab-case.md` con el siguiente número
   consecutivo. Ej: si el último es `0008-…`, el nuevo es `0009-…`.
2. Completa las 4 secciones (Contexto, Decisión, Consecuencias, Alternativas).
3. Marca `Status: Accepted` cuando se incorpore al proyecto.

## Cómo actualizar un ADR existente

Los ADRs son **inmutables** una vez aceptados. Si una decisión cambia:

1. Crea un ADR nuevo con la decisión nueva.
2. En el ADR antiguo, cambia el status a `Superseded by ADR NNNN`.
3. Añade una nota al inicio del antiguo apuntando al nuevo.

No se borran ADRs (para preservar la historia de decisiones).

## Índice

| # | Título | Status |
|---|---|---|
| [0001](0001-layered-architecture-no-ddd.md) | Layered Architecture, no DDD | Accepted |
| [0002](0002-service-result-instead-of-exceptions.md) | ServiceResult en lugar de excepciones para errores de dominio | Accepted |
| [0003](0003-email-log-sync-instead-of-outbox.md) | Email log síncrono con reintento manual; descartar outbox | Accepted |
| [0004](0004-sidebar-counters-cache-30s.md) | Sidebar counters con cache TTL 30s | Accepted |
| [0005](0005-di-container-application-services.md) | DI Container centralizado en `Application::services()` | Accepted |
| [0006](0006-state-pattern-invoice-pipeline.md) | State pattern para el pipeline de facturas | Accepted |
| [0007](0007-domain-events-eventmanager-sync.md) | Domain events vía `EventManager` síncrono in-process | Accepted |
| [0008](0008-optimistic-concurrency-and-idempotency.md) | Optimistic concurrency + idempotency keys para mutaciones | Accepted |
