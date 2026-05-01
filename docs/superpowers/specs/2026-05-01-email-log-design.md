# Plan 2 — Email Audit Log + Reintento manual (W8)

**Plan del roadmap:** [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md) · **Plan #2**
**Auditoría origen:** [`docs/audits/architecture-audit-2026-04-30.md`](../../audits/architecture-audit-2026-04-30.md)
**Fecha:** 2026-05-01
**Tamaño estimado:** 4–6 días

---

## Resumen

Hoy, cuando un correo de aprobación falla (SMTP caído, host inválido, timeout), el sistema persiste el registro de aprobación de todas formas y traga la excepción con `Log::error()`. El usuario que disparó el flujo ve "✓ todo bien", el aprobador nunca recibe el correo, y nadie se entera hasta que alguien revise los logs del servidor o el aprobador se queje. Este es el item **W8** de la auditoría.

Este plan resuelve ese problema introduciendo una **bitácora de correos** (`email_logs`) que registra cada intento de envío y su resultado, y una **UI de reintento manual** para que cuando SMTP esté inestable o se haya caído, los correos pendientes/fallidos sean visibles y recuperables sin entrar al servidor.

---

## Pivot respecto al roadmap original

El roadmap propuso outbox + worker CLI ejecutado por cron (`bin/cake outbox process`). **Se descartó** para no introducir un cron operativamente: significaría otro componente del que cuidarse, monitorear, y reiniciar en caso de fallo del propio cron.

En su lugar, esta solución mantiene el envío **síncrono** (no hay worker) pero hace observable y recuperable cada intento. La diferencia funcional clave es:

- **Outbox original:** SMTP cae → los correos quedan encolados → el worker los drena automáticamente cuando vuelve.
- **Esta solución:** SMTP cae → cada intento queda registrado como `failed` → un humano (admin) entra a `/email-logs`, ve la lista de fallidos, y los reintenta uno a uno o masivamente cuando SMTP vuelva.

Trade-off aceptado: cero infraestructura nueva en el servidor, a cambio de recovery humano en lugar de automático.

El impacto sobre Plan 5, Plan 6 y Plan 7 está documentado en la sección "Cambios al roadmap" del archivo del roadmap.

---

## Alcance funcional

### Para el usuario que dispara un envío (Registro/Revisión, Auxiliares, etc.)

- **Antes:** asignar aprobadores → flash verde "Aprobadores asignados" → el correo puede haberse mandado o no, no hay forma de saberlo.
- **Después:** asignar aprobadores → si todos los correos salieron, mismo flash verde. Si alguno falló, el flash incluye el aviso: *"Aprobadores asignados, pero el correo a Juan Pérez falló: SMTP timeout. Puede reintentar desde el panel de notificaciones de la factura."*
- En la página de edición de la factura/novedad, aparece un panel **"Notificaciones de correo"** que lista cada intento con su destinatario, estado (✅ Enviado / ⚠ Fallido / ⏳ Pendiente), fecha, y un botón **Reintentar** cuando el estado lo permite.

### Para el administrador

- Nueva entrada en el sidebar bajo "Sistema": **Logs de correo** (`/email-logs`).
- Lista paginada de todos los envíos con filtros por estado, tipo de correo, fechas y destinatario.
- Reintento individual desde cualquier fila fallida.
- Botón **Reintentar todos los fallidos** para drenar de un golpe la cola de pendientes tras una caída prolongada de SMTP.
- Cada reintento es trazable: la misma fila se actualiza con `attempts++` y `last_attempt_at`.

### Para el operador del servidor (cero cambios)

- No hay cron nuevo, no hay daemon, no hay servicio adicional.
- El stack de runtime sigue siendo: Apache/Nginx + PHP-FPM + MySQL/MariaDB.
- La única infraestructura nueva es **una tabla**.

---

## Componentes nuevos y modificados

