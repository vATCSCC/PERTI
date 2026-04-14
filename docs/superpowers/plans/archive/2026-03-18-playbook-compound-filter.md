# Playbook Compound Boolean Filter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the flat Playbook search filter with a recursive descent boolean parser supporting grouping, compound AND/OR/NOT, FIR: qualifier, and a visual query builder panel.

**Architecture:** New standalone parser module (`playbook-filter-parser.js`) handles tokenization, AST construction, evaluation, and serialization. New builder module (`playbook-query-builder.js`) renders AST as interactive UI. Both integrate into existing `playbook.js` IIFE by replacing `parseSearch()`, `matchesSearch()`, `routeMatchesSearchClauses()`, and updating `buildSearchIndex()`, `applyRouteEmphasis()`, `updateMapHighlights()`, `renderFilterBadges()`. **Architectural note**: The spec describes changes within `playbook.js` but the parser and builder are extracted into separate modules for maintainability. Each module is ~400-500 lines; combining them into `playbook.js` (already 5,600+ lines) would be unwieldy. The `playbook.js` IIFE keeps thin wrapper functions that delegate to the modules.

**Tech Stack:** Vanilla JS (no build tools), jQuery 2.2.4, MapLibre GL JS, Bootstrap 4.5, Inconsolata monospace font, existing `FacilityHierarchy` module.

**Spec:** `docs/superpowers/specs/2026-03-18-playbook-compound-filter-design.md`

**Testing:** No automated test suite — verify each task via browser console and live Playbook page at `https://perti.vatcscc.org/playbook.php` (or local `php -S localhost:8000`). System is in hibernation with SWIM exemption, but Playbook page loads from MySQL (uses `PERTI_MYSQL_ONLY`). Verify with representative sample expressions listed in each task.

**Stipulations:**
- Check & verify work often with real data & representative samples
- Do not hallucinate — read actual code before modifying
- Document work & update all relevant documentation/help/references when done
- Commit frequently after each task

---

## File Structure

| File | Role | Status |
|------|------|--------|
| `assets/js/playbook-filter-parser.js` | Tokenizer, recursive descent parser, AST evaluator, serializer, FIR resolver, implicit mode rewriter | **New** (~400 lines) |
| `assets/js/playbook-query-builder.js` | Visual builder panel: renders AST as groups/chips, syncs to/from text bar | **New** (~500 lines) |
| `assets/js/playbook.js` | Remove old parser/matcher functions, wire in new parser module, update index building, map highlights, filter badges | **Modify** (lines 230-678 heavily, plus ~4721 search binding) |
| `assets/css/playbook.css` | Add builder overlay + chip + group styles | **Modify** (append ~120 lines) |
| `playbook.php` | Add builder panel container div, builder toggle button, script includes | **Modify** (lines 119-125, 589) |
| `assets/locales/en-US.json` | Add i18n keys for builder UI, updated search help | **Modify** (playbook section ~line 3690) |

---

### Task 1: Create the Filter Parser Module — Tokenizer

**Files:**
- Create: `assets/js/playbook-filter-parser.js`

This is the foundation — a tokenizer that breaks an expression string into tokens. Expose as `window.PlaybookFilterParser`.

- [ ] **Step 1: Create the file with IIFE skeleton and tokenizer**

Create `assets/js/playbook-filter-parser.js` with:
- IIFE wrapper exposing `window.PlaybookFilterParser`
- Token types: `LPAREN`, `RPAREN`, `AND`, `OR`, `NOT`, `COLON`, `COMMA`, `TERM`, `EOF`
- `tokenize(input)` function:
  - Uppercases input
  - Skips whitespace (but records that whitespace occurred between tokens for implicit AND)
  - Recognizes: `(`, `)`, `&`, `|`, `,`, `-`, `!`
  - Recognizes keywords: `AND`, `OR`, `NOT`
  - Recognizes qualifier keywords: `THRU`, `VIA`, `ORIG`, `DEST`, `FIR`, `AVOID`
  - Qualifier followed by `:` produces a `QUALIFIER` token + `COLON` token
  - Everything else is a `TERM` token (alphanumeric + `_` + `*`)
  - Each token has `{ type, value, pos }` (pos = character offset for error reporting)

- [ ] **Step 2: Verify tokenizer in browser console**

Load the Playbook page, open console, test:
```javascript
PlaybookFilterParser.tokenize('THRU:ZDC & -THRU:ZOB')
// Should produce: [QUALIFIER:THRU, COLON, TERM:ZDC, AND, NOT, QUALIFIER:THRU, COLON, TERM:ZOB, EOF]

PlaybookFilterParser.tokenize('(ORIG:KJFK,KLGA | DEST:ZLA)')
// Should produce: [LPAREN, QUALIFIER:ORIG, COLON, TERM:KJFK, COMMA, TERM:KLGA, OR, QUALIFIER:DEST, COLON, TERM:ZLA, RPAREN, EOF]
```

- [ ] **Step 3: Commit**

```bash
git add assets/js/playbook-filter-parser.js
git commit -m "feat(playbook): add filter expression tokenizer"
```

---

### Task 2: Parser — Recursive Descent + Implicit Mode

**Files:**
- Modify: `assets/js/playbook-filter-parser.js`

Add the recursive descent parser that produces an AST from the token stream.

- [ ] **Step 1: Add implicit mode detection**

Add `detectExplicitMode(tokens)` — scans token array for any `AND`, `OR`, `LPAREN`, `RPAREN` token types. Returns `true` if found (explicit mode), `false` otherwise (implicit mode).

- [ ] **Step 2: Add the recursive descent parser**

