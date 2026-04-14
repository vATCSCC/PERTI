# Playbook Compound Boolean Filter & Query Builder

**Date**: 2026-03-18
**Status**: Design approved

## Overview

Replace the flat search filter in the Playbook page with a full boolean expression engine supporting parenthesized grouping, explicit AND/OR/NOT operators, context-dependent comma/space semantics, a new FIR: qualifier, and a visual query builder panel.

## Requirements

1. Compound boolean expressions with arbitrary nesting: `(THRU:ZDC & -THRU:ZOB & ORIG:ZNY) OR (THRU:ZDC & (ORIG:KPHL | ORIG:KTTN) & DEST:ZOA)`
2. Visual query builder panel (side panel overlay) that syncs bidirectionally with the text search bar
3. FIR: qualifier supporting tier names, ICAO prefixes, and direct FIR boundary codes
4. Full expression logic applies at both play-level filtering and route-level emphasis
5. Map highlighting derived from AST traversal
6. No backward compatibility mode — the new parser fully replaces the old one

## Operator Syntax

| Syntax | Meaning |
|--------|---------|
| Space between **same qualifier** | OR — `THRU:X THRU:Y` = traverse X or Y |
| Space between **different qualifiers** | AND — `THRU:X ORIG:Y` = traverse X and origin is Y |
| `&` / `AND` | AND (always, explicit) |
| `\|` / `OR` | OR (always, explicit) |
| `-` / `NOT` / `!` | NOT (always) |
| `()` | Grouping (always) |
| `,` in THRU/VIA/FIR/AVOID | AND — `THRU:X,Y` = traverse both |
| `,` in ORIG/DEST | OR — `ORIG:X,Y` = any origin |

**Precedence**: AND binds tighter than OR (standard boolean logic). `A & B | C & D` = `(A & B) | (C & D)`.

**Implicit vs explicit mode**: When an expression contains any explicit operator (`&`, `|`, `OR`, `AND`, `(`) the implicit space-grouping is disabled and space becomes plain AND. This prevents ambiguity when users mix modes. **Note**: This means `THRU:ZDC THRU:ZOB & ORIG:ZNY` parses as `THRU:ZDC AND THRU:ZOB AND ORIG:ZNY` (all AND), not `(THRU:ZDC OR THRU:ZOB) AND ORIG:ZNY`. Users who want the implicit OR behavior must either omit all explicit operators or use explicit parentheses: `(THRU:ZDC | THRU:ZOB) & ORIG:ZNY`. The help text in the search UI should document this clearly.

**Qualifiers**: `THRU`, `VIA` (alias for THRU), `ORIG`, `DEST`, `FIR`, `AVOID` (alias for NOT THRU).

**Unqualified terms**: `ZNY` without a qualifier expands to `THRU:ZNY | ORIG:ZNY | DEST:ZNY`.

### Comma Semantics (Qualifier-Dependent)

Comma within a value list means different things based on qualifier type:

**Multi-valued qualifiers** (THRU, VIA, FIR, AVOID) — a route traverses many facilities:
- `THRU:X,Y,Z` → `THRU:X AND THRU:Y AND THRU:Z` (must traverse all)
- `-THRU:X,Y,Z` → `NOT THRU:X AND NOT THRU:Y AND NOT THRU:Z` (avoid all)
- `AVOID:X,Y,Z` → `NOT THRU:X AND NOT THRU:Y AND NOT THRU:Z` (same as above)
- `FIR:X,Y` → `FIR:X AND FIR:Y` (must traverse all listed FIRs)

**Single-valued qualifiers** (ORIG, DEST) — a route has one origin/destination:
- `ORIG:X,Y,Z` → `ORIG:X OR ORIG:Y OR ORIG:Z` (any of these origins)
- `-ORIG:X,Y` → `NOT ORIG:X AND NOT ORIG:Y` (none of these origins)
- `DEST:X,Y` → `DEST:X OR DEST:Y` (any of these destinations)
- `-DEST:X,Y` → `NOT DEST:X AND NOT DEST:Y` (none of these destinations)

