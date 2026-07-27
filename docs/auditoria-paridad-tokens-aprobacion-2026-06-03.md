# Auditoría de paridad — Sistemas de aprobación-por-token (ojos frescos sobre código vivo)

> **Fecha:** 2026-06-03 · **Alcance:** análisis, NO implementación (ningún código modificado).
> **Sistemas auditados:** (A) multi-aprobador de facturas (`InvoiceApprovalService` + `invoice_approvals`) y (B) genérico single-entity (`ApprovalTokenService` + `approval_tokens`, novedades + fallback de facturas), más sus consumidores (`ExternalApprovalsController`, `InvoicesController`, `EmployeeNoveltiesController`) y `NotificationService`.
> **Objetivo:** evaluar duplicación real vs diferencia esencial de dominio entre los dos sistemas, y la viabilidad de un **único servicio de aprobaciones+tokens reutilizable**.
>
> **Metodología:** workflow multi-agente (7 agentes: 3 inventarios — genérico / multi-aprobador / consumidores+notificación → 3 ejes transversales — paridad del ciclo de vida / clasificación D-vs-E + seguridad / diseño de unificación → 1 adversarial que refuta el diseño y verifica citas contra el código real). Todo citado con `archivo:línea`.
>
> **Relación con auditorías previas:** sigue el patrón de `docs/auditoria-estructural-fresca-2026-05-29.md` y `docs/auditoria-vista-modulos-flujo-2026-05-30.md` (mapas comparativos + clasificación + roadmap por olas). A diferencia de aquellas (paridad *entre* los 6 módulos de flujo), esta audita la paridad *entre dos subsistemas hermanos* de un eje transversal (aprobación externa por token).

---

## 0. Resumen ejecutivo y veredicto

Existen **dos sistemas de aprobación-por-token paralelos** que comparten algoritmo de token, TTL, ruta pública y contrato de notificación, pero divergen en tabla, cardinalidad y semántica de consumo. El propio código lo reconoce: `ApprovalTokenService` está `@deprecated` "para facturas" (`ApprovalTokenService.php:21-22`) y `InvoiceApprovalService` remite al otro "para aprobaciones single-entity" (`:21-22`).

**Veredicto en tres puntos:**

1. **El núcleo cripto/token está duplicado y es genuinamente unificable (D).** Generación (`bin2hex(random_bytes(32))` → 64 hex), TTL (`APPROVAL_TOKEN_HOURS=48`), validación (`WHERE token = ?`, sin `hash_equals`), almacenamiento en claro, lock pesimista `FOR UPDATE` y construcción de URL son **idénticos** en ambos (`ApprovalTokenService.php:54,55,90-131` vs `InvoiceApprovalService.php:84,81,198-229`). Un motor de tokens común es viable.

2. **La capa de orquestación de dominio es diferencia esencial (🔵) — NO fusionar.** Cardinalidad N:1 multi-aprobador con quórum, cascada de rechazo, supersede/`Reemplazada` y reset (`InvoiceApprovalService.php:318,265-272,443,531`) vs 1:1 single-use con strategy dispatch (`ApprovalTokenService.php:150-153`). Las dos tablas, las dos strategies y la frontera transaccional del consumo se conservan.

3. **El camino de unificación correcto es "núcleo común + dos adaptadores", NO una tabla única ni un hash ingenuo.** La fase adversarial detectó que el diseño inicial de hash-en-reposo (reusar la columna `token` para el digest) **rompe el N:1** porque el multi-aprobador nulifica el token al consumir (`token=null`, `:245`). El núcleo de tokens es unificable hoy; el endurecimiento por hash necesita rediseño previo (ver §5/§6).

> **Hallazgo lateral de seguridad y honestidad documental:** la etiqueta **"SHA256" es falsa en tres sitios** (`ApprovalTokenService.php:13`, `ExternalApprovalsController.php:26,95`) y en `CLAUDE.md`. Ningún sistema hashea: ambos guardan `random_bytes` en claro. La unificación es la oportunidad de hacer la etiqueta cierta o corregirla.

