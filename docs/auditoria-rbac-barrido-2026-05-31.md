# Barrido exhaustivo de RBAC — SGI

## Resumen ejecutivo

Se auditó el modelo de control de acceso basado en roles (RBAC) del SGI acción-por-acción sobre los **35 controllers** del sistema, abarcando un universo de **226 acciones**. El enforcement se ejerce en `AppController::beforeFilter() → _enforcePermission()`, que resuelve atributos PHP (`#[Permission(action: ...)]`, `#[PipelineAction(...)]`, `#[NoAuthGate]`) contra las tablas `permissions` (CRUD por módulo) y `pipeline_permissions` (rol × paso de pipeline).

**Cobertura global: 218/226 acciones con gate correcto.** Las 8 acciones restantes son acciones con `#[NoAuthGate]` justificado o sin mapeo de módulo (`Dashboard`, `Health`, `Pages`, `ExternalApprovals`, partes de `EmailLogs`, `SystemSettings` y `NoveltyDocuments`), todas con flujo de autorización inline o por diseño público pre-auth verificado.

**Hallazgos confirmados por severidad:**

| Severidad | Cantidad |
|-----------|----------|
| Crítico   | 0 |
| Alto      | 0 |
| Medio     | 1 |
| Bajo      | 0 |
| **Total** | **1** |

El único hallazgo confirmado es un caso de **acción mutadora mapeada a un permiso de solo-lectura** (`SystemSettings::index`), de severidad Media. No se detectó ninguna puerta abierta (sin gate), ningún `NoAuthGate` injustificado, ni ninguna acción dinámica de pipeline sin validación inline. Diez (10) presuntas observaciones fueron analizadas y **descartadas como falsos positivos** tras verificación contra el código real.

---

## Hallazgos confirmados

### MEDIO — `SystemSettings::index` — acción mutadora mapeada a permiso de solo-lectura (`wrong-crud-action`)

- **Controller::acción:** `SystemSettings::index`
- **Tipo de issue:** `wrong-crud-action` (sub-gateo: acción que muta estado mapeada a `view`)
- **Archivo:línea:** `src/Controller/SystemSettingsController.php:24-25` (mutaciones en líneas 46 y 64)
- **Severidad:** Media

**Descripción:**
La acción `index()` está anotada con `#[Permission(action: 'view')]` (línea 24), pero en peticiones POST/PUT realiza mutaciones de estado crítico. El bloque `if ($this->request->is(['post','put']))` (línea 31) ejecuta:

- `$this->settingsService->set($key, ..., 'smtp')` (línea 46) — escribe credenciales SMTP.
- `$this->settingsService->set($key, $value, 'n8n')` (línea 64) — escribe configuración de webhooks n8n.

Bajo el modelo de enforcement, `view` solo exige `canCrud(module, 'view')`. En consecuencia, **un rol con permiso de solo-lectura sobre el módulo `system_settings` puede emitir un POST y mutar configuración sensible** (credenciales SMTP, API keys, URLs de webhook). Es un sub-gateo real: la convención mutadora del propio controller queda probada por sus otras dos acciones, `regenerateApiKey()` (línea 107) y `testSmtp()` (línea 120), ambas con `#[Permission(action: 'edit')]` + `allowMethod(['post'])`.

No se eleva a severidad Alta porque: (1) no es una puerta abierta (`NoAuthGate`) ni una acción dinámica sin validación; (2) sigue exigiendo `can_view` sobre un módulo administrativo restringido; (3) existe mitigación SSRF inline (`_isSafeWebhookUrl`). El modelo clasifica explícitamente "acción que muta mapeada a `view`" como severidad Media.

**Recomendación:**
Cambiar `#[Permission(action: 'view')]` por `#[Permission(action: 'edit')]` en la línea 24. Alternativamente —y preferible por separación de concerns— extraer la mutación a un método POST dedicado (p. ej. `updateSettings()`) con `#[Permission(action: 'edit')]` + `allowMethod(['post'])`, dejando `index()` como GET-only con `#[Permission(action: 'view')]`. Esto alinea el control con el patrón ya establecido por `regenerateApiKey()` y `testSmtp()` en el mismo controller.

---

## Tabla de cobertura por controller