Add `parse(input)` function that:
1. Tokenizes the input
2. Detects explicit/implicit mode
3. Calls `parseOrExpr()` which implements the grammar:
   - `parseOrExpr()` → `parseAndExpr()` separated by `OR`/`|` tokens
   - `parseAndExpr()` → `parseUnary()` separated by `AND`/`&` tokens or implicit space
   - `parseUnary()` → handles `NOT`/`-`/`!` prefix, then calls `parsePrimary()`
   - `parsePrimary()` → handles `(expr)`, `qualifier:value_list`, or bare term
4. `parsePrimary()` for qualifiers:
   - Reads qualifier + colon + value_list (comma-separated values)
   - Expands comma values based on qualifier type:
     - Multi-valued (THRU, VIA, FIR, AVOID): comma → AND subtree
     - Single-valued (ORIG, DEST): comma → OR subtree
   - If negated (from parent `parseUnary`), distributes negation into each value
   - AVOID → rewrite to NOT+THRU
   - VIA → rewrite to THRU
5. Returns `{ ast, error }` — if error, includes `{ message, pos }`

- [ ] **Step 3: Add implicit mode post-parse rewrite**

Add `rewriteImplicitMode(ast)`:
- Walks the AST looking for AND nodes
- Within each AND node, groups children by qualifier
- Same-qualifier groups with >1 child get re-wrapped in an OR node
- Different-qualifier children remain as AND
- Returns modified AST

Apply this rewrite only when `detectExplicitMode()` returns false.

- [ ] **Step 4: Verify parser in browser console**

Test representative expressions:
```javascript
// Implicit mode — same qualifier = OR, different = AND
PlaybookFilterParser.parse('THRU:ZDC THRU:ZOB ORIG:ZNY')
// AST: AND(OR(THRU:ZDC, THRU:ZOB), ORIG:ZNY)

// Explicit mode — parens trigger it
PlaybookFilterParser.parse('(THRU:ZDC & -THRU:ZOB) | (ORIG:KPHL & DEST:ZOA)')
// AST: OR(AND(THRU:ZDC, NOT(THRU:ZOB)), AND(ORIG:KPHL, DEST:ZOA))

// Comma expansion — multi-valued AND
PlaybookFilterParser.parse('THRU:ZDC,ZOB')
// AST: AND(THRU:ZDC, THRU:ZOB)

// Comma expansion — single-valued OR
PlaybookFilterParser.parse('ORIG:KJFK,KLGA')
// AST: OR(ORIG:KJFK, ORIG:KLGA)

// Nested grouping
PlaybookFilterParser.parse('THRU:ZDC & (ORIG:KPHL | ORIG:KTTN) & DEST:ZOA')
// AST: AND(THRU:ZDC, OR(ORIG:KPHL, ORIG:KTTN), DEST:ZOA)

// Error case
PlaybookFilterParser.parse('(THRU:ZDC & ORIG:ZNY')
// { ast: null, error: { message: "Expected ')'", pos: 22 } }

// AVOID expansion
PlaybookFilterParser.parse('AVOID:ZOB,ZAU')
// AST: AND(NOT(THRU:ZOB), NOT(THRU:ZAU))

// Unqualified term
PlaybookFilterParser.parse('ZNY')
// AST: OR(THRU:ZNY, ORIG:ZNY, DEST:ZNY)
```

- [ ] **Step 5: Commit**

```bash
git add assets/js/playbook-filter-parser.js
git commit -m "feat(playbook): add recursive descent parser with implicit mode"
```

---

### Task 3: Parser — AST Evaluator and Serializer

**Files:**
- Modify: `assets/js/playbook-filter-parser.js`

Add the matcher and serializer functions.

- [ ] **Step 1: Add `evaluateAST(node, index)` function**

Recursive evaluator:
- `OR` node: `children.some(c => evaluateAST(c, index))`
- `AND` node: `children.every(c => evaluateAST(c, index))`
- `NOT` node: `!evaluateAST(node.child, index)`
- `TERM` node:
  - `qualifier === 'THRU'` → `index.thruCodes.has(node.value)`
  - `qualifier === 'ORIG'` → `index.originCodes.has(node.value)`
  - `qualifier === 'DEST'` → `index.destCodes.has(node.value)`
  - `qualifier === null` → `index.allCodes.has(node.value) || index.searchText.indexOf(node.value.toLowerCase()) !== -1`
  - `qualifier === 'FIR'` or `qualifier === 'AVOID'` → should never reach here (expanded at parse time), return false as safety

- [ ] **Step 2: Add `serializeAST(node)` function**

Converts AST back to text expression:
- `OR` → children joined by ` | `, wrapped in parens if nested
- `AND` → children joined by ` & `
- `NOT` → `-` prefix
- `TERM` → `QUALIFIER:VALUE` or just `VALUE` if unqualified
- Smart parenthesization: only add parens when an OR node is a child of an AND node

- [ ] **Step 3: Add `collectTerms(node)` utility**

Recursively collects all TERM nodes from the AST, each annotated with `{ qualifier, value, negated }`. Used by map highlighting and badge rendering. The `negated` flag is set by tracking NOT ancestors during traversal.

- [ ] **Step 4: Verify in browser console**