Leyenda de las tablas: ✅ presente/canónico · ⚠️ presente pero divergente/deuda · ❌ ausente · 🔵 diferencia legítima de dominio.

---

## 1. Mapa de componentes

| Componente | Sistema A (multi-aprobador) | Sistema B (genérico) |
|---|---|---|
| Servicio | `InvoiceApprovalService.php` | `ApprovalTokenService.php` (`@deprecated` `:21`) |
| Tabla / Entity | `invoice_approvals` · `InvoiceApproval` | `approval_tokens` · `ApprovalToken` |
| Migración | `20260331000001_CreateInvoiceApprovals.php` | `20260221000008_CreateApprovalTokens.php` (+ `…AddApprovedBy…`) |
| Cardinalidad | **N:1** (una fila por aprobador) | **1:1** polimórfico (`entity_type`/`entity_id`) |
| Efecto de dominio | inline + quórum (`processResponse:211`) | vía Strategy (`InvoiceApprovalStrategy` / `NoveltyApprovalStrategy`) |
| Generadores | `InvoicesController::sendApprovalLinks:747`, `::modifyApprovers:778` | `EmployeeNoveltiesController::add:838`, `::resendApproval:1038` ⚠️ inline en controller |
| Consumidor externo | `ExternalApprovalsController` (review `:27` / process `:96`) — **A-first, B-fallback** (`:59`,`:149`) | idem (fallback) |
| Notificación | `NotificationService::sendApprovalLinkNotification:31` (desde el servicio) | `::sendNoveltyApprovalEmail:93` (desde el controller ⚠️) |
| Ruta compartida | `routes.php:115-128` · `/approve/{token}` + `/{token}/process` · constraint `[a-f0-9]{64}` · middleware `rateLimit` | (la misma) |

---

## 2. Paridad del ciclo de vida del token

| Dimensión | Sistema A — `InvoiceApprovalService` / `invoice_approvals` | Sistema B — `ApprovalTokenService` / `approval_tokens` |
|---|---|---|
| Generación | ✅ `bin2hex(random_bytes(32))` → 64 hex, uno por aprobador en `foreach` (`:83-84`) | ✅ `bin2hex(random_bytes(32))` → 64 hex (`:54`) |
| Aleatoriedad | ✅ CSPRNG 256 bits (`:84`) | ✅ CSPRNG 256 bits (`:54`) — paridad total |
| Almacenamiento | ⚠️ **en claro**; col `token varchar(64)` UNIQUE nullable (mig `:15,:24`) | ⚠️ **en claro**; col `token string(64)` UNIQUE NOT NULL (mig `:11-14`) |
| TTL | ✅ 48h fijo `token_expires_at` (`:81`) | ✅ 48h **configurable** por arg (`:55`), default `APPROVAL_TOKEN_HOURS` (`InvoiceConstants.php:136`) |
| Validación timing-safe | ⚠️ **NO**; `WHERE token = ?` (`:198,:224`), sin `hash_equals` | ⚠️ **NO**; `WHERE token = ?` (`:78`), sin `hash_equals` |
| Chequeo de expiración | ✅ en lectura (`:200`) y bajo lock (`:226`) | ✅ en lectura (`:90`) y bajo lock (`:131`) |
| Single-use / consumo | ✅ `FOR UPDATE` (`:229`) + filtro `status=Pendiente`; marca `status` + **`token=null`** (`:240,:245`) | ✅ `FOR UPDATE` (`:122`) + re-chequeo `used_at`; marca **`used_at=now`** (`:135`) |
| Asociación entidad | 🔵 **N:1** (varias filas/factura, `belongsTo Invoices`/`Users` INNER) | 🔵 **1:1** polimórfico `(entity_type,entity_id)`, 2 dominios (`:35-38`) |
| Acción approve/reject | ✅ inline; approve→quórum→`area_approval` (`:289-296`); reject→cascada (`:265-272`) | ✅ vía Strategy `apply()` (`:150-153`) |
| Motivo de rechazo | ⚠️ asimétrico; aprobador solo da `observations`; motivo formal solo en `modifyApprovers($reason)` embebido en history (`:507`), sin columna | ⚠️ sin motivo estructurado; solo `observations` texto libre (`:137`) |
| Auditoría / historial | ✅ dentro del servicio (`recordFieldChange` `:257,:274`) | 🔵 delegada a la strategy (`InvoiceApprovalStrategy:76`, `NoveltyApprovalStrategy:62`); token solo guarda forense (`ip`,`user_agent`,`used_at`) |
| Notificación | ✅ dentro del servicio de dominio (`:130`) | 🔵 disparada desde el **controller** (`EmployeeNoveltiesController:852,1047`) |
| Atomicidad del efecto | ✅ efecto de dominio **dentro** de la tx de consumo (`:220-312`) | ⚠️ efecto (strategy) corre **fuera** del lock (`:146-153`) → sin rollback si la acción falla |

