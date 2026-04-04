/**
 * RAD Role Module — Role detection and UI capability gating.
 *
 * Public API:
 *   RADRole.init()
 *   RADRole.getRole() -> 'TMU'|'ATC'|'PILOT'|'VA'|'OBSERVER'
 *   RADRole.getContext()
 *   RADRole.can(capability)
 *   RADRole.setVAContext(airlineIcao)
 *   RADRole.onRoleChanged(callback)
 */
window.RADRole = (function() {
    var currentRole = null;
    var currentContext = {};
    var capabilities = {};
    var allowedTabs = [];
    var changeCallbacks = [];
    var refreshTimer = null;

    function init() {
        fetchRole();
        // Re-check every 5 minutes
        refreshTimer = setInterval(fetchRole, 5 * 60 * 1000);
    }

    function fetchRole(vaAirline) {
        var params = {};
        // Check sessionStorage for VA context
        var savedVA = sessionStorage.getItem('RAD_VA_CONTEXT');
        if (vaAirline) {
            params.va_airline = vaAirline;
        } else if (savedVA) {
            params.va_airline = savedVA;
        }

        $.get('api/rad/role.php', params)
            .done(function(response) {
                if (response.status === 'ok' && response.data) {
                    var d = response.data;
                    var oldRole = currentRole;
                    currentRole = d.role;
                    currentContext = d.context || {};
                    capabilities = d.capabilities || {};
                    allowedTabs = capabilities.tabs || [];

                    applyRoleUI();

                    if (oldRole !== currentRole) {
                        RADEventBus.emit('role:detected', {
                            role: currentRole,
                            context: currentContext,
                            capabilities: capabilities
                        });
                        changeCallbacks.forEach(function(cb) {
                            try { cb(currentRole, currentContext); } catch(e) {}
                        });
                    }
                }
            })
            .fail(function() {
                // Default to observer on failure
                currentRole = 'OBSERVER';
                capabilities = {};
                allowedTabs = [];
                applyRoleUI();
            });
    }

    function applyRoleUI() {
        // Update role indicator
        var $indicator = $('#rad_role_indicator');
        if ($indicator.length) {
            var roleKey = (currentRole || 'observer').toLowerCase();
            var roleLabel = PERTII18n.t('rad.role.' + roleKey);
            if (roleLabel === 'rad.role.' + roleKey) roleLabel = currentRole || 'Observer';
            var badgeClass = {
                'TMU': 'badge-danger',
                'ATC': 'badge-primary',
                'PILOT': 'badge-success',
                'VA': 'badge-info',
                'OBSERVER': 'badge-secondary'
            }[currentRole] || 'badge-secondary';

            $indicator.html(
                '<span class="badge ' + badgeClass + '">' + roleLabel + '</span>' +
                (currentContext.callsign ? ' <span class="text-muted ml-1">' + currentContext.callsign + '</span>' : '') +
                (currentContext.artcc_id ? ' <span class="text-muted">(' + currentContext.artcc_id + ')</span>' : '') +
                (currentContext.airline_icao ? ' <span class="text-muted">[' + currentContext.airline_icao + ']</span>' : '')
            );
        }

        // Show/hide tabs based on role
        var allTabs = ['search', 'detail', 'edit', 'monitoring'];
        allTabs.forEach(function(tab) {
            var $tabLink = $('#tab-' + tab).closest('.nav-item');
            if (allowedTabs.indexOf(tab) !== -1) {
                $tabLink.css('display', '');
            } else {
                $tabLink.css('display', 'none');
            }
        });

        // If current active tab is hidden, switch to first allowed tab
        var activeTab = $('#radTabs .nav-link.active').attr('id');
        var activeTabName = activeTab ? activeTab.replace('tab-', '') : '';
        if (allowedTabs.length > 0 && allowedTabs.indexOf(activeTabName) === -1) {
            $('#tab-' + allowedTabs[0]).tab('show');
        }

        // Show VA selector only when no role detected (or already VA)
        var $vaSelector = $('#rad_va_selector');
        if ($vaSelector.length) {
            if (currentRole === 'OBSERVER' || currentRole === 'VA') {
                $vaSelector.css('display', '');
            } else {
                $vaSelector.css('display', 'none');
            }
        }
    }

    function setVAContext(airlineIcao) {
        if (airlineIcao) {
            sessionStorage.setItem('RAD_VA_CONTEXT', airlineIcao);
        } else {
            sessionStorage.removeItem('RAD_VA_CONTEXT');
        }
        fetchRole(airlineIcao || undefined);
    }

    return {
        init: init,
        getRole: function() { return currentRole; },
        getContext: function() { return currentContext; },
        can: function(cap) { return capabilities[cap] === true; },
        setVAContext: setVAContext,
        onRoleChanged: function(cb) { changeCallbacks.push(cb); },
        refresh: function() { fetchRole(); }
    };
})();
