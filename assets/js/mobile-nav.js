/**
 * MobileNav — Mobile bottom tab bar + slide-up sheet navigation.
 *
 * Usage:
 *   MobileNav.init({
 *     groups: [
 *       { id: 'overview', icon: 'fa-info-circle', tabs: ['#overview', '#dcc_staffing'] },
 *       { id: 'terminal', icon: 'fa-plane', tabs: ['#t_timelines', '#t_staffing', '#configs', '#t_planning'] },
 *     ],
 *     sheetThreshold: 3,  // slide-up sheet when group has >= this many tabs
 *     sidebarSelector: null  // optional: selector for the sidebar col-2 to hide
 *   });
 */
var MobileNav = (function($) {
    'use strict';

    var LG_BREAKPOINT = 992;
    var config = null;
    var $bottomBar = null;
    var $sheet = null;
    var $backdrop = null;
    var activeGroupId = null;
    var initialized = false;

    // --- i18n helper (falls back to raw key) ---
    function t(key) {
        return (typeof PERTII18n !== 'undefined' && PERTII18n.t) ? PERTII18n.t(key) : key.split('.').pop();
    }

    // --- Check if mobile viewport ---
    function isMobile() {
        return window.innerWidth < LG_BREAKPOINT;
    }

    // --- Find which group a tab belongs to ---
    function findGroupForTab(tabId) {
        if (!config) return null;
        var normalized = tabId.replace(/^#/, '');
        for (var i = 0; i < config.groups.length; i++) {
            var g = config.groups[i];
            for (var j = 0; j < g.tabs.length; j++) {
                if (g.tabs[j].replace(/^#/, '') === normalized) return g;
            }
        }
        return null;
    }

    // --- Get the label for a tab ID from the desktop nav pill ---
    function getTabLabel(tabId) {
        var $link = $('a[data-toggle="tab"][href="' + tabId + '"]');
        if ($link.length) {
            // Strip icons, get text only
            var $clone = $link.clone();
            $clone.find('i').remove();
            return $.trim($clone.text());
        }
        // Fallback: humanize the ID
        return tabId.replace(/^#/, '').replace(/_/g, ' ').replace(/\btmr\b/i, '').trim();
    }

    // --- Get FontAwesome icon class for a tab from the desktop nav pill ---
    function getTabIcon(tabId) {
        var $icon = $('a[data-toggle="tab"][href="' + tabId + '"] i.fas');
        if ($icon.length) {
            var classes = $icon.attr('class').split(/\s+/);
            for (var i = 0; i < classes.length; i++) {
                if (classes[i].indexOf('fa-') === 0 && classes[i] !== 'fa-fw') return classes[i];
            }
        }
        return 'fa-circle';
    }

    // --- Render bottom bar HTML ---
    function renderBottomBar() {
        var html = '<nav class="mobile-bottom-tabs" role="tablist">';
        for (var i = 0; i < config.groups.length; i++) {
            var g = config.groups[i];
            var label = t('mobile.tab.' + g.id);
            var activeClass = (i === 0) ? ' active' : '';
            html += '<button class="mobile-tab' + activeClass + '" data-group="' + g.id + '" role="tab" aria-label="' + label + '" aria-expanded="false">';
            html += '<i class="fas ' + g.icon + '"></i>';
            html += '<span>' + label + '</span>';
            html += '</button>';
        }
        html += '</nav>';
        return html;
    }

    // --- Render sheet HTML (empty, populated on demand) ---
    function renderSheet() {
        var html = '<div class="mobile-section-sheet-backdrop"></div>';
        html += '<div class="mobile-section-sheet">';
        html += '<div class="mobile-section-sheet-handle"></div>';
        html += '<div class="mobile-section-sheet-title"></div>';
        html += '<div class="mobile-section-sheet-body"></div>';
        html += '</div>';
        return html;
    }

    // --- Render segmented toggle for a group ---
    function renderSegmentToggle(group) {
        var html = '<div class="mobile-segment-toggle" data-group="' + group.id + '">';
        for (var i = 0; i < group.tabs.length; i++) {
            var tabId = group.tabs[i];
            var label = getTabLabel(tabId);
            var activeClass = (i === 0) ? ' active' : '';
            html += '<button class="mobile-segment-btn' + activeClass + '" data-tab="' + tabId + '">' + label + '</button>';
        }
        html += '</div>';
        return html;
    }

    // --- Open slide-up sheet for a group ---
    function openSheet(group) {
        var $body = $sheet.find('.mobile-section-sheet-body');
        $body.empty();

        $sheet.find('.mobile-section-sheet-title').text(t('mobile.sheet.selectSection'));

        // Get currently active tab
        var $activePane = $('.tab-pane.active.show');
        var activePaneId = $activePane.length ? '#' + $activePane.attr('id') : '';

        for (var i = 0; i < group.tabs.length; i++) {
            var tabId = group.tabs[i];
            var label = getTabLabel(tabId);
            var icon = getTabIcon(tabId);
            var isActive = (tabId === activePaneId) ? ' active' : '';
            $body.append(
                '<div class="mobile-section-sheet-item' + isActive + '" data-tab="' + tabId + '">' +
                '<i class="fas ' + icon + '"></i>' +
                '<span>' + label + '</span>' +
                '</div>'
            );
        }

        $backdrop.addClass('show');
        $sheet.addClass('show');
        $bottomBar.find('.mobile-tab[data-group="' + group.id + '"]').attr('aria-expanded', 'true');
        // Focus first item for keyboard accessibility
        setTimeout(function() {
            $sheet.find('.mobile-section-sheet-item').first().attr('tabindex', '-1').focus();
        }, 300);
    }

    // --- Close slide-up sheet ---
    function closeSheet() {
        $sheet.removeClass('show');
        $backdrop.removeClass('show');
        $bottomBar.find('.mobile-tab').attr('aria-expanded', 'false');
        // Restore focus to the active bottom tab
        if ($bottomBar) {
            $bottomBar.find('.mobile-tab.active').focus();
        }
    }

    // --- Activate a tab (delegates to Bootstrap) ---
    function activateTab(tabId) {
        var $link = $('a[data-toggle="tab"][href="' + tabId + '"]');
        if ($link.length) {
            $link.tab('show');
        }
    }

    // --- Set active group in bottom bar ---
    function setActiveGroup(groupId) {
        activeGroupId = groupId;
        $bottomBar.find('.mobile-tab').removeClass('active');
        $bottomBar.find('.mobile-tab[data-group="' + groupId + '"]').addClass('active');
    }

    // --- Handle group tap ---
    function onGroupTap(groupId) {
        var group = null;
        for (var i = 0; i < config.groups.length; i++) {
            if (config.groups[i].id === groupId) { group = config.groups[i]; break; }
        }
        if (!group) return;

        var threshold = config.sheetThreshold || 3;

        if (group.tabs.length >= threshold) {
            // Slide-up sheet
            if (activeGroupId === groupId && $sheet.hasClass('show')) {
                closeSheet();
            } else {
                setActiveGroup(groupId);
                openSheet(group);
            }
        } else {
            // Direct navigation — activate the first tab in the group
            // and show segment toggle
            setActiveGroup(groupId);
            activateTab(group.tabs[0]);
            showSegmentToggle(group);
        }
    }

    // --- Show/hide segment toggle strips ---
    function showSegmentToggle(group) {
        // Remove any existing segment toggles
        $('.mobile-segment-toggle').remove();

        if (group.tabs.length <= 1) return;

        // Insert segment toggle at the top of the active tab pane
        var $toggle = $(renderSegmentToggle(group));
        var $tabContent = $('.tab-content').first();
        $tabContent.prepend($toggle);

        // Sync active state with currently visible pane
        var $activePane = $('.tab-pane.active.show');
        if ($activePane.length) {
            $toggle.find('.mobile-segment-btn').removeClass('active');
            $toggle.find('.mobile-segment-btn[data-tab="#' + $activePane.attr('id') + '"]').addClass('active');
        }
    }

    // --- Handle tab shown event from Bootstrap (sync bottom bar) ---
    function onTabShown(e) {
        if (!isMobile()) return;
        var tabId = $(e.target).attr('href');
        var group = findGroupForTab(tabId);
        if (group) {
            setActiveGroup(group.id);

            // Update segment toggle if visible
            var $toggle = $('.mobile-segment-toggle[data-group="' + group.id + '"]');
            // Clean up segment toggles from other groups
            $('.mobile-segment-toggle').not($toggle).remove();
            if ($toggle.length) {
                $toggle.find('.mobile-segment-btn').removeClass('active');
                $toggle.find('.mobile-segment-btn[data-tab="' + tabId + '"]').addClass('active');
            }
        }
    }

    // --- Handle viewport resize ---
    function onResize() {
        if (!config) return;
        if (isMobile()) {
            $bottomBar.show();
            // Hide sidebar
            if (config.sidebarSelector) {
                $(config.sidebarSelector).addClass('mobile-hide-sidebar');
            }
            // Make content full width
            if (config.contentSelector) {
                $(config.contentSelector).addClass('mobile-full-width');
            }
            // Add body class for padding
            $('body').addClass('has-mobile-tabs');
        } else {
            $bottomBar.hide();
            closeSheet();
            $('.mobile-segment-toggle').remove();
            if (config.sidebarSelector) {
                $(config.sidebarSelector).removeClass('mobile-hide-sidebar');
            }
            if (config.contentSelector) {
                $(config.contentSelector).removeClass('mobile-full-width');
            }
            $('body').removeClass('has-mobile-tabs');
        }
    }

    // --- Touch handling for sheet swipe-to-dismiss ---
    function initSheetTouch() {
        var startY = 0;
        var currentY = 0;
        var isDragging = false;
        var sheetEl = $sheet[0];

        sheetEl.addEventListener('touchstart', function(e) {
            startY = e.touches[0].clientY;
            isDragging = true;
        }, { passive: true });

        sheetEl.addEventListener('touchmove', function(e) {
            if (!isDragging) return;
            currentY = e.touches[0].clientY;
            var diff = currentY - startY;
            if (diff > 0) {
                sheetEl.style.transform = 'translateY(' + diff + 'px)';
            }
        }, { passive: true });

        sheetEl.addEventListener('touchend', function() {
            if (!isDragging) return;
            isDragging = false;
            var diff = currentY - startY;
            if (diff > 80) {
                closeSheet();
            }
            sheetEl.style.transform = '';
        }, { passive: true });

        sheetEl.addEventListener('touchcancel', function() {
            isDragging = false;
            sheetEl.style.transform = '';
        }, { passive: true });
    }

    // --- Public init ---
    function init(cfg) {
        if (initialized) return;
        config = cfg;
        initialized = true;

        // Inject bottom bar
        var barHtml = renderBottomBar();
        $('body').append(barHtml);
        $bottomBar = $('.mobile-bottom-tabs');

        // Inject sheet
        var sheetHtml = renderSheet();
        $('body').append(sheetHtml);
        $sheet = $('.mobile-section-sheet');
        $backdrop = $('.mobile-section-sheet-backdrop');

        // Set initial active group
        activeGroupId = config.groups[0].id;

        // --- Event handlers ---

        // Bottom tab tap
        $bottomBar.on('click', '.mobile-tab', function() {
            onGroupTap($(this).data('group'));
        });

        // Sheet item tap
        $sheet.on('click', '.mobile-section-sheet-item', function() {
            var tabId = $(this).data('tab');
            activateTab(tabId);
            closeSheet();
        });

        // Backdrop tap
        $backdrop.on('click', function() {
            closeSheet();
        });

        // Segment toggle tap
        $(document).on('click', '.mobile-segment-btn', function() {
            var tabId = $(this).data('tab');
            activateTab(tabId);
            // Update active state
            $(this).closest('.mobile-segment-toggle').find('.mobile-segment-btn').removeClass('active');
            $(this).addClass('active');
        });

        // Escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $sheet.hasClass('show')) {
                closeSheet();
            }
        });

        // Bootstrap tab shown event
        $(document).on('shown.bs.tab', 'a[data-toggle="tab"]', onTabShown);

        // Viewport resize
        $(window).on('resize', onResize);

        // Touch swipe on sheet
        initSheetTouch();

        // Initial state
        onResize();
    }

    return { init: init };

})(jQuery);
