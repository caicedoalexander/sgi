# REPORTE DE DEAD CODE - SGI

## Resumen
Análisis del proyecto SGI para identificar código no utilizado (métodos, constantes, imports).

**Fecha del análisis:** 28/05/2026
**Índice CodeGraph:** 702 archivos, 5,254 nodos, 8,086 edges
**Estado:** ✅ CÓDIGO CRÍTICO REMOVIDO (Commit: bf5552f)
**Última actualización:** 28/05/2026 - Post-cleanup

---

## HISTORIAL DE CAMBIOS

### ✅ LIMPIEZA COMPLETADA (28/05/2026)

**Commit:** bf5552f  
**Mensaje:** chore: remove critical dead code

1. **REMOVIDO: `DenialReason::MISSING_FIELDS`**
   - Ubicación: src/Constants/Domain/Pipeline/DenialReason.php
   - Líneas eliminadas: 2 (case + mensaje en match)
   - Verificación: 0 referencias en codebase

2. **REMOVIDO: `CircuitBreaker::isOpen()`**
   - Ubicación: src/Service/CircuitBreaker.php
   - Líneas eliminadas: 5 (método completo)
   - Verificación: 0 referencias en codebase

**Impacto:** NINGUNO - Sin cambios en funcionalidad
**Beneficio:** Reducción de código de mantenimiento

---

## 1. ESTADO ACTUAL - CONSTANTES NO UTILIZADAS

### ✅ COMPLETADO
Todas las constantes no utilizadas han sido eliminadas.

---

## 2. MÉTODOS PÚBLICOS SIN REFERENCIAS (Estado Post-Cleanup)

**Hallazgos iniciales:** 52 métodos
**Clasificación actual:**
- ✅ Críticos eliminados: 2
- 🔄 Alto impacto (revisar): ~15-20
- 🟢 Bajo impacto (optimización): ~30-35

### Controllers - Métodos asignados en rutas (confirmado)

Estos métodos existen en `config/routes.php`:
- AdvancesController::confirmRefundPayment()
- AdvancesController::linkCandidates()
- AdvancesController::pendingLegalization()
- AdvancesController::uploadRelationDocument()
- InvoicesController::regressStatus()
- etc.

**Estado:** Falsa alarma del análisis inicial - ESTOS MÉTODOS SÍ SE USAN

### Services - Métodos de API pública sin callers

| Service | Método | Referencias | Estado |
|---------|--------|------------|--------|
| `InvoiceApprovalService` | `getActiveApprovals()` | 0 | Pendiente |
| `InvoiceApprovalService` | `getApprovalSummary()` | 0 | Pendiente |
| `InvoicePipelineService` | `getStatusIndex()` | 0 | Pendiente |
| `NoveltyService` | `canAdvanceIndividually()` | 0 | Pendiente |
| `NoveltyService` | `getVisibleFields()` | 0 | Pendiente |
| `DianCrosscheckService` | `retryFailed()` | 0 | Pendiente |
| `N8nService` | `sendData()` | 0 | Pendiente |
| `NoveltyObservationService` | `getUnreadCount()` | 0 | Pendiente |

**Hipótesis:**
- API pública para futuros reportes/estadísticas
- Métodos helper durante refactoring
- Features deshabilitadas

---

## 3. INCONSISTENCIA DE API - PENDIENTE

### DocumentTypePolicy::getDocumentType() - TRIPLE DEFINICIÓN

**Ubicaciones:**
```
src/Service/Pipeline/Invoice/DocumentTypePolicy.php:19
src/Service/Pipeline/Invoice/Policy/StandardDocumentTypePolicy.php:17
src/Service/Pipeline/Invoice/Policy/AnticipoDocumentTypePolicy.php:25
src/Service/Pipeline/Invoice/Policy/LegalizacionDocumentTypePolicy.php:19
```

**Estado:** 0 referencias - probablemente artifact de refactoring
**Esfuerzo para consolidar:** 10-30 minutos
**Riesgo:** MEDIO

---

## 4. TRAITS CON MÉTODOS SIN USAR - PENDIENTE

### ExcelWizardTrait.php
- `exportConfig()`
- `importProcess()`
- `importUpload()`

**Hipótesis:** Reemplazados por flujo N8n

---

## 5. MÉTODOS "OBSERVATION" - PENDIENTE

Múltiples controllers tienen métodos sin usar:
```
InvoicesController::addObservation()
EmployeeNoveltiesController::addObservation()
PettyCashRecordsController::addObservation()
NoveltyLiquidationDocsController::addObservation()
```

**Hipótesis:** Feature flag para observaciones deshabilitada

---

## ESTADÍSTICAS FINALES

| Métrica | Antes | Después |
|---------|-------|---------|
| Constantes no usadas | 1 | 0 ✅ |
| Métodos críticos sin callers | 2 | 0 ✅ |
| Métodos totales sin callers | 52 | ~50 (revisión manual) |
| Líneas de código muerto | ~300-500 | ~290-490 |
| Líneas eliminadas | 0 | 7 ✅ |

---

## PRÓXIMOS PASOS - FASE 2

### Alto Impacto (Revisar antes de eliminar)

1. **Consolidar `DocumentTypePolicy::getDocumentType()`**
   - Verificar si estas 4 clases se usan en absoluto
   - Si se usan, eliminar métodos duplicados
   - Esfuerzo: 10-30 minutos

2. **Revisar métodos "observation"**
   - Investigar si es feature deshabilitada
   - Buscar feature flags en el código
   - Esfuerzo: 15 minutos

3. **Métodos en Services sin callers**
   - Revisar PRs/branches recientes
   - Buscar comentarios TODO/FIXME
   - Esfuerzo: 5 minutos por método

### Bajo Impacto (Optimización)

1. Métodos de Traits (ExcelWizardTrait)
2. Flujos alternativos no implementados
3. Análisis de imports con PHPStan

---

## COMANDOS ÚTILES

```bash
# Analizar un método específico
bash .claude/scripts/dead-code-analyzer.sh search methodName

# Listar todos los métodos de una clase
bash .claude/scripts/dead-code-analyzer.sh list src/Service/SomeService.php

# Buscar referencias a un método
grep -r "methodName" src --include="*.php" | grep -v "function methodName"

# Ejecutar verificación automática
bash .claude/deadcode-verification.sh
```

---

## CONCLUSIÓN

✅ **Código crítico eliminado exitosamente**

El proyecto está en buen estado. Los hallazgos restantes requieren revisión manual para confirmar que son realmente dead code o API futura.

**Recomendación:** Proceder con Fase 2 cuando sea conveniente. No es urgente.

---

*Análisis generado: 2026-05-28 | CodeGraph Index: Up to date | Limpieza: 2026-05-28*