| Componente | Tipo | Rol |
|---|---|---|
| `email_logs` (tabla DB) | Nuevo | Registro de cada intento de envío y su resultado |
| `App\Constants\EmailLogConstants` | Nuevo | Constantes de status, event_type, entity_type, labels en español |
| `App\Model\Entity\EmailLog` | Nuevo | Entity estándar |
| `App\Model\Table\EmailLogsTable` | Nuevo | Validaciones, finders por entidad y por status |
| `App\Service\EmailLogService` | Nuevo | Coordinador de la bitácora: registrar, marcar resultado, reintentar, sweep. Para reintentar, delega el envío real en `NotificationService` (que es el que tiene el `CircuitBreaker`) |
| `App\Service\NotificationService` | Modificado | Recibe `EmailLogService` por DI, integra el log en cada envío, deja de tragar excepciones, y expone un método de envío "raw" (con destinatario/asunto/template/viewVars ya resueltos) que `EmailLogService::retry` reusa |
| `App\Service\InvoiceApprovalService` | Modificado | Ya no oculta la excepción de SMTP; la convierte en error visible para el caller |
| `App\Controller\EmployeeNoveltiesController` (líneas 641, 788) | Modificado | Mismo: deja de tragar el fallo, lo muestra al usuario |
| `App\Controller\EmailLogsController` | Nuevo | `index` con filtros, `retry`, `retryAllFailed` |
| `templates/EmailLogs/index.php` | Nuevo | Vista global tipo índice del proyecto (15/página, filtros) |
| `templates/element/email_log_panel.php` | Nuevo | Panel inline reusable para invoices/edit y employee_novelties/edit |
| `templates/Invoices/edit.php` | Modificado | Inserta el panel inline |
| `templates/EmployeeNovelties/edit.php` | Modificado | Inserta el panel inline |
| `templates/layout/default.php` (sidebar) | Modificado | Nueva entrada "Logs de correo" bajo "Sistema" |
| Migración `email_logs` | Nuevo | Crea la tabla con los índices descritos abajo |
| Migración seed permisos | Nuevo | Inserta `email_logs.can_view` / `can_edit` para el rol Administrador |
| `AppController::$controllerModuleMap` | Modificado | `'EmailLogs' => 'email_logs'` |
| `AuthorizationService::MODULES` | Modificado | `'email_logs' => 'Logs de correo'` |

---

## Schema `email_logs`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `event_type` | VARCHAR(50) NOT NULL | `invoice_approval_request`, `novelty_approval_request` |
| `entity_type` | VARCHAR(50) NULL | `invoice`, `employee_novelty` |
| `entity_id` | BIGINT UNSIGNED NULL | Referencia lógica a la entidad de origen (sin FK; el log sobrevive si la entidad se borra) |
| `to_email` | VARCHAR(255) NOT NULL | Destinatario |
| `subject` | VARCHAR(255) NOT NULL | Para inspección y filtro de búsqueda |
| `template` | VARCHAR(100) NOT NULL | Nombre del template usado en el envío original |
| `payload` | JSON NOT NULL | `viewVars` y `layout` — todo lo que se necesita para reenviar el correo idéntico |
| `status` | VARCHAR(20) NOT NULL DEFAULT 'pending' | Valores válidos: `pending`, `sent`, `failed` |
| `attempts` | INT UNSIGNED NOT NULL DEFAULT 0 | Cuenta cada intento incluido el primero |
| `last_error` | TEXT NULL | Mensaje truncado a 5000 caracteres de la última excepción |
| `last_attempt_at` | DATETIME NULL | Set al iniciar cada intento |
| `sent_at` | DATETIME NULL | Solo poblado cuando `status='sent'` |
| `created_by` | BIGINT UNSIGNED NULL | Usuario que disparó el envío original. NULL para reintentos masivos desde admin |
| `created` | DATETIME NOT NULL | |
| `modified` | DATETIME NOT NULL | |

**Índices:**

- PK `id`
- `idx_entity (entity_type, entity_id)` — usado por el panel inline
- `idx_status_created (status, created)` — usado por filtros de la vista global y por el sweep de huérfanos
- `idx_event_type (event_type)` — filtro adicional de la vista global

**Constantes (en `App\Constants\EmailLogConstants`):**

- `STATUS_PENDING`, `STATUS_SENT`, `STATUS_FAILED`
- `STATUSES` (array con los tres)
- `STATUS_LABELS` (en español: "Pendiente", "Enviado", "Fallido")
- `EVENT_INVOICE_APPROVAL_REQUEST`, `EVENT_NOVELTY_APPROVAL_REQUEST`
- `EVENT_LABELS` (en español)
- `ENTITY_INVOICE`, `ENTITY_NOVELTY`
- `ORPHAN_THRESHOLD_SECONDS = 300` (5 minutos)

---

## Comportamiento — flujos clave

### Flujo 1: Envío exitoso

1. El caller (por ejemplo `InvoiceApprovalService::assignApprovers`) ya tiene el destinatario, el asunto y los `viewVars` armados.
2. Llama a `NotificationService::sendApprovalLinkNotification($invoice, $url, $approverId, $createdBy)`.
3. `NotificationService` pide a `EmailLogService` que cree una fila con `status='pending'`, `attempts=0`. Recupera el `id` de esa fila.
4. `NotificationService` invoca el envío real a través del `CircuitBreaker` y del `MailerInterface` (mismo flujo que hoy).
5. El SMTP responde OK. `NotificationService` pide a `EmailLogService` que marque la fila como `sent`: `status='sent'`, `attempts=1`, `last_attempt_at=now`, `sent_at=now`.
6. El caller recibe control sin excepción y muestra el flash de éxito normal.

