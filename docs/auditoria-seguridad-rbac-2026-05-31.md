# Auditoría de seguridad / RBAC — SGI

## Resumen ejecutivo

Se auditó el sistema SGI (CakePHP 5.3 / PHP 8.4) sobre dimensiones de control de acceso (RBAC/IDOR), gestión de secretos, autenticación/sesión, validación de entrada, CSRF/middleware y SSRF. Tras verificación adversarial de cada hallazgo contra el código real, se confirman **11 hallazgos** y se descartan 41 reportes como falsos positivos o controles correctos (verificaciones positivas).

### Conteo por severidad (post-ajuste del verificador)

| Severidad | Cantidad |
|-----------|----------|
| Crítico   | 0        |
| Alto      | 2        |
| Medio     | 1        |
| Bajo      | 7        |
| Descartados / FP | 41 |

> Nota: varios hallazgos llegaron etiquetados como `critical`/`high` por el detector, pero el verificador los ajustó a la baja al confirmar mitigaciones existentes (whitelist rol-aware por paso, RBAC fail-closed, `.gitignore` ya corregido). Las severidades de esta sección reflejan el veredicto ajustado.

### Riesgos top (3-5)

1. **IDOR de borrado cross-factura** (Alto): `InvoicesController::deleteDocument` no liga el documento a la factura; permite eliminar adjuntos de otras facturas con auditoría mal atribuida.
2. **Credenciales reales en el historial de Git** (Alto): el `.env` con `SECURITY_SALT`, contraseña y host de BD reales sigue recuperable del historial pese a estar ya en `.gitignore`. Requiere **rotación inmediata**.
3. **`area_approval` escribible por mass assignment en `add`** (Medio): un usuario con permiso de creación puede nacer una factura como "Aprobada", saltándose el gate de aprobación externa.
4. **Endurecimiento de sesión/cookies ausente en el repositorio** (Bajo, varios): flags `secure`/`httponly`/`samesite`, `timeout` de sesión, y `secure`/`sameSite` del CSRF delegados al php.ini del despliegue.
5. **Validaciones de negocio/SSRF acotadas a actores privilegiados** (Bajo): sobrepago sin tope, webhook sin whitelist de URL, open redirect post-login. Reales pero gateados por permiso o sesión válida previa.

---

## Hallazgos confirmados

### Crítico

Ninguno confirmado tras verificación. (Los reportes `critical` originales —`.env` commiteado, mass assignment de `role_id`/`active`— fueron ajustados a Alto o descartados; ver más abajo y la tabla de descartados.)

---

### Alto

#### A-1. IDOR en eliminación de documentos de factura

- **OWASP:** A01:2021 – Broken Access Control
- **Ubicación:** `src/Controller/InvoicesController.php:680-683`
- **Confianza del verificador:** Alta

**Descripción.** `deleteDocument` obtiene la factura y el documento de forma independiente, sin ningún `WHERE` que ligue el documento a la factura indicada. Un usuario autenticado con permiso `delete` sobre facturas puede eliminar adjuntos pertenecientes a **otras** facturas pasando un `documentId` ajeno. La validación de la línea 685 (`InvoiceDocumentService::canDeleteDocument`, `src/Service/InvoiceDocumentService.php:57-60`) solo compara el `pipeline_status` del documento, **no** la pertenencia. `InvoiceDocumentService::deleteDocument(int $documentId)` borra por ID de documento únicamente.

**Evidencia.**
```php
// InvoicesController::deleteDocument (680-683)
$invoice = $this->Invoices->get($id, contain: [...]);
$documentsTable = TableRegistry::getTableLocator()->get('InvoiceDocuments');
$document = $documentsTable->get($documentId);  // sin validar invoice_id

if (!$this->documentService->canDeleteDocument($document, $invoice->pipeline_status)) {
    // canDeleteDocument solo valida estado, no pertenencia
}
```
Patrón correcto contrastado en `RefundsController::deleteDocument` (840-845):
```php
$document = $documentsTable->find()
    ->where(['id' => $documentId, 'refund_id' => $refundId])
    ->first();
```

**Agravante.** El registro de auditoría se anota contra el `invoiceId` del atacante (no la factura víctima), produciendo un borrado silencioso con auditoría corrupta.

**Recomendación.** Cambiar el lookup de `deleteDocument` a:
```php
$document = $documentsTable->find()
    ->where(['id' => $documentId, 'invoice_id' => $invoiceId])
    ->first();
if ($document === null) { /* 404 / fail */ }
```
y propagar `invoice_id` al servicio de borrado, alineándose con Refunds/PettyCash/PaymentScheduling. Acotado por requerir el permiso RBAC `delete` y coincidencia de estado, pero explotable cross-record.