```javascript
// Build a test index matching a play that traverses ZDC, ZOB, originates from ZNY area
var testIndex = {
  originCodes: new Set(['KJFK', 'KLGA', 'N90', 'ZNY']),
  destCodes: new Set(['KLAX', 'SCT', 'ZLA']),
  thruCodes: new Set(['ZNY', 'ZDC', 'ZOB', 'ZID', 'ZKC', 'ZLA']),
  allCodes: new Set(['KJFK', 'KLGA', 'N90', 'ZNY', 'KLAX', 'SCT', 'ZLA', 'ZDC', 'ZOB', 'ZID', 'ZKC']),
  searchText: 'zny-west-via-zdc westbound reroute'
};

var result = PlaybookFilterParser.parse('THRU:ZDC & ORIG:ZNY');
PlaybookFilterParser.evaluate(result.ast, testIndex)  // true

var result2 = PlaybookFilterParser.parse('THRU:ZDC & -THRU:ZOB');
PlaybookFilterParser.evaluate(result2.ast, testIndex)  // false (has ZOB)

var result3 = PlaybookFilterParser.parse('(THRU:ZDC & -THRU:ZOB) | ORIG:ZNY');
PlaybookFilterParser.evaluate(result3.ast, testIndex)  // true (second branch matches)

// Serializer round-trip
PlaybookFilterParser.serialize(result3.ast)
// "(THRU:ZDC & -THRU:ZOB) | ORIG:ZNY"
```

- [ ] **Step 5: Commit**

```bash
git add assets/js/playbook-filter-parser.js
git commit -m "feat(playbook): add AST evaluator, serializer, and term collector"
```

---

### Task 4: FIR Resolution

**Files:**
- Modify: `assets/js/playbook-filter-parser.js`

Add FIR tier data loading and resolution logic.

- [ ] **Step 1: Add FIR data loading**

Add `loadFIRTiers()` — fetches `assets/data/fir_tiers.json` and stores the parsed data. Returns a Promise. Build a flat lookup map from all sections (`global`, `regional`, `country`) keyed by tier code.

Add `setFIRTiers(data)` for programmatic injection (useful for testing).

- [ ] **Step 2: Add `resolveFIR(value, facilityHierarchy)` function**

Resolution order:
1. Check flat tier lookup map for exact match (handles pattern-based, member-based, alias tiers)
2. If alias → follow one level of redirection, re-lookup
3. For pattern-based tiers: expand each `"XY*"` pattern against `FacilityHierarchy.ARTCCS` array (e.g., `EG*` matches `EGPX`, `EGTT`). If `FacilityHierarchy` isn't loaded yet, return empty (no matches).
4. For member-based tiers: return `members` array directly (already contains exact ARTCC codes)
5. If not a tier name: treat value as ICAO prefix, match against `FacilityHierarchy.ARTCCS` codes starting with that prefix
6. If no prefix matches: try exact code match in `FacilityHierarchy.ARTCCS`
7. Returns array of resolved facility codes (may be empty if FIR data not loaded yet)

- [ ] **Step 3: Integrate FIR resolution into parser**

In `parsePrimary()`, when qualifier is `FIR`:
- After parsing the value(s), call `resolveFIR()` for each value
- Replace each FIR term with an OR group of THRU terms using the resolved codes
- If resolution returns empty array, leave as a TERM (will match nothing — effectively filtered out)

- [ ] **Step 4: Verify FIR resolution in console**

```javascript
// Wait for FIR data to load
await PlaybookFilterParser.loadFIRTiers();

// Member-based tier
PlaybookFilterParser.resolveFIR('USA')
// ["ZAB", "ZAU", "ZBW", "ZDC", "ZDV", "ZFW", "ZHU", "ZID", "ZJX", ...]

// Alias
PlaybookFilterParser.resolveFIR('CONUS')
// Same as USA

// Pattern-based tier (needs FacilityHierarchy loaded)
PlaybookFilterParser.resolveFIR('EUR_WEST')
// Codes matching EG*, EI*, LF*, EB*, EH*, EL*, ED*

// ICAO prefix (not a tier name)
PlaybookFilterParser.resolveFIR('EG')
// All facility codes starting with EG

// Parse with FIR
var r = PlaybookFilterParser.parse('FIR:USA & DEST:KLAX');
// AST: AND(OR(THRU:ZAB, THRU:ZAU, ...), DEST:KLAX)
```

- [ ] **Step 5: Commit**

```bash
git add assets/js/playbook-filter-parser.js
git commit -m "feat(playbook): add FIR tier resolution (pattern, member, alias, prefix)"
```

---

### Task 5: Integrate Parser into playbook.js — Replace Core Functions

**Files:**
- Modify: `playbook.php:589` (add script include)
- Modify: `assets/js/playbook.js:230-421` (replace parser/matcher functions)

This is the main integration — swap old functions for new parser module.

- [ ] **Step 1: Add script include in playbook.php**

Insert before the `playbook.js` script tag (line 589):
```html
<script src="assets/js/playbook-filter-parser.js<?= _v('assets/js/playbook-filter-parser.js') ?>"></script>
```

- [ ] **Step 2: Replace `parseSearch()` in playbook.js**

Replace the function at lines 230-268 with a thin wrapper:
```javascript
function parseSearch(text) {
    if (!text || !text.trim()) return null;
    var result = PlaybookFilterParser.parse(text.trim());
    if (result.error) {
        $('#pb_search').addClass('pb-search-error');
        return null; // fail-open: show all plays
    }
    $('#pb_search').removeClass('pb-search-error');
    return result.ast;
}
```

- [ ] **Step 3: Replace `matchesSearch()` and `routeMatchesSearchClauses()`**

Replace `matchesSearch()` (lines 318-359):
```javascript
function matchesSearch(play, ast) {
    if (!ast) return true; // no filter = show all
    buildSearchIndex(play); // lazy init — must call before accessing _searchIndex
    return PlaybookFilterParser.evaluate(ast, play._searchIndex);
}
```

