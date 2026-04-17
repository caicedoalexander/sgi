# Rediseño de UI del Pipeline de Facturas

**Fecha:** 2026-04-17
**Autor:** Alexander (con asistencia de Claude)
**Alcance:** Módulo Invoices — experiencia de edición estado por estado
**Estado del documento:** Diseño aprobado. Pendiente plan de implementación.

## 1. Problema

El pipeline de 5 estados funciona correctamente en el backend, pero la UI presenta fricciones que generan trabajo duplicado y confusión:

- El botón de submit principal exige **dos clics** cuando bastaría uno: primero "Guardar Cambios", refrescar, luego "Guardar y Avanzar".
- Flash warnings aparecen mencionando campos que el rol actual aún no puede editar en ese momento del flujo.
- Las secciones de pasos previos se muestran como formularios read-only extensos, repitiendo información y saturando la pantalla.
- Acciones importantes (envío de enlaces de aprobación, modificación de aprobadores) van acopladas al submit global en vez de tener botones explícitos.
- No hay mecanismo para corregir errores detectados en pasos anteriores (pendiente de diseño transversal separado).
- Cambios destructivos (eliminar pagos, rechazar autorización) no dejan motivo registrado.
- El estado final `pagada` cae en una pantalla "Editar Factura" que no permite editar.

## 2. Principios transversales

Estas reglas aplican a todos los estados del pipeline.

### 2.1 Botón de submit único

- El botón principal siempre representa la **acción natural del estado**:
  - Estados con campos editables → `Guardar y Avanzar a: <siguiente>`.
  - Estados sin campos editables (Tesorería, Aut. Pago) → `Avanzar a: <siguiente>` o botón específico (`Cerrar Factura`).
- **No existe** un botón genérico "Guardar Cambios" aislado.
- Si el usuario intenta avanzar pero faltan requisitos, los datos ingresados **se guardan**, el estado no avanza, y se muestran **errores inline** junto a los campos faltantes. Nada de flash warnings fantasma.
- El botón se deshabilita con tooltip explicativo cuando la acción no es aún posible (ej. "Registre al menos un pago para avanzar.").

### 2.2 Contexto previo como `sgi-ledger`

- Las secciones read-only que hoy muestran datos de pasos anteriores (ej. Contabilidad viendo `general, dates, classification`) se reemplazan por un `sgi-ledger` compacto al tope de la pantalla.
- Cada rol solo ve su **sección editable propia** como formulario. Todo lo demás va en el ledger.
- Correcciones sobre datos de pasos previos se resuelven con el botón "Regresar estado" (sesión de diseño aparte).

### 2.3 Flash warnings filtrados

- `Flash->warning()` sobre requisitos de avance faltantes se filtra por **campos realmente editables por el rol actual en ese estado**.
- Si un requisito no es responsabilidad del rol actual (ej. `area_approval=Aprobada` para Registro/Revisión, que depende de aprobadores externos), no se muestra como advertencia post-save.

### 2.4 Timeline visual en `pipeline_progress`

- El element `pipeline_progress` se enriquece con un array opcional `$timeline` con `{status, user_name, date}` por transición, alimentado desde `invoice_histories`.
- Render: tooltip o sublínea bajo cada nodo del pipeline con fecha y usuario de la transición.

### 2.5 Botón "Regresar estado" (diferido)

- Cada estado debe permitir regresar al estado anterior con **observación obligatoria** que quede en el historial.
- Diseño detallado fuera del alcance de este documento.

### 2.6 Limpieza

- Eliminar el campo legado `approver_id` de `InvoiceFieldAccessPolicy::ALL_FIELDS` y cualquier referencia en vista/entidad. El sistema multi-aprobador (`approver_ids[]` con `invoice_approvals`) es el canónico.

## 3. Diseño por estado

### 3.1 Estado Aprobación — rol Registro/Revisión

**Secciones editables:** datos generales, fechas, clasificación, aprobadores, DIAN.

**Reglas del formulario:**

- Con aprobaciones **pendientes**: datos generales quedan read-only; el campo `dian_validation` se mantiene deshabilitado.
- Con `area_approval = Aprobada`: `dian_validation` se habilita.
- Con `area_approval = Rechazada`: datos editables + aparece el botón `Reiniciar flujo`.

