# ADR 0001 — Layered Architecture, no DDD

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

SGI es un sistema de gestión interna construido con CakePHP 5.3. La auditoría
arquitectónica (2026-04-30) verificó que el proyecto sigue una arquitectura en
capas clásica (Controller → Service → Table/Entity) en lugar de DDD táctico
(aggregates, repositories de dominio, bounded contexts explícitos, etc.).

La pregunta natural del audit y de devs nuevos al proyecto es: ¿por qué no DDD?

## Decisión

**Mantener el layered classic.** Las capas son:

- **Controller** (`src/Controller/`) — HTTP, validación de input, delega a services.
- **Service** (`src/Service/`) — lógica de negocio, transacciones, transiciones de
  estado. Retornan `ServiceResult` (ver ADR 0002).
- **Table/Entity** (`src/Model/`) — ORM CakePHP, asociaciones, validación, finders.
- **Constants** (`src/Constants/`) — valores de dominio (estados, roles, tipos).

No hay aggregates ni repositories de dominio. `Table` cumple el rol de repository.
Las entidades son anémicas en el sentido DDD — la lógica vive en services.

## Consecuencias

**Positivas:**
- Patrón idiomático CakePHP. Devs con experiencia previa en CakePHP son productivos
  desde el día 1.
- Pocas abstracciones; el camino de un request al DB es lineal.
- ORM provee asociaciones, validación y finders sin tener que reimplementar.

**Negativas:**
- Servicios pueden crecer (god services como `InvoicePipelineService` antes de Plan 4).
  Mitigado extrayendo policies/validators/state machines.
- No hay barrera explícita entre módulos; `Table` y `Service` pueden cruzarse libremente.
- Si SGI evoluciona a un sistema multi-bounded-context (ej. expansión a otra unidad de
  negocio), tocará migrar capa por capa.

## Alternativas consideradas

### DDD táctico (Aggregates + Repository pattern)
Descartado porque el dominio actual cabe en un módulo (gestión interna de una empresa)
y el costo de reimplementar repositories sobre Table no se compensa. Si surge un
segundo bounded context, **se reconsiderará** para esa parte.

### Hexagonal / Ports & Adapters
Descartado por el mismo motivo: la complejidad de implementar puertos para todo el
sistema supera el beneficio de testabilidad cuando ya tenemos services puros (sin
side effects ocultos) que se prestan a tests directos. Además, este proyecto no usa
tests automatizados (decisión operativa).

### CQRS / Event Sourcing
Descartado: las consultas no son lo bastante distintas de las mutaciones para
justificar dividir el modelo. ES introduciría complejidad operativa (event store,
replays) sin un caso de uso claro hoy.
