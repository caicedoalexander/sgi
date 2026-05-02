# ADR 0006 — State pattern para el pipeline de facturas

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

Antes del Plan 4, el pipeline de 5 estados de facturas (`aprobacion → contabilidad →
tesoreria → autorizacion_pago → pagada`) vivía como `const TRANSITIONS = [...]`
arrays + `match` / `switch` chains en `InvoicePipelineService`. Síntomas:

- W9 — el mismo patrón procedural se repetía en `NoveltyPipelineService`,
  `PaymentSchedulingPipelineService`.
- Añadir un estado nuevo requería tocar 5+ lugares dentro del mismo archivo.
- OCP violado: cualquier cambio de comportamiento por estado tocaba `InvoicePipelineService`.

## Decisión

**Convertir cada estado en una clase polimórfica que implementa
`InvoicePipelineState`.**

Interfaz mínima (ver `src/Service/Pipeline/State/`):

- `getName(): string`
- `getNext(?string $documentType): ?string`
- `getPrevious(): ?string`
- `getEditableFields(string $roleName): array`
- `validateAdvance(Invoice, ...): array`
- `getRoleVisibility(): array`

Implementaciones: `AprobacionState`, `ContabilidadState`, `TesoreriaState`,
`AutorizacionPagoState`, `PagadaState`, `LegalizadaState`.

`InvoicePipelineService` se convierte en coordinador delgado que delega al state
actual. La transición rejected (`area_approval='Rechazada'`) se maneja como guard
externo, no como estado (no hay transiciones desde rejected sin reset explícito).

## Consecuencias

**Positivas:**
- Añadir un estado nuevo = un archivo nuevo + bind en factory de states. No tocar
  el coordinador.
- Reglas por estado quedan localizadas (cada State tiene su propio `validateAdvance`).
- Tests por State (cuando el proyecto los tenga) son aislados.

**Negativas:**
- Más archivos en `src/Service/Pipeline/State/`. Es el cost de OCP.
- Boilerplate menor: cada State implementa los 6 métodos aunque algunos sean stubs.
- Cambios cross-state (ej. añadir un campo a la interfaz) tocan todos los States. Es
  raro, pero pasa.

## Alternativas consideradas

### Mantener el array `TRANSITIONS` + chain de `match`
Descartado: era el problema W9 original. No escala.

### State machine library (Symfony Workflow, Finite, etc.)
Descartado: introducir dependencia para un dominio de 5 estados es overkill. Las
clases polimórficas dan la flexibilidad necesaria sin librería.

### Estados como enum + métodos en `Invoice` entity
Descartado por anemic-vs-rich tradeoff. Mantener la lógica de transición en services
(layered classic, ADR 0001) y la entidad como datos. Si SGI migrara a DDD, esta
sería la opción natural.
