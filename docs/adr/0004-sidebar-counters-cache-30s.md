# ADR 0004 — Sidebar counters con cache TTL 30s

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

`SidebarCounterService::getCounters()` ejecuta ~13 queries de count por cada page
load (W11 de la auditoría). Multiplicado por el tráfico interno típico, es la fuente
más alta de queries en cualquier request del sistema.

## Decisión

**Cachear el array completo de contadores con TTL de 30 segundos, clave por rol.**

Configuración:

- `Cache::remember()` con engine `sidebar` (FileEngine, prefix `sgi_sidebar_`,
  duration `+30 seconds`).
- Clave: `sidebar_counters_{roleName}`.
- El fallback degradado (`_emptyCounters()`) se cachea junto con el éxito — si la DB
  cae, el sidebar muestra ceros 30s incluso después del recovery. Aceptable.

## Consecuencias

**Positivas:**
- En cache hit, el sidebar consume 0 queries.
- En cache miss (cada 30s por rol), 13 queries.
- Trafficked: 5 roles activos × 1 cache miss / 30s = ~10 cache fills/min global.
  Comparado con cada user-request golpeando 13 queries, es una reducción enorme.
- Cero infraestructura nueva: `FileEngine` ya estaba configurado.

**Negativas:**
- Lag de hasta 30s en ver un nuevo pendiente en el sidebar después de crearlo. En
  UX es invisible (los contadores son indicadores secundarios).
- Si la DB cae, el sidebar muestra ceros durante ese minuto y los siguientes 30s
  post-recovery.
- File-based: si SGI escalara a múltiples instancias, cada una tendría su cache.
  No es problema hoy (single instance) y migrar a Redis es cambiar `className`.

## Alternativas consideradas

### Tabla materializada `sidebar_counters` actualizada en write-side
Descartado por el costo de mantenimiento. Cada Table que afecta contadores
(Invoices, EmployeeNovelties, PettyCashRecords, NoveltyLiquidationDocs,
AdvanceLegalizations…) tendría que agregar hooks `afterSave`/`afterDelete` que
actualicen la tabla. Esa lógica duplica la de `getCounters()` y se desfasa fácilmente.
Una mejora futura, pero no se justifica hoy.

### Caché por usuario en lugar de por rol
Descartado: los contadores ya están agrupados por rol; cachear por usuario explotaría
la memoria (decenas de entradas por rol, una por usuario activo) sin beneficio.

### Caché por query individual (cada count su entrada)
Descartado: complejidad sin beneficio. El array completo de 13 contadores cabe en
una sola entrada de cache de pocos KB.

### Invalidar en `afterSave` de las tablas relevantes (write-through)
Descartado por acoplamiento. Mantenemos TTL puro: simple y predecible. Si en el
futuro 30s no es suficiente, se baja el TTL antes de añadir invalidación selectiva.