### Space Semantics (Qualifier-Dependent, Implicit Mode Only)

When no explicit operators are present:
- Same qualifier terms are OR'd: `THRU:ZDC THRU:ZOB` → `THRU:ZDC OR THRU:ZOB`
- Different qualifier terms are AND'd: `THRU:ZDC ORIG:ZNY` → `THRU:ZDC AND ORIG:ZNY`

This gives useful expressiveness alongside comma:
- `THRU:ZDC,ZOB` = must go through **both** ZDC and ZOB (comma = AND for multi-valued)
- `THRU:ZDC THRU:ZOB` = goes through **either** ZDC or ZOB (space = OR for same qualifier)

## Section 1: Recursive Descent Boolean Parser

**Replaces**: `parseSearch()` (playbook.js lines 217-255)

### Grammar (pseudo-BNF)

```
expression  ::= or_expr
or_expr     ::= and_expr (('|' | 'OR') and_expr)*
and_expr    ::= unary (('&' | 'AND' | <implicit>) unary)*
unary       ::= 'NOT' unary | '-' unary | '!' unary | primary
primary     ::= '(' expression ')' | qualifier ':' value_list | bare_term
qualifier   ::= 'THRU' | 'VIA' | 'ORIG' | 'DEST' | 'FIR' | 'AVOID'
value_list  ::= value (',' value)*
bare_term   ::= [A-Z0-9_]+
```

**Note on implicit mode**: The grammar above represents explicit mode where `<implicit>` (space) is always AND. In implicit mode (no explicit operators detected), the parser applies a post-parse rewrite: adjacent TERM nodes with the same qualifier under an AND node are collected and re-wrapped in an OR node. This rewrite happens after the initial parse, not during it, keeping the grammar clean.

### AST Node Types

```javascript
{ type: 'OR',   children: [...] }
{ type: 'AND',  children: [...] }
{ type: 'NOT',  child: ... }
{ type: 'TERM', qualifier: 'THRU'|'ORIG'|'DEST'|'FIR'|'AVOID'|null, value: 'ZDC' }
```

### Comma Expansion at Parse Time

The parser expands comma-separated value lists into AST subtrees at parse time:

- `THRU:ZDC,ZOB` → `AND(TERM(THRU,ZDC), TERM(THRU,ZOB))`
- `ORIG:KJFK,KLGA` → `OR(TERM(ORIG,KJFK), TERM(ORIG,KLGA))`
- `-THRU:ZFW,ZAU` → `AND(NOT(TERM(THRU,ZFW)), NOT(TERM(THRU,ZAU)))`
- `-ORIG:KJFK,KLGA` → `AND(NOT(TERM(ORIG,KJFK)), NOT(TERM(ORIG,KLGA)))`
- `AVOID:ZFW,ZAU` → `AND(NOT(TERM(THRU,ZFW)), NOT(TERM(THRU,ZAU)))`

### Implicit Mode Detection

Before parsing, scan the token stream for any explicit operator (`&`, `|`, `OR`, `AND`, `(`, `)`). If found, parse in explicit mode (space = AND). If none found, parse in implicit mode (space = AND initially, then post-parse rewrite groups same-qualifier terms into OR).

### FIR Resolution at Parse Time

When a `FIR:value` term is encountered, resolve the value to a set of facility codes. The `fir_tiers.json` file contains three entry types that must all be handled:

**1. Pattern-based tiers** (e.g., `EUR_WEST` with `"patterns": ["EG*", "EI*", "LF*"]`):
- Expand each wildcard pattern against all known facility codes in `FacilityHierarchy`
- `FIR:EUR_WEST` → find all ARTCC/FIR codes matching `EG*`, `EI*`, `LF*`, etc.
- The `*` is a suffix wildcard: `EG*` matches any code starting with `EG`

