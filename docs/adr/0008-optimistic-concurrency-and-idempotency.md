# ADR 0008 — Optimistic concurrency + idempotency keys para mutaciones

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

El audit W6 marcó dos clases de fallo en mutaciones críticas:

- **Doble-click en "Registrar pago"** crea dos pagos para la misma factura.
- **Doble-click en "Avanzar"** intenta dos transiciones, ambas pasan validation y
  una crea inconsistencia (estado avanzado dos veces, pagos recalculados raros).

## Decisión

**Estrategia híbrida según el tipo de mutación.**

### Pagos (`registerPayment`)

**Idempotency key.** El form genera un UUID de un solo uso (`idempotency_key`)
embebido como hidden input. La columna `invoice_payments.idempotency_key` tiene
índice único; PDO lanza error de constraint si la misma key se reusa, y el caller lo
atrapa devolviendo `ServiceResult` "ya procesado, no es error".

### Avances de pipeline (`advanceStatus`, `advance`)

**Optimistic concurrency stateless.** El form incluye un hidden
`expected_status` con el estado actual al renderizar la página. El controller
verifica que el estado en DB siga siendo el esperado antes de avanzar; si difiere,
muestra "alguien más cambió esto, recargue" y aborta.

No usamos versión de fila (column `version` con `WHERE version = ?`) porque el
estado del pipeline ya cumple el rol de "qué fila lógica estás editando" — si el
estado cambió, la fila lógica cambió también.

## Consecuencias

**Positivas:**
- Doble-click en pagos no produce duplicados (DB lo bloquea).
- Doble-click en avances no produce double-advance (controller lo bloquea).
- Stateless: no hay estructura nueva en cache ni en DB para tracking de operaciones.
- Errores de concurrencia muestran feedback claro al usuario en lugar de inconsistencia
  silenciosa.

**Negativas:**
- Dos mecanismos distintos (idempotency key vs expected_status). Trade-off: cada uno
  ajusta a su tipo de mutación; un solo mecanismo no encaja igual de bien en ambos.
- Idempotency keys requieren ser únicos por usuario/sesión. Si el form se cachea
  (back button + reenvío), la segunda petición falla con feedback útil ("ya
  procesado") en lugar de duplicar.
- El usuario que pierde una carrera ve una pantalla de "alguien más cambió esto",
  lo cual a veces sorprende. El mensaje debe ser claro.

## Alternativas consideradas

### Locks pesimistas (`SELECT ... FOR UPDATE`)
Descartado por costo: bloquear filas durante la edición de un usuario congela el
flujo para otros que intentan ver/editar. La concurrencia real en SGI es baja y no
amerita ese costo.

### Token-based deduplication en cache (Redis)
Descartado: introduciría Redis donde no lo necesitamos hoy. La columna única en DB
da el mismo efecto sin infra adicional.

### Disable button JS-only
Descartado: protege la mayoría de los doble-clicks pero no los submits con
JavaScript desactivado, ni recargas posteriores que reenvían el form. Útil como
**capa adicional** (UX), no como única defensa.