**Acciones específicas:**

| Botón | Condición de visibilidad | Efecto |
|---|---|---|
| `Enviar link de aprobación` | Hay selección en `approver_ids[]` y no hay aprobaciones activas | Crea tokens, envía correos, marca form read-only, registra evento |
| `Modificar aprobadores` | Hay aprobaciones ya enviadas (pendientes, aprobadas o rechazadas) | Abre modal con motivo obligatorio → invalida tokens previos → permite nueva selección → envía nuevos links → registra cambio con motivo en historial |
| `Reiniciar flujo` | `area_approval = Rechazada` | Limpia aprobaciones rechazadas y habilita nueva selección |
| `Guardar y Avanzar a: Contabilidad` | Siempre visible (único submit principal) | Guarda campos + avanza si requisitos OK; si no, guarda y muestra errores inline |

**Requisitos de avance** (sin cambios): `area_approval = Aprobada` y `dian_validation = Aprobada`.

**Nuevos endpoints:**

- `POST /invoices/send-approval-links/{id}`
- `POST /invoices/modify-approvers/{id}` (body: `approver_ids[]`, `reason`)

### 3.2 Estado Contabilidad — rol Contabilidad

**Sección editable única:**

```
Fecha de Causación *   [__/__/____]
  (al diligenciar, marca la factura como causada automáticamente)

Lista para Pago *      [Select ▾]
```

**Cambios:**

- Se elimina el checkbox visible `accrued`. El campo sigue existiendo internamente: `accrued` se deriva de `accrual_date` (vacío → false; con valor → true).
- Las secciones `general`, `dates`, `classification` se suprimen y sus datos se muestran en el `sgi-ledger` contextual arriba.

**`ready_for_payment`** queda tal cual por ahora; el catálogo de opciones se revisará con el equipo en una iteración posterior.

**Botón:** `Guardar y Avanzar a: Tesorería`.

### 3.3 Estado Tesorería — rol Tesorería

**Encabezado con alert cuando aplica:**

- Alert específico si la factura regresó por pago parcial:
  > ⚠ Pago parcial detectado — falta $X por cubrir. Registra el pago restante para enviar a autorización nuevamente.

**Resumen de pagos:** Total, Pagado, Falta, Estado (badge). Visible siempre.

**Form de nuevo pago (dentro de collapse "Agregar Pago"):**

```
Banco [Select ▾]   Monto [______]   Fecha [__/__/____]
☐ Pago total (usa monto restante)

[Registrar y enviar a autorización]   [Solo registrar]
```

- Checkbox **"Pago total"** reemplaza al botón ambiguo "Pago Total": al marcarlo, auto-llena monto con `remainingAmount` y deshabilita el campo.
- **"Registrar y enviar a autorización"** ejecuta en una transacción: crear pago + avanzar factura a `autorizacion_pago` + notificar al Contador. Previo a ejecutar, modal de confirmación:
  > "Este pago se registrará y la factura pasará inmediatamente al estado de Autorización de Pago. ¿Continuar?"
- **"Solo registrar"** crea el pago y mantiene la factura en Tesorería (útil para registrar varios pagos parciales antes de avanzar).

**Acciones por fila de pago no autorizado:**

| Acción | Efecto |
|---|---|
| `Editar` | Modal con banco/monto/fecha + observación obligatoria del motivo; registra en historial |
| `Eliminar` | Confirmación estándar, elimina registro |

**Botón principal:** `Avanzar a: Autorización de Pago` (renombrado, sin "Guardar" porque no hay campos del invoice editables).

- Deshabilitado con tooltip "Registre al menos un pago para avanzar" si no hay pagos pendientes.
- Misma confirmación que el del form interno.

**Nuevo endpoint:** `POST /invoice-payments/edit-payment/{invoiceId}/{paymentId}` (body: banco, monto, fecha, motivo).

**Backend `addPayment`:** acepta flag `advance_after=1` para registrar y avanzar en una transacción.

### 3.4 Estado Autorización de Pago — roles Contador + Tesorería

