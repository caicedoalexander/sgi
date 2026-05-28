#!/bin/bash
# Dead Code Analyzer for SGI Project
# Busca código no utilizado en PHP y scripts

set -e

echo "════════════════════════════════════════"
echo "  Dead Code Analyzer - SGI"
echo "════════════════════════════════════════"
echo ""

# Función para buscar un método en todo el proyecto
search_method() {
    local method=$1
    echo "🔍 Buscando referencias a: $method"
    echo "────────────────────────────────────"

    # En código
    grep -r "\->$method\|::$method\|'$method'\|\"$method\"" src --include="*.php" 2>/dev/null || echo "  ✗ Sin referencias en src"

    # En templates
    grep -r "\->$method\|::$method" templates --include="*.php" 2>/dev/null || echo "  ✗ Sin referencias en templates"

    # En routes
    grep -r "$method" config/routes.php 2>/dev/null || echo "  ✗ Sin referencias en routes"

    # En JavaScript
    grep -r "$method" webroot/js --include="*.js" 2>/dev/null || echo "  ✗ Sin referencias en JS"

    echo ""
}

# Función para listar métodos de una clase
list_methods() {
    local file=$1
    echo "📋 Métodos públicos en: $file"
    echo "────────────────────────────────────"
    grep -n "public function" "$file" | grep -v "initialize\|beforeFilter\|afterFilter\|__" || echo "  (sin métodos públicos)"
    echo ""
}

# Función para buscar constantes sin usar
find_unused_constants() {
    echo "🔎 Buscando constantes sin usar..."
    echo "────────────────────────────────────"

    for file in src/Constants/Domain/**/*.php; do
        [ -f "$file" ] && {
            constants=$(grep -oP "case\s+\K[A-Z_]+" "$file" 2>/dev/null | sort -u)
            for const in $constants; do
                refs=$(grep -r "\b$const\b" src --include="*.php" 2>/dev/null | grep -v "^$file:" | wc -l)
                if [ "$refs" -eq 0 ]; then
                    echo "  ❌ $(basename $file): $const"
                fi
            done
        }
    done
    echo ""
}

# Menú principal
if [ $# -eq 0 ]; then
    echo "Uso: $0 [comando] [argumento]"
    echo ""
    echo "Comandos:"
    echo "  search <method>        Buscar referencias a un método"
    echo "  list <archivo.php>     Listar métodos públicos de un archivo"
    echo "  constants              Buscar constantes sin usar"
    echo "  help                   Mostrar esta ayuda"
    echo ""
    echo "Ejemplo:"
    echo "  $0 search confirmPayment"
    echo "  $0 list src/Service/InvoiceService.php"
    echo ""
    exit 0
fi

case "$1" in
    search)
        [ -z "$2" ] && echo "❌ Especifica un método" && exit 1
        search_method "$2"
        ;;
    list)
        [ -z "$2" ] && echo "❌ Especifica un archivo" && exit 1
        [ -f "$2" ] || echo "❌ Archivo no encontrado: $2" && exit 1
        list_methods "$2"
        ;;
    constants)
        find_unused_constants
        ;;
    *)
        echo "❌ Comando desconocido: $1"
        exit 1
        ;;
esac
