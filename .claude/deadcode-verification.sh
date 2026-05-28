#!/bin/bash
# Dead Code Verification Script
# Verifica cada hallazgo de dead code encontrado

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}════════════════════════════════════════${NC}"
echo -e "${BLUE}  VERIFICACIÓN DE DEAD CODE${NC}"
echo -e "${BLUE}════════════════════════════════════════${NC}"
echo ""

# 🔴 CRÍTICO: DenialReason::MISSING_FIELDS
echo -e "${RED}🔴 CRÍTICO: DenialReason::MISSING_FIELDS${NC}"
echo "Verificando si MISSING_FIELDS se usa..."
refs=$(grep -r "MISSING_FIELDS" src --include="*.php" | grep -v "case MISSING_FIELDS" | wc -l)
if [ "$refs" -eq 0 ]; then
    echo -e "${GREEN}✓ CONFIRMA: Sin uso en codebase${NC}"
    echo "  Ubicación: src/Constants/Domain/Pipeline/DenialReason.php:17"
    echo "  Acción: SEGURO eliminar"
else
    echo -e "${YELLOW}⚠ PRECAUCIÓN: Se encontraron $refs referencias${NC}"
fi
echo ""

# 🔴 CRÍTICO: CircuitBreaker::isOpen()
echo -e "${RED}🔴 CRÍTICO: CircuitBreaker::isOpen()${NC}"
echo "Verificando si isOpen() se usa..."
refs=$(grep -r "isOpen\(\)" src --include="*.php" | grep -v "public function isOpen" | wc -l)
if [ "$refs" -eq 0 ]; then
    echo -e "${GREEN}✓ CONFIRMA: Sin uso${NC}"
    echo "  Ubicación: src/Service/Resilience/CircuitBreaker.php"
    echo "  Nota: Verificar si isClosed() es el método usado en su lugar"
else
    echo -e "${YELLOW}⚠ PRECAUCIÓN: Se encontraron $refs referencias${NC}"
fi
echo ""

# 🟡 ALTO: DocumentTypePolicy::getDocumentType()
echo -e "${YELLOW}🟡 ALTO: Inconsistencia en DocumentTypePolicy::getDocumentType()${NC}"
echo "Buscando donde se define getDocumentType()..."
files=$(find src -name "*DocumentTypePolicy*.php" -type f)
echo "  Archivos encontrados:"
for file in $files; do
    if grep -q "function getDocumentType" "$file"; then
        echo "    • $file"
        grep -n "function getDocumentType" "$file"
    fi
done
echo "  Investigar: ¿Se usa getType() en su lugar?"
refs=$(grep -r "getType\(\)" src --include="*.php" | wc -l)
echo "  → getType() se usa en $refs lugares"
echo ""

# 🟡 ALTO: Controllers con métodos no asignados en rutas
echo -e "${YELLOW}🟡 ALTO: Métodos sin asignar en rutas${NC}"
echo ""
echo "Verificando: AdvancesController::confirmRefundPayment()"
if grep -q "confirmRefundPayment" config/routes.php 2>/dev/null; then
    echo -e "${GREEN}✓ Encontrada en routes${NC}"
else
    echo -e "${RED}✗ NO encontrada en routes${NC}"
fi

echo ""
echo "Verificando: AdvancesController::linkCandidates()"
if grep -q "linkCandidates" config/routes.php 2>/dev/null; then
    echo -e "${GREEN}✓ Encontrada en routes${NC}"
else
    echo -e "${RED}✗ NO encontrada en routes${NC}"
fi

echo ""
echo "Verificando: InvoicesController::regressStatus()"
if grep -q "regressStatus" config/routes.php 2>/dev/null; then
    echo -e "${GREEN}✓ Encontrada en routes${NC}"
else
    echo -e "${RED}✗ NO encontrada en routes${NC}"
fi
echo ""

# 🟡 ALTO: Services sin callers
echo -e "${YELLOW}🟡 ALTO: Methods en Services sin callers${NC}"
echo ""

echo "Verificando: InvoiceApprovalService::getActiveApprovals()"
refs=$(grep -r "getActiveApprovals" src --include="*.php" | grep -v "function getActiveApprovals" | wc -l)
echo "  → Referencias encontradas: $refs"

echo "Verificando: InvoicePipelineService::getStatusIndex()"
refs=$(grep -r "getStatusIndex" src --include="*.php" | grep -v "function getStatusIndex" | wc -l)
echo "  → Referencias encontradas: $refs"

echo "Verificando: NoveltyService::canAdvanceIndividually()"
refs=$(grep -r "canAdvanceIndividually" src --include="*.php" | grep -v "function canAdvanceIndividually" | wc -l)
echo "  → Referencias encontradas: $refs"

echo ""
echo -e "${BLUE}════════════════════════════════════════${NC}"
echo "Verificación completada."
echo "Revisar DEAD_CODE_REPORT.md para análisis detallado."
echo -e "${BLUE}════════════════════════════════════════${NC}"