**2. Member-based tiers** (e.g., `USA` with `"members": ["ZAB", "ZAU", "ZBW", ...]`):
- Direct lookup — the members array contains the exact ARTCC codes
- `FIR:USA` → `["ZAB", "ZAU", "ZBW", "ZDC", ...]`

**3. Alias tiers** (e.g., `CONUS` with `"alias": "USA"`):
- Redirect to the referenced tier and resolve it
- `FIR:CONUS` → resolve `USA` → `["ZAB", "ZAU", ...]`
- Follow one level of alias only (no recursive alias chains exist in the data)

**4. Direct ICAO prefix** (not a tier name):
- If the value doesn't match any tier name, treat as ICAO prefix
- Match against all known facility codes starting with the value
- `FIR:EG` → all codes starting with `EG` (e.g., `EGTT`, `EGPX`)

**5. Direct FIR code** (exact match):
- If not a tier name and not a prefix match, try exact code match
- `FIR:CZQX` → exact match against known FIR boundary code

Resolution order: tier name → alias → ICAO prefix → exact code. The resolved codes replace the FIR term in the AST as an OR group of THRU terms, so downstream matching is uniform. FIR terms never reach the matcher.

## Section 2: AST Matcher

**Replaces**: `matchesSearch()` (lines 305-346) and `routeMatchesSearchClauses()` (lines 356-408)

### Unified Matcher

```javascript
function evaluateAST(node, index) → boolean
```

The `index` object has the same shape for both plays and routes:

```javascript
{
  originCodes: Set(['KJFK', 'KLGA', 'N90', 'ZNY']),
  destCodes:   Set(['KLAX', 'SCT', 'ZLA']),
  thruCodes:   Set(['ZNY', 'ZDC', 'ZID', 'ZKC', 'ZLA', ...]),  // includes orig+dest
  allCodes:    Set([...originCodes, ...destCodes, ...thruCodes]),
  searchText:  'play name description...'  // only for plays, empty for routes
}
```

**Note**: No `firCodes` field in the index. FIR terms are fully expanded to THRU terms at parse time (Section 1), so the matcher only needs `thruCodes` to evaluate them. This avoids maintaining a parallel FIR index.

### Evaluation Rules

| Node Type | Logic |
|-----------|-------|
| `OR` | Any child true → true |
| `AND` | All children true → true |
| `NOT` | Invert child |
| `TERM` with `qualifier=THRU/VIA` | `index.thruCodes.has(value)` |
| `TERM` with `qualifier=ORIG` | `index.originCodes.has(value)` |
| `TERM` with `qualifier=DEST` | `index.destCodes.has(value)` |
| `TERM` with `qualifier=FIR` | Never reaches matcher (expanded at parse time) |
| `TERM` with `qualifier=AVOID` | Never reaches matcher (expanded to NOT+THRU at parse time) |
| `TERM` with `qualifier=null` | `index.allCodes.has(value) \|\| index.searchText.indexOf(value.toLowerCase()) !== -1` |

**Unqualified term matching**: Unqualified terms check both facility codes (`allCodes`) and the play's full-text `searchText` field (play name, description, category, impacted area, facilities involved, route strings). This preserves the current behavior where users can search by play name or description text. For routes, `searchText` is empty so only `allCodes` is checked.

### Search Index Building

`buildSearchIndex()` stays mostly the same:
- `originCodes`, `destCodes`, `thruCodes` populated from aggregated facility fields (same as current)
- `allCodes` = union of all three
- `searchText` = concatenation of play name, display name, description, category, impacted area, facilities involved, route strings (same as current `_searchText`)

## Section 3: Visual Query Builder Panel

### Panel Structure

New floating overlay matching existing panel pattern (`.pb-info-overlay`):
- Same CSS: `position: absolute`, `backdrop-filter: blur(8px)`, `border-radius: 8px`, `box-shadow`, draggable titlebar, minimizable
- Position: top-left, offset to the right of the catalog (~344px from left), 300px wide
- `max-height: calc(100% - 84px)` with scrollable content
- Opens/closes via a "Builder" toggle button next to the search input

### Panel Contents

