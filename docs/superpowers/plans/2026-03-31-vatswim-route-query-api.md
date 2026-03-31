# VATSWIM Route Query API — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a unified `POST /api/swim/v1/routes/query` endpoint that returns ranked route suggestions from playbook, CDR, and historical data with boolean filter support and TMI annotations.

**Architecture:** PHP service layer (`RouteQueryService` + `RouteFilterParser`) behind a SWIM API endpoint. Pre-aggregated historical stats in `swim_route_stats` table synced daily from MySQL star schema. Filter parser is a PHP port of the existing JS `playbook-filter-parser.js`.

**Tech Stack:** PHP 8.2, Azure SQL (sqlsrv), MySQL (PDO), PostGIS (PDO pgsql), APCu caching

**Spec:** `docs/superpowers/specs/2026-03-30-vatswim-route-query-api-design.md`

---

## File Structure

| Action | File | Responsibility |
|--------|------|---------------|
| Create | `database/migrations/swim/034_swim_route_stats.sql` | DDL for pre-aggregated historical route stats |
| Create | `load/services/RouteFilterParser.php` | PHP port of boolean filter parser (tokenize, parse, evaluate) |
| Create | `load/services/RouteQueryService.php` | Core query orchestrator (source fan-out, merge, rank, enrich) |
| Create | `api/swim/v1/routes/query.php` | API endpoint (auth, validation, response formatting) |
| Modify | `scripts/swim_tmi_sync_daemon.php` | Add route stats sync phase to Tier 2 |
| Modify | `api/swim/v1/index.php` | Register new + previously unlisted endpoints |

---

### Task 1: Migration — `swim_route_stats` Table

**Files:**
- Create: `database/migrations/swim/034_swim_route_stats.sql`

- [ ] **Step 1: Write the migration SQL**

```sql
-- Migration 034: swim_route_stats — pre-aggregated historical route statistics
-- Source: MySQL perti_site.route_history_facts + dim_route + dim_aircraft_type + dim_operator
-- Sync: swim_tmi_sync_daemon.php Tier 2, daily full replace

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'swim_route_stats')
BEGIN
    CREATE TABLE dbo.swim_route_stats (
        stat_id          INT IDENTITY(1,1) PRIMARY KEY,
        origin_icao      NVARCHAR(4)   NOT NULL,
        dest_icao        NVARCHAR(4)   NOT NULL,
        route_hash       BINARY(16)    NOT NULL,
        normalized_route NVARCHAR(MAX) NOT NULL,
        flight_count     INT           NOT NULL,
        usage_pct        DECIMAL(5,2)  NOT NULL,
        avg_altitude_ft  INT           NULL,
        common_aircraft  NVARCHAR(200) NULL,
        common_operators NVARCHAR(200) NULL,
        first_seen       DATE          NOT NULL,
        last_seen        DATE          NOT NULL,
        last_sync_utc    DATETIME2(0)  NOT NULL DEFAULT SYSUTCDATETIME()
    );

    CREATE UNIQUE INDEX IX_route_stats_pair_hash
        ON dbo.swim_route_stats(origin_icao, dest_icao, route_hash);

    CREATE INDEX IX_route_stats_pair_count
        ON dbo.swim_route_stats(origin_icao, dest_icao, flight_count DESC)
        INCLUDE (normalized_route, usage_pct, last_seen);

    PRINT 'Created swim_route_stats with indexes';
END
ELSE
    PRINT 'swim_route_stats already exists — skipping';
GO
```

- [ ] **Step 2: Commit migration**

```bash
git add database/migrations/swim/034_swim_route_stats.sql
git commit -m "feat(swim): add swim_route_stats migration 034"
```

---

### Task 2: RouteFilterParser — PHP Port of Boolean Filter Parser

**Files:**
- Create: `load/services/RouteFilterParser.php`
- Reference: `assets/js/playbook-filter-parser.js` (810 lines — the authoritative grammar)

This is a direct PHP port of the JS recursive descent parser. The grammar, tokenizer, AST node types, comma semantics, implicit/explicit mode detection, and FIR resolution must match exactly.

- [ ] **Step 1: Create RouteFilterParser.php with tokenizer**

