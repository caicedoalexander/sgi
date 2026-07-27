#!/usr/bin/env bash
# Stop: si en la sesión se tocó implementación, emite UN recordatorio consolidado.
# No bloqueante: nunca usa decision:block; siempre exit 0.

INPUT=$(cat)
SESSION=$(printf '%s' "$INPUT" | jq -r '.session_id // "nosession"' 2>/dev/null)
MARK="${TMPDIR:-/tmp}/spi-review-${SESSION}.touched"

if [ -f "$MARK" ]; then
  COUNT=$(sort -u "$MARK" 2>/dev/null | grep -c . 2>/dev/null || echo 0)
  rm -f "$MARK"
  MSG="Recordatorio SPI: en esta sesión se tocaron ${COUNT} archivo(s) de implementación (src/templates/migraciones). Antes de dar por cerrado el trabajo, lanzá el subagente 'spi-implementation-reviewer' sobre el diff (git diff) para revisar convenciones, anti-drift de vista, RBAC y fidelidad al plan."
  jq -n --arg m "$MSG" '{systemMessage: $m}'
fi

exit 0
