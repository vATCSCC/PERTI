/**
 * RAD Clearance Builder — Route diff engine + phraseology generator + structured form.
 *
 * Public API:
 *   RADClearanceBuilder.init(container)
 *   RADClearanceBuilder.setRoutes(filedRoute, assignedRoute, callsign, dest)
 *   RADClearanceBuilder.getDiff()
 *   RADClearanceBuilder.getClearanceText()
 *   RADClearanceBuilder.getSegments()
 *   RADClearanceBuilder.setClosingPhrase(phrase)
 *   RADClearanceBuilder.reset()
 */
window.RADClearanceBuilder = (function() {
    var container = null;
    var filedRoute = '';
    var assignedRoute = '';
    var diffResult = null;
    var closingPhrase = 'then_as_filed';
    var callsign = '';
    var clearanceLimit = '';

    // =========================================================================
    // LCS Diff Engine
    // =========================================================================

    /**
     * Tokenize a route string into uppercase waypoint/airway tokens.
     * Strips empty tokens from extra whitespace.
     */
    function tokenize(route) {
        if (!route) return [];
        return route.trim().toUpperCase().split(/\s+/).filter(function(t) { return t.length > 0; });
    }

    /**
     * Longest Common Subsequence — returns array of common tokens in order.
     * Standard DP approach: O(m*n) where m,n are token counts.
     */
    function lcs(a, b) {
        var m = a.length, n = b.length;
        var dp = [];
        var i, j;
        for (i = 0; i <= m; i++) {
            dp[i] = [];
            for (j = 0; j <= n; j++) {
                if (i === 0 || j === 0) {
                    dp[i][j] = 0;
                } else if (a[i - 1] === b[j - 1]) {
                    dp[i][j] = dp[i - 1][j - 1] + 1;
                } else {
                    dp[i][j] = Math.max(dp[i - 1][j], dp[i][j - 1]);
                }
            }
        }

        var result = [];
        i = m; j = n;
        while (i > 0 && j > 0) {
            if (a[i - 1] === b[j - 1]) {
                result.unshift(a[i - 1]);
                i--; j--;
            } else if (dp[i - 1][j] > dp[i][j - 1]) {
                i--;
            } else {
                j--;
            }
        }
        return result;
    }

    /**
     * Compute structured diff between filed and assigned routes.
     * Returns: { anchors, segments, clearance_limit }
     *
     * Each segment: { anchor_before, anchor_after, removed[], inserted[], type }
     *   type: 'begin' (before first anchor), 'mid' (between anchors), 'end' (after last anchor), 'full' (no anchors)
     */
    function computeDiff(filed, assigned) {
        var filedTokens = tokenize(filed);
        var assignedTokens = tokenize(assigned);

        if (filedTokens.length === 0 || assignedTokens.length === 0) {
            return null;
        }

        // Check if routes are identical
        if (filedTokens.join(' ') === assignedTokens.join(' ')) {
            return null;
        }

        var anchors = lcs(filedTokens, assignedTokens);

        if (anchors.length === 0) {
            return {
                anchors: [],
                segments: [{
                    anchor_before: null,
                    anchor_after: null,
                    removed: filedTokens,
                    inserted: assignedTokens,
                    type: 'full'
                }],
                clearance_limit: assignedTokens[assignedTokens.length - 1] || ''
            };
        }

        // Walk both token arrays, building segments between anchors
        var segments = [];
        var filedIdx = 0;
        var assignedIdx = 0;

        for (var a = 0; a < anchors.length; a++) {
            var anchor = anchors[a];
            // Find next occurrence of anchor at or after current index
            var filedPos = indexOf(filedTokens, anchor, filedIdx);
            var assignedPos = indexOf(assignedTokens, anchor, assignedIdx);

            var removed = filedTokens.slice(filedIdx, filedPos);
            var inserted = assignedTokens.slice(assignedIdx, assignedPos);

            if (removed.length > 0 || inserted.length > 0) {
                var prevAnchor = a > 0 ? anchors[a - 1] : null;
                var segType = prevAnchor ? 'mid' : 'begin';
                segments.push({
                    anchor_before: prevAnchor,
                    anchor_after: anchor,
                    removed: removed,
                    inserted: inserted,
                    type: segType
                });
            }

            filedIdx = filedPos + 1;
            assignedIdx = assignedPos + 1;
        }

        // Tokens after the last anchor
        var trailingRemoved = filedTokens.slice(filedIdx);
        var trailingInserted = assignedTokens.slice(assignedIdx);
        if (trailingRemoved.length > 0 || trailingInserted.length > 0) {
            segments.push({
                anchor_before: anchors[anchors.length - 1],
                anchor_after: null,
                removed: trailingRemoved,
                inserted: trailingInserted,
                type: 'end'
            });
        }

        // If routes differ only in anchor ordering (no actual segment changes), return null
        if (segments.length === 0) {
            return null;
        }

        return {
            anchors: anchors,
            segments: segments,
            clearance_limit: assignedTokens[assignedTokens.length - 1] || ''
        };
    }

    /** Array.indexOf with startFrom parameter (ES3 compatible) */
    function indexOf(arr, val, startFrom) {
        for (var i = startFrom || 0; i < arr.length; i++) {
            if (arr[i] === val) return i;
        }
        return -1;
    }

    // =========================================================================
    // Phraseology Generator
    // =========================================================================

    /**
     * Generate ATC clearance phraseology from diff result.
     */
    function generatePhraseology(diff) {
        if (!diff || !diff.segments || diff.segments.length === 0) {
            return '';
        }

        var closing = closingPhrase === 'rest_unchanged'
            ? 'REST OF ROUTE UNCHANGED'
            : 'THEN AS FILED';

        var parts = [];
        parts.push(callsign || '{CALLSIGN}');
        parts.push('CLEARED TO');
        parts.push(diff.clearance_limit || clearanceLimit || '{DESTINATION}');
        parts.push('VIA');

        diff.segments.forEach(function(seg, idx) {
            if (idx > 0) parts.push('THEN');

            if (seg.type === 'begin') {
                parts.push(seg.inserted.join(' '));
                if (seg.anchor_after) parts.push(seg.anchor_after);
            } else if (seg.type === 'mid') {
                parts.push('AFTER ' + seg.anchor_before);
                parts.push(seg.inserted.join(' '));
                if (seg.anchor_after) parts.push(seg.anchor_after);
            } else if (seg.type === 'end') {
                parts.push('AFTER ' + seg.anchor_before);
                parts.push(seg.inserted.join(' '));
            } else if (seg.type === 'full') {
                parts.push(seg.inserted.join(' '));
            }
        });

        // Only add closing phrase if last segment isn't 'end' or 'full'
        var lastSeg = diff.segments[diff.segments.length - 1];
        if (lastSeg.type !== 'end' && lastSeg.type !== 'full') {
            parts.push(closing);
        }

        return parts.join(' ').replace(/\s+/g, ' ').trim();
    }

    // =========================================================================
    // Form Renderer
    // =========================================================================

    function render() {
        if (!container) return;

        if (!diffResult || !diffResult.segments || diffResult.segments.length === 0) {
            var noChangeMsg = (!filedRoute || !assignedRoute)
                ? PERTII18n.t('rad.amendment.noPreview')
                : PERTII18n.t('rad.clearance.noChanges');
            container.innerHTML = '<div class="text-muted" style="padding:8px;">' + noChangeMsg + '</div>';
            return;
        }

        var html = '';

        // Auto-diff visualization
        html += '<div class="rad-clearance-diff">';
        html += '<div class="rad-clearance-diff-label">' + PERTII18n.t('rad.clearance.autoDetected') + '</div>';
        html += renderDiffVisualization(diffResult);
        html += '</div>';

        // Segment list
        html += '<div class="rad-clearance-segments">';
        diffResult.segments.forEach(function(seg, idx) {
            html += renderSegmentRow(seg, idx);
        });
        html += '</div>';

        // Closing phrase toggle
        html += '<div class="rad-clearance-closing">';
        html += '<label>' + PERTII18n.t('rad.clearance.closingPhrase') + ':</label>';
        html += '<select id="rad_closing_phrase" class="form-control form-control-sm" style="width:auto;display:inline-block;margin-left:6px;">';
        html += '<option value="then_as_filed"' + (closingPhrase === 'then_as_filed' ? ' selected' : '') + '>' + PERTII18n.t('rad.clearance.thenAsFiled') + '</option>';
        html += '<option value="rest_unchanged"' + (closingPhrase === 'rest_unchanged' ? ' selected' : '') + '>' + PERTII18n.t('rad.clearance.restUnchanged') + '</option>';
        html += '</select>';
        html += '</div>';

        // Generated clearance preview
        var phraseology = generatePhraseology(diffResult);
        html += '<div class="rad-clearance-preview">';
        html += '<div class="rad-clearance-preview-label">' + PERTII18n.t('rad.clearance.generatedClearance') + '</div>';
        html += '<div class="rad-clearance-text" id="rad_clearance_text">' + escapeHtml(phraseology) + '</div>';
        html += '<button class="btn btn-sm btn-outline-secondary mt-1" id="rad_btn_copy_clearance">';
        html += '<i class="fas fa-copy mr-1"></i>' + PERTII18n.t('rad.clearance.copyToClipboard');
        html += '</button>';
        html += '</div>';

        container.innerHTML = html;

        // Bind closing phrase change
        $('#rad_closing_phrase').on('change', function() {
            closingPhrase = $(this).val();
            render();
            RADEventBus.emit('clearance:updated', {
                text: generatePhraseology(diffResult),
                segments: getStructuredSegments(),
                closingPhrase: closingPhrase
            });
        });

        // Copy to clipboard
        $('#rad_btn_copy_clearance').on('click', function() {
            var text = $('#rad_clearance_text').text();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    PERTIDialog.success(PERTII18n.t('rad.clearance.copied'));
                });
            }
        });
    }

    function renderDiffVisualization(diff) {
        var filedTokens = tokenize(filedRoute);
        var assignedTokens = tokenize(assignedRoute);
        var anchorSet = {};
        diff.anchors.forEach(function(a) { anchorSet[a] = true; });

        var html = '';

        // Filed route with removed tokens struck through
        html += '<div class="rad-diff-row">';
        html += '<span class="rad-diff-label">' + PERTII18n.t('rad.clearance.filedRoute') + ':</span>';
        filedTokens.forEach(function(token) {
            if (anchorSet[token]) {
                html += '<span class="rad-diff-anchor">' + token + '</span> ';
            } else {
                html += '<span class="rad-diff-removed">' + token + '</span> ';
            }
        });
        html += '</div>';

        // Assigned route with new tokens highlighted
        html += '<div class="rad-diff-row">';
        html += '<span class="rad-diff-label">' + PERTII18n.t('rad.clearance.assignedRoute') + ':</span>';
        assignedTokens.forEach(function(token) {
            if (anchorSet[token]) {
                html += '<span class="rad-diff-anchor">' + token + '</span> ';
            } else {
                html += '<span class="rad-diff-inserted">' + token + '</span> ';
            }
        });
        html += '</div>';

        return html;
    }

    function renderSegmentRow(seg, idx) {
        var html = '<div class="rad-clearance-segment" data-idx="' + idx + '">';
        var label = seg.type === 'begin' ? 'BEGIN' : seg.type === 'end' ? 'END' : seg.type === 'full' ? 'FULL' : 'MID';
        html += '<span class="rad-segment-badge">' + label + '</span>';

        if (seg.anchor_before) {
            html += ' after <span class="rad-diff-anchor">' + seg.anchor_before + '</span>';
        }
        if (seg.inserted.length > 0) {
            html += ' <span class="rad-diff-inserted">' + seg.inserted.join(' ') + '</span>';
        }
        if (seg.anchor_after) {
            html += ' <span class="rad-diff-anchor">' + seg.anchor_after + '</span>';
        }

        if (seg.removed.length > 0) {
            html += ' <span class="rad-segment-removed">(replaces: <span class="rad-diff-removed">' + seg.removed.join(' ') + '</span>)</span>';
        }

        html += '</div>';
        return html;
    }

    function getStructuredSegments() {
        if (!diffResult || !diffResult.segments) return [];
        return diffResult.segments.map(function(seg) {
            return {
                anchor_before: seg.anchor_before,
                anchor_after: seg.anchor_after,
                amendment: seg.inserted.join(' '),
                type: seg.type
            };
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // =========================================================================
    // Public API
    // =========================================================================

    function init(containerEl) {
        container = typeof containerEl === 'string'
            ? document.getElementById(containerEl)
            : containerEl;
    }

    function setRoutes(filed, assigned, cs, dest) {
        filedRoute = (filed || '').trim();
        assignedRoute = (assigned || '').trim();
        callsign = cs || callsign || '';
        clearanceLimit = dest || clearanceLimit || '';
        diffResult = computeDiff(filedRoute, assignedRoute);
        render();

        RADEventBus.emit('clearance:updated', {
            text: diffResult ? generatePhraseology(diffResult) : '',
            segments: getStructuredSegments(),
            closingPhrase: closingPhrase
        });
    }

    function reset() {
        filedRoute = '';
        assignedRoute = '';
        diffResult = null;
        callsign = '';
        clearanceLimit = '';
        closingPhrase = 'then_as_filed';
        if (container) container.innerHTML = '';
    }

    return {
        init: init,
        setRoutes: setRoutes,
        getDiff: function() { return diffResult; },
        getClearanceText: function() { return diffResult ? generatePhraseology(diffResult) : ''; },
        getSegments: getStructuredSegments,
        setClosingPhrase: function(phrase) { closingPhrase = phrase; render(); },
        reset: reset
    };
})();
