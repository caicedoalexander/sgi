# Permisos del pipeline de Legalizaciones

**Fecha:** 2026-05-06
**Estado:** Diseño aprobado — pendiente de implementación
**Alcance:** Agregar el pipeline `legalizations` a la matriz de permisos rol × paso configurable desde `/roles/edit/{id}`.

---

## Contexto

El sistema ya tiene una infraestructura de permisos por pipeline (`PipelineAuthorizationService` + tabla `pipeline_permissions`) que soporta cinco pipelines: `invoices`, `novelties`, `payment_schedulings`, `refunds`, `petty_cash`. Cada uno declara sus pasos en `PipelineStepConstants::STEPS_BY_PIPELINE` y la UI de `Roles/edit` los renderiza automáticamente como una matriz de checkboxes (rol × paso).

**El flujo de Legalización de Anticipos no participa de esta matriz.** Vive en `AdvancesController` con autorización rol×estado hardcodeada en `AdvanceLegalizationActionPolicy` (Contabilidad/Admin para validación/revisión/exacto/faltante/sobrante; Tesorería/Admin para confirmShortage/registerRefund). Esto significa:

- Un administrador no puede ajustar quién opera cada paso del flujo desde la UI.
- La sección "Legalizaciones" no aparece en `/roles/edit/{id}` junto a los otros pipelines.

## Objetivo

Que `legalizations` sea un pipeline más en la matriz: declarado en `PipelineStepConstants`, con sus pasos visibles en la UI de roles, y con `AdvanceLegalizationActionPolicy` consultando `pipeline_permissions` en lugar de hardcodear roles.

## Alcance del nuevo pipeline

**Constante:** `PIPELINE_LEGALIZATIONS = 'legalizations'`

**Etiqueta:** `Legalizaciones`

**Pasos configurables (5):**

| Constante | Slug | Etiqueta |
|---|---|---|
| `AdvanceConstants::STATUS_VALIDACION` | `validacion` | Validación |
| `AdvanceConstants::STATUS_REVISION_FIRMAS` | `revision_firmas` | Revisión y Firmas |
| `AdvanceConstants::STATUS_CONTABILIDAD` | `contabilidad` | Contabilidad |
| `AdvanceConstants::STATUS_TESORERIA` | `tesoreria` | Tesorería |
| `AdvanceConstants::STATUS_AUTORIZACION_PAGO` | `autorizacion_pago` | Autorización de pago |

**Excluido:** `STATUS_LEGALIZADA` (estado terminal sin acciones operables — mismo criterio que `pagada` en facturas).

## Cambios

### 1. `src/Constants/PipelineStepConstants.php`

- Agregar la constante `PIPELINE_LEGALIZATIONS`.
- Agregar la entrada en `PIPELINE_LABELS`.
- Agregar los 5 pasos en `STEPS_BY_PIPELINE` referenciando `AdvanceConstants`.
- Agregar las 5 etiquetas en `STEP_LABELS`.

### 2. `src/Service/Pipeline/Policy/AdvanceLegalizationActionPolicy.php`

- Inyectar `PipelineAuthorizationService` por constructor.
- Eliminar los helpers `_isAccountingOrAdmin()` y `_isTreasuryOrAdmin()`.
- Reemplazarlos por un único helper:

```php
private function _canOperate(int $roleId, string $roleName, string $step): bool
{
    return $this->pipelineAuth->canOperate(
        $roleId,
        $roleName,
        PipelineStepConstants::PIPELINE_LEGALIZATIONS,
        $step,
    );
}
```

- Cambiar la firma de los 11 métodos `canXxx()` para recibir `int $roleId` además de `string $roleName`. La dimensión "estado" sigue delegada a `$leg->canXxx()` (auditoría MA-010 — sin cambio).

Los 11 métodos: `canLinkInvoices`, `canUnlinkInvoice`, `canUploadRelationDocument`, `canMoveToRevision`, `canMarkSigned`, `canReturnToValidacion`, `canMarkExact`, `canRegisterShortage`, `canRegisterSurplus`, `canConfirmShortage`, `canRegisterRefund`.

### 3. `src/Controller/AdvancesController.php`