```php
<?php
/**
 * RouteFilterParser — PHP port of playbook-filter-parser.js
 *
 * Recursive descent boolean parser for route filter expressions.
 * Supports qualifiers (THRU:, ORIG:, DEST:, VIA:, FIR:, AVOID:),
 * boolean operators (AND/OR/NOT), grouping, and FIR tier resolution.
 *
 * Grammar (explicit mode):
 *   expression  ::= or_expr
 *   or_expr     ::= and_expr (('|' | 'OR') and_expr)*
 *   and_expr    ::= unary (('&' | 'AND' | <space>) unary)*
 *   unary       ::= ('NOT' | '-' | '!') unary | primary
 *   primary     ::= '(' expression ')' | qualifier ':' value_list | bare_term
 *   value_list  ::= value (',' value)*
 *
 * Implicit mode: space between same-qualifier terms = OR (post-parse rewrite).
 *
 * @see assets/js/playbook-filter-parser.js
 */

namespace PERTI\Services;

class RouteFilterParser
{
    // Token types
    private const T_LPAREN    = 'LPAREN';
    private const T_RPAREN    = 'RPAREN';
    private const T_AND       = 'AND';
    private const T_OR        = 'OR';
    private const T_NOT       = 'NOT';
    private const T_COMMA     = 'COMMA';
    private const T_COLON     = 'COLON';
    private const T_TERM      = 'TERM';
    private const T_QUALIFIER = 'QUALIFIER';
    private const T_EOF       = 'EOF';

    private const QUALIFIERS = ['THRU' => 1, 'VIA' => 1, 'ORIG' => 1, 'DEST' => 1, 'FIR' => 1, 'AVOID' => 1];
    private const MULTI_VALUED = ['THRU' => 1, 'VIA' => 1, 'FIR' => 1, 'AVOID' => 1];

    /** @var array|null FIR tier data from fir_tiers.json */
    private static ?array $firTierData = null;
    /** @var array|null Flat lookup: tierCode → tier entry */
    private static ?array $firTierLookup = null;
    /** @var array Known ARTCC codes for pattern expansion */
    private static array $knownARTCCs = [];

    // Parser state
    private array $tokens = [];
    private int $pos = 0;

    /**
     * Parse a filter expression string into an AST.
     *
     * @param string $input Filter expression (e.g., "THRU:ZDC & ORIG:KJFK")
     * @return array ['ast' => array|null, 'error' => array|null]
     */
    public function parse(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return ['ast' => null, 'error' => null];
        }

        try {
            $this->tokens = $this->tokenize($input);
        } catch (\Throwable $e) {
            return ['ast' => null, 'error' => ['message' => $e->getMessage(), 'pos' => 0]];
        }

        $explicitMode = $this->detectExplicitMode();
        $this->pos = 0;

        try {
            $ast = $this->parseOrExpr();

            // Consume trailing content as AND
            while ($this->peek()['type'] !== self::T_EOF) {
                if ($this->peek()['type'] === self::T_RPAREN) {
                    throw new \RuntimeException("Unexpected ')'");
                }
                $more = $this->parseOrExpr();
                $ast = $this->mergeNode('AND', $ast, $more);
            }

            if (!$explicitMode) {
                $ast = $this->rewriteImplicitMode($ast);
            }
            $ast = $this->cleanAST($ast);

            return ['ast' => $ast, 'error' => null];
        } catch (\Throwable $e) {
            return ['ast' => null, 'error' => ['message' => $e->getMessage(), 'pos' => 0]];
        }
    }

    /**
     * Evaluate an AST against a route's search index.
     *
     * @param array|null $node AST node
     * @param array $index ['originCodes' => [...], 'destCodes' => [...], 'thruCodes' => [...], 'allCodes' => [...], 'searchText' => '...']
     * @return bool
     */
    public static function evaluate(?array $node, array $index): bool
    {
        if ($node === null) return true;

        switch ($node['type']) {
            case 'OR':
                foreach ($node['children'] as $child) {
                    if (self::evaluate($child, $index)) return true;
                }
                return false;
            case 'AND':
                foreach ($node['children'] as $child) {
                    if (!self::evaluate($child, $index)) return false;
                }
                return true;
            case 'NOT':
                return !self::evaluate($node['child'], $index);
            case 'TERM':
                return self::evaluateTerm($node, $index);
            default:
                return true;
        }
    }

    private static function evaluateTerm(array $node, array $index): bool
    {
        $val = $node['value'];
        switch ($node['qualifier'] ?? null) {
            case 'THRU':
                return in_array($val, $index['thruCodes'], true);
            case 'ORIG':
                return in_array($val, $index['originCodes'], true);
            case 'DEST':
                return in_array($val, $index['destCodes'], true);
            case null:
                // Bare term: check all codes + text search
                return in_array($val, $index['allCodes'], true)
                    || (isset($index['searchText']) && stripos($index['searchText'], $val) !== false);
            default:
                return false;
        }
    }

    /**
     * Collect all TERM nodes from AST.
     * @return array of ['qualifier' => string|null, 'value' => string, 'negated' => bool]
     */
    public static function collectTerms(?array $node, bool $negated = false): array
    {
        if ($node === null) return [];

        switch ($node['type']) {
            case 'OR':
            case 'AND':
                $result = [];
                foreach ($node['children'] as $child) {
                    $result = array_merge($result, self::collectTerms($child, $negated));
                }
                return $result;
            case 'NOT':
                return self::collectTerms($node['child'], !$negated);
            case 'TERM':
                if ($node['qualifier'] === null) return [];
                return [['qualifier' => $node['qualifier'], 'value' => $node['value'], 'negated' => $negated]];
            default:
                return [];
        }
    }

    // =========================================================================
    // TOKENIZER
    // =========================================================================

    private function tokenize(string $input): array
    {
        $tokens = [];
        $i = 0;
        $len = strlen($input);
        $hadSpace = false;

        while ($i < $len) {
            $ch = $input[$i];

            // Whitespace
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $hadSpace = true;
                $i++;
                continue;
            }

            // Implicit AND from whitespace
            if ($hadSpace && count($tokens) > 0) {
                $lastType = $tokens[count($tokens) - 1]['type'];
                if ($lastType === self::T_TERM || $lastType === self::T_RPAREN) {
                    if ($ch !== '&' && $ch !== '|' && $ch !== ')') {
                        $upperRest = strtoupper(substr($input, $i));
                        if (strpos($upperRest, 'OR ') !== 0 && strpos($upperRest, "OR\t") !== 0 &&
                            strpos($upperRest, 'AND ') !== 0 && strpos($upperRest, "AND\t") !== 0) {
                            $tokens[] = ['type' => self::T_AND, 'value' => ' ', 'pos' => $i, 'implicit' => true];
                        }
                    }
                }
            }
            $hadSpace = false;

            // Single-character tokens
            if ($ch === '(') { $tokens[] = ['type' => self::T_LPAREN, 'value' => '(', 'pos' => $i]; $i++; continue; }
            if ($ch === ')') { $tokens[] = ['type' => self::T_RPAREN, 'value' => ')', 'pos' => $i]; $i++; continue; }
            if ($ch === '&') { $tokens[] = ['type' => self::T_AND, 'value' => '&', 'pos' => $i]; $i++; continue; }
            if ($ch === '|') { $tokens[] = ['type' => self::T_OR, 'value' => '|', 'pos' => $i]; $i++; continue; }
            if ($ch === ',') { $tokens[] = ['type' => self::T_COMMA, 'value' => ',', 'pos' => $i]; $i++; continue; }
            if ($ch === '-') { $tokens[] = ['type' => self::T_NOT, 'value' => '-', 'pos' => $i]; $i++; continue; }
            if ($ch === '!') { $tokens[] = ['type' => self::T_NOT, 'value' => '!', 'pos' => $i]; $i++; continue; }

            // Words
            if ($this->isWordChar($ch)) {
                $start = $i;
                while ($i < $len && $this->isWordChar($input[$i])) $i++;
                $word = substr($input, $start, $i - $start);
                $upper = strtoupper($word);

                if ($upper === 'AND') { $tokens[] = ['type' => self::T_AND, 'value' => $upper, 'pos' => $start]; continue; }
                if ($upper === 'OR')  { $tokens[] = ['type' => self::T_OR, 'value' => $upper, 'pos' => $start]; continue; }
                if ($upper === 'NOT') { $tokens[] = ['type' => self::T_NOT, 'value' => $upper, 'pos' => $start]; continue; }

                if ($i < $len && $input[$i] === ':' && isset(self::QUALIFIERS[$upper])) {
                    $tokens[] = ['type' => self::T_QUALIFIER, 'value' => $upper, 'pos' => $start];
                    $tokens[] = ['type' => self::T_COLON, 'value' => ':', 'pos' => $i];
                    $i++; // skip colon
                    continue;
                }

                $tokens[] = ['type' => self::T_TERM, 'value' => $upper, 'pos' => $start];
                continue;
            }

            // Standalone colon or unknown — skip
            $i++;
        }

        $tokens[] = ['type' => self::T_EOF, 'value' => '', 'pos' => $len];
        return $tokens;
    }

    private function isWordChar(string $ch): bool
    {
        $c = ord($ch);
        return ($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122) || // A-Z, a-z
               ($c >= 48 && $c <= 57) ||                             // 0-9
               $ch === '_' || $ch === '*';
    }

    // =========================================================================
    // RECURSIVE DESCENT PARSER
    // =========================================================================

    private function peek(): array
    {
        return $this->tokens[$this->pos] ?? $this->tokens[count($this->tokens) - 1];
    }

    private function advance(): array
    {
        return $this->tokens[$this->pos++];
    }

    private function expect(string $type): array
    {
        $tok = $this->peek();
        if ($tok['type'] !== $type) {
            throw new \RuntimeException("Expected '{$type}' but got '{$tok['value']}'");
        }
        return $this->advance();
    }

    private function parseOrExpr(): array
    {
        $left = $this->parseAndExpr();
        while ($this->peek()['type'] === self::T_OR) {
            $this->advance();
            $right = $this->parseAndExpr();
            $left = $this->mergeNode('OR', $left, $right);
        }
        return $left;
    }

    private function parseAndExpr(): array
    {
        $left = $this->parseUnary();
        while (true) {
            $tok = $this->peek();
            if ($tok['type'] === self::T_AND) {
                $this->advance();
                $right = $this->parseUnary();
                $left = $this->mergeNode('AND', $left, $right);
            } elseif (in_array($tok['type'], [self::T_NOT, self::T_QUALIFIER, self::T_TERM, self::T_LPAREN], true)) {
                $right = $this->parseUnary();
                $left = $this->mergeNode('AND', $left, $right);
            } else {
                break;
            }
        }
        return $left;
    }

    private function parseUnary(): array
    {
        $tok = $this->peek();
        if ($tok['type'] === self::T_NOT) {
            $this->advance();
            $child = $this->parseUnary();
            // Double negation cancels
            if ($child['type'] === 'NOT') return $child['child'];
            // Distribute NOT over comma-AND
            if ($child['type'] === 'AND' && !empty($child['_fromComma'])) {
                return [
                    'type' => 'AND',
                    'children' => array_map(fn($c) => ['type' => 'NOT', 'child' => $c], $child['children']),
                    '_fromComma' => true,
                ];
            }
            return ['type' => 'NOT', 'child' => $child];
        }
        return $this->parsePrimary();
    }

    private function parsePrimary(): array
    {
        $tok = $this->peek();

        // Grouped expression
        if ($tok['type'] === self::T_LPAREN) {
            $this->advance();
            if ($this->peek()['type'] === self::T_RPAREN) {
                $this->advance();
                return ['type' => 'AND', 'children' => []];
            }
            $expr = $this->parseOrExpr();
            $this->expect(self::T_RPAREN);
            return $expr;
        }

        // Qualifier:value_list
        if ($tok['type'] === self::T_QUALIFIER) {
            $qualTok = $this->advance();
            $this->advance(); // colon
            return $this->parseValueList($qualTok['value']);
        }

        // Bare term
        if ($tok['type'] === self::T_TERM) {
            $termTok = $this->advance();
            $val = $termTok['value'];
            return [
                'type' => 'OR',
                'children' => [
                    ['type' => 'TERM', 'qualifier' => 'THRU', 'value' => $val],
                    ['type' => 'TERM', 'qualifier' => 'ORIG', 'value' => $val],
                    ['type' => 'TERM', 'qualifier' => 'DEST', 'value' => $val],
                    ['type' => 'TERM', 'qualifier' => null, 'value' => $val],
                ],
                '_unqualified' => true,
                '_rawValue' => $val,
            ];
        }

        // EOF or unexpected
        if ($tok['type'] === self::T_EOF) {
            return ['type' => 'AND', 'children' => []];
        }
        $this->advance(); // skip unexpected
        return $this->parsePrimary();
    }

    private function parseValueList(string $qualifier): array
    {
        $normQual = $qualifier;
        if ($normQual === 'VIA') $normQual = 'THRU';

        $isAvoid = ($qualifier === 'AVOID');
        if ($isAvoid) $normQual = 'THRU';

        $isMultiValued = isset(self::MULTI_VALUED[$qualifier]);

        // Read first value
        $values = [];
        if ($this->peek()['type'] === self::T_TERM) {
            $values[] = $this->advance()['value'];
        } else {
            return ['type' => 'AND', 'children' => []];
        }

        // Comma-separated additional values
        while ($this->peek()['type'] === self::T_COMMA) {
            $this->advance();
            if ($this->peek()['type'] === self::T_TERM) {
                $values[] = $this->advance()['value'];
            }
        }

        // FIR resolution
        if ($qualifier === 'FIR') {
            return $this->expandFIRValues($values, $isMultiValued);
        }

        // Build AST subtree
        $terms = array_map(function ($v) use ($normQual, $isAvoid) {
            $node = ['type' => 'TERM', 'qualifier' => $normQual, 'value' => $v];
            if ($isAvoid) $node = ['type' => 'NOT', 'child' => $node];
            return $node;
        }, $values);

        if (count($terms) === 1) return $terms[0];

        if ($isMultiValued) {
            return ['type' => 'AND', 'children' => $terms, '_fromComma' => true];
        } else {
            return ['type' => 'OR', 'children' => $terms];
        }
    }

    // =========================================================================
    // IMPLICIT MODE
    // =========================================================================

    private function detectExplicitMode(): bool
    {
        foreach ($this->tokens as $t) {
            if ($t['type'] === self::T_LPAREN || $t['type'] === self::T_RPAREN) return true;
            if ($t['type'] === self::T_OR) return true;
            if ($t['type'] === self::T_AND && empty($t['implicit'])) return true;
        }
        return false;
    }

    private function rewriteImplicitMode(?array $node): ?array
    {
        if ($node === null) return null;

        if ($node['type'] === 'AND' && isset($node['children'])) {
            $node['children'] = array_map([$this, 'rewriteImplicitMode'], $node['children']);
            if (!empty($node['_fromComma'])) return $node;

            $groups = [];
            $order = [];
            $nonTerms = [];

            foreach ($node['children'] as $child) {
                $qual = $this->getTermQualifier($child);
                if ($qual !== null) {
                    if (!isset($groups[$qual])) {
                        $groups[$qual] = [];
                        $order[] = $qual;
                    }
                    $groups[$qual][] = $child;
                } else {
                    $nonTerms[] = $child;
                }
            }

            $newChildren = [];
            foreach ($order as $qual) {
                $members = $groups[$qual];
                if (count($members) > 1) {
                    $newChildren[] = ['type' => 'OR', 'children' => $members];
                } else {
                    $newChildren[] = $members[0];
                }
            }
            $newChildren = array_merge($newChildren, $nonTerms);

            if (count($newChildren) === 1) return $newChildren[0];
            $node['children'] = $newChildren;
            return $node;
        }

        if ($node['type'] === 'OR' && isset($node['children'])) {
            $node['children'] = array_map([$this, 'rewriteImplicitMode'], $node['children']);
        }
        if ($node['type'] === 'NOT' && isset($node['child'])) {
            $node['child'] = $this->rewriteImplicitMode($node['child']);
        }

        return $node;
    }

    private function getTermQualifier(array $node): ?string
    {
        if ($node['type'] === 'TERM') return $node['qualifier'] ?? '_unqualified';
        if ($node['type'] === 'NOT' && isset($node['child']) && $node['child']['type'] === 'TERM') {
            return ($node['child']['qualifier'] ?? '_unqualified') . '_NOT';
        }
        if ($node['type'] === 'OR' && !empty($node['_unqualified'])) return '_unqualified';
        return null;
    }

    // =========================================================================
    // AST UTILITIES
    // =========================================================================

    private function mergeNode(string $type, array $left, array $right): array
    {
        $children = [];
        if ($left['type'] === $type && isset($left['children']) && empty($left['_fromComma'])) {
            $children = array_merge($children, $left['children']);
        } else {
            $children[] = $left;
        }
        if ($right['type'] === $type && isset($right['children']) && empty($right['_fromComma'])) {
            $children = array_merge($children, $right['children']);
        } else {
            $children[] = $right;
        }
        return ['type' => $type, 'children' => $children];
    }

    private function cleanAST(?array $node): ?array
    {
        if ($node === null) return null;

        if ($node['type'] === 'AND' || $node['type'] === 'OR') {
            if (!isset($node['children'])) return null;
            $node['children'] = array_values(array_filter(
                array_map([$this, 'cleanAST'], $node['children']),
                fn($c) => $c !== null
            ));
            $node['children'] = array_values(array_filter($node['children'], function ($c) {
                return !(($c['type'] === 'AND' || $c['type'] === 'OR') && isset($c['children']) && count($c['children']) === 0);
            }));
            if (count($node['children']) === 0) return null;
            if (count($node['children']) === 1) return $node['children'][0];
        }

        if ($node['type'] === 'NOT') {
            $node['child'] = $this->cleanAST($node['child']);
            if ($node['child'] === null) return null;
        }

        return $node;
    }

    // =========================================================================
    // FIR RESOLUTION
    // =========================================================================

    /**
     * Load FIR tier data from fir_tiers.json.
     * Call once at startup or on first use.
     */
    public static function loadFIRTiers(string $jsonPath = null): void
    {
        if (self::$firTierData !== null) return;

        $path = $jsonPath ?? dirname(__DIR__, 2) . '/assets/data/fir_tiers.json';
        if (!file_exists($path)) {
            self::$firTierData = [];
            self::$firTierLookup = [];
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        self::$firTierData = $data ?: [];
        self::buildFIRLookup($data);
    }

    /**
     * Set known ARTCC codes for pattern expansion.
     * @param array $codes e.g., ['ZNY', 'ZDC', 'ZBW', ...]
     */
    public static function setKnownARTCCs(array $codes): void
    {
        self::$knownARTCCs = array_map('strtoupper', $codes);
    }

    private static function buildFIRLookup(?array $data): void
    {
        self::$firTierLookup = [];
        if (!$data) return;
        foreach (['global', 'regional', 'country'] as $section) {
            $entries = $data[$section] ?? [];
            foreach ($entries as $key => $entry) {
                self::$firTierLookup[strtoupper($key)] = $entry;
            }
        }
    }

    /**
     * Resolve a FIR value to ARTCC codes.
     */
    public static function resolveFIR(string $value): array
    {
        self::loadFIRTiers();
        $upper = strtoupper($value);

        // 1. Check tier lookup
        if (self::$firTierLookup) {
            $entry = self::$firTierLookup[$upper] ?? null;
            if ($entry) {
                if (isset($entry['alias'])) {
                    $target = self::$firTierLookup[strtoupper($entry['alias'])] ?? null;
                    if ($target) $entry = $target;
                    else return [];
                }
                if (isset($entry['members'])) return $entry['members'];
                if (isset($entry['patterns'])) return self::expandPatterns($entry['patterns']);
            }
        }

        // 2. ICAO prefix match
        $prefixMatches = array_filter(self::$knownARTCCs, fn($code) => str_starts_with($code, $upper) && $code !== $upper);
        if (!empty($prefixMatches)) return array_values($prefixMatches);

        // 3. Exact match
        if (in_array($upper, self::$knownARTCCs, true)) return [$upper];

        return [];
    }

    private static function expandPatterns(array $patterns): array
    {
        $result = [];
        $seen = [];
        foreach ($patterns as $pat) {
            $pat = strtoupper($pat);
            if (str_ends_with($pat, '*')) {
                $prefix = substr($pat, 0, -1);
                foreach (self::$knownARTCCs as $code) {
                    if (str_starts_with($code, $prefix) && !isset($seen[$code])) {
                        $result[] = $code;
                        $seen[$code] = true;
                    }
                }
            } elseif (!isset($seen[$pat])) {
                $result[] = $pat;
                $seen[$pat] = true;
            }
        }
        return $result;
    }

    private function expandFIRValues(array $values, bool $isMultiValued): array
    {
        $allNodes = [];
        foreach ($values as $val) {
            $codes = self::resolveFIR($val);
            if (empty($codes)) {
                $allNodes[] = ['type' => 'TERM', 'qualifier' => 'THRU', 'value' => '_FIR_UNRESOLVED_' . $val];
            } elseif (count($codes) === 1) {
                $allNodes[] = ['type' => 'TERM', 'qualifier' => 'THRU', 'value' => $codes[0]];
            } else {
                $allNodes[] = [
                    'type' => 'OR',
                    'children' => array_map(fn($c) => ['type' => 'TERM', 'qualifier' => 'THRU', 'value' => $c], $codes),
                ];
            }
        }

        if (empty($allNodes)) return ['type' => 'AND', 'children' => []];
        if (count($allNodes) === 1) return $allNodes[0];
        if ($isMultiValued) {
            return ['type' => 'AND', 'children' => $allNodes];
        }
        return ['type' => 'OR', 'children' => $allNodes];
    }
}
```

