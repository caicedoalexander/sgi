# ADR 0007 — Domain events vía `EventManager` síncrono in-process

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

El audit C6 marcaba un ciclo entre `InvoicePipelineService` ↔ `InvoicePaymentService`
↔ `AdvanceLegalizationService`. El ciclo se sostenía con `?? new` lazy-init, lo cual
oscurecía el grafo y hacía las dependencias circulares invisibles.

El Plan 5 introdujo eventos de dominio para romper el ciclo. La pregunta de
implementación fue: ¿qué mecanismo de despacho?

El roadmap original asumía que el outbox del Plan 2 sería la base del despacho. Pero
el outbox fue descartado (ver ADR 0003). Así que Plan 5 tuvo que decidir mecanismo
desde cero.

## Decisión

**Despachar eventos de dominio síncronamente, in-process, vía
`Cake\Event\EventManager`.**

Eventos definidos:

- `InvoicePaidEvent` — disparado cuando una factura llega a `pagada`.
- `InvoiceRefundAuthorizedEvent`, `InvoiceRefundRejectedEvent` — flujos de refund.
- `AdvanceLegalizedEvent` — cuando una legalización completa.

Suscriptores:

- `LegalizationInitializerSubscriber` reacciona a `Invoice.paid` y dispara la
  inicialización si la factura es de tipo anticipo.
- (otros según el dominio)

`InvoicePipelineService` ya no llama directo a `AdvanceLegalizationService`. Emite
el evento al `EventManager` global y deja que el subscriber decida.

## Consecuencias

**Positivas:**
- El ciclo de dependencias C6 se rompe. Cada service ya no conoce a los otros.
- Añadir un nuevo subscriber a un evento existente no toca al publisher.
- Despacho **síncrono** garantiza que el subscriber corre dentro de la misma
  transacción que el publisher (cuando hay una). Plan 5 lo aprovecha para que la
  inicialización de legalización rollback junto con el `authorizePayment`.

**Negativas:**
- Acoplamiento temporal: si un subscriber es lento, el publisher espera.
  Mitigación: subscribers deben ser rápidos; trabajos largos se delegan a otra
  pieza (que hoy no existe — si surge la necesidad, se introducirá un mecanismo
  diferido específico).
- Errores en subscriber pueden derribar al publisher. Mitigación: subscribers
  críticos atrapan sus propias excepciones; los no-críticos pueden burbujear.
- No hay despacho diferido / out-of-process. Si SGI necesita publicar a otros
  sistemas, se introducirá outbox específico para integración (no para eventos
  internos).

## Alternativas consideradas

### Outbox + worker para todos los eventos
Descartado por la misma razón del ADR 0003: cero infra nueva. El outbox era el
camino natural cuando estaba sobre la mesa, pero al descartarse, EventManager era la
opción restante razonable.

### Reusar `WebhookService` como bus de eventos internos
Descartado: el webhook service hace HTTP a sistemas externos (n8n). Reutilizarlo para
eventos internos mezclaría dos canales con semánticas distintas (in-process vs HTTP).

### Bus async dedicado (RabbitMQ, Redis pub/sub)
Descartado por sobre-ingeniería. El dominio actual no requiere despacho asíncrono;
introducir un broker añade infraestructura, monitoreo y latencia.
