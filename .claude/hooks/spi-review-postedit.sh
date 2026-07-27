#!/usr/bin/env bash
# PostToolUse (Write|Edit): recuerda el revisor de spec/plan; marca el toque de implementación.
# No bloqueante: siempre exit 0.

INPUT=$(cat)
FILE=$(printf '%s' "$INPUT" | jq -r '.tool_input.file_path // empty' 2>/dev/null)
SESSION=$(printf '%s' "$INPUT" | jq -r '.session_id // "nosession"' 2>/dev/null)

# Normalizar separadores de Windows (\ -> /)
FILE_NORM=${FILE//\\//}

emit_context() {
  jq -n --arg ctx "$1" \
    '{hookSpecificOutput: {hookEventName: "PostToolUse", additionalContext: $ctx}}'
}

case "$FILE_NORM" in
  */docs/superpowers/specs/*-design.md)
    emit_context "Recordatorio SPI: editaste un SPEC de diseño. Antes de pasar al plan, lanzá el subagente 'spi-design-reviewer' sobre este archivo (revisa estructura, convenciones CakePHP/SPI y acoplamiento). Es read-only; ofrecéselo al usuario si corresponde."
    ;;
  */docs/superpowers/plans/*.md)
    emit_context "Recordatorio SPI: editaste un PLAN. Antes de implementar, lanzá el subagente 'spi-plan-reviewer' (revisa fidelidad spec↔plan, orden de build y convenciones). Read-only."
    ;;
  */src/*|*/templates/*|*/config/Migrations/*)
    printf '%s\n' "$FILE_NORM" >> "${TMPDIR:-/tmp}/spi-review-${SESSION}.touched" 2>/dev/null || true
    ;;
  *)
    : # nada
    ;;
esac

exit 0