---

#### A-2. Credenciales sensibles recuperables del historial de Git (.env)

- **OWASP:** A02:2021 – Cryptographic Failures
- **Ubicación:** historial de Git — commit `7486891` (`git show 7486891:.env`); estado actual `/home/alexander/Documentos/dev/sgi/.env`
- **Confianza del verificador:** Alta

**Descripción.** El `.env` **actual** NO está trackeado (`.gitignore` líneas 4 y 12 lo cubren; `git ls-files .env` vacío). Sin embargo, el archivo SÍ estuvo commiteado y fue borrado después (commit `87d9b0c "Delete .env"`); borrarlo en un commit posterior NO lo elimina del historial. `git show 7486891:.env` recupera credenciales reales:
- `SECURITY_SALT=<REDACTADO — hash de 64 hex usado para hashing/cifrado>`
- `DATABASE_PASSWORD=<REDACTADO>`
- `DATABASE_HOST/PORT/USERNAME/NAME=<REDACTADO — host remoto, puerto, usuario y nombre de BD>`
- `DEBUG=true`

> Los valores reales se omiten deliberadamente de este documento para no reintroducir el secreto en el repositorio. Consultá el historial Git local (commit del `.env`) si necesitás confirmarlos para la rotación.

El `SECURITY_SALT` se usa para hashing/cifrado (incluidos tokens de aprobación). Cualquiera con acceso al histórico (clones, forks, publicación futura) recupera estas credenciales aunque ya no estén en HEAD.

**Recomendación (prioritaria).**
1. **ROTAR YA** `SECURITY_SALT` y la contraseña/credenciales de la BD del host remoto — la rotación invalida la exposición histórica.
2. Reescribir el historial con `git-filter-repo` (o BFG) para purgar el `.env`.
3. Mantener `.env` en `.gitignore` (ya hecho) y publicar `.env.example` sin secretos.
4. Usar un gestor de secretos / variables de entorno del orquestador en producción.

> Ajustado de Crítico a Alto: ya no es un secreto vivo en el árbol actual y el `.gitignore` está corregido, pero las credenciales reales persisten en el historial y siguen siendo válidas mientras no se roten.

---

### Medio

#### M-1. `area_approval` escribible vía mass assignment en `Invoices::add`

- **OWASP:** A01:2021 – Broken Access Control
- **Ubicación:** `src/Model/Entity/Invoice.php:29`; vector real en `src/Controller/InvoicesController.php:238-250`
- **Confianza del verificador:** Media

**Descripción.** `'area_approval' => true` en `$_accessible`. El flujo de **edit** está protegido (pasa por `InvoicePipelineService::saveAndAdvance` → `PipelineFieldPolicy::filterEntityData`, y `area_approval` no figura en `InvoiceFieldAccessPolicy::FIELDS_BY_STEP` en ningún paso). Pero **`add` (238-250)** hace `patchEntity(newEmptyEntity(), getData())` con datos crudos y sin filtrado por paso/rol. Un usuario con permiso `invoices.create` puede enviar `area_approval=Aprobada`; como la factura nace en `STATUS_APROBACION`, esto vuelve `requiresApproval()` false e `isApproved()` true (`Invoice.php:62-89`), saltándose el envío real de links de aprobación.

**Evidencia.** `Invoice.php:29: 'area_approval' => true,`

**Recomendación.** Marcar `'area_approval' => false` en `$_accessible` (como ya está `area_approval_date`), forzando su asignación exclusivamente vía `setApprovalResult()` / `InvoiceApprovalService`. Cierra el vector de `add` sin afectar el flujo de `edit` ya protegido.

> Ajustado de Alto a Medio: requiere permiso de creación previo, no permite escalada entre roles, y el avance posterior sigue gateado por `canOperate`/`pipeline_permissions`.

---

### Bajo

#### B-1. Cookies de sesión sin flags de seguridad explícitas

- **OWASP:** A02:2021 — **Ubicación:** `config/app.php:426-428` — **Confianza:** Media

`'Session' => ['defaults' => 'php']` sin `ini` que fije `session.cookie_secure`, `session.cookie_httponly` ni `session.cookie_samesite`. El endurecimiento de la cookie de sesión queda delegado al php.ini del despliegue. **Recomendación:** `'Session' => ['defaults' => 'php', 'ini' => ['session.cookie_secure' => true, 'session.cookie_httponly' => true, 'session.cookie_samesite' => 'Strict']]`. Bajo porque la explotabilidad depende del php.ini de producción (no determinística desde el código) y el CSRF tiene defensa propia.