**Important**: The current `matchesSearch()` calls `buildSearchIndex(p)` at line 320 — the new version must preserve this call. The search index is lazily built on first access because plays load asynchronously.

Replace `routeMatchesSearchClauses()` (lines 369-421):
```javascript
function routeMatchesSearch(route, ast) {
    if (!ast) return true;
    buildRouteSearchIndex(route); // lazy init — same pattern as play index
    return PlaybookFilterParser.evaluate(ast, route._searchIndex);
}
```

- [ ] **Step 4: Update `buildSearchIndex()` to produce new index shape**

Modify `buildSearchIndex()` (lines 270-316) so each play gets a `_searchIndex` object:
```javascript
play._searchIndex = {
    originCodes: play._originCodes,    // existing Set
    destCodes:   play._destCodes,      // existing Set
    thruCodes:   play._traversedCodes, // existing Set (includes orig+dest)
    allCodes:    play._facilityCodes,  // existing Set (all codes)
    searchText:  play._searchText      // existing string
};
```

The existing `_originCodes`, `_destCodes`, `_traversedCodes`, `_facilityCodes`, `_searchText` fields are already built by the current `buildSearchIndex()`. Just add the `_searchIndex` wrapper object.

Also add route-level index building. Refactor `routeMatchesSearch()` to lazily build a `_searchIndex` on the route object (same pattern as `buildSearchIndex` for plays). Each route needs:
```javascript
function buildRouteSearchIndex(route) {
    if (route._searchIndex) return;
    var origCodes = new Set();
    var destCodes = new Set();
    var thruCodes = new Set();
    csvSplit(route.origin_airports).concat(csvSplit(route.origin_tracons)).concat(csvSplit(route.origin_artccs))
        .forEach(function(c) { if (c) origCodes.add(c.toUpperCase()); });
    csvSplit(route.dest_airports).concat(csvSplit(route.dest_tracons)).concat(csvSplit(route.dest_artccs))
        .forEach(function(c) { if (c) destCodes.add(c.toUpperCase()); });
    csvSplit(route.traversed_artccs).concat(csvSplit(route.traversed_tracons))
        .concat(csvSplit(route.traversed_sectors_low)).concat(csvSplit(route.traversed_sectors_high))
        .concat(csvSplit(route.traversed_sectors_superhigh))
        .forEach(function(c) { if (c) thruCodes.add(c.toUpperCase()); });
    // Origin+dest ARTCCs/TRACONs are traversed by definition
    origCodes.forEach(function(c) { thruCodes.add(c); });
    destCodes.forEach(function(c) { thruCodes.add(c); });
    var allCodes = new Set();
    origCodes.forEach(function(c) { allCodes.add(c); });
    destCodes.forEach(function(c) { allCodes.add(c); });
    thruCodes.forEach(function(c) { allCodes.add(c); });
    route._searchIndex = {
        originCodes: origCodes,
        destCodes: destCodes,
        thruCodes: thruCodes,
        allCodes: allCodes,
        searchText: ((route.route_string || '') + ' ' + (route.origin || '') + ' ' + (route.dest || '')).toUpperCase()
    };
}
```

**Important**: The current `routeMatchesSearchClauses()` at line 390 builds a `textBlob` from `route_string + origin + dest` — the new `searchText` must preserve this so unqualified terms still match route strings. Do NOT set `searchText: ''`.

- [ ] **Step 5: Update `applyFilters()` and `applyRouteEmphasis()` for AST**

Modify `applyFilters()` (lines 899-931):
1. Rename `currentSearchClauses` variable (line 59) to `currentAST` — initialized to `null` instead of `[]`
2. `parseSearch(searchText)` returns AST or null → assign to `currentAST`
3. Replace `currentSearchClauses.length &&` checks with `currentAST &&`
4. Pass AST to `matchesSearch(play, ast)`, `updateMapHighlights(ast)`, `renderFilterBadges(ast)`

Modify `applyRouteEmphasis()` (lines 427-437):
```javascript
function applyRouteEmphasis() {
    var hasSearch = !!currentAST;
    $('.pb-route-table tbody tr').each(function() {
        var rid = parseInt($(this).attr('data-route-id'));
        var route = (activePlayData && activePlayData.routes || []).find(function(r) { return r.route_id === rid; });
        if (!route) return;
        var matches = !hasSearch || routeMatchesSearch(route, currentAST);
        $(this).toggleClass('pb-route-dimmed', hasSearch && !matches);
        $(this).toggleClass('pb-route-emphasized', hasSearch && matches);
    });
}
```

Also rename `lastHighlightClauses` (line 455) to `lastHighlightAST` — initialized to `null`. Update its reference in `updateMapHighlights()` (line 519) and `updatePlayHighlights()` callback (line 5006).

**Important**: Grep for ALL references to `currentSearchClauses` and `lastHighlightClauses` throughout the file — there are 12+ usages at lines 59, 428, 433, 455, 519, 901, 909, 922, 923, 1283, 1398, 2379, 2395, 5006. Every usage must be updated:
- `.length` checks → `!!variable` or `!= null`
- `routeMatchesSearchClauses(route, clauses)` → `routeMatchesSearch(route, ast)`

- [ ] **Step 6: Trigger FIR data load on page init**

In the page init section of `playbook.js` (near line 4700), add:
```javascript
PlaybookFilterParser.loadFIRTiers();
```

This fires and forgets — FIR data loads async. If a user types `FIR:` before load completes, the parser falls back to empty resolution (no matches). Once loaded, re-filtering will pick it up.

- [ ] **Step 7: Verify in browser**