### Flujo 2: Envío fallido

1. Pasos 1–4 idénticos al flujo 1.
2. SMTP lanza excepción (timeout, host inválido, auth fallida, o el CircuitBreaker cortó).
3. `NotificationService` pide a `EmailLogService` que marque la fila como `failed`: `status='failed'`, `attempts=1`, `last_attempt_at=now`, `last_error=` el mensaje de la excepción truncado.
4. `NotificationService` **propaga la excepción** al caller (a diferencia del comportamiento actual donde se la traga).
5. El caller (`InvoiceApprovalService::assignApprovers`) la captura, la convierte en un error agregado para el usuario, y continúa con el siguiente aprobador del lote (no aborta a los demás).
6. El controller que invocó al servicio muestra el flash con el mensaje del error y las instrucciones de reintento.

### Flujo 3: Proceso interrumpido a mitad de envío

Si PHP-FPM mata el proceso entre el INSERT pending y el envío SMTP (timeout duro, OOM, kill -9), la fila queda como `pending` indefinidamente.

Tratamiento: **lazy sweep**. Cada vez que el admin entra a `/email-logs` (acción `index`) o cuando alguien dispara un `retry`, antes de cualquier otra cosa el `EmailLogService` ejecuta `sweepOrphanPendings()`. Esta operación marca como `failed` (con `last_error='Envío inconcluso (proceso interrumpido)'`) cualquier fila `pending` cuyo `created` sea más viejo que `ORPHAN_THRESHOLD_SECONDS` (5 minutos) y no tenga `last_attempt_at` en los últimos 5 minutos.

No hay cron. Si nadie consulta nunca la página, los huérfanos siguen `pending` y nadie se entera — coherente con el principio "cero infraestructura nueva". El primer admin que entre los limpia.

### Flujo 4: Reintento individual desde el panel inline

1. El admin (o el usuario con permiso de edición sobre la factura/novedad) ve el panel inline en la página de edición y hace clic en **Reintentar** sobre una fila `failed`.
2. POST a `/email-logs/retry/{id}` con CSRF.
3. `EmailLogsController::retry` ejecuta `sweepOrphanPendings()` y luego `EmailLogService::retry($id)`.
4. `EmailLogService::retry` carga la fila, reconstruye el envío con el `payload` guardado (mismo destinatario, mismo asunto, mismas `viewVars`, mismo template) y lo manda vía `NotificationService` (que pasa por el CircuitBreaker).
5. Resultado:
   - Éxito → la fila se actualiza a `sent`, `attempts++`, `sent_at=now`.
   - Falla → la fila queda `failed`, `attempts++`, `last_error` actualizado con el nuevo mensaje. Si el CircuitBreaker está abierto, `last_error` lo refleja explícitamente.
6. Redirect al referer con flash que indica el resultado (`Reintento exitoso` o `Reintento falló: <mensaje>`).

### Flujo 5: Reintento masivo desde la vista global

1. El admin entra a `/email-logs`, opcionalmente filtra por `status=failed` y rango de fechas.
2. Click en **Reintentar todos los fallidos**, confirma en el modal.
3. POST a `/email-logs/retry-all-failed`.
4. `EmailLogService::retryAllFailed($limit=100)` itera sobre las filas `failed` y llama a `retry()` por cada una. El límite es para no quedarse colgado si hay miles.
5. Devuelve `{success: N, failed: M}`. Flash informa el resultado.
6. Si tras el primer batch quedan más, el admin pulsa otra vez. (Patrón estándar: sin paginación de procesamiento, sin progress bar — para el volumen esperado, 100 por batch es suficiente.)

### Flujo 6: Test SMTP (sin cambios)

`NotificationService::testSmtpConnection()` se queda exactamente como está hoy: síncrono, devuelve `['success', 'message']`, **no** crea fila en `email_logs`. Es diagnóstico inmediato — el usuario está mirando el resultado en pantalla, no necesita rastro persistente.

### Flujo 7: Reenvío de aprobación (`resendApproval`)

`EmployeeNoveltiesController::resendApproval` (línea 749) hoy permite **regenerar** el token y mandar un correo de aprobación nuevo (caso de uso: token expirado o aprobador cambió). Es un flujo conceptualmente distinto al "reintento" de este plan.

