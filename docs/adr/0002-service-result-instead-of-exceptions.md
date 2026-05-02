# ADR 0002 — ServiceResult en lugar de excepciones para errores de dominio

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

Los servicios de SGI pueden fallar por dos clases de razón:

1. **Errores de dominio**: el caller envió datos que no satisfacen una regla de negocio
   (factura ya está en estado final, falta documento requerido, motivo demasiado
   corto, etc.). Son **esperables**, parte del flujo normal del usuario, y la UI
   debe mostrarlos como mensajes accionables.

2. **Errores de infraestructura**: la DB cae, el SMTP no responde, el filesystem
   está lleno. Son **excepcionales**, raros, y requieren intervención técnica.

Mezclar ambos en excepciones tiene problemas:
- Forzar el caller a `try/catch` cada llamada produce ruido.
- Excepciones de dominio se pierden con `catch (Exception $e) { return null; }` (fue
  el caso del audit W12).
- Los stack traces de excepciones de dominio inflan logs sin valor.

## Decisión

**Métodos con side effects que pueden fallar por reglas de negocio devuelven
`ServiceResult`. Las excepciones se reservan para errores de infraestructura.**

`ServiceResult` (ver `src/Service/ServiceResult.php`) es:

```php
new ServiceResult(
    bool $success,
    mixed $data = null,
    array $errors = []
);
```

Con factory methods `::ok($data)` y `::fail($errors)`. El caller verifica
`$result->success` antes de usar `$result->data`.

**Métodos que NO devuelven `ServiceResult`:**
- Getters / finders / cálculos puros (devuelven el tipo natural).
- Métodos `?Entity` que pueden retornar `null` por diseño (no es un fallo).
- Métodos `void` que no pueden fallar de manera que el caller deba reaccionar.

## Consecuencias

**Positivas:**
- El caller ve explícitamente que el método puede fallar y debe verificar.
- Errores de dominio quedan como datos manipulables (lista de strings), no como
  excepciones que se atrapan o se dejan burbujear.
- Logs limpios: errores de infra dejan stack trace; errores de dominio no.
- Componer múltiples llamadas con verificación de éxito es trivial.

**Negativas:**
- Hay que recordar verificar `$result->success`. Olvidarlo no es fail-fast.
- Existe ambigüedad en el límite: ¿"no se pudo guardar" es dominio o infra? Por
  convención: si la causa es validación → dominio; si es PDOException → infra.
- Migrar gradualmente del patrón anterior (array con `success`/`error`) llevó
  el Plan 7 (W15).

## Alternativas consideradas

### Excepciones de dominio (clase `DomainException` propia)
Descartado por la convención del lenguaje: excepciones tienen costo de stack trace y
producen flujo de control no-local. Para errores esperables, prefiere valor de retorno.

### Result type funcional (Either / Result<T, E>)
Descartado por sobre-ingeniería para PHP. `ServiceResult` con `success/data/errors`
cubre los casos sin requerir librería de funcional ni tipos genéricos.

### Mixed return (a veces array, a veces null, a veces bool)
Descartado: es lo que ya teníamos antes del Plan 7 y produjo el W15 ("callers must
know which return shape this method has"). `ServiceResult` uniforma.