Load Playbook page. Test in search bar:
- `ZDC` — should filter to plays with ZDC in any facility code
- `THRU:ZDC` — should filter to plays traversing ZDC
- `THRU:ZDC ORIG:ZNY` — implicit mode: (THRU:ZDC OR nothing) AND ORIG:ZNY... wait, only one THRU term so no implicit OR. Should be THRU:ZDC AND ORIG:ZNY
- `THRU:ZDC THRU:ZOB` — implicit mode: THRU:ZDC OR THRU:ZOB
- `(THRU:ZDC & -THRU:ZOB) | ORIG:ZNY` — compound expression
- `ORIG:KJFK,KLGA` — comma OR: origin is KJFK or KLGA
- `THRU:ZDC,ZOB` — comma AND: traverses both ZDC and ZOB
- Empty search — shows all plays
- `(THRU:ZDC` — parse error, red border, shows all plays

- [ ] **Step 8: Commit**

```bash
git add playbook.php assets/js/playbook.js assets/js/playbook-filter-parser.js
git commit -m "feat(playbook): integrate compound filter parser, replace old search"
```

---

### Task 6: Update Map Highlighting for AST

**Files:**
- Modify: `assets/js/playbook.js:475-590` (classifySearchTerm + updateMapHighlights)

- [ ] **Step 1: Update `updateMapHighlights()` to accept AST**

Replace the clause-array iteration with AST term collection:
```javascript
function updateMapHighlights(ast) {
    // Clear all search highlight filters first
    clearSearchHighlightFilters();
    if (!ast) return;

    var terms = PlaybookFilterParser.collectTerms(ast);
    var includeCodes = { artcc: [], tracon: [], sector: { low: [], high: [], superhigh: [] } };
    var excludeCodes = { artcc: [], tracon: [], sector: { low: [], high: [], superhigh: [] } };

    terms.forEach(function(t) {
        var target = t.negated ? excludeCodes : includeCodes;
        var classification = classifySearchTerm(t.value);
        // ... same classification logic as current, push to correct array
    });

    // Apply MapLibre filters — same layer names as current
    applyHighlightFilters('artcc-search-include', includeCodes.artcc);
    applyHighlightFilters('artcc-search-exclude', excludeCodes.artcc);
    // ... same for tracon, sector layers
}
```

`classifySearchTerm()` (lines 475-511) stays unchanged — it already classifies facility codes correctly.

- [ ] **Step 2: Verify map highlighting**

On the Playbook page:
- Type `THRU:ZDC` → ZDC ARTCC boundary should get green border on map
- Type `-THRU:ZOB` → ZOB should get red border
- Type `(THRU:ZDC & -THRU:ZOB) | ORIG:ZNY` → ZDC green, ZOB red, ZNY green
- Clear search → all highlights removed

- [ ] **Step 3: Commit**

```bash
git add assets/js/playbook.js
git commit -m "feat(playbook): update map highlighting to walk AST"
```

---

### Task 7: Update Filter Badges for AST

**Files:**
- Modify: `assets/js/playbook.js:633-678` (renderFilterBadges)

- [ ] **Step 1: Update `renderFilterBadges()` to accept AST**

Replace clause-array badge rendering with AST term collection:
```javascript
function renderFilterBadges(ast) {
    var container = $('#pb_filter_badges');
    if (!ast) { container.empty(); return; }

    var terms = PlaybookFilterParser.collectTerms(ast);
    var hasFH = typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.isLoaded;
    var html = '';

    // Group terms by their parent OR groups for display
    // Insert "OR" separator badges between top-level OR branches
    var topGroups = (ast.type === 'OR') ? ast.children : [ast];

    topGroups.forEach(function(group, gi) {
        if (gi > 0) {
            html += '<span class="pb-filter-badge pb-filter-badge-or">OR</span>';
        }
        var groupTerms = PlaybookFilterParser.collectTerms(group);
        groupTerms.forEach(function(t) {
            var prefix = '';
            if (t.qualifier === 'THRU') prefix = 'THRU: ';
            else if (t.qualifier === 'ORIG') prefix = 'ORIG: ';
            else if (t.qualifier === 'DEST') prefix = 'DEST: ';

            var label = (t.negated ? '-' : '') + prefix + t.value;

            // Region color from FacilityHierarchy (same logic as current)
            var bgStyle = '';
            if (hasFH) {
                var regionBg = FacilityHierarchy.getRegionBgColor(t.value);
                var regionColor = FacilityHierarchy.getRegionColor(t.value);
                // Sector code fallback to parent prefix (same as current)
                if (!regionBg && t.value.length > 3) { /* existing prefix logic */ }
                if (regionBg) bgStyle = 'background:' + regionBg + ';color:' + (regionColor || '#495057') + ';';
            }

            var borderColor = t.negated ? '#dc3545' : '#28a745';
            var style = bgStyle + 'border-color:' + borderColor + ';';
            var cls = 'pb-filter-badge' + (t.negated ? ' pb-filter-badge-negated' : '');
            html += '<span class="' + cls + '" style="' + style + '">' + escHtml(label) + '</span>';
        });
    });
    container.html(html);
}
```

- [ ] **Step 2: Add OR badge CSS**

Add to `playbook.css` after line 224:
```css
.pb-filter-badge-or {
    border-color: #e65100;
    background: #fff3e0;
    color: #e65100;
    font-weight: 700;
}

.pb-search-error {
    border-color: #dc3545 !important;
    box-shadow: 0 0 0 0.15rem rgba(220, 53, 69, 0.25) !important;
}
```

- [ ] **Step 3: Verify badges**