**Divergencias estructurales:** la cardinalidad (N:1 vs 1:1) y la frontera transaccional del consumo son las únicas diferencias de fondo; todo lo demás es paridad real o deuda compartida (token en claro, sin `hash_equals`, motivo de rechazo no estructurado).

---

## 3. Clasificación: duplicación (D) vs diferencia esencial (E)

**~9 piezas D** (todo el núcleo) · **~7 piezas E** (toda la capa de cardinalidad/dominio). La línea de corte natural: **motor de tokens compartido (D)** + **dos adaptadores de dominio (E)**.

| Pieza | D/E | Justificación + cita |
|---|---|---|
| Generación del token | **D** | Idéntico literal: `ApprovalTokenService.php:54` == `InvoiceApprovalService.php:84` |
| Almacenamiento (col `varchar(64)` UNIQUE, en claro) | **D** | Mismo esquema, mismo modelo de (in)seguridad. Mig `:11-14` vs `:15,:24` |
| TTL / expiración (48h) | **D** | Misma constante `APPROVAL_TOKEN_HOURS`, mismo `new DateTime('+N hours')` |
| Validación (lookup + no-expirado, sin timing-safe) | **D** | `:78-92` vs `:196-204`. `hash_equals` ausente en ambos archivos |
| Mecánica de consumo (lock `FOR UPDATE` + re-chequeo) | **D** | Patrón anti-TOCTOU idéntico: `:120-144` vs `:220-230` |
| Construcción de URL `{base}/approve/{token}` | **D** | **Triplicada**: `InvoiceApprovalService.php:102`, `EmployeeNoveltiesController.php:842,1040` |
| Ruta + constraint `[a-f0-9]{64}` | **D** | Una sola ruta sirve a ambos (`routes.php:115-128`) — ya compartida |
| Identity-check (usuario asignado == actual) | **D** | `ExternalApprovalsController.php:40` (A) y `:77,:84` (B) — mismo predicado, distinto campo |
| `viewVar 'approvalUrl'` del email | **D** | Mismo contrato de plantilla en ambos (`NotificationService.php:65,118`) |
| **Frontera transaccional del consumo** | **E-parcial** | El *lock* es D, pero el *alcance* de la tx difiere (A envuelve el efecto, B no). Es la diferencia que corrige la Ola 4 (ver Anexo COR-1) |
| Dispatch a strategy (B) | **E** | Despacho polimórfico 1:1 para 2 dominios (`:150-153`) |
| Multi-fila por aprobador + `status` | **E** | N filas/factura (`:83-99`); N:1 ≠ 1:1 |
| Quórum "todos aprueban" | **E** | `areAllApproved()` (`:318-333`), sin análogo en B |
| Cascada de rechazo | **E** | `_invalidatePendingTokens()` (`:266,:563`), concepto multi-aprobador |
| Supersede / re-rondas | **E** | `modifyApprovers()`→`Reemplazada` (`:472`), `resetFlow()` borra (`:531`) |
| Efecto de dominio del approve | **E** | A: quórum→`area_approval`; B-invoice: pipeline `saveAndAdvance` como admin; B-novelty: set directo. Tres efectos distintos |

