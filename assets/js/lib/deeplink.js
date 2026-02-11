/**
 * PERTI Deep Link Utility
 *
 * Hash-based deep linking for tabbed pages. Auto-discovers Bootstrap tabs,
 * supports custom tab systems via registration, and provides copy-link buttons.
 *
 * URL format: page.php?params#tab-id
 * Compound:   page.php?params#tab/section  (e.g. nod.php#tmi/gs)
 *
 * @module lib/deeplink
 * @version 1.0.0
 */

const PERTIDeepLink = (function() {
    'use strict';

    const _customHandlers = {};
    let _initialized = false;
    let _suppressHashUpdate = false;

    // Only allow safe characters in hash values (prevent selector injection)
    const _SAFE_HASH = /^[a-zA-Z0-9_\-\/]+$/;

    /**
     * Update the URL hash without creating a history entry.
     * @param {string} hash - Hash value (without #)
     */
    function update(hash) {
        if (_suppressHashUpdate) return;
        if (!hash) return;
        var clean = hash.replace(/^#/, '');
        if (!_SAFE_HASH.test(clean)) return;
        history.replaceState(null, '', window.location.pathname + window.location.search + '#' + clean);
    }

    /**
     * Register a custom (non-Bootstrap) tab system.
     * @param {string} name - Handler name (e.g. 'nod', 'splits')
     * @param {Object} handler
     * @param {Function} handler.activate - Called with hash id to activate a tab
     * @param {Function} handler.getCurrent - Returns current active tab id
     */
    function register(name, handler) {
        _customHandlers[name] = handler;
        // If there's a pending hash and no Bootstrap tab matched, try this handler
        _tryRestoreCustom();
    }

    /**
     * Inject copy-link buttons next to tab links matching a selector.
     * @param {string} [selector] - CSS selector for tab containers. Defaults to all nav-pills/nav-tabs.
     */
    function addCopyButtons(selector) {
        var containers = document.querySelectorAll(
            selector || '.nav-pills, .nav-tabs, .nod-panel-tabs, .mode-tabs'
        );
        containers.forEach(function(container) {
            var tabs = container.querySelectorAll(
                'a[data-toggle="tab"], .nod-panel-tab, .mode-tab'
            );
            tabs.forEach(function(tab) {
                if (tab.querySelector('.perti-deeplink-btn')) return;
                var btn = document.createElement('span');
                btn.className = 'perti-deeplink-btn';
                btn.title = 'Copy link';
                btn.innerHTML = '<i class="fas fa-link"></i>';
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    _copyTabLink(btn, tab);
                });
                tab.style.position = 'relative';
                tab.appendChild(btn);
            });
        });
    }

    /**
     * Copy the deep link URL for a specific tab to clipboard and show toast.
     */
    function _copyTabLink(btnEl, tabEl) {
        // Determine the hash for this specific tab
        var hash = '';
        if (tabEl.getAttribute('data-toggle') === 'tab' || tabEl.closest('[data-toggle="tab"]')) {
            // Bootstrap tab: href="#tab_id"
            hash = (tabEl.getAttribute('href') || '').replace(/^#/, '');
        } else if (tabEl.dataset.tab) {
            // NOD custom tab
            hash = tabEl.dataset.tab;
        } else if (tabEl.dataset.mode) {
            // Splits custom tab
            hash = tabEl.dataset.mode;
        }
        var url = window.location.origin + window.location.pathname + window.location.search + (hash ? '#' + hash : '');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function() {
                _showCopiedFeedback(btnEl);
            });
        } else {
            // Fallback for older browsers
            var ta = document.createElement('textarea');
            ta.value = url;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            _showCopiedFeedback(btnEl);
        }
    }

    function _showCopiedFeedback(btnEl) {
        if (btnEl) {
            btnEl.title = 'Copied!';
            btnEl.classList.add('copied');
            setTimeout(function() {
                btnEl.title = 'Copy link';
                btnEl.classList.remove('copied');
            }, 1500);
        }
        // Use SweetAlert2 toast if available
        if (window.Swal) {
            Swal.fire({
                toast: true,
                position: 'bottom-end',
                icon: 'success',
                title: 'Link copied',
                showConfirmButton: false,
                timer: 1500,
                timerProgressBar: true
            });
        }
    }

    /**
     * Try to restore a hash by activating the matching Bootstrap tab.
     * @returns {boolean} true if a Bootstrap tab was found and activated
     */
    function _restoreBootstrapTab(hash) {
        if (typeof $ === 'undefined') return false;
        if (!_SAFE_HASH.test(hash)) return false;
        // hash may be a tab pane ID (e.g. "t_staffing") or a link href target
        var tabLink = $('a[data-toggle="tab"][href="#' + hash + '"]');
        if (tabLink.length) {
            _suppressHashUpdate = true;
            try { tabLink.tab('show'); } catch (e) { /* tab may not exist */ }
            _suppressHashUpdate = false;
            return true;
        }
        return false;
    }

    /**
     * Try to restore hash via registered custom handlers.
     */
    function _tryRestoreCustom() {
        var hash = window.location.hash.replace(/^#/, '');
        if (!hash || !_SAFE_HASH.test(hash)) return;
        // Skip if a Bootstrap tab already handled it
        var base = hash.split('/')[0];
        if (typeof $ !== 'undefined' && _SAFE_HASH.test(base) && $('a[data-toggle="tab"][href="#' + base + '"]').length) return;

        for (var name in _customHandlers) {
            try {
                _suppressHashUpdate = true;
                _customHandlers[name].activate(hash);
                _suppressHashUpdate = false;
                return;
            } catch (e) {
                _suppressHashUpdate = false;
            }
        }
    }

    /**
     * Initialize: listen for Bootstrap tab events and restore hash on load.
     */
    function init() {
        if (_initialized) return;
        _initialized = true;

        // Listen for Bootstrap tab switches
        if (typeof $ !== 'undefined') {
            $(document).on('shown.bs.tab', 'a[data-toggle="tab"]', function(e) {
                var paneId = $(e.target).attr('href');
                if (paneId && paneId.charAt(0) === '#') {
                    update(paneId.substring(1));
                }
            });
        }

        // Restore hash on page load
        var hash = window.location.hash.replace(/^#/, '');
        if (hash) {
            // Defer to allow page JS to finish initializing
            setTimeout(function() {
                var base = hash.split('/')[0];
                if (!_restoreBootstrapTab(base)) {
                    _tryRestoreCustom();
                }
            }, 100);
        }

        // Inject copy-link buttons after a short delay (DOM must be ready)
        setTimeout(function() {
            addCopyButtons();
        }, 500);
    }

    // Auto-init on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        // DOM already loaded (script loaded async or at bottom)
        setTimeout(init, 0);
    }

    return {
        init: init,
        register: register,
        update: update,
        addCopyButtons: addCopyButtons
    };
})();