- [ ] **Step 2: Verify parser matches JS behavior**

Manually verify these expressions parse identically to the JS version:

| Expression | Expected AST |
|-----------|-------------|
| `THRU:ZDC` | `{type:TERM, qualifier:THRU, value:ZDC}` |
| `THRU:ZDC,ZOB` | `{type:AND, children:[THRU:ZDC, THRU:ZOB], _fromComma:true}` |
| `ORIG:KJFK,KEWR` | `{type:OR, children:[ORIG:KJFK, ORIG:KEWR]}` |
| `THRU:ZDC & -THRU:ZBW` | `{type:AND, children:[THRU:ZDC, NOT(THRU:ZBW)]}` |
| `(ORIG:KJFK \| ORIG:KEWR) & DEST:KLAX` | `{type:AND, children:[OR(ORIG:KJFK, ORIG:KEWR), DEST:KLAX]}` |
| `AVOID:ZBW` | `{type:NOT, child:THRU:ZBW}` |

Add a temporary test at the bottom of the file (remove before commit):
```php
// Quick smoke test — remove before commit
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    $parser = new RouteFilterParser();
    $tests = [
        'THRU:ZDC',
        'THRU:ZDC,ZOB',
        'ORIG:KJFK,KEWR',
        'THRU:ZDC & -THRU:ZBW',
        '(ORIG:KJFK | ORIG:KEWR) & DEST:KLAX',
        'AVOID:ZBW',
    ];
    foreach ($tests as $expr) {
        $result = $parser->parse($expr);
        echo "$expr => " . json_encode($result['ast'], JSON_PRETTY_PRINT) . "\n\n";
    }
}
```

Run: `php load/services/RouteFilterParser.php`

- [ ] **Step 3: Remove smoke test and commit**

Remove the CLI test block, then:

```bash
git add load/services/RouteFilterParser.php
git commit -m "feat(swim): add RouteFilterParser — PHP port of playbook boolean filter parser"
```

---

### Task 3: RouteQueryService — Core Query Orchestrator

**Files:**
- Create: `load/services/RouteQueryService.php`

- [ ] **Step 1: Create RouteQueryService with facility token resolution**