Comportamiento tras este cambio: `resendApproval` sigue funcionando igual, pero al pasar por el `NotificationService` modificado **automáticamente** crea una nueva fila en `email_logs` para ese correo. No requiere cambios adicionales en el controller — hereda el nuevo comportamiento de `sendNoveltyApprovalEmail`.

Resultado neto: si un aprobador no recibió el correo y el token aún no expiró, el admin/usuario usa **Reintentar** (mismo token, mismo correo). Si el token expiró o cambia el aprobador, usa **resendApproval** (token nuevo, correo nuevo, log nuevo). Ambos coexisten sin solapamiento.

---

## Comportamiento del CircuitBreaker

El `CircuitBreaker` de `NotificationService` (umbral 3 fallos / recuperación 300s) se mantiene tal cual. Su interacción con el log:

- 3 fallos consecutivos → CB abre.
- 4° intento (sea de un envío nuevo o de un reintento manual) → falla rápido sin tocar SMTP. La fila queda `failed` con `last_error='Circuit breaker abierto - SMTP no disponible'` (o el mensaje exacto que lance el CB).
- 5 minutos después o tras un reset manual del cache → CB cierra y un reintento volverá a probar SMTP real.

Esto significa que un admin que pulse "Reintentar todos los fallidos" inmediatamente después de la caída verá todos sus reintentos fallar igual de rápido (CB sigue abierto). Comportamiento esperado: esperar la ventana de recuperación, o intervenir el cache si es urgente.

---

## UI — detalles

### Panel inline (`templates/element/email_log_panel.php`)

- Visible en la página de edición de factura y de novedad.
- Solo se renderiza si hay al menos un log para esa entidad.
- Encabezado: "Notificaciones de correo".
- Tabla con columnas: Destinatario · Estado (badge con icono y color) · Fecha (último intento) · Acción.
- El estado fallido muestra adicionalmente el `last_error` en color rojo, debajo de la fila.
- El botón **Reintentar** aparece solo en filas `failed` y en filas `pending` consideradas huérfanas (más viejas que 5 minutos sin `last_attempt_at` reciente). Nunca en `sent`.
- Estilos siguiendo `STYLES.md`: borders en lugar de shadows, prefijo `.sgi-` para clases custom, Bootstrap Icons para los iconos de estado.

### Vista global (`templates/EmailLogs/index.php`)

Sigue el patrón de los demás índices del proyecto: paginación 15/página, filtros con Select2 y Flatpickr, tabla con `clickable-row` cuando aplique.

Filtros:

- Estado (Todos / Pendiente / Enviado / Fallido)
- Tipo (Todos / Aprobación factura / Aprobación novedad)
- Desde / Hasta (rango de fecha sobre `created`)
- Destinatario (búsqueda LIKE)

Acciones globales:

- **Reintentar todos los fallidos** — solo Administrador, modal de confirmación, procesa hasta 100 por click.

Acciones por fila:

- **Reintentar** — solo en filas con estado `failed` o `pending` huérfano.
- (No hay vista detalle separada — el panel ya muestra todo lo relevante; el `payload` completo no aporta valor al usuario humano.)

### Sidebar

Nueva entrada bajo la sección "Sistema":

- Texto: **Logs de correo**
- Icono: `bi-envelope-exclamation`
- Visible solo si el usuario tiene `email_logs.can_view`

---

## Permisos

| Acción | Quién |
|---|---|
| Ver `/email-logs` index | Administrador |
| Reintento individual desde `/email-logs` | Administrador |
| Reintento masivo (`retryAllFailed`) | Administrador |
| Reintento desde panel inline en `invoices/edit/{id}` | Quien tenga `invoices.can_edit` sobre esa factura (mismo permiso que asignar aprobadores) |
| Reintento desde panel inline en `employee_novelties/edit/{id}` | Quien tenga `employee_novelties.can_edit` |

Razonamiento del permiso inline reutilizado: si el usuario puede editar la factura (incluyendo asignar aprobadores y ver el flujo), puede también reintentar el correo de aprobación de su propia factura. No vale la pena introducir una permission key nueva solo para ese caso.

Implementación del permiso inline: el controller de `EmailLogsController::retry` valida internamente — si el `entity_type` y `entity_id` corresponden a una factura, requiere `invoices.can_edit` sobre esa factura; si es novedad, `employee_novelties.can_edit`. El admin pasa por defecto (bypass de admin ya existente).

---

## Validación manual

Tras el merge, ejecutar en orden:

1. **Migración aplica y rolea.** `php bin/cake migrations migrate` crea la tabla. `migrations rollback` la borra. Volver a aplicar sin errores (el patrón `hasTable()` cubre re-ejecuciones).