#### B-2. Timeout / lifetime de sesión no configurados

- **OWASP:** A02:2021 — **Ubicación:** `config/app.php:426-428` — **Confianza:** Media

Sin `'timeout'` ni `ini` de `session.cookie_lifetime`/`session.gc_maxlifetime`; expiración por inactividad delegada al php.ini. Para una app financiera conviene un timeout explícito. **Recomendación:** `'Session' => ['defaults' => 'php', 'timeout' => 30, 'ini' => ['session.cookie_lifetime' => 3600, 'session.gc_maxlifetime' => 3600]]`. Bajo: carencia de hardening, sin vector remoto directo.

#### B-3. CSRF cookie sin `secure` ni `sameSite`

- **OWASP:** A01:2021 (CSRF) — **Ubicación:** `src/Application.php:147-149` — **Confianza:** Media

`new CsrfProtectionMiddleware(['httponly' => true])` sin `secure` ni `sameSite`. No rompe la defensa CSRF primaria (double-submit token); es defensa en profundidad para navegadores antiguos y transporte. **Recomendación:** `['httponly' => true, 'secure' => env('SECURITY_SECURE_COOKIE', true), 'sameSite' => 'Strict']` + HTTPS forzado en producción.

#### B-4. SSRF por falta de validación de URL de webhook

- **OWASP:** A10:2021 — **Ubicación:** `src/Controller/SystemSettingsController.php:58` — **Confianza:** Media

`n8n_webhook_dian_crosscheck` se persiste sin validar la URL; `WebhookService` la usa en `Client::post()`. Acotado: solo un **administrador** con permiso `edit` sobre `system_settings` fija la URL (requiere ingeniería social), `Cake\Http\Client` solo soporta http/https (no `file://`/`gopher://`), y la respuesta no se expone cruda. **Recomendación:** validar esquema http/https y bloquear `localhost`/`127.0.0.1`/`::1` y rangos privados (10.x, 172.16–31.x, 192.168.x) antes de guardar.

#### B-5. Open redirect en login

- **OWASP:** A01:2021 — **Ubicación:** `src/Controller/UsersController.php:28` — **Confianza:** Media

`login()` lee `getQuery('redirect')` crudo y lo pasa a `$this->redirect()`; una cadena absoluta (`?redirect=https://evil.com`) se emite tal cual en `Location`. Solo dispara para un usuario **ya autenticado** que visita `/users/login` (vector de phishing, sin XSS). **Recomendación:** usar `$this->Authentication->getLoginRedirect()` o validar que el destino sea ruta interna (whitelist `controller`/`action`/`id`).

#### B-6. Pago sin validación de monto ≤ saldo pendiente

- **OWASP:** A04:2021 — **Ubicación:** `src/Service/InvoicePaymentService.php:74-92,211-293` — **Confianza:** Alta

`registerPayment()` no compara `amount` contra `getPendingBalance()`; `InvoicePaymentsTable` no impone tope superior. Un rol Tesorería puede registrar un sobrepago. NO produce saldo negativo (`getPendingBalance` aplica `max(0.0, ...)`) y aún requiere autorización de un Contador. **Recomendación:** validar `amount <= pending` en `registerPayment()` y/o regla en `InvoicePaymentsTable::buildRules()`. Bajo: integridad de negocio por actor de confianza, no escalada.

#### B-7. Sin rate limiting de login por usuario/email

- **OWASP:** A07:2021 — **Ubicación:** `src/Model/Entity/User.php`; `config/routes.php:58-71` — **Confianza:** Alta

Hashing correcto (`PASSWORD_DEFAULT`/bcrypt). El único anti-fuerza-bruta es `RateLimitMiddleware(5, 300)` **por IP**; no hay límite/lockout por email ni CAPTCHA, eludible con botnet/credential stuffing distribuido. **Recomendación:** rate limit por email + lockout temporal tras N fallos + CAPTCHA tras 2 fallos. Bajo: existe mitigación por IP + bcrypt encarece el ataque.

---

## Falsos positivos / descartados