```php
<?php
/**
 * RouteQueryService — Unified route query across playbook, CDR, and historical sources.
 *
 * Orchestrates: facility token resolution → source queries → filter evaluation →
 * TMI annotation → merge/dedup → ranking → enrichment → response assembly.
 *
 * @see docs/superpowers/specs/2026-03-30-vatswim-route-query-api-design.md
 */

namespace PERTI\Services;

require_once __DIR__ . '/RouteFilterParser.php';

class RouteQueryService
{
    private $connSwim;   // sqlsrv — SWIM_API database
    private $connTmi;    // sqlsrv — VATSIM_TMI database
    private $connPdo;    // PDO — MySQL perti_site (for TRACON→airport expansion)

    // Facility token type constants
    private const FACILITY_AIRPORT = 'airport';
    private const FACILITY_ARTCC   = 'artcc';
    private const FACILITY_TRACON  = 'tracon';

    public function __construct($connSwim, $connTmi = null, $connPdo = null)
    {
        $this->connSwim = $connSwim;
        $this->connTmi = $connTmi;
        $this->connPdo = $connPdo;
    }

    /**
     * Execute a route query.
     *
     * @param array $request Validated request body
     * @return array Response data (results, summary, warnings)
     */
    public function query(array $request): array
    {
        $startTime = microtime(true);
        $warnings = [];

        $origins = $this->normalizeTokens($request['origin'] ?? null);
        $destinations = $this->normalizeTokens($request['destination'] ?? null);
        $sources = $request['sources'] ?? ['playbook', 'cdr', 'historical'];
        $filterExpr = $request['filter'] ?? null;
        $filters = $request['filters'] ?? [];
        $context = $request['context'] ?? [];
        $includes = $request['include'] ?? [];
        $sort = $request['sort'] ?? 'score';
        $limit = $request['limit'] ?? 20;
        $offset = $request['offset'] ?? 0;

        // Parse filter expression if provided
        $filterAST = null;
        if ($filterExpr !== null && $filterExpr !== '') {
            $parser = new RouteFilterParser();
            $parsed = $parser->parse($filterExpr);
            if ($parsed['error'] !== null) {
                return ['error' => 'Filter parse error: ' . $parsed['error']['message'], 'http_code' => 400];
            }
            $filterAST = $parsed['ast'];
        }

        // Classify facility tokens
        $originTokens = array_map([$this, 'classifyToken'], $origins);
        $destTokens = array_map([$this, 'classifyToken'], $destinations);

        // Query each source
        $allResults = [];
        $sourceCounts = [];

        if (in_array('playbook', $sources, true)) {
            $pbResults = $this->queryPlaybook($originTokens, $destTokens, $filters, $filterAST);
            $sourceCounts['playbook'] = count($pbResults);
            $allResults = array_merge($allResults, $pbResults);
        }

        if (in_array('cdr', $sources, true)) {
            $cdrResults = $this->queryCDR($originTokens, $destTokens, $filters);
            $sourceCounts['cdr'] = count($cdrResults);
            $allResults = array_merge($allResults, $cdrResults);
        }

        if (in_array('historical', $sources, true)) {
            $histResults = $this->queryHistorical($originTokens, $destTokens);
            $sourceCounts['historical'] = count($histResults);
            $allResults = array_merge($allResults, $histResults);
        }

        // Apply filter expression to CDR/historical results (playbook already filtered)
        if ($filterAST !== null) {
            $allResults = $this->applyFilterAST($allResults, $filterAST);
        }

        // TMI annotation
        $tmiFlags = [];
        if (!empty($context['include_active_tmis'])) {
            $tmiFlags = $this->fetchActiveTMIs($originTokens, $destTokens, $context);
            if ($tmiFlags === null) {
                $warnings[] = 'tmi_data_unavailable';
                $tmiFlags = [];
            }
        }

        // Merge and deduplicate
        $merged = $this->mergeAndDedup($allResults);

        // Attach TMI flags
        if (!empty($tmiFlags)) {
            $merged = $this->attachTMIFlags($merged, $tmiFlags);
        }

        // Rank
        $ranked = $this->rank($merged, $sort, !empty($context['include_active_tmis']));

        // Paginate
        $totalResults = count($ranked);
        $paged = array_slice($ranked, $offset, $limit);

        // Enrich (geometry, traversal, statistics)
        if (!empty($includes)) {
            $paged = $this->enrich($paged, $includes, $warnings);
        }

        // Assign ranks
        foreach ($paged as $i => &$row) {
            $row['rank'] = $offset + $i + 1;
        }
        unset($row);

        $queryTimeMs = (int)round((microtime(true) - $startTime) * 1000);

        return [
            'query' => [
                'origin' => $request['origin'] ?? null,
                'destination' => $request['destination'] ?? null,
                'filter' => $filterExpr,
                'sources_queried' => $sources,
            ],
            'results' => array_values($paged),
            'summary' => [
                'total_results' => $totalResults,
                'returned' => count($paged),
                'offset' => $offset,
                'sources_hit' => $sourceCounts,
                'active_tmis' => count($tmiFlags),
                'query_time_ms' => $queryTimeMs,
            ],
            'warnings' => $warnings,
        ];
    }

    // =========================================================================
    // FACILITY TOKEN RESOLUTION
    // =========================================================================

    private function normalizeTokens($value): array
    {
        if ($value === null) return [];
        if (is_string($value)) return [strtoupper(trim($value))];
        if (is_array($value)) return array_map(fn($v) => strtoupper(trim($v)), $value);
        return [];
    }

    /**
     * Classify a facility token as airport, ARTCC, or TRACON.
     * @return array ['code' => string, 'type' => string]
     */
    private function classifyToken(string $code): array
    {
        $len = strlen($code);

        // 4-char = airport ICAO
        if ($len === 4) {
            return ['code' => $code, 'type' => self::FACILITY_AIRPORT];
        }

        // 3-char starting with Z = ARTCC
        if ($len === 3 && $code[0] === 'Z') {
            return ['code' => $code, 'type' => self::FACILITY_ARTCC];
        }

        // 3-char = could be TRACON or FAA LID airport
        // TRACONs: N90, PCT, SCT, A80, C90, D10, I90, L30, P50, etc.
        // FAA LIDs: JFK, LAX, ORD, etc.
        // Heuristic: codes with digits are usually TRACONs
        if ($len === 3) {
            if (preg_match('/\d/', $code)) {
                return ['code' => $code, 'type' => self::FACILITY_TRACON];
            }
            // 3-letter alpha = FAA LID airport, resolve to ICAO
            return ['code' => $code, 'type' => self::FACILITY_AIRPORT];
        }

        // 2-char = likely ARTCC or FIR prefix, treat as ARTCC
        if ($len === 2) {
            return ['code' => $code, 'type' => self::FACILITY_ARTCC];
        }

        // Default: treat as airport
        return ['code' => $code, 'type' => self::FACILITY_AIRPORT];
    }

    // =========================================================================
    // SOURCE QUERIES
    // =========================================================================

    private function queryPlaybook(array $originTokens, array $destTokens, array $filters, ?array $filterAST): array
    {
        // Build SQL with text filter in WHERE (for performance)
        $where = ["p.status = 'active'", "p.visibility = 'public'"];
        $params = [];

        $textFilter = trim($filters['text'] ?? '');
        if ($textFilter !== '') {
            $where[] = "(r.route_string LIKE '%' + ? + '%' OR p.play_name LIKE '%' + ? + '%' OR r.remarks LIKE '%' + ? + '%')";
            $params[] = $textFilter;
            $params[] = $textFilter;
            $params[] = $textFilter;
        }

        $sql = "
            SELECT r.route_id, r.route_string, r.origin, r.dest,
                   r.origin_airports, r.dest_airports,
                   r.origin_artccs, r.dest_artccs, r.origin_tracons, r.dest_tracons,
                   r.traversed_artccs, r.traversed_tracons,
                   r.route_geometry, r.remarks, r.sort_order,
                   p.play_id, p.play_name, p.display_name, p.category, p.source AS play_source
            FROM dbo.swim_playbook_routes r
            JOIN dbo.swim_playbook_plays p ON r.play_id = p.play_id
            WHERE " . implode(' AND ', $where);

        $stmt = sqlsrv_query($this->connSwim, $sql, $params);
        if ($stmt === false) return [];

        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Apply origin/dest token filtering in PHP (word-boundary CSV matching)
            if (!empty($originTokens) && !$this->matchesTokens($row, $originTokens, 'origin')) continue;
            if (!empty($destTokens) && !$this->matchesTokens($row, $destTokens, 'dest')) continue;

            // Apply filter AST evaluation
            if ($filterAST !== null) {
                $index = $this->buildRouteIndex($row);
                if (!RouteFilterParser::evaluate($filterAST, $index)) continue;
            }

            $results[] = [
                'source' => 'playbook',
                'route_string' => $this->normalizeRouteString($row['route_string']),
                '_raw_route' => $row['route_string'],
                'metadata' => [
                    'play_name' => $row['play_name'],
                    'play_id' => (int)$row['play_id'],
                    'display_name' => $row['display_name'],
                    'category' => $row['category'],
                    'cdr_code' => null,
                    'distance_nm' => $this->extractDistanceFromGeometry($row['route_geometry']),
                    'direction' => null,
                ],
                'statistics' => null,
                'tmi_flags' => [],
                'traversal' => $this->parseCSVTraversal($row),
                '_route_geometry_json' => $row['route_geometry'],
                '_traversed_artccs' => $this->parseCSV($row['traversed_artccs'] ?? ''),
            ];
        }
        sqlsrv_free_stmt($stmt);

        return $results;
    }

    private function queryCDR(array $originTokens, array $destTokens, array $filters): array
    {
        $where = ['is_active = 1'];
        $params = [];

        // Build origin WHERE from tokens
        $originWhere = $this->buildCDRTokenWhere($originTokens, 'origin', $params);
        if ($originWhere) $where[] = $originWhere;

        $destWhere = $this->buildCDRTokenWhere($destTokens, 'dest', $params);
        if ($destWhere) $where[] = $destWhere;

        // Direction filter
        if (!empty($filters['direction'])) {
            $where[] = 'direction = ?';
            $params[] = strtoupper(trim($filters['direction']));
        }

        // Altitude filters
        if (!empty($filters['altitude_min'])) {
            $where[] = '(altitude_max_ft IS NULL OR altitude_max_ft >= ?)';
            $params[] = (int)$filters['altitude_min'];
        }
        if (!empty($filters['altitude_max'])) {
            $where[] = '(altitude_min_ft IS NULL OR altitude_min_ft <= ?)';
            $params[] = (int)$filters['altitude_max'];
        }

        // Text filter
        $textFilter = trim($filters['text'] ?? '');
        if ($textFilter !== '') {
            $where[] = "(cdr_code LIKE '%' + ? + '%' OR full_route LIKE '%' + ? + '%')";
            $params[] = $textFilter;
            $params[] = $textFilter;
        }

        $sql = "
            SELECT cdr_id, cdr_code, full_route, origin_icao, dest_icao,
                   dep_artcc, arr_artcc, direction, altitude_min_ft, altitude_max_ft
            FROM dbo.swim_coded_departure_routes
            WHERE " . implode(' AND ', $where);

        $stmt = sqlsrv_query($this->connSwim, $sql, $params);
        if ($stmt === false) return [];

        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = [
                'source' => 'cdr',
                'route_string' => $this->normalizeRouteString($row['full_route']),
                '_raw_route' => $row['full_route'],
                'metadata' => [
                    'play_name' => null,
                    'play_id' => null,
                    'cdr_code' => $row['cdr_code'],
                    'distance_nm' => null,
                    'direction' => $row['direction'],
                ],
                'statistics' => null,
                'tmi_flags' => [],
                'traversal' => null,
                '_route_geometry_json' => null,
                '_traversed_artccs' => array_filter([$row['dep_artcc'], $row['arr_artcc']]),
            ];
        }
        sqlsrv_free_stmt($stmt);

        return $results;
    }

    private function queryHistorical(array $originTokens, array $destTokens): array
    {
        // Expand tokens to ICAO airport lists for the IN clause
        $originAirports = $this->expandTokensToAirports($originTokens);
        $destAirports = $this->expandTokensToAirports($destTokens);

        if (empty($originAirports) && empty($destAirports)) return [];

        $where = [];
        $params = [];

        if (!empty($originAirports)) {
            $placeholders = implode(',', array_fill(0, count($originAirports), '?'));
            $where[] = "origin_icao IN ($placeholders)";
            $params = array_merge($params, $originAirports);
        }

        if (!empty($destAirports)) {
            $placeholders = implode(',', array_fill(0, count($destAirports), '?'));
            $where[] = "dest_icao IN ($placeholders)";
            $params = array_merge($params, $destAirports);
        }

        $sql = "
            SELECT origin_icao, dest_icao, normalized_route, route_hash,
                   flight_count, usage_pct, avg_altitude_ft,
                   common_aircraft, common_operators, first_seen, last_seen
            FROM dbo.swim_route_stats
            WHERE " . implode(' AND ', $where) . "
            ORDER BY flight_count DESC
        ";

        $stmt = sqlsrv_query($this->connSwim, $sql, $params);
        if ($stmt === false) return [];

        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $firstSeen = $row['first_seen'] instanceof \DateTime ? $row['first_seen']->format('Y-m-d') : (string)$row['first_seen'];
            $lastSeen = $row['last_seen'] instanceof \DateTime ? $row['last_seen']->format('Y-m-d') : (string)$row['last_seen'];

            $results[] = [
                'source' => 'historical',
                'route_string' => $this->normalizeRouteString($row['normalized_route']),
                '_raw_route' => $row['normalized_route'],
                'metadata' => [
                    'play_name' => null,
                    'play_id' => null,
                    'cdr_code' => null,
                    'distance_nm' => null,
                    'direction' => null,
                ],
                'statistics' => [
                    'flight_count' => (int)$row['flight_count'],
                    'usage_pct' => round((float)$row['usage_pct'], 1),
                    'avg_altitude_ft' => $row['avg_altitude_ft'] !== null ? (int)$row['avg_altitude_ft'] : null,
                    'common_aircraft' => $row['common_aircraft'] ? explode(',', $row['common_aircraft']) : [],
                    'common_operators' => $row['common_operators'] ? explode(',', $row['common_operators']) : [],
                    'first_seen' => $firstSeen,
                    'last_seen' => $lastSeen,
                ],
                'tmi_flags' => [],
                'traversal' => null,
                '_route_geometry_json' => null,
                '_traversed_artccs' => [],
            ];
        }
        sqlsrv_free_stmt($stmt);

        return $results;
    }

    // =========================================================================
    // TMI ANNOTATION
    // =========================================================================

    private function fetchActiveTMIs(array $originTokens, array $destTokens, array $context): ?array
    {
        if (!$this->connTmi) return null;

        // Collect airports and ARTCCs from tokens
        $airports = [];
        $artccs = [];
        foreach (array_merge($originTokens, $destTokens) as $token) {
            if ($token['type'] === self::FACILITY_AIRPORT) $airports[] = $token['code'];
            elseif ($token['type'] === self::FACILITY_ARTCC) $artccs[] = $token['code'];
        }

        if (empty($airports) && empty($artccs)) return [];

        $where = ["status IN ('ACTIVE', 'PROPOSED', 'PENDING_COORD')"];
        $params = [];

        $orClauses = [];
        if (!empty($airports)) {
            $placeholders = implode(',', array_fill(0, count($airports), '?'));
            $orClauses[] = "ctl_element IN ($placeholders)";
            $params = array_merge($params, $airports);
        }
        // scope_json LIKE matching for ARTCCs
        foreach ($artccs as $artcc) {
            $orClauses[] = "scope_json LIKE ?";
            $params[] = '%' . $artcc . '%';
        }

        if (!empty($orClauses)) {
            $where[] = '(' . implode(' OR ', $orClauses) . ')';
        }

        // Time window
        if (!empty($context['departure_time_utc'])) {
            $where[] = "(end_utc IS NULL OR end_utc >= ?)";
            $params[] = $context['departure_time_utc'];
        }

        $sql = "
            SELECT program_id, program_type, ctl_element, status,
                   program_rate, scope_json, start_utc, end_utc
            FROM dbo.tmi_programs
            WHERE " . implode(' AND ', $where);

        $stmt = sqlsrv_query($this->connTmi, $sql, $params);
        if ($stmt === false) return null;

        $tmis = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $type = $row['program_type'];
            // Simplify program type for consumers
            $simpleType = str_starts_with($type, 'GDP') ? 'GDP' : $type;

            $impact = 'flow_restriction';
            if ($simpleType === 'GS') $impact = 'ground_stop';
            elseif ($simpleType === 'GDP') $impact = 'arrival_delay';
            elseif ($simpleType === 'AFP') $impact = 'flow_restriction';

            $tmis[] = [
                'type' => $simpleType,
                'airport' => $row['ctl_element'],
                'program_id' => (int)$row['program_id'],
                'aar' => $row['program_rate'] !== null ? (int)$row['program_rate'] : null,
                'status' => strtolower($row['status']),
                'impact' => $impact,
                '_scope_artccs' => $row['scope_json'] ? (json_decode($row['scope_json'], true) ?: []) : [],
            ];
        }
        sqlsrv_free_stmt($stmt);

        return $tmis;
    }

    private function attachTMIFlags(array $results, array $tmis): array
    {
        foreach ($results as &$result) {
            $flags = [];
            $routeArtccs = $result['_traversed_artccs'] ?? [];

            foreach ($tmis as $tmi) {
                $hit = false;
                // Check if TMI airport matches route origin/dest
                // (route_string doesn't contain airports, so check metadata)
                if ($tmi['airport']) $hit = true; // TMI at a relevant airport (already filtered by query tokens)

                // Check ARTCC scope overlap
                if (!$hit && !empty($tmi['_scope_artccs']) && !empty($routeArtccs)) {
                    $hit = !empty(array_intersect($tmi['_scope_artccs'], $routeArtccs));
                }

                if ($hit) {
                    $flags[] = [
                        'type' => $tmi['type'],
                        'airport' => $tmi['airport'],
                        'program_id' => $tmi['program_id'],
                        'aar' => $tmi['aar'],
                        'status' => $tmi['status'],
                        'impact' => $tmi['impact'],
                    ];
                }
            }

            $result['tmi_flags'] = $flags;
        }
        unset($result);
        return $results;
    }

    // =========================================================================
    // MERGE, DEDUP, RANK
    // =========================================================================

    private function mergeAndDedup(array $results): array
    {
        $byRoute = [];
        foreach ($results as $result) {
            $key = $result['route_string'];
            if (!isset($byRoute[$key])) {
                $byRoute[$key] = $result;
                $byRoute[$key]['also_in'] = [];
            } else {
                // Merge: keep the one with more metadata, record other source
                $existing = &$byRoute[$key];
                $existing['also_in'][] = $result['source'];

                // Merge metadata from other sources
                if ($result['source'] === 'playbook' && $existing['source'] !== 'playbook') {
                    // Playbook wins as primary
                    $result['also_in'] = array_merge([$existing['source']], $existing['also_in']);
                    $result['statistics'] = $existing['statistics'] ?? $result['statistics'];
                    $result['tmi_flags'] = array_merge($existing['tmi_flags'], $result['tmi_flags']);
                    $result['_traversed_artccs'] = !empty($result['_traversed_artccs']) ? $result['_traversed_artccs'] : $existing['_traversed_artccs'];
                    $byRoute[$key] = $result;
                } else {
                    // Merge statistics from historical onto existing
                    if ($result['statistics'] !== null && $existing['statistics'] === null) {
                        $existing['statistics'] = $result['statistics'];
                    }
                    // Merge CDR code
                    if (!empty($result['metadata']['cdr_code']) && empty($existing['metadata']['cdr_code'])) {
                        $existing['metadata']['cdr_code'] = $result['metadata']['cdr_code'];
                    }
                }
                unset($existing);
            }
        }

        return array_values($byRoute);
    }

    private function rank(array $results, string $sortMode, bool $tmiActive): array
    {
        // Find max flight count for normalization
        $maxCount = 1;
        foreach ($results as $r) {
            $count = $r['statistics']['flight_count'] ?? 0;
            if ($count > $maxCount) $maxCount = $count;
        }

        // Score each result
        foreach ($results as &$r) {
            $score = 0.0;

            // Historical popularity (0-50)
            $flightCount = $r['statistics']['flight_count'] ?? 0;
            $score += min(50, ($flightCount / $maxCount) * 50);

            // Source authority (0-20)
            if ($r['source'] === 'playbook') $score += 20;
            elseif ($r['source'] === 'cdr') $score += 15;
            else $score += 10;

            // Recency (0-15)
            $lastSeen = $r['statistics']['last_seen'] ?? null;
            if ($lastSeen) {
                $daysSince = (time() - strtotime($lastSeen)) / 86400;
                if ($daysSince <= 7) $score += 15;
                elseif ($daysSince <= 30) $score += 10;
                elseif ($daysSince <= 90) $score += 5;
            }

            // TMI compliance (0-15)
            if ($tmiActive) {
                $score += empty($r['tmi_flags']) ? 15 : 0;
            }

            $r['score'] = round($score, 1);
        }
        unset($r);

        // Sort
        usort($results, function ($a, $b) use ($sortMode) {
            switch ($sortMode) {
                case 'popularity':
                    return ($b['statistics']['flight_count'] ?? 0) <=> ($a['statistics']['flight_count'] ?? 0);
                case 'distance':
                    return ($a['metadata']['distance_nm'] ?? PHP_INT_MAX) <=> ($b['metadata']['distance_nm'] ?? PHP_INT_MAX);
                case 'recency':
                    return ($b['statistics']['last_seen'] ?? '') <=> ($a['statistics']['last_seen'] ?? '');
                default: // 'score'
                    $cmp = $b['score'] <=> $a['score'];
                    if ($cmp !== 0) return $cmp;
                    return ($b['statistics']['flight_count'] ?? 0) <=> ($a['statistics']['flight_count'] ?? 0);
            }
        });

        return $results;
    }

    // =========================================================================
    // ENRICHMENT
    // =========================================================================

    private function enrich(array $results, array $includes, array &$warnings): array
    {
        $includeGeometry = in_array('geometry', $includes, true);
        $includeTraversal = in_array('traversal', $includes, true);
        $includeStatistics = in_array('statistics', $includes, true);

        // Geometry enrichment via PostGIS
        if ($includeGeometry) {
            $results = $this->enrichGeometry($results, $warnings);
        }

        // Statistics enrichment (attach historical stats to playbook/CDR routes that lack them)
        if ($includeStatistics) {
            $results = $this->enrichStatistics($results);
        }

        return $results;
    }

    private function enrichGeometry(array $results, array &$warnings): array
    {
        require_once dirname(__DIR__) . '/services/GISService.php';

        $gis = \GISService::getInstance();
        if (!$gis) {
            $warnings[] = 'geometry_unavailable';
            return $results;
        }

        // Collect routes needing expansion (skip those with frozen geometry)
        $needsExpansion = [];
        foreach ($results as $i => $r) {
            if (!empty($r['_route_geometry_json'])) {
                // Parse frozen geometry
                $geo = json_decode($r['_route_geometry_json'], true);
                if ($geo && isset($geo['geojson'])) {
                    $results[$i]['geometry'] = $geo['geojson'];
                    if (isset($geo['distance_nm'])) {
                        $results[$i]['metadata']['distance_nm'] = round((float)$geo['distance_nm'], 1);
                    }
                    continue;
                }
            }
            $needsExpansion[$i] = $r['_raw_route'];
        }

        // Batch expand remaining routes
        if (!empty($needsExpansion)) {
            $routes = array_values($needsExpansion);
            $indices = array_keys($needsExpansion);
            $expanded = $gis->expandRoutesBatch($routes);

            foreach ($expanded as $exp) {
                $idx = $indices[$exp['index']] ?? null;
                if ($idx === null) continue;

                if ($exp['geojson'] && empty($exp['error'])) {
                    $results[$idx]['geometry'] = $exp['geojson'];
                    $results[$idx]['metadata']['distance_nm'] = $exp['distance_nm'];
                    if (!empty($exp['artccs'])) {
                        $results[$idx]['_traversed_artccs'] = $exp['artccs'];
                        $results[$idx]['traversal'] = [
                            'artccs' => $exp['artccs'],
                            'tracons' => [],
                        ];
                    }
                }
            }
        }

        return $results;
    }

    private function enrichStatistics(array $results): array
    {
        // Find playbook/CDR results that lack statistics
        $needStats = [];
        foreach ($results as $i => $r) {
            if ($r['statistics'] === null) {
                $needStats[$i] = $r['route_string'];
            }
        }

        if (empty($needStats)) return $results;

        // Batch lookup in swim_route_stats by normalized route hash
        // (Simple approach: match by route_string similarity — exact match on normalized string)
        foreach ($needStats as $i => $routeStr) {
            $sql = "SELECT TOP 1 flight_count, usage_pct, avg_altitude_ft,
                           common_aircraft, common_operators, first_seen, last_seen
                    FROM dbo.swim_route_stats
                    WHERE normalized_route = ?
                    ORDER BY flight_count DESC";
            $stmt = sqlsrv_query($this->connSwim, $sql, [$routeStr]);
            if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $firstSeen = $row['first_seen'] instanceof \DateTime ? $row['first_seen']->format('Y-m-d') : (string)$row['first_seen'];
                $lastSeen = $row['last_seen'] instanceof \DateTime ? $row['last_seen']->format('Y-m-d') : (string)$row['last_seen'];
                $results[$i]['statistics'] = [
                    'flight_count' => (int)$row['flight_count'],
                    'usage_pct' => round((float)$row['usage_pct'], 1),
                    'avg_altitude_ft' => $row['avg_altitude_ft'] !== null ? (int)$row['avg_altitude_ft'] : null,
                    'common_aircraft' => $row['common_aircraft'] ? explode(',', $row['common_aircraft']) : [],
                    'common_operators' => $row['common_operators'] ? explode(',', $row['common_operators']) : [],
                    'first_seen' => $firstSeen,
                    'last_seen' => $lastSeen,
                ];
            }
            if ($stmt) sqlsrv_free_stmt($stmt);
        }

        return $results;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function matchesTokens(array $row, array $tokens, string $direction): bool
    {
        foreach ($tokens as $token) {
            $code = $token['code'];
            $type = $token['type'];

            $col = ($direction === 'origin') ? 'origin' : 'dest';

            if ($type === self::FACILITY_AIRPORT) {
                if ($this->csvContains($row[$col . '_airports'] ?? '', $code)) return true;
            } elseif ($type === self::FACILITY_ARTCC) {
                if ($this->csvContains($row[$col . '_artccs'] ?? '', $code)) return true;
            } elseif ($type === self::FACILITY_TRACON) {
                if ($this->csvContains($row[$col . '_tracons'] ?? '', $code)) return true;
            }
        }
        return false;
    }

    private function csvContains(string $csv, string $value): bool
    {
        if ($csv === '') return false;
        $items = array_map('trim', explode(',', $csv));
        return in_array($value, $items, true);
    }

    private function parseCSV(string $csv): array
    {
        if ($csv === '') return [];
        return array_filter(array_map('trim', explode(',', $csv)));
    }

    private function parseCSVTraversal(array $row): ?array
    {
        $artccs = $this->parseCSV($row['traversed_artccs'] ?? '');
        $tracons = $this->parseCSV($row['traversed_tracons'] ?? '');
        if (empty($artccs) && empty($tracons)) return null;
        return ['artccs' => $artccs, 'tracons' => $tracons];
    }

    private function buildRouteIndex(array $row): array
    {
        $originCodes = array_merge(
            $this->parseCSV($row['origin_airports'] ?? ''),
            $this->parseCSV($row['origin_artccs'] ?? ''),
            $this->parseCSV($row['origin_tracons'] ?? '')
        );
        $destCodes = array_merge(
            $this->parseCSV($row['dest_airports'] ?? ''),
            $this->parseCSV($row['dest_artccs'] ?? ''),
            $this->parseCSV($row['dest_tracons'] ?? '')
        );
        $thruCodes = array_merge(
            $this->parseCSV($row['traversed_artccs'] ?? ''),
            $this->parseCSV($row['traversed_tracons'] ?? '')
        );
        $allCodes = array_unique(array_merge($originCodes, $destCodes, $thruCodes));
        $searchText = strtolower(implode(' ', [
            $row['route_string'] ?? '',
            $row['play_name'] ?? '',
            $row['remarks'] ?? '',
        ]));

        return [
            'originCodes' => $originCodes,
            'destCodes' => $destCodes,
            'thruCodes' => $thruCodes,
            'allCodes' => $allCodes,
            'searchText' => $searchText,
        ];
    }

    private function applyFilterAST(array $results, array $filterAST): array
    {
        // For CDR/historical results that weren't already filtered by the playbook query
        return array_values(array_filter($results, function ($r) use ($filterAST) {
            if ($r['source'] === 'playbook') return true; // already filtered
            // Build a minimal index for CDR/historical
            $index = [
                'originCodes' => [],
                'destCodes' => [],
                'thruCodes' => $r['_traversed_artccs'] ?? [],
                'allCodes' => $r['_traversed_artccs'] ?? [],
                'searchText' => strtolower($r['_raw_route'] ?? ''),
            ];
            return RouteFilterParser::evaluate($filterAST, $index);
        }));
    }

    private function normalizeRouteString(string $route): string
    {
        // Strip leading/trailing ICAO airport codes, collapse whitespace, uppercase
        $route = strtoupper(trim($route));
        $route = preg_replace('/\s+/', ' ', $route);

        // Strip leading airport (4-char K/P prefix or international)
        $parts = explode(' ', $route);
        if (count($parts) > 2 && strlen($parts[0]) === 4 && preg_match('/^[A-Z]{4}$/', $parts[0])) {
            array_shift($parts);
        }
        // Strip trailing airport
        if (count($parts) > 1 && strlen(end($parts)) === 4 && preg_match('/^[A-Z]{4}$/', end($parts))) {
            array_pop($parts);
        }

        return implode(' ', $parts);
    }

    private function extractDistanceFromGeometry(?string $geoJson): ?float
    {
        if (!$geoJson) return null;
        $geo = json_decode($geoJson, true);
        if ($geo && isset($geo['distance_nm'])) {
            return round((float)$geo['distance_nm'], 1);
        }
        return null;
    }

    private function buildCDRTokenWhere(array $tokens, string $direction, array &$params): ?string
    {
        if (empty($tokens)) return null;

        $col = ($direction === 'origin') ? 'origin_icao' : 'dest_icao';
        $artccCol = ($direction === 'origin') ? 'dep_artcc' : 'arr_artcc';

        $orParts = [];
        $airportCodes = [];
        $artccCodes = [];

        foreach ($tokens as $token) {
            if ($token['type'] === self::FACILITY_AIRPORT) {
                $airportCodes[] = $token['code'];
            } elseif ($token['type'] === self::FACILITY_ARTCC) {
                $artccCodes[] = $token['code'];
            } elseif ($token['type'] === self::FACILITY_TRACON) {
                // TRACON tokens need expansion — for now treat as airport-ish
                // CDRs don't have TRACON data, skip
            }
        }

        if (!empty($airportCodes)) {
            $placeholders = implode(',', array_fill(0, count($airportCodes), '?'));
            $orParts[] = "$col IN ($placeholders)";
            $params = array_merge($params, $airportCodes);
        }

        if (!empty($artccCodes)) {
            $placeholders = implode(',', array_fill(0, count($artccCodes), '?'));
            $orParts[] = "$artccCol IN ($placeholders)";
            $params = array_merge($params, $artccCodes);
        }

        if (empty($orParts)) return null;
        return '(' . implode(' OR ', $orParts) . ')';
    }

    private function expandTokensToAirports(array $tokens): array
    {
        $airports = [];
        foreach ($tokens as $token) {
            if ($token['type'] === self::FACILITY_AIRPORT) {
                $airports[] = $token['code'];
            } elseif ($token['type'] === self::FACILITY_ARTCC || $token['type'] === self::FACILITY_TRACON) {
                // Expand ARTCC/TRACON to airport list via swim_playbook_routes scope columns
                // For historical queries, we do a simpler approach: just pass the code through
                // and let the SQL handle it (historical stats are per-airport already)
                // This means ARTCC/TRACON queries on historical data require airport expansion
                $expanded = $this->expandFacilityToAirports($token);
                $airports = array_merge($airports, $expanded);
            }
        }
        return array_unique($airports);
    }

    private function expandFacilityToAirports(array $token): array
    {
        // Use SWIM_API airport reference or ADL airports table
        // Simple approach: query distinct airports from swim_playbook_routes scope columns
        $code = $token['code'];
        $type = $token['type'];

        if ($type === self::FACILITY_ARTCC) {
            // Get airports from playbook routes tagged with this ARTCC
            $sql = "SELECT DISTINCT value FROM (
                        SELECT TRIM(value) AS value FROM dbo.swim_playbook_routes
                        CROSS APPLY STRING_SPLIT(origin_airports, ',')
                        WHERE origin_artccs LIKE '%' + ? + '%'
                        UNION
                        SELECT TRIM(value) AS value FROM dbo.swim_playbook_routes
                        CROSS APPLY STRING_SPLIT(dest_airports, ',')
                        WHERE dest_artccs LIKE '%' + ? + '%'
                    ) t WHERE LEN(value) = 4";
            $stmt = sqlsrv_query($this->connSwim, $sql, [$code, $code]);
            if (!$stmt) return [];
            $airports = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $airports[] = $row['value'];
            }
            sqlsrv_free_stmt($stmt);
            return $airports;
        }

        if ($type === self::FACILITY_TRACON) {
            $sql = "SELECT DISTINCT value FROM (
                        SELECT TRIM(value) AS value FROM dbo.swim_playbook_routes
                        CROSS APPLY STRING_SPLIT(origin_airports, ',')
                        WHERE origin_tracons LIKE '%' + ? + '%'
                        UNION
                        SELECT TRIM(value) AS value FROM dbo.swim_playbook_routes
                        CROSS APPLY STRING_SPLIT(dest_airports, ',')
                        WHERE dest_tracons LIKE '%' + ? + '%'
                    ) t WHERE LEN(value) = 4";
            $stmt = sqlsrv_query($this->connSwim, $sql, [$code, $code]);
            if (!$stmt) return [];
            $airports = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $airports[] = $row['value'];
            }
            sqlsrv_free_stmt($stmt);
            return $airports;
        }

        return [];
    }

    /**
     * Format results for API response (strip internal fields).
     */
    public static function formatResults(array $results): array
    {
        return array_map(function ($r) {
            $out = [
                'rank' => $r['rank'] ?? 0,
                'score' => $r['score'] ?? 0,
                'source' => $r['source'],
                'route_string' => $r['route_string'],
                'metadata' => $r['metadata'],
            ];

            if (!empty($r['also_in'])) $out['also_in'] = array_values(array_unique($r['also_in']));
            if ($r['statistics'] !== null) $out['statistics'] = $r['statistics'];
            if (!empty($r['tmi_flags'])) $out['tmi_flags'] = $r['tmi_flags'];
            if ($r['traversal'] !== null) $out['traversal'] = $r['traversal'];
            if (isset($r['geometry'])) $out['geometry'] = $r['geometry'];

            return $out;
        }, $results);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add load/services/RouteQueryService.php
git commit -m "feat(swim): add RouteQueryService — multi-source route query orchestrator"
```