Este estado se divide en **dos sub-fases** sin añadir un sexto estado al pipeline. La sub-fase se **deriva** en runtime a partir de los pagos:

- **Sub-fase A (Autorización)**: existen pagos con `status=pending`.
- **Sub-fase B (Cierre/Soportes)**: todos los pagos están `status=authorized`.

#### Sub-fase A — rol Contador

Vista enfocada en la tabla de pagos pendientes.

| Acción | Efecto |
|---|---|
| `Autorizar` (por fila) | Confirmación simple. Marca pago `status=authorized`, registra autorizador y fecha. |
| `Rechazar` (por fila) | Modal con motivo obligatorio. Marca pago `status=rejected` (no elimina), guarda motivo, registra en historial, notifica a Tesorería. La factura regresa a Tesorería. |

- Autorizar uno por uno (volúmenes típicos son 1-2 pagos por factura; no se implementa batch).
- Al autorizar el último pendiente sin pagos rechazados: banner verde "Todos los pagos autorizados. Esperando cierre por Tesorería." El Contador ya no tiene acciones.

**Submit principal del Contador:** oculto. Todas las acciones son AJAX por fila.

#### Sub-fase B — rol Tesorería

```
⚠ Todos los pagos de esta factura están autorizados.
   Sube los soportes del pago y cierra la factura.

Soportes del pago  [+ Subir]
 — soporte_bancolombia.pdf  12/03/2026  [Eliminar]
 — soporte_davivienda.pdf   18/03/2026  [Eliminar]

Pagos autorizados (read-only, con link al soporte asociado)
 ✓ Bancolombia   $800.000  autorizado 14/03/2026
 ✓ Davivienda    $400.000  autorizado 19/03/2026

[Cerrar Factura]
```

**Regla de pago parcial preservada:** al ejecutar `Cerrar Factura`, si `payment_status = 'Pago Parcial'`, la factura regresa a Tesorería. Flash específico: *"Pago parcial. La factura regresa a Tesorería hasta completar el monto total."*

**Botón principal Tesorería:** `Cerrar Factura`.

- Deshabilitado si faltan soportes; tooltip: *"Sube al menos un soporte para cerrar."*

#### Cambios de modelo para esta sub-fase

- `invoice_payments.status`: enum `pending | authorized | rejected` (reemplaza booleano `authorized`). Migración con backfill (`authorized=true → 'authorized'`, `false → 'pending'`).
- `invoice_payments.rejection_reason`: TEXT nullable.
- `rejectPayment` no elimina; marca `status=rejected` + motivo.
- `recalculatePaymentStatus` ignora pagos `rejected` al sumar.
- Nueva regla en `TRANSITION_REQUIREMENTS[autorizacion_pago]`: `_payment_authorized` + `_has_payment_supports` (custom rule).
- Secciones visibles del Contador: reducir a `general (ledger) + treasury`; eliminar sección informativa `payment_authorization` redundante.
- Secciones visibles de Tesorería en `autorizacion_pago`: `general (ledger) + treasury (read-only) + payment_supports (nuevo)`.
- Element `payment_section` acepta prop `mode ∈ {authorize, close, view}` para variar la vista.

#### Soportes de pago — modelo unificado

El soporte cuelga del **evento de pago más grueso** que exista. No se duplica:

| Ruta de pago | Tabla de soportes |
|---|---|
| Factura individual (pago directo) | `invoice_payment_attachments` (FK a `invoice_payments.id`) |
| Caja menor | `petty_cash_payment_attachments` (FK a `petty_cash_payments.id`) |
| Programación de pagos | `payment_scheduling_attachments` (ya existe) |

**Consecuencia UI:**

- Factura individual en sub-fase B → sección "Soportes del pago" editable + botón "Cerrar Factura".
- Factura agrupada en caja menor → banner *"El cierre de esta factura se gestiona desde el registro de caja menor [link]"*. Sin botón propio.
- Factura en programación → banner *"El cierre de esta factura se gestiona desde la programación de pagos [link]"*. Sin botón propio.

Esta misma sub-fase B se replicará en los módulos de caja menor y programación de pagos en iteraciones futuras.

### 3.5 Estado Pagada

