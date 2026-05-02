# ADR 0005 — DI Container centralizado en `Application::services()`

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

Antes del Plan 3 (DI Container), el wiring de servicios usaba el patrón
`?? new ServiceClass()` en cada constructor. Síntomas (W3 del audit):

- `InvoiceHistoryService` se instanciaba múltiples veces por request (cada service
  que lo necesitaba creaba uno nuevo).
- El grafo de dependencias era invisible — había que leer cada constructor para
  reconstruirlo.
- La cache interna de `AuthorizationService` (W5) era inútil porque cada request
  creaba un `AuthorizationService` nuevo.
- Test wiring era imposible sin `?? new MockClass()` en producción.

## Decisión

**Registrar todos los services en `Application::services(ContainerInterface)`** —
método que CakePHP 5 expone para configurar su contenedor (League Container debajo).

Los servicios se construyen con sus dependencias declaradas. Los constructores ya no
tienen `?? new`. El container resuelve transitivamente.

`AuthorizationService` queda registrado como single-instance por request (su
caché interna empieza a funcionar — resuelve W5 colateralmente).

## Consecuencias

**Positivas:**
- Grafo de dependencias visible en un solo archivo.
- `InvoiceHistoryService` y similares se construyen una vez por request.
- Inyectar mocks/stubs en futuras tareas (cuando aparezcan tests) es trivial: cambiar
  el binding en una sub-clase de `Application`.
- `?? new` desaparece del código de servicios.

**Negativas:**
- `Application::services()` crece con cada servicio nuevo. Mitigable extrayendo
  módulos (no necesario hoy).
- Los servicios dependen del container — si el container falla en construir uno, la
  app no arranca. Es un fail-fast deseable, pero requiere disciplina al añadir deps.

## Alternativas consideradas

### Service Locator (`$this->getContainer()->get(...)` en cada caller)
Descartado: oculta dependencias dentro de los métodos en lugar de declararlas en el
constructor. Es el anti-patrón que `?? new` ya sufría, sólo movido un nivel.

### Una factory por servicio
Descartado: 36+ factories sería ruido. El container hace lo mismo con menos código.

### Auto-wiring por reflexión sin registro explícito
Descartado: es lo que el container hace cuando no hay binding explícito, pero la
explicitud de un binding facilita debugging y deja un contrato claro de "qué inyectar
para este servicio".