---

### Task 4: API Endpoint — `query.php`

**Files:**
- Create: `api/swim/v1/routes/query.php`

- [ ] **Step 1: Create the endpoint**

```php
<?php
/**
 * VATSWIM API v1 - Unified Route Query
 *
 * Returns ranked route suggestions from playbook, CDR, and historical data
 * with optional boolean filter expressions and TMI impact annotations.
 *
 * POST /api/swim/v1/routes/query  — Full query with JSON body
 * GET  /api/swim/v1/routes/query  — Simple city-pair lookup via query params
 *
 * @version 1.0.0
 * @since 2026-03-31
 * @see docs/superpowers/specs/2026-03-30-vatswim-route-query-api-design.md
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/RouteQueryService.php';

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    perti_set_cors();
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    SwimResponse::error('Method not allowed. GET and POST are supported.', 405, 'METHOD_NOT_ALLOWED');
}

// Auth required
swim_init_auth(true, false);

// Get SWIM_API connection
$conn_swim_api = get_conn_swim();
if (!$conn_swim_api) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Parse request
$request = [];

if ($method === 'POST') {
    $body = swim_get_json_body();
    if ($body === null) {
        // Empty POST body — treat as empty query
        $body = [];
    }
    $request = $body;
} else {
    // GET: map query params to request structure
    $origin = swim_get_param('origin');
    $dest = swim_get_param('destination') ?? swim_get_param('dest');
    if ($origin !== null) $request['origin'] = $origin;
    if ($dest !== null) $request['destination'] = $dest;

    $filter = swim_get_param('filter');
    if ($filter !== null) $request['filter'] = $filter;

    $sources = swim_get_param('sources');
    if ($sources !== null) $request['sources'] = array_map('trim', explode(',', $sources));

    $include = swim_get_param('include');
    if ($include !== null) $request['include'] = array_map('trim', explode(',', $include));

    $sort = swim_get_param('sort');
    if ($sort !== null) $request['sort'] = $sort;

    $request['limit'] = swim_get_int_param('limit', 20, 1, 100);
    $request['offset'] = swim_get_int_param('offset', 0, 0, 10000);
}

// Validate required fields
$hasOrigin = !empty($request['origin']);
$hasDest = !empty($request['destination']);
$hasFilter = !empty($request['filter']);

if (!$hasOrigin && !$hasDest && !$hasFilter) {
    SwimResponse::error('At least one of origin, destination, or filter is required', 400, 'MISSING_PARAMETER');
}

// Validate sources
$validSources = ['playbook', 'cdr', 'historical'];
if (isset($request['sources'])) {
    foreach ($request['sources'] as $src) {
        if (!in_array($src, $validSources, true)) {
            SwimResponse::error("Unknown source: $src. Valid: " . implode(', ', $validSources), 400, 'INVALID_PARAMETER');
        }
    }
}

// Validate sort
$validSorts = ['score', 'popularity', 'distance', 'recency'];
$sort = $request['sort'] ?? 'score';
if (!in_array($sort, $validSorts, true)) {
    SwimResponse::error("Unknown sort: $sort. Valid: " . implode(', ', $validSorts), 400, 'INVALID_PARAMETER');
}
$request['sort'] = $sort;

// Clamp limit/offset
$request['limit'] = max(1, min(100, (int)($request['limit'] ?? 20)));
$request['offset'] = max(0, (int)($request['offset'] ?? 0));

// Check cache
$cacheKey = 'route_query:' . md5(json_encode($request));
$cached = SwimResponse::tryCached($cacheKey);
if ($cached !== null) {
    // TMI flags are always live — reattach if context requested TMIs
    // For simplicity, cache the full response including TMI flags
    SwimResponse::json($cached, 200);
}

// Execute query
$connTmi = get_conn_tmi();
$connPdo = $GLOBALS['conn_pdo'] ?? null;

$service = new \PERTI\Services\RouteQueryService($conn_swim_api, $connTmi, $connPdo);
$result = $service->query($request);

// Check for errors
if (isset($result['error'])) {
    SwimResponse::error($result['error'], $result['http_code'] ?? 400, 'QUERY_ERROR');
}

// Format results (strip internal fields)
$result['results'] = \PERTI\Services\RouteQueryService::formatResults($result['results']);

// Cache and return
SwimResponse::successCached($cacheKey, $result);
```

