# DEAD CODE FINDINGS - SGI (ACTUALIZADO)

**Fecha de análisis:** 28/05/2026  
**Método:** CodeGraph indexing + grep pattern analysis  
**Archivos analizados:** 702  
**Nodos del grafo:** 5,254  
**Estado:** ✅ CÓDIGO CRÍTICO REMOVIDO

---

## RESUMEN EJECUTIVO

### Antes
- 1 constante sin usar
- 52 métodos públicos sin callers
- ~300-500 líneas de dead code

### Después (ACTUAL)
- ✅ 0 constantes sin usar
- ✅ 2 métodos críticos removidos (7 líneas)
- ~290-490 líneas de dead code potencial (requiere revisión)

---

## 🔴 HALLAZGOS CRÍTICOS (✅ COMPLETADO)

### 1. ✅ DenialReason::MISSING_FIELDS - ELIMINADO

**Ubicación:** `src/Constants/Domain/Pipeline/DenialReason.php:17`

**Código eliminado:**
```php
case MISSING_FIELDS = 'missing_fields';
```

**Análisis:**
- ✓ Nunca retornado desde `denialReasonFor*()` methods
- ✓ Tiene mensaje pero nunca invocado
- ✓ 0 referencias en codebase (VERIFICADO)

**Resultado:** ELIMINADO ✅  
**Commit:** bf5552f

---

### 2. ✅ CircuitBreaker::isOpen() - ELIMINADO

**Ubicación:** `src/Service/CircuitBreaker.php`

**Código eliminado:**
```php
public function isOpen(): bool
{
    return $this->_getState() === self::STATE_OPEN;
}
```

**Análisis:**
- ✓ 0 referencias en codebase (VERIFICADO)
- ✓ Alternativa potencial a `isClosed()` o `getState()`
- ✓ Método simple sin efectos secundarios

**Resultado:** ELIMINADO ✅  
**Commit:** bf5552f

---

## 🟡 HALLAZGOS ALTOS (PENDIENTE - REVISAR)

### 1. DocumentTypePolicy::getDocumentType() - TRIPLE DEFINICIÓN

**Ubicación:**
```
src/Service/Pipeline/Invoice/DocumentTypePolicy.php:19
src/Service/Pipeline/Invoice/Policy/StandardDocumentTypePolicy.php:17
src/Service/Pipeline/Invoice/Policy/AnticipoDocumentTypePolicy.php:25
src/Service/Pipeline/Invoice/Policy/LegalizacionDocumentTypePolicy.php:19
```

**Problema:**
- 4 clases definen `getDocumentType()`
- NINGUNA se invoca desde el código (0 referencias VERIFICADAS)
- Posible artifact de refactoring incompleto

**Recomendación:**
- [ ] Verificar si estas clases se usan en absoluto
- [ ] Si se usan, consolidar a un único método
- [ ] Si no se usan, eliminar las clases redundantes

**Esfuerzo:** 10-30 minutos  
**Riesgo:** MEDIO

---

### 2. Métodos en Services sin callers - REVISAR

**VERIFICADO como REAL:**

| Service | Método | Referencias | Notas |
|---------|--------|------------|-------|
| `InvoiceApprovalService` | `getActiveApprovals()` | 0 | Posible API futura |
| `InvoicePipelineService` | `getStatusIndex()` | 0 | Helper reemplazado |
| `NoveltyService` | `canAdvanceIndividually()` | 0 | Lógica no integrada |

**Hipótesis:**
- Para API pública futura (reportes, estadísticas)
- Métodos helper durante refactoring
- Features deshabilitadas

**Recomendación:**
- [ ] Revisar PRs/branches recientes
- [ ] Buscar en comentarios: `TODO`, `FIXME`
- [ ] Si no hay planes futuros, eliminar

**Esfuerzo:** 5 minutos por método  
**Riesgo:** BAJO (si se confirma no se usa)

---

### 3. Métodos "observation" - REVISAR

Múltiples controllers tienen `addObservation()` sin usar:
- InvoicesController
- EmployeeNoveltiesController
- PettyCashRecordsController
- NoveltyLiquidationDocsController

**Hipótesis:** Feature flag para observaciones deshabilitada

**Esfuerzo:** 15 minutos para investigar

---

