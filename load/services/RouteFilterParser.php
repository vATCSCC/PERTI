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