**Acceso:** `InvoicesController::edit()` detecta `pipeline_status = pagada && roleName !== ADMIN` y redirige a `view`.

**Vista `view` enriquecida:**

1. **Ledger completo** con toda la info de la factura.
2. **Timeline del pipeline** (de `invoice_histories`): cada transición con usuario y fecha.
3. **Pagos autorizados** (read-only) con link de descarga del soporte asociado. Cualquier usuario con `invoices.can_view` puede descargar.
4. **Documentos** abiertos (`+ Adjuntar`): subidas post-cierre se marcan con badge "Post-cierre" + usuario + fecha.
5. **Observaciones** abiertas (`+ Añadir`): igual regla de badge post-cierre.
6. **Historial de cambios** (`invoice_histories`).

**Botones de acción:** sin avance posible. Las subidas y observaciones tienen sus propios submits locales.

**"Descargar comprobante de pago consolidado" (PDF con factura + pagos + soportes):** feature futuro, fuera del alcance de este documento.

## 4. Dependencias y fuera de alcance

### Dependencias

- Diseño del botón transversal **"Regresar estado"** con observación obligatoria (sesión separada).
- Replicación de la sub-fase B en **Caja Menor** y **Programación de Pagos** (módulos propios).

### Fuera de alcance

- Revisión del catálogo de `ready_for_payment` (requiere validación con el equipo).
- Botón "Descargar comprobante de pago consolidado".
- Notificación al proveedor cuando una factura se marca como Pagada.
- Batch de autorización de pagos.

## 5. Checklist de cambios técnicos (referencia rápida)

Este no es el plan de implementación — es inventario para derivarlo.

**Modelos / migraciones:**

- [ ] `invoice_payments.status` (enum) + backfill.
- [ ] `invoice_payments.rejection_reason` (TEXT nullable).
- [ ] Nueva tabla `invoice_payment_attachments`.
- [ ] Nueva tabla `petty_cash_payment_attachments` (para soportar futura replicación).
- [ ] Remover `approver_id` de `invoices` si se confirma sin uso (revisar datos existentes).

**Servicios:**

- [ ] `InvoicePipelineService`: nueva regla `_has_payment_supports`; filtrado de `advanceErrors` por editabilidad del rol.
- [ ] `InvoicePaymentService`: `recalculatePaymentStatus` ignora `rejected`; `advance_after` en `addPayment`; nueva acción `editPayment`.
- [ ] `InvoiceApprovalService`: acción `modifyApprovers` con motivo + invalidación de tokens; `sendApprovalLinks` desacoplado.
- [ ] `InvoiceFieldAccessPolicy`: eliminar `approver_id` de `ALL_FIELDS`; ajustar `VISIBLE_SECTIONS_BY_ROLE` para reducir secciones read-only.

**Controllers:**

- [ ] `InvoicesController::edit`: redirect a `view` cuando `pagada && !Admin`; unificación del botón principal.
- [ ] `InvoicesController::sendApprovalLinks`, `modifyApprovers`.
- [ ] `InvoicePaymentsController::editPayment`, soporte de `advance_after`, ajuste de `rejectPayment` (no elimina).

**Templates:**

- [ ] Nuevo element `ledger_context.php` reutilizable por todos los estados.
- [ ] `payment_section` acepta `mode` + tabla de soportes; agrega checkbox "Pago total" y botones "Registrar y enviar..." / "Solo registrar".
- [ ] `pipeline_progress` acepta `$timeline` con transiciones.
- [ ] Vista `view.php` enriquecida: ledger, timeline, pagos, documentos, observaciones, historial.
- [ ] `edit.php`: secciones previas eliminadas, reemplazadas por `ledger_context`; botones contextuales por estado/rol.
- [ ] Modales: "Modificar aprobadores", "Editar pago", "Rechazar pago".

**JS:**

- [ ] `sgi-payment.js`: checkbox "Pago total", confirm de "Registrar y enviar".
- [ ] Validación inline client-side de campos requeridos antes de avanzar.

## 6. Preguntas abiertas

Ninguna a la fecha de este documento. Todo lo no decidido se explicitó como "fuera de alcance" o "dependencia".