## 🟢 HALLAZGOS BAJOS (OPTIMIZACIÓN)

### 1. ExcelWizardTrait - Métodos sin usar
- `exportConfig()`
- `importProcess()`
- `importUpload()`

→ Probablemente reemplazados por flujo N8n

### 2. Imports no utilizados
Requiere análisis con `phpstan` o `psalm`:
```bash
composer require --dev phpstan/phpstan
./vendor/bin/phpstan analyse src --level=5
```

---

## IMPACTO Y RIESGO

### Líneas de Código Removible (después de limpieza)
- **Alto:** 0 (COMPLETADO ✅)
- **Pendiente:** 200-300 líneas
- **Bajo:** 100-200 líneas
- **TOTAL PENDIENTE:** ~300-500 líneas

### Impacto en Performance
✓ **NINGUNO** - Dead code no afecta ejecución

### Riesgo de Eliminación
- **Crítico:** NINGUNO (COMPLETADO ✅)
- **Alto:** BAJO (con revisión manual)
- **Bajo:** BAJO

---

## PLAN DE ACCIÓN ACTUALIZADO

### ✅ Fase 1: Crítico (COMPLETADO)

```bash
✓ Eliminado: DenialReason::MISSING_FIELDS
✓ Eliminado: CircuitBreaker::isOpen()
✓ Commit: bf5552f
✓ Tiempo realizado: <5 minutos
```

### 📋 Fase 2: Alto (PRÓXIMO)

```bash
# Opción A: Revisar DocumentTypePolicy
grep -r "getDocumentType\|StandardDocumentTypePolicy\|AnticipoDocumentTypePolicy" src

# Opción B: Revisar métodos observation
grep -r "addObservation" src/Controller

# Opción C: Revisar métodos en Services
grep -r "getActiveApprovals\|getStatusIndex\|canAdvanceIndividually" src
```

**Esfuerzo estimado:** 30-60 minutos  
**Riesgo:** BAJO

### 🔧 Fase 3: Bajo (OPCIONAL)

```bash
# Limpiar métodos de Traits no usados
# Revisar métodos "observation"
# Ejecutar PHPStan para imports
```

---

## ARCHIVOS GENERADOS

- **`DEAD_CODE_REPORT.md`** - Análisis detallado (este archivo)
- **`DEAD_CODE_FINDINGS.md`** - Conclusiones con plan (aquí)
- **`.claude/deadcode-summary.txt`** - Quick reference
- **`.claude/scripts/dead-code-analyzer.sh`** - Script de búsqueda
- **`.claude/deadcode-verification.sh`** - Verificación automatizada

---

## COMANDOS PARA FASE 2

```bash
# Ver si un método se usa
grep -r "methodName" src --include="*.php" | grep -v "function methodName" | grep -v "public function"

# Ver todos los métodos de una clase
grep -n "public function\|protected function\|private function" src/Service/SomeService.php

# Ejecutar verificación
bash .claude/deadcode-verification.sh

# Buscar patrones
bash .claude/scripts/dead-code-analyzer.sh search methodName
```

---

## NOTAS IMPORTANTES

1. **Métodos en interfaces:** Pueden no tener callers directos pero ser requeridos.

2. **Referencias dinámicas:** PHP permite `$obj->$method()` que puede no ser detectada por grep.

3. **Rutas dinámicas:** `config/routes.php` puede generar rutas dinámicamente.

4. **Tests:** Algunos métodos pueden solo usarse en tests - revisar `tests/` primero.

5. **API Pública:** Algunos métodos pueden ser parte de API pública sin referencias internas.

---

## CONCLUSIÓN

✅ **Fase 1 completada exitosamente**

El proyecto está en buen estado. Los hallazgos restantes requieren revisión manual para confirmar que son realmente dead code.

**Estadísticas:**
- Líneas eliminadas: 7
- Constantes sin usar: 0 (después de limpieza)
- Métodos críticos sin callers: 0 (después de limpieza)
- Pendiente de revisión: ~50 métodos (bajo impacto)

**Recomendación:** Proceder con Fase 2 cuando sea conveniente. No es urgente.

---

*Análisis generado: 2026-05-28 | Actualizado: 2026-05-28 Post-Cleanup | CodeGraph Index: Up to date*
