---
name: sgi-copcsa-design
description: Use this skill to generate well-branded interfaces, screens and assets for SGI · COPCSA (Sistema de Gestión Interna), either for production code or throwaway prototypes/mocks. Contains the design tokens, colors, type, components, icons, and patterns for the SGI internal admin tool — invoice reconciliation, employee management, document handling, petty cash, reimbursements.
user-invocable: true
---

# SGI · COPCSA — Design Skill

Read `design/components.md` at the root for the full design system: principles, content tone, visual foundations, component catalog. Read `components.css` for the token definitions (importable). Read files inside `docs/` for the actual components and patterns in detail, with usage guidelines and code snippets.

## Hard rules (do not break)

1. **No borders, no shadows.** Use `background-color`, 1px `var(--rule)` lines, or 3px accent strips. Never `border: 1px solid` on cards or `box-shadow: anything`.
2. **Mono for data.** Invoice IDs, dates, CC numbers, money amounts, filenames all in `JetBrains Mono`.
3. **Uppercase labels.** Section titles like "PROVEEDOR", "VALOR FACTURA" use 10–11px font-weight 700 with letter-spacing 0.6–0.8px.
4. **Spanish formal.** No "tú" in UI labels, no emoji, no exclamation marks. Money is `$ 120.000` (Colombian format).
5. **Soft pills.** Always use `primarySoft` / `warningSoft` / `dangerSoft` variants for state badges, never the solid colors.
6. **Square corners.** Cards `radius: 0`. Pills `radius: 3px`. Buttons `radius: 4px`.
7. **Inter + JetBrains Mono only.** Don't introduce new fonts.

## Visual tone

Warm operational palette: green (success/paid), orange (edit/favorites), brown (tertiary accent). No corporate blue. Dark sidebar (`#212529`) anchors the layout. White cards on `#f5f5f5` canvas.

## When asked to design a new SGI view

1. Identify the pattern: is it a **list** (use Lista de Facturas as reference), a **detail** (use Detalle de Factura), or a **directory** (use Empleados)?
2. Reuse the existing components — don't reinvent buttons, pills, avatars.
3. Apply the same density: card padding 20px, gaps 14–16px between cards, page padding 24px horizontal.
4. Add the same affordances: search bar with `IconSearch`, filter chips, sortable columns with `IconChevronDown`.
5. Match the empty states: short Spanish copy, no emoji, dropzone with dashed-look via `background: #fafafa` (not a real dashed border).

If the user invokes this skill without specific guidance, ask which SGI module they want to design and what flow / screen, then proceed as an expert SGI designer.

## Files in this skill

- `docs/design/*.md` — full design system docs (principles, tone, foundations, components catalog)
- `components.css` — importable CSS with all tokens + utility classes