- **Titlebar**: "Query Builder" label, close button (same style as catalog/info titlebars)
- **Groups**: Each OR-group is a bordered card containing condition chips
- **Condition chips**: Same symbology as existing `.pb-filter-badge` — `FacilityHierarchy` region background fill, green `#28a745` border (include) / red `#dc3545` border (negated/exclude), qualifier as text prefix, `x` to remove
- **Nested OR sub-groups**: Dashed border variant of the group container
- **`+ Add condition`**: Per group, inline form row with qualifier dropdown + text input
- **`+ Add OR sub-group`**: Per group, creates a nested dashed-border container
- **`+ Add OR Group`**: At bottom, adds a new top-level group with OR divider
- **OR divider**: Orange `OR` label between groups
- **Expression preview**: Readonly text at bottom showing the serialized expression, click to switch to text editing mode

### Sync Behavior

- Editing the text search bar → re-parses AST → rebuilds builder UI
- Editing in the builder (add/remove/change conditions) → serializes AST to text → updates search bar
- Both trigger `applyFilters()` on change
- Builder toggle button shows active state when panel is open
- **Malformed text input**: If the text bar contains a syntax error, the builder shows a warning banner ("Parse error — edit in text mode or clear") and does not attempt to render partial AST. The text bar gets a red border to indicate the error. Filtering falls back to no-filter (show all plays) until the expression is valid.

## Section 4: Map Highlighting

**Modifies**: `updateMapHighlights()` (lines 505-577) and `classifySearchTerm()` (lines 462-498)

### AST-Based Extraction

Walk the AST recursively to collect all TERM nodes. Each term gets classified via `classifySearchTerm()` and `FacilityHierarchy`:
- Positive terms → green include layers (`artcc-search-include`, `tracon-search-include`, etc.)
- Negated terms → red exclude layers (`artcc-search-exclude`, etc.)
- No change to MapLibre layer structure or symbology

### FIR Terms on Map

FIR terms are already resolved to ARTCC codes at parse time. The AST walker encounters THRU terms (the expansion result), which get classified and highlighted normally. No special FIR handling needed in the map layer.

### Compound Expression Polarity

When a facility code appears both positive and negative across different OR groups, the map shows **both colors** — green include border and red exclude border are separate MapLibre layers that can overlap. The include layer renders first (below), exclude layer renders on top (above), so the red border is visible as an indicator that the facility is excluded in at least one branch. This avoids the misleading "red wins" problem where a facility that is included in one OR branch would appear excluded.

## Section 5: Error Handling

### Malformed Expressions

| Error | Behavior |
|-------|----------|
| Unmatched `(` | Parser returns error with position; search bar shows red border; no filtering applied (show all plays) |
| Unmatched `)` | Same as above |
| Empty qualifier `THRU:` | Ignore the qualifier, treat as if the term was not entered |
| Trailing operator `THRU:ZDC &` | Ignore trailing operator, parse what's valid |
| Unknown qualifier `FOO:ZDC` | Treat `FOO:ZDC` as an unqualified bare term (match against allCodes/searchText) |
| Empty group `()` | Ignore, treat as no-op |
| Double negation `NOT NOT X` | Apply double negation (cancels out, equivalent to `X`) |
| `-` prefix + `NOT` keyword: `-NOT X` | Same as double negation |
| Empty input | No filter applied, show all plays |

### Builder Panel Error Display

- Parse errors show a yellow warning banner in the builder panel
- The expression preview shows the raw text with the error position highlighted
- Users can fix the expression in either the text bar or the builder (once valid)

## Section 6: File Changes