- [ ] **Step 2: Commit**

```bash
git add api/swim/v1/routes/query.php
git commit -m "feat(swim): add POST/GET /routes/query endpoint — unified route suggestions"
```

---

### Task 5: Sync Daemon — Route Stats Aggregation Phase

**Files:**
- Modify: `scripts/swim_tmi_sync_daemon.php`

- [ ] **Step 1: Add route stats sync function**

Add after the existing `runTier2Sync` function (near line ~1362). This function reads from MySQL `route_history_facts` star schema and writes to `swim_route_stats` in SWIM_API.

Find the end of `runTier2Sync` (the `return` statement around line 1362) and add the new function after it:

```php
/**
 * Sync route history stats from MySQL star schema to swim_route_stats.
 * Runs as part of Tier 2 (daily reference sync).
 *
 * Source: MySQL perti_site.route_history_facts + dim_route + dim_aircraft_type + dim_operator
 * Target: SWIM_API.dbo.swim_route_stats
 */
function syncRouteStats($conn_pdo, $conn_swim, bool $debug): array {
    $start = microtime(true);
    $stats = ['rows_read' => 0, 'inserted' => 0, 'duration_ms' => 0, 'error' => null];

    if (!$conn_pdo) {
        $stats['error'] = 'MySQL connection not available';
        $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
        return $stats;
    }

    try {
        // Aggregate route statistics per city pair per normalized route
        // Minimum 5 flights to be included
        $sql = "
            SELECT
                f.origin_icao,
                f.dest_icao,
                d.route_hash,
                d.normalized_route,
                COUNT(*) AS flight_count,
                ROUND(COUNT(*) * 100.0 / pair_totals.pair_count, 2) AS usage_pct,
                ROUND(AVG(f.altitude_ft) / 100) * 100 AS avg_altitude_ft,
                MIN(t.flight_date) AS first_seen,
                MAX(t.flight_date) AS last_seen
            FROM route_history_facts f
            JOIN dim_route d ON f.route_dim_id = d.route_dim_id
            JOIN dim_time t ON f.time_dim_id = t.time_dim_id
            JOIN (
                SELECT origin_icao, dest_icao, COUNT(*) AS pair_count
                FROM route_history_facts
                GROUP BY origin_icao, dest_icao
            ) pair_totals ON f.origin_icao = pair_totals.origin_icao AND f.dest_icao = pair_totals.dest_icao
            GROUP BY f.origin_icao, f.dest_icao, d.route_hash, d.normalized_route, pair_totals.pair_count
            HAVING COUNT(*) >= 5
            ORDER BY f.origin_icao, f.dest_icao, flight_count DESC
        ";

        $stmt = $conn_pdo->query($sql);
        $routes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stats['rows_read'] = count($routes);

        if ($debug) {
            tmi_log("  Route stats: {$stats['rows_read']} aggregated routes from MySQL");
        }

        if (empty($routes)) {
            $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
            return $stats;
        }

        // Now get top-5 aircraft and operators per route
        // This is a separate query to avoid massive GROUP_CONCAT in the main aggregate
        $topAircraftSql = "
            SELECT f.origin_icao, f.dest_icao, d.route_hash,
                   GROUP_CONCAT(a.icao_code ORDER BY cnt DESC SEPARATOR ',') AS top_aircraft
            FROM (
                SELECT f2.origin_icao, f2.dest_icao, f2.route_dim_id, f2.aircraft_dim_id, COUNT(*) AS cnt
                FROM route_history_facts f2
                WHERE f2.aircraft_dim_id IS NOT NULL
                GROUP BY f2.origin_icao, f2.dest_icao, f2.route_dim_id, f2.aircraft_dim_id
            ) f
            JOIN dim_route d ON f.route_dim_id = d.route_dim_id
            JOIN dim_aircraft_type a ON f.aircraft_dim_id = a.aircraft_dim_id
            GROUP BY f.origin_icao, f.dest_icao, d.route_hash
        ";
        $acStmt = $conn_pdo->query($topAircraftSql);
        $acMap = [];
        while ($row = $acStmt->fetch(\PDO::FETCH_ASSOC)) {
            $key = $row['origin_icao'] . '|' . $row['dest_icao'] . '|' . bin2hex($row['route_hash']);
            $codes = explode(',', $row['top_aircraft']);
            $acMap[$key] = implode(',', array_slice($codes, 0, 5));
        }

        $topOperatorsSql = "
            SELECT f.origin_icao, f.dest_icao, d.route_hash,
                   GROUP_CONCAT(o.airline_icao ORDER BY cnt DESC SEPARATOR ',') AS top_operators
            FROM (
                SELECT f2.origin_icao, f2.dest_icao, f2.route_dim_id, f2.operator_dim_id, COUNT(*) AS cnt
                FROM route_history_facts f2
                WHERE f2.operator_dim_id IS NOT NULL
                GROUP BY f2.origin_icao, f2.dest_icao, f2.route_dim_id, f2.operator_dim_id
            ) f
            JOIN dim_route d ON f.route_dim_id = d.route_dim_id
            JOIN dim_operator o ON f.operator_dim_id = o.operator_dim_id
            GROUP BY f.origin_icao, f.dest_icao, d.route_hash
        ";
        $opStmt = $conn_pdo->query($topOperatorsSql);
        $opMap = [];
        while ($row = $opStmt->fetch(\PDO::FETCH_ASSOC)) {
            $key = $row['origin_icao'] . '|' . $row['dest_icao'] . '|' . bin2hex($row['route_hash']);
            $codes = explode(',', $row['top_operators']);
            $opMap[$key] = implode(',', array_slice($codes, 0, 5));
        }

        // Enrich routes with top aircraft/operators
        foreach ($routes as &$route) {
            $key = $route['origin_icao'] . '|' . $route['dest_icao'] . '|' . bin2hex($route['route_hash']);
            $route['common_aircraft'] = $acMap[$key] ?? null;
            $route['common_operators'] = $opMap[$key] ?? null;
        }
        unset($route);

        // Truncate + batch insert into SWIM_API
        @sqlsrv_query($conn_swim, "TRUNCATE TABLE dbo.swim_route_stats");

        $columns = [
            'origin_icao' => 'NVARCHAR(4)',
            'dest_icao' => 'NVARCHAR(4)',
            'route_hash' => 'VARBINARY(16)',
            'normalized_route' => 'NVARCHAR(MAX)',
            'flight_count' => 'INT',
            'usage_pct' => 'DECIMAL(5,2)',
            'avg_altitude_ft' => 'INT',
            'common_aircraft' => 'NVARCHAR(200)',
            'common_operators' => 'NVARCHAR(200)',
            'first_seen' => 'DATE',
            'last_seen' => 'DATE',
        ];

        // Convert route_hash from binary to hex string for JSON transport
        $jsonRows = array_map(function ($r) {
            $r['route_hash'] = '0x' . bin2hex($r['route_hash']);
            $r['first_seen'] = ($r['first_seen'] instanceof \DateTime) ? $r['first_seen']->format('Y-m-d') : $r['first_seen'];
            $r['last_seen'] = ($r['last_seen'] instanceof \DateTime) ? $r['last_seen']->format('Y-m-d') : $r['last_seen'];
            return $r;
        }, $routes);

        foreach (array_chunk($jsonRows, 500) as $batch) {
            $json = json_encode($batch, JSON_UNESCAPED_UNICODE);
            if ($json === false) continue;

            $withCols = [];
            foreach ($columns as $colName => $sqlType) {
                if ($colName === 'route_hash') {
                    // VARBINARY needs special handling — use CONVERT
                    $withCols[] = "[$colName] NVARCHAR(34) '\$.$colName'";
                } else {
                    $withCols[] = "[$colName] $sqlType '\$.$colName'";
                }
            }
            $withClause = implode(",\n                ", $withCols);

            $insertCols = implode(', ', array_map(fn($c) => "[$c]", array_keys($columns)));

            // For route_hash, convert from hex string to binary
            $selectCols = [];
            foreach (array_keys($columns) as $col) {
                if ($col === 'route_hash') {
                    $selectCols[] = "CONVERT(VARBINARY(16), [$col], 1) AS [$col]";
                } else {
                    $selectCols[] = "[$col]";
                }
            }
            $selectClause = implode(', ', $selectCols);

            $insertSql = "
                INSERT INTO dbo.swim_route_stats ($insertCols, last_sync_utc)
                SELECT $selectClause, SYSUTCDATETIME()
                FROM OPENJSON(?) WITH ($withClause)
            ";

            $result = @sqlsrv_query($conn_swim, $insertSql, [&$json], ['QueryTimeout' => 120]);
            if ($result === false) {
                $stats['error'] = "INSERT swim_route_stats failed: " . json_encode(sqlsrv_errors());
                break;
            }
            $stats['inserted'] += sqlsrv_rows_affected($result);
            sqlsrv_free_stmt($result);
        }

    } catch (\Throwable $e) {
        $stats['error'] = 'Route stats sync error: ' . $e->getMessage();
    }

    $stats['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
    return $stats;
}
```