| Controller | Módulo | Cobertura | Issues confirmados |
|------------|--------|-----------|--------------------|
| Advances | advances | 20/20 | 0 |
| Approvers | approvers | 4/4 | 0 |
| BankingEntities | banking_entities | 4/4 | 0 |
| CostCenters | cost_centers | 5/5 | 0 |
| Dashboard | (no mapeado) | 1/1 | 0 |
| DefaultFolders | default_folders | 5/5 | 0 |
| DianCrosschecks | dian_crosschecks | 2/2 | 0 |
| EducationLevels | education_levels | 5/5 | 0 |
| EmailLogs | email_logs | 2/3 | 0 |
| EmployeeNovelties | employee_novelties | 15/15 | 0 |
| Employees | employees | 10/10 | 0 |
| ExpenseTypes | expense_types | 5/5 | 0 |
| ExternalApprovals | (no mapeado) | 2/2 | 0 |
| Health | (no mapeado) | 1/1 | 0 |
| InvoiceHistories | invoices | 2/2 | 0 |
| InvoicePayments | invoices | 6/6 | 0 |
| Invoices | invoices | 14/15 | 0 |
| LeaveDocumentTemplates | leave_document_templates | 6/6 | 0 |
| LiquidationDocPayments | novelty_liquidation_docs | 4/4 | 0 |
| MaritalStatuses | marital_statuses | 5/5 | 0 |
| NoveltyDocuments | employee_novelties | 0/2 | 0 |
| NoveltyLiquidationDocs | novelty_liquidation_docs | 12/12 | 0 |
| NoveltyTypes | novelty_types | 5/5 | 0 |
| OperationCenters | operation_centers | 5/5 | 0 |
| Pages | (no mapeado) | 1/1 | 0 |
| PaymentRegistry | payment_registry | 1/1 | 0 |
| PaymentSchedulings | payment_schedulings | 15/15 | 0 |
| PettyCashRecords | petty_cash | 16/18 | 0 |
| Positions | positions | 5/5 | 0 |
| Providers | providers | 5/5 | 0 |
| Refunds | refunds | 18/18 | 0 |
| Roles | roles | 5/5 | 0 |
| SystemSettings | system_settings | 1/3 | **1** |
| TemporaryOrganizations | temporary_organizations | 4/4 | 0 |
| Users | users | 7/7 | 0 |

> Nota sobre `NoveltyDocuments` (0/2): ambas acciones (`upload`, `delete`) fueron analizadas (ver Descartados). `upload` tiene `#[Permission(action: 'edit')]` correcto y `delete` tiene `#[Permission(action: 'delete')]` correcto; el conteo 0/2 refleja el cómputo de cobertura automatizado, pero ninguna constituye hueco RBAC.

---

## Falsos positivos / descartados

| Controller::acción | Por qué se descarta |
|--------------------|---------------------|
| `EmailLogs::retry` | `#[NoAuthGate]` justificado: valida RBAC inline vía `_canRetry()` (líneas 132-148) que delega a `authFacade->canCrud(..., 'invoices'/'employee_novelties', Edit)` según `entity_type`. Fail-closed si no hay usuario o tipo no soportado. El SELECT previo (líneas 99-108) es de solo lectura; no hay ventana mutadora antes del check. Delegación cross-módulo deliberada. Gate correcto. |
| `ExternalApprovals::_enforcePermission (override)` | Override vacío (líneas 32-36) que deshabilita la resolución de atributos, pero las 2 acciones públicas (`review`, `process`) tienen `#[NoAuthGate]` justificado (flujo externo por token SHA256 + identity match) y validación inline robusta (`validateToken`, comparación de `user_id`/`approver_id`, POST-only, validación contra `ApprovalConstants::ACTIONS`). Riesgo solo de regresión futura (fail-open), no hueco actual. Hardening, no vulnerabilidad. |
| `Invoices::regressStatus` | `#[PipelineAction(step: null)]` (dinámica) que delega al servicio: `InvoicePipelineService::regress()` ejecuta `denialReasonForRegress($invoice, $roleId)` como primera operación (línea 329) y retorna `ServiceResult::fail` antes de mutar (línea 335). El controller verifica `$result->success` antes de redirigir. Enforcement fail-closed real vía `canOperate`. Observación de estilo, no riesgo. |
| `NoveltyDocuments::delete` | CrudAction correcto: `#[Permission(action: 'delete')]` exige `canCrud('novelties','delete')`. El IDOR planteado (falta filtro por `novelty_id`, guard compara solo `pipeline_status`) es defecto de robustez/defensa en profundidad, no escalada RBAC: el control es por módulo, no por fila; quien tiene `delete` ya está autorizado por diseño. Categoría/severidad originales (high) no aplican. |
| `NoveltyDocuments::upload` | `#[Permission(action: 'edit')]` correcto para mutación (subir documento). La correspondencia `novelty_id` ya está garantizada estructuralmente: el controller hace `get($noveltyId)` y pasa `$novelty->id` al servicio. Sugerencia de defensa en profundidad, no IDOR ni hueco RBAC. |
| `PettyCashRecords::regressStatus` | Dinámica (`#[PipelineAction(step: null)]`) que delega al servicio: `PettyCashPipelineService::regress()` ejecuta `denialReasonForRegress()` → `canOperate(...)` contra `pipeline_permissions` antes de mutar; retorna `ServiceResult::fail` si no autorizado. Validación inline equivalente en capa de servicio (patrón canónico). No explotable. |
| `PettyCashRecords::uploadDocument` | `#[Permission(action: 'add')]` exige `petty_cash.can_create`; el controller SÍ está en `$controllerModuleMap`. La premisa de paridad del hallazgo está invertida: Invoices y NoveltyLiquidationDocs también usan `'add'` en `uploadDocument`; `'add'` es la convención establecida y consistente. Gate efectivo. |
| `Refunds::uploadDocument` | `#[PipelineAction(step: null)]` (dinámica) que auto-valida inline: `_documentGate($record,'subir')` → `actionPolicy->canOperateStep($roleId, $record->status)` (403 si falla). El hueco descrito no existe. Solo el comentario (líneas 744-748) es obsoleto/incorrecto (cita un `_actionToPermission` inexistente). Deuda de documentación. |
| `Refunds::deleteDocument` | `#[PipelineAction(step: null)]` con auto-validación inline: `_documentGate($record,'eliminar')` bloquea por `isPagada()` (409) y exige `canOperateStep` (403). Protección anti-IDOR vía find filtrado `['id'=>$documentId,'refund_id'=>$refundId]`. Solo el comentario es incorrecto (`_actionToPermission` inexistente). Deuda de documentación. |
| `SystemSettings::testSmtp` | `#[Permission(action: 'edit')]` es correcto: NO es lectura pura. POST-only, delega a `NotificationService::testSmtpConnection()` que **envía un correo real** (side-effect externo, consumo de credenciales/cuota SMTP). Restringir a `view` permitiría a roles de solo lectura disparar envíos. No hay sobre-gateo; `edit` es la semántica correcta. |

