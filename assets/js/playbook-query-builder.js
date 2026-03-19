/**
 * PlaybookQueryBuilder — Visual query builder panel for Playbook compound filters.
 *
 * Renders an AST as interactive groups/chips and syncs bidirectionally
 * with the text search bar. Uses PlaybookFilterParser for parsing/serialization.
 *
 * @see docs/superpowers/specs/2026-03-18-playbook-compound-filter-design.md
 */
(function(global) {
    'use strict';

    var t = typeof PERTII18n !== 'undefined' ? PERTII18n.t.bind(PERTII18n) : function(k) { return k; };

    /**
     * @param {Object} config
     * @param {string} config.container - Selector for builder content container
     * @param {string} config.searchInput - Selector for search text input
     * @param {Function} config.onUpdate - Called with (text) when builder modifies expression
     */
    function PlaybookQueryBuilder(config) {
        this.$container = $(config.container);
        this.$overlay = this.$container.closest('.pb-builder-overlay');
        this.$searchInput = $(config.searchInput);
        this.onUpdate = config.onUpdate || function() {};
        this._ast = null;
        this._visible = false;
        this._minimized = false;
    }

    PlaybookQueryBuilder.prototype.show = function() {
        this.$overlay.show();
        this._visible = true;
        this.renderFromAST(this._ast);
    };

    PlaybookQueryBuilder.prototype.hide = function() {
        this.$overlay.hide();
        this._visible = false;
    };

    PlaybookQueryBuilder.prototype.toggle = function() {
        if (this._visible) this.hide();
        else this.show();
    };

    PlaybookQueryBuilder.prototype.isVisible = function() {
        return this._visible;
    };

    PlaybookQueryBuilder.prototype.toggleMinimize = function() {
        this._minimized = !this._minimized;
        this.$overlay.toggleClass('pb-builder-minimized', this._minimized);
        var $icon = this.$overlay.find('#pb_builder_minimize i');
        $icon.toggleClass('fa-chevron-up', !this._minimized);
        $icon.toggleClass('fa-chevron-down', this._minimized);
    };

    /**
     * Render the builder UI from an AST.
     */
    PlaybookQueryBuilder.prototype.renderFromAST = function(ast) {
        this._ast = ast;
        if (!this._visible) return;

        var self = this;
        var html = '';

        if (!ast) {
            html += '<div class="pb-builder-empty">' + t('playbook.builder.emptyGroup') + '</div>';
            html += this._renderAddGroupBtn();
            html += this._renderPreview('');
            this.$container.html(html);
            this._bindEvents();
            return;
        }

        // Parse error state
        if (ast === '_ERROR') {
            html += '<div class="pb-builder-warning">' + t('playbook.builder.warningParseError') + '</div>';
            this.$container.html(html);
            return;
        }

        // Normalize: top-level OR → multiple groups; anything else → single group
        var topGroups = (ast.type === 'OR' && !ast._unqualified) ? ast.children : [ast];

        topGroups.forEach(function(group, gi) {
            if (gi > 0) {
                html += '<div class="pb-builder-or-divider">OR</div>';
            }
            html += self._renderGroup(group, gi);
        });

        html += this._renderAddGroupBtn();
        html += this._renderPreview(PlaybookFilterParser.serialize(ast));

        this.$container.html(html);
        this._bindEvents();
    };

    /**
     * Render a single AND group as a bordered card with chips.
     */
    PlaybookQueryBuilder.prototype._renderGroup = function(group, groupIndex) {
        var self = this;
        var html = '<div class="pb-builder-group" data-group="' + groupIndex + '">';
        html += '<div class="pb-builder-group-label">';
        html += t('playbook.builder.groupLabel', { n: groupIndex + 1 });
        html += ' <span style="opacity:0.5;">(' + t('playbook.builder.groupHint') + ')</span>';
        html += '</div>';
        html += '<div class="pb-builder-chips">';

        var terms = PlaybookFilterParser.collectTerms(group);
        var hasFH = typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.isLoaded;

        terms.forEach(function(term, ti) {
            html += self._renderChip(term, groupIndex, ti, hasFH);
        });

        html += '</div>';
        html += '<button class="pb-builder-add" data-action="add-condition" data-group="' + groupIndex + '">';
        html += t('playbook.builder.addCondition');
        html += '</button>';
        html += '</div>';
        return html;
    };

    /**
     * Render a single condition chip.
     */
    PlaybookQueryBuilder.prototype._renderChip = function(term, groupIndex, termIndex, hasFH) {
        var qualPrefix = '';
        if (term.qualifier === 'THRU') qualPrefix = 'THRU:';
        else if (term.qualifier === 'ORIG') qualPrefix = 'ORIG:';
        else if (term.qualifier === 'DEST') qualPrefix = 'DEST:';

        var label = (term.negated ? '-' : '') + qualPrefix + term.value;

        // Region color from FacilityHierarchy
        var bgStyle = '';
        if (hasFH) {
            var regionBg = FacilityHierarchy.getRegionBgColor(term.value);
            var regionColor = FacilityHierarchy.getRegionColor(term.value);
            if (!regionBg && term.value.length > 3) {
                var p4 = term.value.substring(0, 4);
                var p3 = term.value.substring(0, 3);
                if (term.value.length > 4) {
                    regionBg = FacilityHierarchy.getRegionBgColor(p4);
                    regionColor = FacilityHierarchy.getRegionColor(p4);
                }
                if (!regionBg) {
                    regionBg = FacilityHierarchy.getRegionBgColor(p3);
                    regionColor = FacilityHierarchy.getRegionColor(p3);
                }
            }
            if (regionBg) bgStyle = 'background:' + regionBg + ';color:' + (regionColor || '#495057') + ';';
        }

        var borderColor = term.negated ? '#dc3545' : '#28a745';
        var style = bgStyle + 'border-color:' + borderColor + ';';
        var cls = 'pb-builder-chip' + (term.negated ? ' pb-builder-chip-negated' : '');

        return '<span class="' + cls + '" style="' + style + '" data-group="' + groupIndex + '" data-term="' + termIndex + '">' +
            escHtml(label) +
            '<span class="pb-builder-chip-remove" data-action="remove-chip" data-group="' + groupIndex + '" data-term="' + termIndex + '" title="' + t('playbook.builder.removeChip') + '">&times;</span>' +
            '</span>';
    };

    PlaybookQueryBuilder.prototype._renderAddGroupBtn = function() {
        return '<div class="pb-builder-add-group" data-action="add-group">' +
            '<span class="pb-builder-add">' + t('playbook.builder.addGroup') + '</span>' +
            '</div>';
    };

    PlaybookQueryBuilder.prototype._renderPreview = function(text) {
        return '<div class="pb-builder-preview-label">' + t('playbook.builder.previewLabel') + '</div>' +
            '<div class="pb-builder-preview" data-action="edit-preview" title="' + t('playbook.builder.previewClickHint') + '">' +
            (text ? escHtml(text) : '<em style="opacity:0.5;">' + t('playbook.builder.emptyGroup') + '</em>') +
            '</div>';
    };

    /**
     * Bind event handlers for builder interactions.
     */
    PlaybookQueryBuilder.prototype._bindEvents = function() {
        var self = this;

        // Remove all previous builder handlers to prevent accumulation
        this.$container.off('.builder');

        // Remove chip
        this.$container.on('click.builder', '[data-action="remove-chip"]', function(e) {
            e.stopPropagation();
            self._removeTermFromAST(
                parseInt($(this).data('group')),
                parseInt($(this).data('term'))
            );
        });

        // Add condition — show inline form
        this.$container.on('click.builder', '[data-action="add-condition"]', function(e) {
            e.stopPropagation();
            var gi = parseInt($(this).data('group'));
            var $btn = $(this);
            if ($btn.next('.pb-builder-add-form').length) return; // already open

            var formHtml = '<div class="pb-builder-add-form" data-group="' + gi + '">' +
                '<select class="pb-builder-add-qual">' +
                '<option value="THRU">THRU</option>' +
                '<option value="ORIG">ORIG</option>' +
                '<option value="DEST">DEST</option>' +
                '<option value="FIR">FIR</option>' +
                '<option value="AVOID">AVOID</option>' +
                '<option value="-THRU">-THRU</option>' +
                '<option value="-ORIG">-ORIG</option>' +
                '<option value="-DEST">-DEST</option>' +
                '</select>' +
                '<input type="text" class="pb-builder-add-value" placeholder="e.g. ZDC">' +
                '</div>';
            $btn.after(formHtml);
            $btn.next('.pb-builder-add-form').find('input').focus();
        });

        // Add condition — submit on Enter or blur
        this.$container.on('keydown.builder', '.pb-builder-add-value', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                self._submitAddForm($(this).closest('.pb-builder-add-form'));
            }
            if (e.key === 'Escape') {
                $(this).closest('.pb-builder-add-form').remove();
            }
        });
        this.$container.on('blur.builder', '.pb-builder-add-value', function() {
            var $input = $(this);
            setTimeout(function() {
                var $form = $input.closest('.pb-builder-add-form');
                if (!$form.length || !$.contains(document.documentElement, $form[0])) return;
                var val = $input.val().trim();
                if (val) {
                    self._submitAddForm($form);
                } else {
                    $form.remove();
                }
            }, 0);
        });

        // Add OR group
        this.$container.on('click.builder', '[data-action="add-group"]', function() {
            self._addORGroup();
        });

        // Edit preview — click to switch to text input
        this.$container.on('click.builder', '[data-action="edit-preview"]', function() {
            var currentText = self._ast ? PlaybookFilterParser.serialize(self._ast) : '';
            var $preview = $(this);
            $preview.replaceWith(
                '<input type="text" class="pb-builder-preview pb-builder-preview-edit" value="' + escAttr(currentText) + '">'
            );
            self.$container.find('.pb-builder-preview-edit').focus().select();
        });

        this.$container.on('keydown.builder', '.pb-builder-preview-edit', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                self._submitPreviewEdit($(this).val());
            }
            if (e.key === 'Escape') {
                self.renderFromAST(self._ast);
            }
        });
        this.$container.on('blur.builder', '.pb-builder-preview-edit', function() {
            var $input = $(this);
            setTimeout(function() {
                if (!$input.closest('.pb-builder-preview-edit').length &&
                    !$.contains(document.documentElement, $input[0])) return;
                self._submitPreviewEdit($input.val());
            }, 0);
        });
    };

    /**
     * Remove a term from the AST by group and term index.
     */
    PlaybookQueryBuilder.prototype._removeTermFromAST = function(groupIndex, termIndex) {
        if (!this._ast) return;

        var topGroups = (this._ast.type === 'OR' && !this._ast._unqualified) ? this._ast.children : [this._ast];
        var groupTexts = [];

        for (var gi = 0; gi < topGroups.length; gi++) {
            var groupTerms = PlaybookFilterParser.collectTerms(topGroups[gi]);
            var remainingTerms = [];
            for (var ti = 0; ti < groupTerms.length; ti++) {
                if (gi === groupIndex && ti === termIndex) continue; // skip removed term
                var term = groupTerms[ti];
                var text = (term.negated ? '-' : '') +
                           (term.qualifier ? term.qualifier + ':' : '') +
                           term.value;
                remainingTerms.push(text);
            }
            if (remainingTerms.length > 0) {
                groupTexts.push(remainingTerms.length > 1 ? remainingTerms.join(' & ') : remainingTerms[0]);
            }
        }

        this._updateText(groupTexts.join(' | '));
    };

    /**
     * Submit the inline add-condition form.
     */
    PlaybookQueryBuilder.prototype._submitAddForm = function($form) {
        // Guard: prevent double-submit from Enter keydown → blur race
        if ($form.data('_submitted')) return;
        $form.data('_submitted', true);

        var qual = $form.find('.pb-builder-add-qual').val();
        var val = $form.find('.pb-builder-add-value').val().trim().toUpperCase();
        var isNewGroup = $form.hasClass('pb-builder-new-group-form');
        var targetGroup = parseInt($form.data('group'));
        $form.remove();

        if (!val) return;

        var negated = qual.charAt(0) === '-';
        if (negated) qual = qual.substring(1);

        var term = (negated ? '-' : '') + qual + ':' + val;

        if (!this._ast) {
            this._updateText(term);
            return;
        }

        if (isNewGroup) {
            // New OR group — append with |
            this._updateText(PlaybookFilterParser.serialize(this._ast) + ' | ' + term);
            return;
        }

        // Insert AND condition into the target group
        var topGroups = (this._ast.type === 'OR' && !this._ast._unqualified) ? this._ast.children : [this._ast];
        var groupTexts = [];

        for (var gi = 0; gi < topGroups.length; gi++) {
            var groupTerms = PlaybookFilterParser.collectTerms(topGroups[gi]);
            var termTexts = [];
            for (var ti = 0; ti < groupTerms.length; ti++) {
                var t = groupTerms[ti];
                termTexts.push(
                    (t.negated ? '-' : '') +
                    (t.qualifier ? t.qualifier + ':' : '') +
                    t.value
                );
            }
            if (gi === targetGroup || (isNaN(targetGroup) && gi === topGroups.length - 1)) {
                termTexts.push(term);
            }
            groupTexts.push(termTexts.length > 1 ? termTexts.join(' & ') : termTexts[0]);
        }

        this._updateText(groupTexts.join(' | '));
    };

    /**
     * Add a new empty OR group by showing an inline form.
     */
    PlaybookQueryBuilder.prototype._addORGroup = function() {
        var $addGroup = this.$container.find('[data-action="add-group"]');
        if ($addGroup.prev('.pb-builder-add-form').length) return; // already open

        var formHtml = '<div class="pb-builder-add-form pb-builder-new-group-form">' +
            '<select class="pb-builder-add-qual">' +
            '<option value="THRU">THRU</option>' +
            '<option value="ORIG">ORIG</option>' +
            '<option value="DEST">DEST</option>' +
            '<option value="FIR">FIR</option>' +
            '<option value="AVOID">AVOID</option>' +
            '<option value="-THRU">-THRU</option>' +
            '<option value="-ORIG">-ORIG</option>' +
            '<option value="-DEST">-DEST</option>' +
            '</select>' +
            '<input type="text" class="pb-builder-add-value" placeholder="e.g. ZDC">' +
            '</div>';
        $addGroup.before(formHtml);
        $addGroup.prev('.pb-builder-add-form').find('input').focus();
    };

    /**
     * Submit text from the preview edit input.
     */
    PlaybookQueryBuilder.prototype._submitPreviewEdit = function(text) {
        this._updateText(text);
    };

    /**
     * Update the search text and trigger filter refresh.
     */
    PlaybookQueryBuilder.prototype._updateText = function(text) {
        this.$searchInput.val(text);
        this.onUpdate(text);
    };

    PlaybookQueryBuilder.prototype.destroy = function() {
        this.$container.off('.builder');
    };

    // Helpers
    function escHtml(s) {
        if (!s) return '';
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function escAttr(s) {
        return escHtml(s).replace(/'/g, '&#39;');
    }

    global.PlaybookQueryBuilder = PlaybookQueryBuilder;

})(window);
