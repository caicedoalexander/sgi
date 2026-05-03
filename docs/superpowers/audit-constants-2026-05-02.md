# Auditoría de constantes huérfanas — 2026-05-02

Constantes en `src/Constants/` sin referencias fuera de su propio archivo en `src/`, `templates/`, `config/` y `bin/`. Migraciones legacy ejecutadas no cuentan como uso vivo (commit `pipeline-permissions`).

## Tier 1 — Borrar (alias backward-compat o explícitamente @deprecated)

| Archivo | Constante | Valor | Motivo |
|---------|-----------|-------|--------|
| `PettyCashConstants` | `REGRESS_ROLE_BY_STATUS` | `array` | `@deprecated` — migrado a `pipeline_permissions` |
| `RefundConstants` | `REGRESS_ROLE_BY_STATUS` | `array` | `@deprecated` — migrado a `pipeline_permissions` |
| `NoveltyConstants` | `STATUS_FIRMAS_APROBACION` | `= self::STATUS_REVISION_FIRMAS` | Alias backward-compat por renombre |
| `NoveltyConstants` | `STATUS_PENDING` | `= self::STATUS_REGISTRO` | Alias backward-compat |
| `NoveltyConstants` | `STATUS_APPROVED` | `= self::STATUS_PAGADA` | Alias backward-compat |
| `NoveltyConstants` | `STATUS_REJECTED` | `= self::STATUS_RECHAZADA` | Alias backward-compat |
| `NoveltyConstants` | `STATUSES` | `= self::ALL_STATUSES` | Alias backward-compat |

## Tier 2 — Probables (zero callers externos, no aparecen en arrays internos)

| Archivo | Constante | Valor | Comentario |
|---------|-----------|-------|------------|
| `InvoiceConstants` | `APPROVER_STATUSES` | array | Definida pero sin uso (existe `APPROVER_STATUSES_ACTIVE` que es la usada) |
| `InvoiceConstants` | `PAYMENT_RECORD_STATUSES` | array | Definida pero sin uso |
| `InvoiceConstants` | `DIAN_PENDING` | `'Pendiente'` | Constante individual (existe `DIAN_STATUSES` que la incluye) |
| `NoveltyConstants` | `DOC_TYPE_SUPPORT` | `'support'` | Constante suelta sin uso ni array contenedor |
| `PipelineStepConstants` | `PIPELINES` | array | Lista declarativa sin caller |

## Tier 3 — Riesgo de borrado (zero callers externos PERO referenciadas internamente en arrays exportados)

> Borrar estas obliga a inlinear strings en los arrays públicos del mismo archivo, lo que rompe la abstracción "valor con nombre". **Recomendación: NO borrar.**

| Archivo | Constante | Referenciada en | Recomendación |
|---------|-----------|-----------------|---------------|
| `AdvanceConstants` | `MODULE` | string `'advances'` se usa en controllers | Conservar (anchor del slug) |
| `ContractTypeConstants` | `FIJO`, `INDEFINIDO` | `self::ALL`, `self::LABELS` | Conservar |
| `InvoiceConstants` | `HOLDER_TYPE_PROVIDER`, `HOLDER_TYPE_EMPLOYEE`, `HOLDER_TYPE_MANUAL` | `self::HOLDER_TYPES` | Conservar |
| `NoveltyConstants` | `STATUS_REGISTRO` | `self::ALL_STATUSES`, `self::STATUS_LABELS`, `self::STATUS_ICONS` | Conservar |
| `NoveltyConstants` | `PERIOD_SEGUNDA_QUINCENA`, `PERIOD_CIERRE_NOMINA` | `self::PERIODS`, `self::PERIOD_LABELS` | Conservar |
| `NoveltyConstants` | `PAYMENT_PENDIENTE`, `PAYMENT_NA` | `self::PAYMENT_STATUSES`, `self::PAYMENT_LABELS` | Conservar |
| `NoveltyConstants` | `APPROVAL_PENDING` | Valor `'Pendiente'` posiblemente hardcodeado en templates | Conservar (riesgo) |
| `PettyCashConstants` | `CODE_PREFIX` | `'CM'` posiblemente usado en seq generation | Conservar (riesgo) |
| `ProviderConstants` | `DOCUMENT_TYPE_NIT`, `DOCUMENT_TYPE_CC`, `DOCUMENT_TYPE_OTHER` | `self::DOCUMENT_TYPES` | Conservar |
| `RefundConstants` | `OBSERVATION_TYPE_GENERAL` | `self::OBSERVATION_TYPES` | Conservar |

## Acción solicitada al usuario

1. Confirmar borrado de **Tier 1 completo** (acción default). Si quieres conservar alguna, indícalo.
2. Confirmar borrado de **Tier 2** ítem por ítem (riesgo bajo pero requiere veto explícito).
3. **Tier 3** se conserva por default. Solo se borra si pides explícitamente alguna.