- `THRU:ZDC` → single green-bordered badge "THRU: ZDC"
- `-THRU:ZOB` → red-bordered, strikethrough badge "-THRU: ZOB"
- `(THRU:ZDC & -THRU:ZOB) | ORIG:ZNY` → badges: `THRU: ZDC`, `-THRU: ZOB`, `OR`, `ORIG: ZNY`
- Parse error `(THRU:ZDC` → no badges, red search border

- [ ] **Step 4: Commit**

```bash
git add assets/js/playbook.js assets/css/playbook.css
git commit -m "feat(playbook): update filter badges for AST + OR separators"
```

---

### Task 8: Visual Query Builder Panel — HTML + CSS

**Files:**
- Modify: `playbook.php` (add builder container + toggle button)
- Modify: `assets/css/playbook.css` (add builder overlay styles)

- [ ] **Step 1: Add builder toggle button in playbook.php**

At line 121 (after search help button), add:
```html
<button class="btn btn-sm btn-link pb-builder-toggle-btn" id="pb_builder_toggle" title="<?= __('playbook.builder.toggle') ?>">
    <i class="fas fa-project-diagram"></i>
</button>
```

- [ ] **Step 2: Add builder panel container in playbook.php**

Inside `#pb_map_section` div, before its closing `</div>` at line 167 (after the info overlay, line 166). **NOT** after line 189 which is inside `#pb_detail_section`. The builder panel must be positioned inside the map container so it uses `position: absolute` relative to the map. Insert just before line 167:
```html
<!-- Query Builder overlay -->
<div class="pb-builder-overlay" id="pb_builder_overlay" style="display:none;">
    <div class="pb-overlay-titlebar" id="pb_builder_titlebar">
        <span class="pb-overlay-title">
            <i class="fas fa-project-diagram" style="color:#f0ad4e;"></i>
            <?= __('playbook.builder.title') ?>
        </span>
        <div class="pb-overlay-controls">
            <button class="pb-overlay-minimize" id="pb_builder_close" title="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
    <div class="pb-builder-content" id="pb_builder_content">
        <!-- Populated by playbook-query-builder.js -->
    </div>
</div>
```

- [ ] **Step 3: Add builder overlay CSS**

Append to `playbook.css`:
```css
/* ── Query Builder Overlay ────────────────────────────────────── */
.pb-builder-overlay {
    position: absolute;
    top: 72px;
    left: 344px;
    width: 300px;
    max-height: calc(100% - 84px);
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(8px);
    border-radius: 8px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.18);
    z-index: 20;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.pb-builder-content {
    flex: 1;
    overflow-y: auto;
    padding: 0.5rem;
}

.pb-builder-group {
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 6px;
    margin-bottom: 6px;
    background: #fafafa;
}

.pb-builder-group-label {
    font-family: 'Inconsolata', monospace;
    font-size: 0.62rem;
    font-weight: 600;
    color: #888;
    margin-bottom: 4px;
}

.pb-builder-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 4px;
}

.pb-builder-chip {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-family: 'Inconsolata', monospace;
    font-size: 0.62rem;
    font-weight: 600;
    padding: 1px 6px;
    border-radius: 3px;
    border: 2px solid #28a745;
    background: #f8f9fa;
    color: #495057;
    white-space: nowrap;
}

.pb-builder-chip-negated {
    border-color: #dc3545;
    text-decoration: line-through;
}

.pb-builder-chip-remove {
    cursor: pointer;
    color: #aaa;
    font-size: 0.7rem;
    margin-left: 2px;
}
.pb-builder-chip-remove:hover { color: #dc3545; }

.pb-builder-or-divider {
    text-align: center;
    margin: 4px 0;
    font-family: 'Inconsolata', monospace;
    font-size: 0.68rem;
    font-weight: 700;
    color: #e65100;
}

.pb-builder-subgroup {
    border: 2px dashed #90caf9;
    border-radius: 4px;
    padding: 3px 5px;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: #fff;
}

.pb-builder-add {
    font-family: 'Inconsolata', monospace;
    font-size: 0.62rem;
    font-weight: 600;
    color: #239BCD;
    cursor: pointer;
    border: none;
    background: none;
    padding: 2px 4px;
}
.pb-builder-add:hover { text-decoration: underline; }

.pb-builder-add-group {
    text-align: center;
    padding: 6px;
    border: 1px dashed #ccc;
    border-radius: 6px;
    cursor: pointer;
    margin-top: 4px;
}
.pb-builder-add-group:hover { border-color: #239BCD; }

.pb-builder-preview {
    margin-top: 8px;
    padding: 4px 6px;
    background: #f5f5f5;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    font-family: 'Inconsolata', monospace;
    font-size: 0.6rem;
    color: #495057;
    word-break: break-all;
    cursor: pointer;
}

.pb-builder-preview-label {
    font-size: 0.55rem;
    color: #999;
    margin-bottom: 1px;
    text-transform: uppercase;
}

.pb-builder-warning {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 4px;
    padding: 4px 6px;
    margin-bottom: 6px;
    font-size: 0.62rem;
    color: #856404;
}

.pb-builder-toggle-btn {
    padding: 2px 4px;
    font-size: 0.8rem;
    color: #999;
}
.pb-builder-toggle-btn:hover { color: #239BCD; }
.pb-builder-toggle-btn.active { color: #239BCD; }

/* Responsive — stack below catalog on mobile */
@media (max-width: 767px) {
    .pb-builder-overlay {
        position: relative;
        left: auto;
        top: auto;
        width: 100%;
        max-height: none;
        margin-top: 8px;
    }
}
```

- [ ] **Step 4: Verify layout**