- En cada llamada a `$this->actionPolicy->canXxx($leg, $roleName)` agregar `$this->_getCurrentUser()->id` como argumento → `$this->actionPolicy->canXxx($leg, (int)$this->_getCurrentUser()->id, $roleName)`.
- Aproximadamente 13 sitios (uno por endpoint del flujo de legalización).

## Bypass de Administrador

**No hay bypass automático.** `PipelineAuthorizationService::canOperate()` no contempla bypass para Admin — coherente con el patrón establecido por los otros pipelines. Un administrador debe tener marcadas explícitamente sus casillas en la UI de roles para operar el flujo.

## Sin cambios

- `templates/Roles/edit.php` — itera `STEPS_BY_PIPELINE`, recoge la nueva sección automáticamente.
- `RolesController` — ya guarda `pipeline_permissions` desde el POST con la estructura `data['pipeline_permissions'][pipeline][step]`.
- Tabla `pipeline_permissions` — ya existe, soporta cualquier slug de pipeline declarado.
- Tabla `permissions` (CRUD por módulo) — sin cambios; `advances.view`/`advances.edit` siguen controlando el acceso de entrada.

## Sin seed inicial

No se ejecuta migración para sembrar permisos. Tras el merge, **nadie** opera el flujo de legalización hasta que un administrador entre a `/roles/edit/{id}` y marque manualmente las casillas para cada rol. Decisión consciente del operador del proyecto.

**Configuración recomendada (manual, post-merge):**

| Rol | Validación | Revisión y Firmas | Contabilidad | Tesorería | Aut. pago |
|---|:-:|:-:|:-:|:-:|:-:|
| Administrador | ✓ | ✓ | ✓ | ✓ | ✓ |
| Contabilidad | ✓ | ✓ | ✓ |  |  |
| Tesorería |  |  |  | ✓ |  |

## Plan de implementación

1. **Declarar el pipeline** en `PipelineStepConstants.php`.
   *Verificación:* `/roles/edit/{cualquiera}` muestra la sección "Legalizaciones" con 5 checkboxes, todos desmarcados.

2. **Refactorizar `AdvanceLegalizationActionPolicy`** (constructor + 11 métodos + helper).
   *Verificación:* `composer cs-check` pasa; `php bin/cake server` arranca sin errores de DI.

3. **Ajustar las ~13 llamadas en `AdvancesController`** para pasar `$roleId`.
   *Verificación:* como administrador con la matriz vacía, entrar a `/advances/legalization/{id}` carga la vista pero todas las acciones devuelven el flash "No tienes permiso para esta acción en el estado actual" (comportamiento esperado por la ausencia de bypass).

4. **Configurar roles desde `/roles/edit/{id}`** según la tabla recomendada.

## Validación manual end-to-end

Tras el paso 4:

- **Administrador** (5 casillas marcadas): opera todo el flujo (linkInvoices, markSigned, markExact, registerShortage, registerSurplus, confirmShortage, registerRefund).
- **Contabilidad** (`validacion`, `revision_firmas`, `contabilidad` marcados): puede vincular/desvincular facturas, subir relación, marcar firmado, devolver a validación, declarar exacto/faltante/sobrante. **No puede** `confirmShortage` ni `registerRefund` (estado `tesoreria` desmarcado).
- **Tesorería** (`tesoreria` marcado): puede `confirmShortage` y `registerRefund`. **No puede** vincular facturas ni declarar casos (estados `validacion`/`revision_firmas` desmarcados).
- **Rol sin casillas marcadas**: puede entrar a `/advances/legalization/{id}` (controlado por `advances.view`, sin cambios) pero ninguna acción del flujo funciona.

## Tradeoffs aceptados

- **Sin bypass para Admin** → coherente con los otros pipelines, pero exige configurar manualmente al admin tras el deploy. A cambio, el comportamiento es uniforme y predecible.
- **Sin seed automático** → requiere paso manual post-deploy para que el flujo opere. A cambio, no hay datos cableados en migraciones que diverjan de lo que el operador realmente quiere.
- **Firmas del policy cambian** → 11 métodos pasan de 2 a 3 argumentos. Cambio mecánico contenido a un controlador.