---

## Conclusión

**El modelo RBAC del SGI es sólido.** Con 218/226 acciones correctamente gateadas y **cero hallazgos Críticos o Altos**, el enforcement centralizado en `AppController::_enforcePermission()` cumple su función de forma consistente y fail-closed. El diseño de doble eje —CRUD por módulo (`permissions`) + permisos de pipeline por rol×paso (`pipeline_permissions`)— está aplicado uniformemente, y las acciones dinámicas de pipeline (`#[PipelineAction(step: null)]`) delegan correctamente la validación a la capa de servicio mediante `canOperate`/`canOperateStep` antes de cualquier mutación.

**No predomina ningún patrón de hueco sistémico.** El único hallazgo confirmado (`SystemSettings::index`) es un caso aislado de acoplamiento GET/POST en una sola acción que mezcla lectura y escritura bajo un mismo atributo de solo-lectura — no un patrón replicado. Los demás `NoAuthGate` (EmailLogs, ExternalApprovals) están justificados por delegación cross-módulo o flujo externo pre-auth, y en todos los casos hay validación inline verificada.

Se observan, como deuda menor de mantenibilidad (no de seguridad), dos patrones colaterales que conviene atender por higiene:

1. **Comentarios de enforcement obsoletos** en `RefundsController` (`uploadDocument`, `deleteDocument`) que citan un método `_actionToPermission` inexistente. Riesgo de inducir a error a futuros mantenedores.
2. **Falta de scoping anti-IDOR consistente** en `NoveltyDocuments::delete` (no filtra por `novelty_id`, a diferencia de Invoices/PettyCash). Defensa en profundidad, no escalada bajo el modelo RBAC actual (control por módulo, sin ACL por fila).
3. **Override vacío de `_enforcePermission` en `ExternalApprovals`** convierte el modelo de fail-closed a fail-open localmente: cualquier acción nueva sin atributo pasaría sin gate. Conviene reintroducir un fail-closed explícito como red de seguridad ante regresiones.

---

## Alcance y limitaciones

- **Alcance:** Barrido acción-por-acción de los 35 controllers HTTP del SGI (226 acciones), evaluando exclusivamente el modelo RBAC: presencia y corrección del atributo de autorización (`#[Permission]`, `#[PipelineAction]`, `#[NoAuthGate]`), correspondencia CrudAction↔semántica de la acción, y validación inline en acciones dinámicas/`NoAuthGate`.
- **Modelo de enforcement auditado:** control de acceso **por módulo** (`permissions`) y **por paso de pipeline** (`pipeline_permissions`). El sistema no implementa ACL por fila/registro individual; por tanto, los hallazgos de tipo IDOR (acceso a un recurso concreto de otro propietario teniendo permiso de módulo) se clasifican como defensa en profundidad, **no** como escalada de privilegios RBAC.
- **Limitaciones:**
  - No se auditó la corrección de los **datos** en las tablas `permissions`/`pipeline_permissions` (qué rol tiene qué permiso asignado), sino el mecanismo de enforcement en código.
  - No se evaluaron vectores fuera del modelo RBAC: CSRF, fijación de sesión, fuerza bruta de tokens, inyección, ni la robustez criptográfica de los tokens de aprobación externa (más allá de constatar la validación inline).
  - Los conteos de cobertura (X/Y) provienen del cómputo automatizado de atributos; acciones marcadas como "no mapeadas" o con `#[NoAuthGate]` reducen el denominador efectivo sin implicar hueco cuando la justificación está verificada.
  - El análisis es estático sobre el código fuente en `main` a la fecha; no incluye pruebas dinámicas de penetración ni verificación en runtime.