Load Playbook page. The builder toggle button (circuit diagram icon) should appear next to the help `?`. Clicking it should show/hide the builder panel to the right of the catalog. The panel should have the same visual weight as the info overlay on the right.

- [ ] **Step 5: Commit**

```bash
git add playbook.php assets/css/playbook.css
git commit -m "feat(playbook): add query builder panel HTML + CSS overlay"
```

---

### Task 9: Visual Query Builder — JavaScript Module

**Files:**
- Create: `assets/js/playbook-query-builder.js`
- Modify: `playbook.php` (add script include)

- [ ] **Step 1: Create the builder module with IIFE skeleton**

Create `assets/js/playbook-query-builder.js`:
- IIFE exposing `window.PlaybookQueryBuilder`
- Constructor takes `{ container, searchInput, onUpdate }` config
- `onUpdate` callback fires when builder modifies the expression

Key methods:
- `renderFromAST(ast)` — renders the AST into the builder container as groups/chips
- `buildAST()` — reads current builder state and constructs an AST
- `show()` / `hide()` / `toggle()` — panel visibility
- `destroy()` — cleanup event listeners

- [ ] **Step 2: Implement `renderFromAST(ast)`**

Walks the AST and renders:
- Top-level OR node → multiple groups separated by OR dividers
- Each AND group → bordered card with condition chips
- Each TERM → chip with qualifier prefix, FacilityHierarchy region color, include/exclude border
- Nested OR → dashed-border sub-group within a card
- NOT nodes → chip gets negated styling
- Each chip has an `x` remove button
- Each group has `+ Add condition` link
- Bottom has `+ Add OR Group` link and expression preview

- [ ] **Step 3: Implement chip interaction handlers**

- **Remove chip** (`x` click): Remove the corresponding TERM from the AST, re-serialize to text, update search bar, fire `onUpdate`
- **Add condition** (click `+ Add`): Show inline form — qualifier dropdown (`THRU`, `ORIG`, `DEST`, `FIR`, `AVOID`, `NOT THRU`, `NOT ORIG`, `NOT DEST`) + text input. On Enter/blur: parse input, add TERM to the group's AND node, re-serialize, update search bar, fire `onUpdate`
- **Add OR Group** (click `+ Add OR Group`): Add a new empty AND group to the top-level OR node, render placeholder "empty group" state
- **Qualifier dropdown on chip**: Click qualifier text → shows dropdown to change qualifier type. Changes the TERM's qualifier, re-serializes, fires `onUpdate`

- [ ] **Step 4: Implement expression preview**

At the bottom of the builder panel:
- Shows readonly text of `PlaybookFilterParser.serialize(ast)`
- Clicking it switches to an editable text input (same text)
- On blur/Enter: re-parse the edited text, update builder and search bar

- [ ] **Step 5: Wire builder into playbook.js**

In `playbook.js`, after the init section (~line 4700):
```javascript
var queryBuilder = new PlaybookQueryBuilder({
    container: '#pb_builder_content',
    searchInput: '#pb_search',
    onUpdate: function(text) {
        $('#pb_search').val(text);
        applyFilters();
    }
});

$('#pb_builder_toggle').on('click', function() {
    queryBuilder.toggle();
    $(this).toggleClass('active');
});
$('#pb_builder_close').on('click', function() {
    queryBuilder.hide();
    $('#pb_builder_toggle').removeClass('active');
});
```

In `applyFilters()`, after parsing, sync to builder:
```javascript
if (queryBuilder.isVisible()) {
    queryBuilder.renderFromAST(ast);
}
```

- [ ] **Step 6: Add script include in playbook.php**

Before the `playbook.js` script tag:
```html
<script src="assets/js/playbook-query-builder.js<?= _v('assets/js/playbook-query-builder.js') ?>"></script>
```

- [ ] **Step 7: Verify full builder workflow**

1. Type `THRU:ZDC & -THRU:ZOB` in search bar → builder shows Group 1 with two chips
2. Click `x` on `-THRU:ZOB` chip → search bar updates to `THRU:ZDC`, play list re-filters
3. Click `+ Add condition` → qualifier dropdown appears → select ORIG, type ZNY → chip added, search bar updates
4. Click `+ Add OR Group` → new empty group appears with OR divider
5. Click expression preview → edit text directly → builder updates
6. Type parse error in search bar → builder shows warning banner

- [ ] **Step 8: Commit**

```bash
git add assets/js/playbook-query-builder.js playbook.php assets/js/playbook.js
git commit -m "feat(playbook): add visual query builder panel with AST sync"
```

---

### Task 10: i18n Keys + Search Help Update

**Files:**
- Modify: `assets/locales/en-US.json`
- Modify: `assets/js/playbook.js` (search help dialog)

- [ ] **Step 1: Add i18n keys for builder UI**

Add under the `"playbook"` section in `en-US.json`:
```json
"builder": {
    "title": "Query Builder",
    "toggle": "Toggle query builder",
    "groupLabel": "Group {n}",
    "groupHint": "conditions joined with AND",
    "addCondition": "+ Add condition",
    "addSubgroup": "+ Add OR sub-group",
    "addGroup": "+ Add OR Group",
    "previewLabel": "Expression Preview",
    "previewClickHint": "Click to edit as text",
    "warningParseError": "Parse error \u2014 edit in text mode or clear",
    "qualifierThru": "THRU",
    "qualifierOrig": "ORIG",
    "qualifierDest": "DEST",
    "qualifierFir": "FIR",
    "qualifierAvoid": "AVOID",
    "qualifierNotThru": "NOT THRU",
    "qualifierNotOrig": "NOT ORIG",
    "qualifierNotDest": "NOT DEST",
    "emptyGroup": "Empty group \u2014 add a condition",
    "removeChip": "Remove condition"
}
```

