# Quitar botón "Solo registrar" del elemento de pagos

**Fecha:** 2026-04-22
**Alcance:** UI + JS + backend

## Contexto

El elemento compartido `templates/element/payment_section.php` expone dos botones al registrar un pago:

- `Solo registrar` (`data-btn-register-only`) — registra sin avanzar estado.
- `Registrar y enviar a autorización` (`data-btn-register-advance`) — registra y avanza factura a `autorizacion_pago`.

El flag `advance_after` solo es consumido por `InvoicePaymentsController` / `InvoicePaymentService`. Los otros 3 consumidores del elemento (`LegalizationPayments`, `PettyCashPayments`, `LiquidationDocPayments`) ya ignoran el flag — su `addPayment` siempre registra sin avanzar estado padre.

## Decisión

Dejar un único botón: **"Registrar y enviar a autorización"**. Eliminar flag `advance_after` de toda la cadena (UI → JS → Controller → Service).

## Cambios

### 1. `templates/element/payment_section.php`
- Eliminar `<button data-btn-register-only>` (líneas 126-128).
- Dejar solo `data-btn-register-advance`.

### 2. `webroot/js/sgi-payment.js`
- Eliminar variable `btnRegisterOnly` y su listener (líneas 21, 116-123).
- Eliminar parámetro `advanceAfter` de `submitPayment()`; quitar bloque `if (advanceAfter) fields['advance_after'] = '1'`.
- Ajustar guard `if (!btnRegisterAdvance && !btnRegisterOnly)` → `if (!btnRegisterAdvance)`.

### 3. `src/Controller/InvoicePaymentsController.php`
- Eliminar línea `$advanceAfter = (bool)($data['advance_after'] ?? false);` (línea 51).
- Quitar argumento `$advanceAfter` de la llamada a `registerPayment` (línea 61).

### 4. `src/Service/InvoicePaymentService.php`
- Quitar parámetro `bool $advanceAfter = true` de `registerPayment()` (línea 153).
- Quitar del `use` del closure transactional (línea 171).
- Eliminar bloque `if (!$advanceAfter) return ServiceResult::ok(...)` (líneas 194-196).
- Actualizar PHPDoc del método (líneas 144-148) — describir que siempre avanza.

### 5. `CLAUDE.md`
- Línea 63: quitar texto "acepta `advance_after` flag".

## Riesgos

- **Tests:** no hay tests en `tests/` que referencien `advance_after` / `registerPayment` — verificado con grep.
- **Otros flujos (Legalizations, PettyCash, Novelties):** el botón que queda dice "enviar a autorización" — semánticamente correcto porque los pagos registrados quedan pendientes y el Contador los autoriza desde la tabla. No requieren cambio.

## Commit

`feat(payments): remover botón "Solo registrar" y flag advance_after`