| Título | Por qué se descartó |
|--------|---------------------|
| FieldAccessPolicy sin `unset(roleId)` | Verificación positiva: `getEditableFields` retorna `[]` si el rol no opera el paso (rol-aware correcto). |
| Admin bypass acotado | Correcto: `ADMIN_BYPASS_MODULES=['users','roles']`, resto pasa por lookup en `permissions` (fail-closed). |
| Permisos de pipeline en transiciones | Correcto: avance/regreso gateados por `canOperate` con default-deny. |
| Atributos de autorización en todas las acciones | Correcto y fail-closed: `_enforcePermission` lanza `LogicException` si falta atributo; cobertura completa. |
| Filtrado por estados visibles del rol | Correcto fail-closed: `_visibleStatusConditions` retorna `['1 = 0']` si no hay estados. |
| Falta de regeneración de ID de sesión | FP: el plugin Authentication ejecuta `Session::renew()` → `session_regenerate_id(true)` tras login. |
| "Remember Me" no funcional | Bug de UX, no de seguridad: ausencia de cookie persistente = menor superficie. |
| Rate limit login permisivo (5/300s) | Tuning de un control existente y razonable; no vulnerabilidad. |
| Enumeración de usuarios | FP: mensaje genérico único, ya es la mitigación correcta. |
| `findAuth()` sin estado extra | Correcto: filtra `active=true`; ausencia de lockout es hardening, no defecto. |
| Password sin `needs_rehash` | FP: `PASSWORD_DEFAULT` correcto; rehash es mejora opcional. |
| Tokens sin comparación time-constant | FP: token de 256 bits CSPRNG comparado en SQL indexado; sin oráculo práctico. |
| Tokens reutilizables tras expiración | FP: tres `if` independientes + `FOR UPDATE` revalidan `used_at`/`expires_at`. |
| Tokens de aprobación sin rate limiting | FP: entropía 2^256 (no 2^64) + doble gate de identidad autenticada + rate limit ya presente. |
| CSRF en aprobación externa | FP: middleware CSRF global aplica; doble verificación token+identidad. |
| Autorización de approver débil | FP: fail-closed; `unauthenticatedRedirect` + match `user_id`/`approver_id`. |
| Generación de token no validada | FP: `random_bytes` lanza excepción si falla; `bin2hex(32)` siempre 64 hex. |
| Token nullify sin hard delete | FP: filtro por `status=PENDING` + `FOR UPDATE` ya impiden reuso. |
| Path traversal en borrado de documento | FP: `file_path` se genera server-side desde MIME real, no de input. |
| Mass assignment `role_id` (User) | FP: solo alcanzable vía `users` add/edit, admin-only por RBAC. |
| Mass assignment `active` (User) | FP: whitelist deliberado; edición admin-only; reactivarse requiere ya autenticarse. |
| `patchEntity` sin fieldList (Users) | FP: `$_accessible` ES el whitelist; CakePHP lo respeta. |
| Mass assignment `pipeline_status` (Invoice) | FP: filtrado por whitelist rol-aware en edit; sobrescrito server-side en add. |
| Mass assignment `registered_by` (Invoice) | FP: fijado server-side al user autenticado en add; filtrado en edit. |
| Mass assignment `amount` (Invoice) | FP: editable solo en `aprobacion` por rol autorizado; bloqueado en pipeline de pago. |
| `pipeline_status` en otras entidades de flujo | FP: estado fijado server-side (constantes/enum) o filtrado por FieldAccessPolicy. |
| `Refund.status` "riesgoso" | FP: `status => false`; asignado por código, no mass assignment. |
| `registerPayment` acepta datos arbitrarios | FP: array literal de 7 claves hardcodeadas; whitelist manual de facto. |
| `patchEntity` sin fieldList (CRUD múltiple) | FP: `$_accessible` estrecho por entidad; framework lo respeta. |
| Sin validación de FK en Invoice | FP: `buildRules()` usa `existsIn()` para todos los FK de input. |
| Type juggling en comparaciones de estado | FP: usa `===`/`in_array(...,true)` (correcto). |
| Rate limiting vulnerable a IP spoofing | FP: usa `REMOTE_ADDR` (peer TCP), no `X-Forwarded-For`. |
| HostHeaderMiddleware bypass en debug | FP: producción `debug=false` valida; skip en dev es intencional. |
| GET sin Cache-Control | FP: acciones gateadas por RBAC; dato de baja sensibilidad; no es A01. |
| Upload ejecutable por misconfig | FP: extensión derivada de MIME real whitelisted; imposible escribir `.php`. |
| Rate limit upload vs size limit | FP: validación de tamaño pre-persistencia + rate limit + guía de sync infra. |
| Webhook entrante solo X-Api-Key sin HMAC | FP: endpoint GET solo-lectura sin body; `hash_equals` + guarda de clave vacía. |
| API key expuesta sin rate limit de regeneración | FP: acción admin-only POST+CSRF; `set()` sobrescribe atómico. |
| Validación de método HTTP incompleta | FP: todas las acciones mutantes fuerzan `allowMethod(['post'])`. |
| Session storage 'php' race condition | FP: handler nativo bloquea el fichero; no es A01 ni explotable. |
| `DEBUG=true` en .env | FP: `.env` no versionado; aplica solo a dev local. |
| Credenciales en `app_local.php` | FP: no trackeado; lee todo vía `env(...)`, sin hardcode. |
| Cifrado de credenciales no hermético (legado) | FP: fail-closed, `_decrypt` retorna `null` para legado, nunca texto plano. |
| `APP_FULL_BASE_URL` no validado | FP: `!$fullBaseUrl` lanza `InternalErrorException` (fail-closed). |
| PII en `email_logs` sin cifrar | FP: datos de negocio ya en claro en tablas fuente; acceso por RBAC; payload necesario para retry. |
| Log injection (CRLF) en StructuredLogger | FP: `json_encode` escapa `\r`/`\n` (flags presentes no lo desactivan). |
| Hash SHA256 de bucket determinístico | FP: clave opaca server-side, nunca expuesta; no afecta el límite. |
| API key n8n sin rotación | FP: `regenerateApiKey()` admin-only rota/revoca atómicamente. |

