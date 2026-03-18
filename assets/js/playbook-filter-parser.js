/**
 * PlaybookFilterParser — Recursive descent boolean parser for Playbook search.
 *
 * Replaces the flat parseSearch() in playbook.js with full compound boolean
 * expressions: AND/OR/NOT, grouping with parens, FIR: qualifier, AVOID:,
 * context-dependent comma and space semantics.
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
 * @see docs/superpowers/specs/2026-03-18-playbook-compound-filter-design.md
 */
(function(global) {
    'use strict';

    // =========================================================================
    // TOKEN TYPES
    // =========================================================================
    var T = {
        LPAREN:    'LPAREN',
        RPAREN:    'RPAREN',
        AND:       'AND',
        OR:        'OR',
        NOT:       'NOT',
        COMMA:     'COMMA',
        COLON:     'COLON',
        TERM:      'TERM',
        QUALIFIER: 'QUALIFIER',
        EOF:       'EOF'
    };

    var QUALIFIERS = { THRU: 1, VIA: 1, ORIG: 1, DEST: 1, FIR: 1, AVOID: 1 };

    // Multi-valued qualifiers: comma = AND (traverse both)
    var MULTI_VALUED = { THRU: 1, VIA: 1, FIR: 1, AVOID: 1 };

    // =========================================================================
    // TOKENIZER
    // =========================================================================

    /**
     * Tokenize a filter expression string into an array of tokens.
     * Each token: { type, value, pos }
     */
    function tokenize(input) {
        var tokens = [];
        var i = 0;
        var len = input.length;
        var hadSpace = false;

        while (i < len) {
            var ch = input[i];

            // Whitespace — record for implicit AND detection
            if (ch === ' ' || ch === '\t' || ch === '\n' || ch === '\r') {
                hadSpace = true;
                i++;
                continue;
            }

            // If we had whitespace and the previous token is meaningful (not an operator),
            // inject an implicit space marker for the parser
            if (hadSpace && tokens.length > 0) {
                var lastType = tokens[tokens.length - 1].type;
                // Space only acts as implicit AND between terms/groups, not after operators
                if (lastType === T.TERM || lastType === T.RPAREN) {
                    // Peek ahead: if the next non-ws char is an explicit operator, skip
                    if (ch !== '&' && ch !== '|' && ch !== ')') {
                        var upperRest = input.substring(i).toUpperCase();
                        if (upperRest.indexOf('OR ') !== 0 && upperRest.indexOf('OR\t') !== 0 &&
                            upperRest.indexOf('AND ') !== 0 && upperRest.indexOf('AND\t') !== 0) {
                            tokens.push({ type: T.AND, value: ' ', pos: i, implicit: true });
                        }
                    }
                }
            }
            hadSpace = false;

            // Single-character tokens
            if (ch === '(') { tokens.push({ type: T.LPAREN, value: '(', pos: i }); i++; continue; }
            if (ch === ')') { tokens.push({ type: T.RPAREN, value: ')', pos: i }); i++; continue; }
            if (ch === '&') { tokens.push({ type: T.AND, value: '&', pos: i }); i++; continue; }
            if (ch === '|') { tokens.push({ type: T.OR, value: '|', pos: i }); i++; continue; }
            if (ch === ',') { tokens.push({ type: T.COMMA, value: ',', pos: i }); i++; continue; }
            if (ch === '-') { tokens.push({ type: T.NOT, value: '-', pos: i }); i++; continue; }
            if (ch === '!') { tokens.push({ type: T.NOT, value: '!', pos: i }); i++; continue; }

            // Words: qualifier keywords, operator keywords, terms
            if (isWordChar(ch)) {
                var start = i;
                while (i < len && isWordChar(input[i])) i++;
                var word = input.substring(start, i);
                var upper = word.toUpperCase();

                // Check for operator keywords
                if (upper === 'AND') { tokens.push({ type: T.AND, value: upper, pos: start }); continue; }
                if (upper === 'OR')  { tokens.push({ type: T.OR, value: upper, pos: start }); continue; }
                if (upper === 'NOT') { tokens.push({ type: T.NOT, value: upper, pos: start }); continue; }

                // Check for qualifier followed by colon
                if (i < len && input[i] === ':' && QUALIFIERS[upper]) {
                    tokens.push({ type: T.QUALIFIER, value: upper, pos: start });
                    tokens.push({ type: T.COLON, value: ':', pos: i });
                    i++; // skip colon
                    continue;
                }

                // Regular term
                tokens.push({ type: T.TERM, value: upper, pos: start });
                continue;
            }

            // Colon not preceded by a qualifier — treat as part of a term
            if (ch === ':') {
                // Standalone colon — skip it
                i++;
                continue;
            }

            // Unknown character — skip
            i++;
        }

        tokens.push({ type: T.EOF, value: '', pos: len });
        return tokens;
    }

    function isWordChar(ch) {
        var c = ch.charCodeAt(0);
        return (c >= 65 && c <= 90) || (c >= 97 && c <= 122) || // A-Z, a-z
               (c >= 48 && c <= 57) ||                           // 0-9
               ch === '_' || ch === '*';
    }

    // =========================================================================
    // PARSER — Recursive descent
    // =========================================================================

    /**
     * Parse a filter expression string into an AST.
     * Returns { ast, error } where error is { message, pos } or null.
     */
    function parse(input) {
        if (!input || !input.trim()) return { ast: null, error: null };

        var tokens;
        try {
            tokens = tokenize(input);
        } catch (e) {
            return { ast: null, error: { message: e.message, pos: 0 } };
        }

        var explicitMode = detectExplicitMode(tokens);
        var pos = 0;

        function peek() { return tokens[pos] || tokens[tokens.length - 1]; }
        function advance() { return tokens[pos++]; }

        function expect(type) {
            var tok = peek();
            if (tok.type !== type) {
                throw { message: "Expected '" + type + "' but got '" + tok.value + "'", pos: tok.pos };
            }
            return advance();
        }

        function parseOrExpr() {
            var left = parseAndExpr();
            while (peek().type === T.OR) {
                advance(); // consume OR
                var right = parseAndExpr();
                left = mergeNode('OR', left, right);
            }
            return left;
        }

        function parseAndExpr() {
            var left = parseUnary();
            while (true) {
                var tok = peek();
                if (tok.type === T.AND) {
                    advance(); // consume explicit AND or implicit space AND
                    var right = parseUnary();
                    left = mergeNode('AND', left, right);
                } else if (tok.type === T.NOT || tok.type === T.QUALIFIER ||
                           tok.type === T.TERM || tok.type === T.LPAREN) {
                    // Implicit AND — next token starts a new expression
                    // but only if the previous wasn't already handled
                    // This handles cases like qualifier terms right after each other
                    // without any operator when the tokenizer missed the implicit AND
                    var right = parseUnary();
                    left = mergeNode('AND', left, right);
                } else {
                    break;
                }
            }
            return left;
        }

        function parseUnary() {
            var tok = peek();
            if (tok.type === T.NOT) {
                advance(); // consume NOT/- /!
                var child = parseUnary(); // allows --X = X
                // Double negation cancels out
                if (child.type === 'NOT') return child.child;
                return { type: 'NOT', child: child };
            }
            return parsePrimary();
        }

        function parsePrimary() {
            var tok = peek();

            // Grouped expression
            if (tok.type === T.LPAREN) {
                advance(); // consume (
                if (peek().type === T.RPAREN) {
                    advance(); // empty group — no-op
                    return { type: 'AND', children: [] };
                }
                var expr = parseOrExpr();
                expect(T.RPAREN);
                return expr;
            }

            // Qualifier:value_list
            if (tok.type === T.QUALIFIER) {
                var qualTok = advance(); // consume qualifier
                advance(); // consume colon (already guaranteed by tokenizer)
                return parseValueList(qualTok.value);
            }

            // Bare term
            if (tok.type === T.TERM) {
                var termTok = advance();
                var resolved = resolveAlias(termTok.value);
                // Unqualified term → match THRU | ORIG | DEST | text search
                return {
                    type: 'OR',
                    children: [
                        { type: 'TERM', qualifier: 'THRU', value: resolved },
                        { type: 'TERM', qualifier: 'ORIG', value: resolved },
                        { type: 'TERM', qualifier: 'DEST', value: resolved },
                        { type: 'TERM', qualifier: null, value: resolved }
                    ],
                    _unqualified: true,
                    _rawValue: resolved
                };
            }

            // Trailing operator or unexpected token — skip
            if (tok.type === T.EOF) {
                return { type: 'AND', children: [] };
            }
            advance(); // skip unexpected token
            return parsePrimary(); // try again
        }

        function parseValueList(qualifier) {
            // Normalize qualifier
            var normQual = qualifier;
            if (normQual === 'VIA') normQual = 'THRU';

            var isAvoid = qualifier === 'AVOID';
            if (isAvoid) normQual = 'THRU';

            var isMultiValued = !!MULTI_VALUED[qualifier];

            // Read first value
            var values = [];
            if (peek().type === T.TERM) {
                values.push(advance().value);
            } else {
                // Empty qualifier — ignore
                return { type: 'AND', children: [] };
            }

            // Read comma-separated additional values
            while (peek().type === T.COMMA) {
                advance(); // consume comma
                if (peek().type === T.TERM) {
                    values.push(advance().value);
                }
            }

            // Handle FIR resolution
            if (normQual === 'FIR' || qualifier === 'FIR') {
                return expandFIRValues(values, isMultiValued);
            }

            // Resolve aliases
            values = values.map(resolveAlias);

            // Build AST subtree
            var terms = values.map(function(v) {
                var node = { type: 'TERM', qualifier: normQual, value: v };
                if (isAvoid) node = { type: 'NOT', child: node };
                return node;
            });

            if (terms.length === 1) return terms[0];

            if (isMultiValued) {
                // THRU:X,Y = AND (traverse both)
                // Mark as comma-generated so rewriteImplicitMode doesn't convert to OR
                return { type: 'AND', children: terms, _fromComma: true };
            } else {
                // ORIG:X,Y = OR (any origin)
                return { type: 'OR', children: terms };
            }
        }

        try {
            var ast = parseOrExpr();

            // Check for unconsumed tokens (other than EOF)
            if (peek().type !== T.EOF) {
                // Trailing content — try to consume more as AND
                while (peek().type !== T.EOF) {
                    if (peek().type === T.RPAREN) {
                        // Unmatched closing paren
                        throw { message: "Unexpected ')'", pos: peek().pos };
                    }
                    var more = parseOrExpr();
                    ast = mergeNode('AND', ast, more);
                }
            }

            // Apply implicit mode rewrite
            if (!explicitMode) {
                ast = rewriteImplicitMode(ast);
            }

            // Flatten empty nodes
            ast = cleanAST(ast);

            return { ast: ast, error: null };
        } catch (e) {
            return { ast: null, error: { message: e.message || 'Parse error', pos: e.pos || 0 } };
        }
    }

    // =========================================================================
    // IMPLICIT MODE
    // =========================================================================

    /**
     * Detect if expression uses explicit operators.
     * Presence of &, |, OR, AND, ( or ) triggers explicit mode where space = AND.
     */
    function detectExplicitMode(tokens) {
        for (var i = 0; i < tokens.length; i++) {
            var t = tokens[i];
            if (t.type === T.LPAREN || t.type === T.RPAREN) return true;
            if (t.type === T.OR) return true;
            if (t.type === T.AND && !t.implicit) return true; // explicit & or AND keyword
        }
        return false;
    }

    /**
     * Post-parse rewrite for implicit mode:
     * Adjacent same-qualifier terms under AND nodes get grouped into OR.
     * THRU:ZDC THRU:ZOB → OR(THRU:ZDC, THRU:ZOB)
     * THRU:ZDC ORIG:ZNY → AND(THRU:ZDC, ORIG:ZNY) (unchanged)
     */
    function rewriteImplicitMode(node) {
        if (!node) return node;

        if (node.type === 'AND' && node.children) {
            // Recursively rewrite children first
            node.children = node.children.map(rewriteImplicitMode);

            // Comma-generated AND nodes (e.g. THRU:X,Y) must stay AND
            if (node._fromComma) return node;

            // Group children by qualifier
            var groups = {};
            var order = [];
            var nonTerms = [];

            node.children.forEach(function(child) {
                var qual = getTermQualifier(child);
                if (qual) {
                    if (!groups[qual]) {
                        groups[qual] = [];
                        order.push(qual);
                    }
                    groups[qual].push(child);
                } else {
                    nonTerms.push(child);
                }
            });

            // Rebuild children: same-qualifier groups with >1 member → OR
            var newChildren = [];
            order.forEach(function(qual) {
                var members = groups[qual];
                if (members.length > 1) {
                    newChildren.push({ type: 'OR', children: members });
                } else {
                    newChildren.push(members[0]);
                }
            });
            newChildren = newChildren.concat(nonTerms);

            if (newChildren.length === 1) return newChildren[0];
            node.children = newChildren;
            return node;
        }

        if (node.type === 'OR' && node.children) {
            node.children = node.children.map(rewriteImplicitMode);
        }
        if (node.type === 'NOT' && node.child) {
            node.child = rewriteImplicitMode(node.child);
        }

        return node;
    }

    /**
     * Get the qualifier of a node for grouping purposes.
     * Returns qualifier string for TERM nodes, null for complex nodes.
     */
    function getTermQualifier(node) {
        if (node.type === 'TERM') return node.qualifier || '_unqualified';
        if (node.type === 'NOT' && node.child && node.child.type === 'TERM') {
            return (node.child.qualifier || '_unqualified') + '_NOT';
        }
        // Unqualified OR expansion — treat as a single entity, don't split
        if (node.type === 'OR' && node._unqualified) return '_unqualified';
        return null;
    }

    // =========================================================================
    // AST UTILITIES
    // =========================================================================

    /**
     * Merge two nodes under a parent of the given type, flattening if possible.
     */
    function mergeNode(type, left, right) {
        var children = [];
        if (left.type === type && left.children && !left._fromComma) {
            children = children.concat(left.children);
        } else {
            children.push(left);
        }
        if (right.type === type && right.children && !right._fromComma) {
            children = children.concat(right.children);
        } else {
            children.push(right);
        }
        return { type: type, children: children };
    }

    /**
     * Remove empty AND/OR nodes, collapse single-child nodes.
     */
    function cleanAST(node) {
        if (!node) return null;

        if (node.type === 'AND' || node.type === 'OR') {
            if (!node.children) return null;
            node.children = node.children.map(cleanAST).filter(function(c) {
                return c != null;
            });
            // Remove empty containers
            node.children = node.children.filter(function(c) {
                return !(c.type === 'AND' && c.children && c.children.length === 0) &&
                       !(c.type === 'OR' && c.children && c.children.length === 0);
            });
            if (node.children.length === 0) return null;
            if (node.children.length === 1) return node.children[0];
        }

        if (node.type === 'NOT') {
            node.child = cleanAST(node.child);
            if (!node.child) return null;
        }

        return node;
    }

    /**
     * Resolve facility alias via FacilityHierarchy if available.
     */
    function resolveAlias(code) {
        if (typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.ALIAS_TO_CANONICAL) {
            return FacilityHierarchy.ALIAS_TO_CANONICAL[code] || code;
        }
        return code;
    }

    // =========================================================================
    // EVALUATOR
    // =========================================================================

    /**
     * Evaluate an AST against a search index.
     * Index shape: { originCodes: Set, destCodes: Set, thruCodes: Set, allCodes: Set, searchText: string }
     */
    function evaluate(node, index) {
        if (!node || !index) return true;

        switch (node.type) {
            case 'OR':
                return node.children.some(function(c) { return evaluate(c, index); });
            case 'AND':
                return node.children.every(function(c) { return evaluate(c, index); });
            case 'NOT':
                return !evaluate(node.child, index);
            case 'TERM':
                return evaluateTerm(node, index);
            default:
                return true;
        }
    }

    function evaluateTerm(node, index) {
        var val = node.value;
        switch (node.qualifier) {
            case 'THRU':
                return index.thruCodes.has(val);
            case 'ORIG':
                return index.originCodes.has(val);
            case 'DEST':
                return index.destCodes.has(val);
            case 'FIR':
            case 'AVOID':
                // Should never reach here (expanded at parse time)
                return false;
            case null:
            case undefined:
                // Bare term: check all codes + text search
                return index.allCodes.has(val) ||
                       (index.searchText && index.searchText.indexOf(val) !== -1);
            default:
                return false;
        }
    }

    // =========================================================================
    // SERIALIZER
    // =========================================================================

    /**
     * Serialize an AST back to a text expression.
     */
    function serialize(node) {
        if (!node) return '';
        return serializeNode(node, null);
    }

    function serializeNode(node, parentType) {
        switch (node.type) {
            case 'OR': {
                // Unqualified terms serialize back to just the raw value
                if (node._unqualified && node._rawValue) return node._rawValue;
                var parts = node.children.map(function(c) { return serializeNode(c, 'OR'); });
                var joined = parts.join(' | ');
                // Wrap in parens if nested inside AND
                return parentType === 'AND' ? '(' + joined + ')' : joined;
            }
            case 'AND': {
                var parts = node.children.map(function(c) { return serializeNode(c, 'AND'); });
                return parts.join(' & ');
            }
            case 'NOT':
                return '-' + serializeNode(node.child, 'NOT');
            case 'TERM': {
                var prefix = node.qualifier ? node.qualifier + ':' : '';
                return prefix + node.value;
            }
            default:
                return '';
        }
    }

    // =========================================================================
    // TERM COLLECTOR
    // =========================================================================

    /**
     * Recursively collect all TERM nodes from the AST.
     * Returns array of { qualifier, value, negated } objects.
     */
    function collectTerms(node, negated) {
        if (!node) return [];
        negated = !!negated;

        switch (node.type) {
            case 'OR':
            case 'AND':
                var result = [];
                node.children.forEach(function(c) {
                    result = result.concat(collectTerms(c, negated));
                });
                return result;
            case 'NOT':
                return collectTerms(node.child, !negated);
            case 'TERM':
                // Skip null-qualifier text-match nodes (internal to unqualified expansion)
                if (node.qualifier === null) return [];
                return [{ qualifier: node.qualifier, value: node.value, negated: negated }];
            default:
                return [];
        }
    }

    // =========================================================================
    // FIR RESOLUTION
    // =========================================================================

    var firTierData = null;
    var firTierLookup = null; // flat map: tierCode → tier entry

    /**
     * Load FIR tier data from fir_tiers.json.
     * Returns a Promise that resolves when data is loaded.
     */
    function loadFIRTiers() {
        if (firTierData) return Promise.resolve(firTierData);

        return fetch('assets/data/fir_tiers.json')
            .then(function(resp) { return resp.json(); })
            .then(function(data) {
                firTierData = data;
                buildFIRLookup(data);
                return data;
            })
            .catch(function() {
                // Silently fail — FIR: will resolve to empty
                firTierData = {};
                firTierLookup = {};
                return {};
            });
    }

    /**
     * Set FIR tier data programmatically (for testing).
     */
    function setFIRTiers(data) {
        firTierData = data;
        buildFIRLookup(data);
    }

    function buildFIRLookup(data) {
        firTierLookup = {};
        if (!data) return;
        ['global', 'regional', 'country'].forEach(function(section) {
            var entries = data[section];
            if (!entries) return;
            Object.keys(entries).forEach(function(key) {
                firTierLookup[key.toUpperCase()] = entries[key];
            });
        });
    }

    /**
     * Resolve a FIR value to an array of facility codes.
     * Resolution order: tier name → alias → ICAO prefix → exact code.
     */
    function resolveFIR(value) {
        var upper = value.toUpperCase();

        // 1. Check tier lookup
        if (firTierLookup) {
            var entry = firTierLookup[upper];
            if (entry) {
                // Alias — follow one level
                if (entry.alias) {
                    var target = firTierLookup[entry.alias.toUpperCase()];
                    if (target) entry = target;
                    else return []; // broken alias
                }

                // Member-based tier
                if (entry.members) {
                    return entry.members.slice(); // copy
                }

                // Pattern-based tier
                if (entry.patterns) {
                    return expandPatterns(entry.patterns);
                }
            }
        }

        // 2. ICAO prefix match against known ARTCCs
        var artccs = getKnownARTCCs();
        var prefixMatches = artccs.filter(function(code) {
            return code.indexOf(upper) === 0 && code !== upper;
        });
        if (prefixMatches.length > 0) return prefixMatches;

        // 3. Exact code match
        if (artccs.indexOf(upper) !== -1) return [upper];

        return [];
    }

    /**
     * Expand wildcard patterns (e.g., "EG*") against known ARTCC codes.
     */
    function expandPatterns(patterns) {
        var artccs = getKnownARTCCs();
        var result = [];
        var seen = {};
        patterns.forEach(function(pat) {
            pat = pat.toUpperCase();
            if (pat.charAt(pat.length - 1) === '*') {
                var prefix = pat.substring(0, pat.length - 1);
                artccs.forEach(function(code) {
                    if (code.indexOf(prefix) === 0 && !seen[code]) {
                        result.push(code);
                        seen[code] = true;
                    }
                });
            } else if (!seen[pat]) {
                result.push(pat);
                seen[pat] = true;
            }
        });
        return result;
    }

    function getKnownARTCCs() {
        if (typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.ARTCCS) {
            return FacilityHierarchy.ARTCCS;
        }
        return [];
    }

    /**
     * Expand FIR values into AST nodes (THRU terms).
     */
    function expandFIRValues(values, isMultiValued) {
        var allNodes = [];

        values.forEach(function(val) {
            var codes = resolveFIR(val);
            if (codes.length === 0) {
                // Unresolved FIR — create a THRU term that won't match anything
                allNodes.push({ type: 'TERM', qualifier: 'THRU', value: '_FIR_UNRESOLVED_' + val });
            } else if (codes.length === 1) {
                allNodes.push({ type: 'TERM', qualifier: 'THRU', value: codes[0] });
            } else {
                // Multiple codes — OR group (traverse any of these)
                allNodes.push({
                    type: 'OR',
                    children: codes.map(function(c) {
                        return { type: 'TERM', qualifier: 'THRU', value: c };
                    })
                });
            }
        });

        if (allNodes.length === 0) return { type: 'AND', children: [] };
        if (allNodes.length === 1) return allNodes[0];
        if (isMultiValued) {
            // FIR:X,Y = traverse both → AND
            return { type: 'AND', children: allNodes };
        }
        return { type: 'OR', children: allNodes };
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    global.PlaybookFilterParser = {
        // Core
        tokenize: tokenize,
        parse: parse,
        evaluate: evaluate,
        serialize: serialize,
        collectTerms: collectTerms,

        // FIR
        loadFIRTiers: loadFIRTiers,
        setFIRTiers: setFIRTiers,
        resolveFIR: resolveFIR,

        // Constants (for builder UI)
        QUALIFIERS: Object.keys(QUALIFIERS),
        MULTI_VALUED: MULTI_VALUED
    };

})(window);