- [ ] **Step 2: Wire into Tier 2 sync**

In the `runTier2Sync` function, find the playbook throughput sync block (around line 1327-1354) that ends before the `$totalMs` calculation. Add the route stats sync call after it:

```php
    // Route history stats from MySQL (daily aggregation)
    if ($conn_pdo) {
        $name = 'swim_route_stats';
        tmi_log("  Syncing reference: $name (MySQL route history) ...");
        $stats = syncRouteStats($conn_pdo, $conn_swim, $debug);
        $results[$name] = $stats;

        if ($stats['error']) {
            tmi_log("  $name: ERROR - {$stats['error']}", 'ERROR');
        } else {
            tmi_log("  $name: {$stats['inserted']} inserted in {$stats['duration_ms']}ms");
        }

        updateSyncState($conn_swim, $name, $stats['inserted'], $stats['duration_ms'], 'full', $stats['error']);
    }
```

- [ ] **Step 3: Commit**

```bash
git add scripts/swim_tmi_sync_daemon.php
git commit -m "feat(swim): add route stats sync phase to Tier 2 daemon"
```

---

### Task 6: SWIM API Index Update

**Files:**
- Modify: `api/swim/v1/index.php`

- [ ] **Step 1: Add route query and previously unlisted endpoints to the index**