---

## 4. Seguridad

Todos los riesgos verificados leyendo el código real (5 archivos + 2 migraciones) y confirmados en la fase adversarial.

| # | Riesgo | Severidad | Evidencia |
|---|---|---|---|
| **S1** | **Tokens en CLARO** (sin hash en reposo). Un dump de BD expone tokens vivos reusables hasta TTL/consumo. **Ambos** sistemas. | **ALTA** | `ApprovalTokenService.php:54,59`; `InvoiceApprovalService.php:84,89`. Cero `hash()`. Mitigante asimétrico: A nulifica `token` al consumir (`:245`) → ventana menor; **B conserva el token tras consumir** (`used_at`) → ventana mayor |
| **S2** | Comparación **NO timing-safe** (sin `hash_equals`). | **BAJA** | `WHERE token = ?` (`ApprovalTokenService.php:79`; `InvoiceApprovalService.php:198`). Lookup B-tree sobre índice, no byte-a-byte en PHP; token 256-bit + constraint `[a-f0-9]{64}`. **No es justificación de peso para hashear** (el motor real es S1, ver Anexo) |
| **S3** | Efecto de dominio **fuera** de la tx de consumo (B) → si la strategy falla, el token queda quemado sin aplicar la acción. | **MEDIA** | `ApprovalTokenService.php:142` cierra la tx con el `save`; strategy en `:150-154` fuera. A no sufre esto (efecto dentro de `:220-312`) |
| **S4** | Single-use garantizado. | — | A: `token=null`+`status` bajo lock (`:225,:245`); B: `used_at` bajo lock (`:127,:135`) |
| **S5** | TTL aplicado en lectura y bajo lock. | — | A `:200,:226`; B `:90,:131` |
| **S6** | Colisión inter-sistema (misma ruta, dos tablas, sin discriminador). | **BAJA** | Mitigada por orden A-first/B-fallback (`ExternalApprovalsController.php:32→60`, `:109→150`); colisión real 2⁻²⁵⁶. Acoplamiento frágil real |
| **S7** | Scheme divergente: novedad ignora `X-Forwarded-Proto` → el token-secreto puede viajar en URL `http://` tras terminación TLS. | **MEDIA** | `EmployeeNoveltiesController.php:841,1039` (`request->scheme()` directo) vs A `InvoicesController::_getBaseUrl()` (`:473`, respeta forwarded-proto). **Bug real** |
| **S8** | Rate limiting en `/approve`. | — (positivo) | `routes.php:117` |

**Prioridad de remediación de seguridad:** **S7** (bug real, se cierra gratis con un `ApprovalUrlBuilder` único) → **S3** (se cierra moviendo el efecto dentro del lock) → **S1** (hash en reposo, pero requiere rediseño, ver §5/§6). S2/S6 desaparecen como efecto colateral.

---

## 5. Diseño de unificación (corregido por la fase adversarial)

**Premisa:** extraer un núcleo común **sin** colapsar las dos cardinalidades ni fusionar las dos tablas.

### Núcleo propuesto

```
src/Service/Approval/Token/
  ApprovalTokenManager.php        ← núcleo: issue / resolve / consume
  ApprovalTokenStore.php          ← puerto de persistencia (Repository Pattern)
  Store/SingleUseTokenStore.php   ← adapta approval_tokens (used_at)
  Store/MultiApproverTokenStore.php ← adapta invoice_approvals (token nullable por fila)
  TokenRecord.php                 ← DTO inmutable (reemplaza los dos "token record" ad-hoc)
  ApprovalUrlBuilder.php          ← única construcción {base}/approve/{token}, forwarded-proto-aware
```