| File | Change | Scope |
|------|--------|-------|
| `assets/js/playbook.js` | Replace `parseSearch()` with recursive descent parser; replace `matchesSearch()` and `routeMatchesSearchClauses()` with unified `evaluateAST()`; update `buildSearchIndex()`; update `updateMapHighlights()` to walk AST; update `renderFilterBadges()` to walk AST; add builder panel toggle logic; add `fir_tiers.json` fetch on init | Major |
| `assets/js/playbook-query-builder.js` | **New** — visual builder panel: render AST as editable groups/chips, sync to/from text bar, inline condition adding | New |
| `assets/css/playbook.css` | Add `.pb-builder-overlay`, `.pb-builder-group`, `.pb-builder-chip`, `.pb-builder-or-divider`, `.pb-builder-subgroup` | Moderate |
| `playbook.php` | Add builder panel HTML container (empty div, populated by JS), add builder toggle button next to search input, add script include for `playbook-query-builder.js` | Small |
| `assets/locales/en-US.json` | Add i18n keys for builder UI: group labels, add condition, add group, expression preview, help text, error messages | Small |

### Not Changed

- `playbook-cdr-search.js` — separate structured search module, unaffected
- `route-analysis-panel.js` — route analysis, unaffected
- Server-side API endpoints — all filtering stays client-side
- `fir_tiers.json` — read-only data file; **not currently loaded on Playbook page** — `playbook.js` will add a `fetch()` call to load it on init (same pattern as `fir-scope.js` uses)

## Examples

```
# Simple: routes through ZDC
THRU:ZDC

# Routes through ZDC originating from ZNY area (implicit mode — different qualifiers AND)
THRU:ZDC ORIG:ZNY
→ (THRU:ZDC) AND (ORIG:ZNY)

# Routes through BOTH ZDC and ZID (comma = AND for multi-valued)
THRU:ZDC,ZID
→ THRU:ZDC AND THRU:ZID

# Routes through ZDC OR ZID (implicit mode — same qualifier space = OR)
THRU:ZDC THRU:ZID
→ THRU:ZDC OR THRU:ZID

# Origin is JFK or LGA (comma = OR for single-valued)
ORIG:KJFK,KLGA
→ ORIG:KJFK OR ORIG:KLGA

# Complex compound expression (explicit mode — parentheses trigger it)
(THRU:ZDC & -THRU:ZOB & ORIG:ZNY & DEST:ZLA & -ORIG:KEWR) | (THRU:ZDC & -THRU:ZOB & (ORIG:KPHL | ORIG:KTTN) & DEST:ZOA)

# Mixing implicit and explicit — the & triggers explicit mode, so ALL spaces are AND
THRU:ZDC THRU:ZOB & ORIG:ZNY
→ THRU:ZDC AND THRU:ZOB AND ORIG:ZNY
# To get OR on the THRU terms, use: (THRU:ZDC | THRU:ZOB) & ORIG:ZNY

# FIR filtering — tier name (pattern-based)
FIR:EUR_WEST
→ resolves patterns ["EG*", "EI*", "LF*", ...] against known facility codes

# FIR filtering — tier name (member-based)
FIR:USA
→ resolves to members ["ZAB", "ZAU", "ZBW", "ZDC", ...]

# FIR filtering — alias
FIR:CONUS
→ resolves alias to USA → ["ZAB", "ZAU", ...]

# FIR filtering — ICAO prefix
FIR:EG
→ matches all EG-prefix FIR boundaries (UK)

# FIR filtering — direct code
FIR:CZQX
→ exact match against Gander oceanic FIR

# Avoid facilities
AVOID:ZOB,ZAU
→ NOT THRU:ZOB AND NOT THRU:ZAU

# Negated destination (comma = OR for DEST, negation distributes as AND-NOT)
-DEST:KJFK,KLGA
→ NOT DEST:KJFK AND NOT DEST:KLGA (none of these destinations)

# Unqualified term — searches facility codes + play name/description text
ZNY
→ THRU:ZNY OR ORIG:ZNY OR DEST:ZNY (facility match)
→ also matches play names/descriptions containing "ZNY" (text search fallback)

# Error: unmatched paren — shows red border, no filtering
(THRU:ZDC & ORIG:ZNY
→ parse error at position 22: expected ')'

# Error: unknown qualifier — treated as bare term
FOO:ZDC
→ treated as unqualified text "FOO:ZDC"
```