---

## Recomendaciones priorizadas (checklist)

Ordenadas por impacto/esfuerzo (alto impacto + bajo esfuerzo primero):

- [ ] **(Crítico operativo, bajo esfuerzo)** Rotar `SECURITY_SALT` y credenciales de BD del host remoto **ya** [A-2].
- [ ] **(Alto, bajo esfuerzo)** Ligar documento↔factura en `InvoicesController::deleteDocument` con `WHERE id + invoice_id` [A-1].
- [ ] **(Medio, bajo esfuerzo)** `'area_approval' => false` en `Invoice.php` `$_accessible` [M-1].
- [ ] **(Bajo, muy bajo esfuerzo)** Añadir bloque `ini` con `cookie_secure`/`httponly`/`samesite` + `timeout` a `Session` en `config/app.php` [B-1, B-2].
- [ ] **(Bajo, muy bajo esfuerzo)** Añadir `secure`/`sameSite` al `CsrfProtectionMiddleware` [B-3].
- [ ] **(Bajo, bajo esfuerzo)** Validar monto ≤ saldo pendiente en `registerPayment()` + regla en `buildRules()` [B-6].
- [ ] **(Bajo, bajo esfuerzo)** Sanear `redirect` en login (`getLoginRedirect()` o whitelist de ruta interna) [B-5].
- [ ] **(Bajo, medio esfuerzo)** Validar URL de webhook (esquema + bloqueo de IPs privadas/localhost) [B-4].
- [ ] **(Bajo, medio esfuerzo)** Rate limit/lockout de login por email + CAPTCHA tras N fallos [B-7].
- [ ] **(Higiene, medio esfuerzo)** Reescribir historial Git con `git-filter-repo` para purgar `.env` [A-2].

---

## Alcance y limitaciones

- **Alcance:** controladores, servicios, entidades, middlewares, configuración (`config/app.php`, `routes.php`, `Application.php`) y plantillas relevantes a RBAC/IDOR, secretos, autenticación/sesión, validación de entrada, CSRF, SSRF y subida/borrado de archivos. Revisión estática del código en la rama `main`.
- **Verificación:** cada hallazgo fue contrastado contra el código fuente real; las severidades reportadas reflejan el veredicto ajustado del verificador (no la etiqueta original del detector).
- **Limitaciones:**
  - La postura efectiva de varios ítems de sesión/cookies/CSRF (B-1, B-2, B-3) depende del **php.ini y del proxy/TLS del despliegue**, no visibles en el repositorio; podrían estar ya endurecidos a nivel de servidor.
  - No se ejecutó la aplicación ni pruebas dinámicas (DAST) ni de penetración; los chequeos de servidor/curl son manuales y fuera de este alcance.
  - No se auditó la cadena de dependencias (composer audit / CVEs) ni la configuración de infraestructura (nginx, firewall, base de datos remota).
  - La validez actual de las credenciales históricas (A-2) no se comprobó por conexión; se asume válida salvo rotación confirmada.
  - La cobertura de RBAC se verificó por conteo de atributos en los controladores principales de flujo; no se enumeró exhaustivamente cada controlador del sistema.