| Método del manager | Reemplaza |
|---|---|
| `issue(store, entityType, entityId, createdBy, meta, hoursValid)` | `ApprovalTokenService::generateToken:48` + `InvoiceApprovalService` token-gen `:84` |
| `resolve(store, token): ?TokenRecord` | `ApprovalTokenService::validateToken:75` + `InvoiceApprovalService::validateToken:194` |
| `consume(store, token, action, ctx, domainCallback): ServiceResult` | `ApprovalTokenService::consumeToken:107` + mitad de `processResponse:211` |

- **Cardinalidad en el store, no en el núcleo:** `MultiApproverTokenStore::put()` inserta **una fila por aprobador** (`issue()` se llama N veces) → la N:1 se conserva intacta. `SingleUseTokenStore::put()` inserta una fila polimórfica.
- **Efecto de dominio como callback dentro del lock:** `consume()` abre `transactional()`+`FOR UPDATE`, marca consumo vía `store->markConsumed()` (cada store sabe su mecánica: `used_at` vs `token=null`+`status`) y ejecuta el `domainCallback` **dentro** de la misma tx → corrige S3 sin subir dominio al núcleo. Para A el callback recomputa quórum/cascada; para B despacha la strategy.
- **`TokenRecord` común** elimina el `(object)` ad-hoc de `ExternalApprovalsController.php:47-51` y unifica el fallback A→B (vía `CompositeTokenStore` que **debe** garantizar orden multi→single como invariante, no opción — ver Anexo OBJ-5).
- **Novedades gana un `NoveltyApprovalService`** que saca la generación+notificación del controller (`add`/`resend` colapsan a una llamada) → corrige la asimetría de capas y S7.

### Correcciones obligatorias antes de proceder (de la fase adversarial)

1. **OBJ-1 (CRÍTICA) — NO reusar la columna `token` para el digest en multi-aprobador.** El single-use de A depende de escribir `token=null` (`:245`,`:475`,`:575`); MySQL permite múltiples NULL en el índice UNIQUE, que es lo que sostiene la N:1. Meter el digest en esa misma columna choca con la mecánica de nulificación. Si se hashea, A necesita **columna `token_hash` separada** (o el store mantiene el digest efímero, perdiendo el beneficio de S1 en ese lado). La afirmación "sin DDL" solo vale para B.
2. **C-ERR-1 — eliminar el "riesgo e7" del plan: es falso.** `InvoiceApprovalService::validateToken` **sí filtra** por `status=Pendiente` (`:199`), igual que `processResponse` (`:225`). No hay asimetría que "asegurar".
3. **S2 no es justificación del hash.** El motivo real para hashear es S1 (dump de BD); no sobrevender el timing-safe.

---

## 6. Roadmap por olas

> Recomendación adversarial: ejecutar **Olas 1–2 ahora** (drift y bugs reales, riesgo bajo) y **detenerse antes de la Ola 3** (hash) hasta resolver OBJ-1/OBJ-2. La unificación del *núcleo de tokens* es viable; el *almacenamiento hasheado tal como se diseñó inicialmente, no*.

- **Ola 0 — Red de seguridad.** Tests de caracterización end-to-end de ambos flujos: emisión, review+process, single-use, expiración, quórum, cascada de rechazo, fallback A→B. Baseline verde (ref. 642 tests).
- **Ola 1 — Infra sin tocar persistencia ni hashing.** `ApprovalUrlBuilder` (forwarded-proto-aware → cierra **S7**) + `TokenRecord` DTO. Reemplazar las 3 URLs y el record ad-hoc. Tokens en vuelo intactos.
- **Ola 2 — Núcleo `ApprovalTokenManager` + stores, aún en claro.** `issue/resolve/consume` + `SingleUseTokenStore`/`MultiApproverTokenStore`. Reconectar `InvoiceApprovalService` y nuevo `NoveltyApprovalService` (saca novedad del controller). Disolver `ApprovalTokenService` (shim `@deprecated` si quedan callers). Comportamiento idéntico.
- **⛔ Punto de decisión — resolver OBJ-1/OBJ-2 antes de continuar** (diseño de `token_hash` separado para A; confirmar beneficio asimétrico del hash).
- **Ola 3 — Hash + timing-safe**, con ventana dual-read de 48h (= TTL) para tokens en vuelo. Corregir la etiqueta "SHA256" en los 3 sitios. *(Condicionada a OBJ-1.)*
- **Ola 4 — Efecto de dominio dentro del lock** (cierra **S3**). Evaluar el perfil de contención del avance de pipeline de Invoice dentro del `FOR UPDATE` (Anexo OBJ-4 — no es transferencia neutra).
- **Ola 5 — Cierre de ventana + limpieza.** Quitar dual-read, eliminar shim, unificar `validateToken`→`resolve`.