Find the `'endpoints'` array in `index.php` and add a new `'routes'` category and a `'playbook'` category. Look for an appropriate location (after the existing categories).

Add these entries:

```php
        'routes' => [
            'POST /api/swim/v1/routes/query' => 'Unified route query — ranked suggestions from playbook, CDR, and historical data',
            'GET /api/swim/v1/routes/query' => 'Simple city-pair route lookup (shorthand)',
            'GET /api/swim/v1/routes/cdrs' => 'Coded departure routes (CDR) catalog',
            'GET /api/swim/v1/routes/resolve' => 'Route string resolution via PostGIS (waypoints, geometry)',
            'POST /api/swim/v1/routes/resolve' => 'Batch route resolution (up to 50 routes)',
        ],
        'playbook' => [
            'GET /api/swim/v1/playbook/plays' => 'Playbook plays and routes (with optional geometry)',
            'GET /api/swim/v1/playbook/analysis' => 'Route analysis (distance, traversal, timing)',
            'GET /api/swim/v1/playbook/traversal' => 'Route traversal data',
            'GET /api/swim/v1/playbook/throughput' => 'Route throughput metrics (CTP)',
            'GET /api/swim/v1/playbook/facility-counts' => 'Aggregated facility route statistics',
        ],
```

- [ ] **Step 2: Commit**

```bash
git add api/swim/v1/index.php
git commit -m "feat(swim): register route query + playbook endpoints in SWIM API index"
```

---

### Task 7: Validation — Deploy Migration and Smoke Test

- [ ] **Step 1: Deploy the migration to SWIM_API**

Run the migration against SWIM_API database using admin credentials:

```bash
# From project root — use sqlcmd or the migration runner
php scripts/run_migration.php database/migrations/swim/034_swim_route_stats.sql
```

Verify:
```sql
SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'swim_route_stats';
-- Expected: 1
```

- [ ] **Step 2: Manual smoke test via curl**

Test GET shorthand:
```bash
curl -s -H "Authorization: Bearer YOUR_API_KEY" \
  "https://perti.vatcscc.org/api/swim/v1/routes/query?origin=KJFK&destination=KLAX&limit=5" | jq .
```

Expected: 200 with results from playbook + CDR sources (historical will be empty until sync runs).

Test POST with filter expression:
```bash
curl -s -X POST -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"origin":"KJFK","destination":"KLAX","filter":"THRU:ZOB","sources":["playbook","cdr"],"limit":5}' \
  "https://perti.vatcscc.org/api/swim/v1/routes/query" | jq .
```

Test multi-token origin:
```bash
curl -s -X POST -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"origin":["KJFK","KEWR","KLGA"],"destination":"KLAX","limit":5}' \
  "https://perti.vatcscc.org/api/swim/v1/routes/query" | jq .
```

Test ARTCC token:
```bash
curl -s -X POST -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"origin":"ZNY","destination":"ZLA","limit":5}' \
  "https://perti.vatcscc.org/api/swim/v1/routes/query" | jq .
```

- [ ] **Step 3: Verify sync daemon picks up route stats**

After the next Tier 2 sync cycle (daily at 06:00Z, or trigger manually), verify:
```sql
SELECT COUNT(*) FROM dbo.swim_route_stats;
-- Expected: >0 rows (depends on route_history_facts backfill progress)

SELECT TOP 5 origin_icao, dest_icao, normalized_route, flight_count, usage_pct
FROM dbo.swim_route_stats
ORDER BY flight_count DESC;
```

- [ ] **Step 4: Final commit — fix spec migration number**

```bash
git add docs/superpowers/specs/2026-03-30-vatswim-route-query-api-design.md
git commit -m "fix(docs): correct migration number from 058 to 034 in route query spec"
```
