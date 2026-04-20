# Diseño: ciclo de vida de aprobadores de factura

**Fecha:** 2026-04-20
**Contexto:** dos bugs reportados en el flujo de aprobadores.

## Bugs origen

1. **Post-aprobación permite "reemplazar" vía Enviar link.** Con la factura ya aprobada, el select de "Enviar link de aprobación" seguía visible porque `hasPendingApprovals` es false (ninguna `pending`). Seleccionar un nuevo aprobador creaba un batch nuevo y, por el truco del time-window en `getCurrentApprovals`, la UI ocultaba las aprobaciones previas como si hubieran sido "reemplazadas".
2. **Modificar aprobadores no reemplaza, agrega.** `modifyApprovers` solo invalida tokens `pending`. Las aprobaciones ya resueltas (`approved`/`rejected`) se quedaban, y el nuevo batch se sumaba.

## Reglas de negocio resultantes

- Solo se puede actuar sobre aprobadores mientras `pipeline_status = aprobacion`.
- Con cero aprobaciones vigentes: aparece el select + botón **Enviar link de aprobación**.
- Con al menos una aprobación vigente (pendiente, aprobada o rechazada): desaparece el select; solo queda **Modificar aprobadores** (motivo obligatorio).
- **Modificar reemplaza el set completo:** todas las aprobaciones vigentes se marcan como `superseded`, `area_approval` vuelve a `Pendiente`, y se crea un batch nuevo.
- Factura ya avanzada a `contabilidad` o posterior: sección de aprobadores en solo lectura.
- Factura rechazada (`area_approval=Rechazada`): sigue apareciendo **Reiniciar flujo**.

## Modelo de datos

Enum `invoice_approvals.status` extendido con `superseded`. Sin columnas nuevas.

Constante: `InvoiceConstants::APPROVER_STATUS_SUPERSEDED = 'superseded'`.

Migración solo si la columna es enum MySQL estricto (no si es `varchar`).

## Cambios en servicio

- `hasAnyActiveApprovals(int $invoiceId): bool` — cuenta `pending|approved|rejected`.
- `getCurrentApprovals` filtra `status != superseded` (elimina el time-window de 1 min).
- `sendApprovalLinks` falla si `hasAnyActiveApprovals` o si el estado no es `aprobacion`.
- `modifyApprovers`:
  - Guard: `pipeline_status = aprobacion`.
  - Dentro de la transacción: `updateAll` marca previas como `superseded`, limpia token.
  - Resetea `area_approval` a `Pendiente`, `area_approval_date` a null.
  - Llama `assignApprovers` para crear el nuevo batch.

## Cambios en controller

`InvoicesController::edit()` calcula y pasa al template:

- `$canSendLinks = !hasAnyActiveApprovals && estado=aprobacion && editable`
- `$canModifyApprovers = hasAnyActiveApprovals && estado=aprobacion && editable`

## Cambios en template

`templates/Invoices/edit.php` — sección Aprobadores:

```php
if ($canSendLinks) {
    // select + botón Enviar link (form auxiliar)
} elseif ($canModifyApprovers) {
    // resumen + botón Modificar aprobadores
} else {
    // solo lectura
}
```

## Plan de ejecución

| # | Paso | Archivos |
|---|------|----------|
| 1 | Verificar tipo de `status`; migración si enum | `config/Migrations/` |
| 2 | Constante `APPROVER_STATUS_SUPERSEDED` | `src/Constants/InvoiceConstants.php` |
| 3 | `hasAnyActiveApprovals`, filtro `getCurrentApprovals`, guards `sendApprovalLinks` | `src/Service/InvoiceApprovalService.php` |
| 4 | `modifyApprovers`: superseded + reset `area_approval` + guard estado | idem |
| 5 | Flags en `edit()` | `src/Controller/InvoicesController.php` |
| 6 | Ramificación en template | `templates/Invoices/edit.php` |
| 7 | QA manual | — |

## Test plan

1. Factura nueva en `aprobacion` → select visible → aprobar → select desaparece, botón Modificar aparece.
2. Factura totalmente aprobada aún en `aprobacion` → solo Modificar (cierra bug 1).
3. Modificar con pendientes → previas superseded, nuevo batch, `area_approval=Pendiente` (cierra bug 2).
4. Modificar con aprobadas → previas superseded, reset a Pendiente, nuevo batch.
5. Factura en `contabilidad` → sección solo lectura.
6. Rechazada → Reiniciar flujo sigue funcionando.
7. `invoice_histories` registra `approvers_modified` con motivo.