---

## 7. Hallazgos accionables priorizados

| # | Hallazgo | Severidad | Acción |
|---|---|---|---|
| H1 | Etiqueta **"SHA256" falsa** en 3 sitios + `CLAUDE.md` (ningún sistema hashea) | ALTA (honestidad/seguridad) | Corregir doc **o** implementar hash real (Ola 3) — no dejar la mentira |
| H2 | **S7** scheme divergente: token-secreto puede viajar en `http://` | MEDIA | `ApprovalUrlBuilder` forwarded-proto-aware (Ola 1) |
| H3 | URL `{base}/approve/{token}` **triplicada** + record ad-hoc | MEDIA (drift) | `ApprovalUrlBuilder` + `TokenRecord` (Ola 1) |
| H4 | Generación+notificación de novedad **inline en el controller** | MEDIA (capas) | `NoveltyApprovalService` (Ola 2) |
| H5 | **S3** efecto de dominio fuera del lock en B | MEDIA | Mover dentro del lock (Ola 4) |
| H6 | **S1** tokens en claro | ALTA | Hash en reposo (Ola 3, **tras** resolver OBJ-1) |
| H7 | Motivo de rechazo no estructurado (ambos) | BAJA | Columna `rejection_reason` dedicada (oportunista) |

**Lo que NO debe unificarse (🔵 dominio):** las dos tablas (FK `CASCADE` vs `RESTRICT`, `status` vs `used_at`), quórum/cascada/supersede/reset, las dos strategies (pipeline-as-admin vs set-directo), las dos plantillas de email, y las constantes con spelling deliberado (`APPROVER_STATUS_*` vs `APPROVAL_*`, `'Rechazado'` vs `'Rechazada'`).

---

## Anexo — Correcciones de la fase adversarial

- **OBJ-1 (CRÍTICA):** reusar la columna `token` para el digest rompe la mecánica `token=null` del N:1 → requiere `token_hash` separada en A. *(Incorporado en §5.)*
- **OBJ-4 (MEDIA):** mover el avance de pipeline de Invoice (vía `saveAndAdvance` como admin) dentro del `FOR UPDATE` cambia el perfil de contención (lock más largo) — no es transferencia de robustez neutra; evaluar antes de Ola 4.
- **OBJ-5 (BAJA):** el `CompositeTokenStore` del fallback **debe** garantizar orden multi→single como invariante (no opción), para preservar la mitigación de S6.
- **COR-1:** la frontera transaccional del consumo es **E-parcial**, no D (el lock es D; el alcance de la tx es E). *(Reflejado en §3.)*
- **C-ERR-1:** **FALSO** que `InvoiceApprovalService::validateToken` no filtre por `status` — sí filtra (`:199`). El "riesgo e7" del diseño inicial es inexistente. *(Eliminado del plan.)*
- **C-ERR-3:** la etiqueta "SHA256" aparece en **3** sitios (no 1): `ApprovalTokenService.php:13` + `ExternalApprovalsController.php:26,95`. *(Reflejado en H1.)*
- **Severidad S2:** confirmada **BAJA**; no usar como justificación del hash (el motor es S1).

---

*Auditoría generada por workflow multi-agente (7 agentes, fase adversarial incluida). Análisis puro — ningún código modificado.*