2. **Happy path — factura.** Como Registro/Revisión, crear factura y asignar 2 aprobadores reales con SMTP correctamente configurado. Esperado: ambos correos llegan al inbox; en `/email-logs` aparecen 2 filas con `status='sent'`, `attempts=1`, `sent_at` poblado; en la página de edición de la factura el panel inline muestra los 2 envíos con ícono verde.

3. **Happy path — novedad.** Crear una novedad que requiera aprobación de jefe. Mismo chequeo: correo recibido, log con `sent`, panel inline visible.

4. **Falla SMTP — registro y notificación al usuario.** En "Ajustes del Sistema" cambiar `smtp_host` a un host inválido (por ejemplo `smtp.invalid.local`) y guardar. Asignar aprobador a una factura. Esperado: la factura queda con su aprobador asignado (DB consistente — no se revierte la asignación), el flash incluye el aviso del error con instrucciones de reintento, y en `/email-logs` la fila queda `failed` con un `last_error` legible.

5. **Recuperación tras falla.** Restaurar SMTP correcto. Click en **Reintentar** en el panel inline. Esperado: el correo llega al inbox, la fila pasa a `sent`, `attempts=2`, `last_error` se mantiene como referencia histórica.

6. **Reintento masivo.** Repetir el escenario de falla con 3 facturas distintas (3 fallos en distintos momentos). Restaurar SMTP. Ir a `/email-logs` como Administrador, filtrar por `status=failed`, ver las 3 filas. Click en **Reintentar todos los fallidos** y confirmar. Esperado: flash con `Reintentos: 3 exitosos, 0 fallidos`, las 3 filas en `sent`.

7. **CircuitBreaker abierto.** Forzar 3 fallos consecutivos con SMTP roto. Confirmar que el 4° intento falla en sub-segundo (sin esperar timeout SMTP) y que `last_error` indica circuit breaker abierto. Esperar la ventana de recuperación o resetear el cache → un nuevo reintento debe volver a llegar al SMTP.

8. **Pending huérfano.** Insertar manualmente vía consola DB: `INSERT INTO email_logs (event_type, to_email, subject, template, payload, status, attempts, created, modified) VALUES (...)` con `status='pending'` y `created` muy antiguo. Cargar `/email-logs` como admin. Esperado: la fila aparece como `failed` con `last_error='Envío inconcluso (proceso interrumpido)'` (el sweep la transformó al cargar el índice).

9. **Permisos.** Loguearse como rol no-Administrador (Tesorería, Contabilidad, etc.). Esperado: el sidebar **no** muestra "Logs de correo"; visitar `/email-logs` directamente redirige al dashboard (regla actual de `_enforcePermission()`); el panel inline en `invoices/edit/{id}` **sí** es visible si el rol tiene `invoices.can_edit`, y el botón **Reintentar** funciona para los correos de esa factura.

10. **Test SMTP no se loguea.** Click en "Probar conexión SMTP" en Ajustes. Esperado: feedback en pantalla (success/error) idéntico al actual; **ninguna** fila nueva en `email_logs`.

---

## Lo que NO entra en este plan

- Tests automatizados (política del proyecto: validación manual).
- Bounce processing async (saber si el destinatario realmente recibió el correo más allá de "SMTP lo aceptó").
- Métrica de correos fallidos en el dashboard (encaja en Plan 7).
- Reintento automático en background (era el outbox; descartado en este plan).
- Bulkhead para SMTP/webhooks (W14, replanteado para Plan 6 con timeouts).
- Tabla `outbox` (no se crea — fue sustituida por `email_logs` en este plan).

---

## Criterios de éxito (resumen)

- Ningún correo se envía sin dejar rastro: 1 fila en `email_logs` por cada intento.
- Cada fallo es visible para el usuario que disparó el envío (en el flash) y para el administrador (en `/email-logs`).
- Una caída prolongada de SMTP es recuperable desde la UI sin acceso al servidor.
- Cero infraestructura nueva en el servidor (sin cron, sin daemon, sin servicio adicional).
- W8 cerrado.

---

## Referencias

- Auditoría origen: [`docs/audits/architecture-audit-2026-04-30.md`](../../audits/architecture-audit-2026-04-30.md), sección W8.
- Roadmap maestro: [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md), Plan 2.
- Convenciones del proyecto: `CLAUDE.md` (raíz), `ARCHITECTURE.md`, `STYLES.md`.
- Plan 1 (precedente): [`2026-04-30-quick-critical-fixes-design.md`](./2026-04-30-quick-critical-fixes-design.md) — patrón de migración con `BaseMigration` + `hasTable()`, patrón de constantes, patrón de validación manual.