- [ ] **Step 2: Update search help dialog text**

In `playbook.js` (~line 4873), update the SweetAlert search help dialog to document the new syntax:

Replace the existing help text with comprehensive documentation covering:
- Qualifiers: THRU, ORIG, DEST, FIR, AVOID, VIA
- Operators: `&`/AND, `|`/OR, `-`/NOT/`!`, `()`
- Comma semantics: THRU:X,Y = both (AND), ORIG:X,Y = either (OR)
- Space semantics: THRU:X THRU:Y = either (implicit OR), THRU:X ORIG:Y = both (implicit AND)
- Explicit mode: using `&`, `|`, or `()` makes all spaces = AND
- FIR: usage with tier names, ICAO prefixes
- Examples of common queries

Use i18n keys for all strings.

- [ ] **Step 3: Verify help dialog**

Click the `?` help button on the Playbook page. Confirm the dialog shows the new syntax documentation with all operators and examples.

- [ ] **Step 4: Commit**

```bash
git add assets/locales/en-US.json assets/js/playbook.js
git commit -m "feat(playbook): add i18n keys for builder + update search help"
```

---

### Task 11: Draggable Builder Panel + Polish

**Files:**
- Modify: `assets/js/playbook-query-builder.js`
- Modify: `assets/js/playbook.js`

- [ ] **Step 1: Add drag support to builder panel**

Reuse the existing jQuery UI draggable pattern from the catalog panel (lines 4707-4717 in playbook.js). Add the builder panel to the same `if ($.fn.draggable)` block:

```javascript
// In playbook.js, inside the existing if ($.fn.draggable) block (~line 4707):
$('#pb_builder_overlay').draggable({
    handle: '#pb_builder_titlebar',
    containment: '#pb_map_section',
    scroll: false
});
```

**Note**: The existing overlays use jQuery UI `.draggable()`, NOT raw mousedown/mousemove. Follow the same pattern.

- [ ] **Step 2: Add minimize/restore support**

The builder panel should support minimizing (collapse to just titlebar) like the catalog. Add a minimize button in the titlebar controls and toggle a `.minimized` class.

- [ ] **Step 3: Test edge cases**

- Drag builder panel around the map area
- Minimize and restore builder
- Resize browser window — builder should stay within map bounds
- Mobile responsive: builder should stack below catalog on small screens
- Empty search → builder shows "no expression" state
- Very long expression → builder scrolls properly, expression preview wraps

- [ ] **Step 4: Commit**

```bash
git add assets/js/playbook-query-builder.js assets/js/playbook.js
git commit -m "feat(playbook): draggable + minimizable builder panel"
```

---

### Task 12: Documentation & Final Verification

**Files:**
- Modify: `assets/locales/en-US.json` (if any missing keys found)
- Modify: `wiki/` or relevant docs if search syntax is documented there

- [ ] **Step 1: Verify all representative sample expressions**

Test each expression from the spec's Examples section on the live Playbook page:
```
THRU:ZDC
THRU:ZDC ORIG:ZNY
THRU:ZDC,ZID
THRU:ZDC THRU:ZID
ORIG:KJFK,KLGA
(THRU:ZDC & -THRU:ZOB & ORIG:ZNY & DEST:ZLA & -ORIG:KEWR) | (THRU:ZDC & -THRU:ZOB & (ORIG:KPHL | ORIG:KTTN) & DEST:ZOA)
THRU:ZDC THRU:ZOB & ORIG:ZNY
FIR:EUR_WEST
FIR:USA
FIR:CONUS
FIR:EG
AVOID:ZOB,ZAU
-DEST:KJFK,KLGA
ZNY
(THRU:ZDC & ORIG:ZNY    ← parse error test
FOO:ZDC                  ← unknown qualifier test
```

For each, verify:
- Correct plays shown/hidden
- Correct filter badges rendered
- Correct map highlighting (green/red borders)
- Correct route emphasis when a play is selected
- Builder panel shows correct groups/chips
- Round-trip: edit in builder → text updates → re-parse → same result

- [ ] **Step 2: Check search help documentation**

Verify the `?` help dialog covers all new syntax comprehensively.

- [ ] **Step 3: Check i18n coverage**

Verify no hardcoded English strings in the builder JS. All user-visible text should use `PERTII18n.t()`.

- [ ] **Step 4: Update CLAUDE.md if needed**

If the Playbook search syntax is referenced anywhere in CLAUDE.md or wiki docs, update those references.

- [ ] **Step 5: Final commit**

```bash
git add assets/locales/en-US.json assets/js/playbook.js assets/js/playbook-filter-parser.js assets/js/playbook-query-builder.js
git commit -m "docs(playbook): finalize compound filter documentation and verification"
```

---

## Task Dependency Graph

```
Task 1 (Tokenizer)
  → Task 2 (Parser)
    → Task 3 (Evaluator + Serializer)
      → Task 4 (FIR Resolution)
        → Task 5 (Integration into playbook.js) ← critical path
          → Task 6 (Map Highlighting)
          → Task 7 (Filter Badges)
          → Task 8 (Builder HTML + CSS)
            → Task 9 (Builder JavaScript)
              → Task 10 (i18n + Help)
                → Task 11 (Drag + Polish)
                  → Task 12 (Final Verification)
```

Tasks 6, 7, and 8 can be done in parallel after Task 5. Task 9 depends on Task 8. Tasks 10-12 are sequential at the end.
