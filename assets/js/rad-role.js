/**
 * RAD Role Module — Role detection, manual override, and UI capability gating.
 *
 * Public API:
 *   RADRole.init()
 *   RADRole.getRole() -> 'TMU'|'ATC'|'PILOT'|'VA'|'OBSERVER'
 *   RADRole.getDetectedRole() -> auto-detected role before any override
 *   RADRole.isOverride() -> boolean
 *   RADRole.getContext()
 *   RADRole.can(capability)
 *   RADRole.setOverride(role)   — manually set role (or null to clear)
 *   RADRole.setVAContext(airlineIcao)
 *   RADRole.onRoleChanged(callback)
 */
window.RADRole = (function() {
    var currentRole = null;
    var detectedRole = null;
    var overrideActive = false;
    var allowedRoles = [];
    var currentContext = {};
    var capabilities = {};
    var allowedTabs = [];
    var changeCallbacks = [];
    var refreshTimer = null;
    var carrierFilter = sessionStorage.getItem('RAD_VA_CONTEXT') || '';

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

        // Check sessionStorage for role override
        var savedOverride = sessionStorage.getItem('RAD_ROLE_OVERRIDE');
        if (savedOverride) {
            params.override_role = savedOverride;
        }

        $.get('api/rad/role.php', params)
            .done(function(response) {
                if (response.status === 'ok' && response.data) {
                    var d = response.data;
                    var oldRole = currentRole;
                    currentRole = d.role;
                    detectedRole = d.detected_role || d.role;
                    overrideActive = d.is_override || false;
                    allowedRoles = d.allowed_roles || [d.role];
                    currentContext = d.context || {};
                    capabilities = d.capabilities || {};
                    allowedTabs = capabilities.tabs || [];

                    // Populate carrier datalist if provided
                    if (d.carriers && d.carriers.length > 0) {
                        var $dl = $('#rad_carrier_list');
                        if ($dl.length && $dl.children().length === 0) {
                            d.carriers.forEach(function(c) {
                                $dl.append('<option value="' + c.icao + '">' + c.icao + ' — ' + (c.name || '') + '</option>');
                            });
                        }
                    }

                    applyRoleUI();

                    if (oldRole !== currentRole) {
                        RADEventBus.emit('role:detected', {
                            role: currentRole,
                            detectedRole: detectedRole,
                            isOverride: overrideActive,
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
                currentRole = 'OBSERVER';
                detectedRole = 'OBSERVER';
                overrideActive = false;
                allowedRoles = ['OBSERVER'];
                capabilities = {};
                allowedTabs = [];
                applyRoleUI();
            });
    }

    function applyRoleUI() {
        // Update role indicator badge
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

            var html = '<span class="badge ' + badgeClass + '">' + roleLabel + '</span>';
            if (overrideActive) {
                html += ' <span class="rad-role-override-tag">' + PERTII18n.t('rad.role.override') + '</span>';
            }
            if (currentContext.callsign) html += ' <span class="text-muted ml-1">' + currentContext.callsign + '</span>';
            if (currentContext.artcc_id) html += ' <span class="text-muted">(' + currentContext.artcc_id + ')</span>';
            if (currentContext.airline_icao) html += ' <span class="text-muted">[' + currentContext.airline_icao + ']</span>';
            $indicator.html(html);
        }

        // Update role selector dropdown
        var $selector = $('#rad_role_selector');
        if ($selector.length && allowedRoles.length > 1) {
            $selector.closest('.rad-role-select-wrap').css('display', '');
            var currentVal = $selector.val();
            var selectedVal = overrideActive ? currentRole : '';
            $selector.empty();
            $selector.append('<option value="">' + PERTII18n.t('rad.role.auto') + ' (' + detectedRole + ')</option>');
            allowedRoles.forEach(function(role) {
                if (role === 'OBSERVER') return; // no use selecting observer manually
                var label = PERTII18n.t('rad.role.' + role.toLowerCase());
                if (label === 'rad.role.' + role.toLowerCase()) label = role;
                $selector.append('<option value="' + role + '"' + (selectedVal === role ? ' selected' : '') + '>' + label + '</option>');
            });
        } else if ($selector.length) {
            $selector.closest('.rad-role-select-wrap').css('display', 'none');
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

        // Show VA carrier selector for VA and TMU roles
        var $vaSelector = $('#rad_va_selector');
        if ($vaSelector.length) {
            if (currentRole === 'VA' || currentRole === 'TMU') {
                $vaSelector.css('display', '');
            } else {
                $vaSelector.css('display', 'none');
            }
        }
    }

    function setOverride(role) {
        if (role) {
            sessionStorage.setItem('RAD_ROLE_OVERRIDE', role);
        } else {
            sessionStorage.removeItem('RAD_ROLE_OVERRIDE');
        }
        fetchRole();
    }

    function setVAContext(airlineIcao) {
        carrierFilter = (airlineIcao || '').toUpperCase().trim();
        if (carrierFilter) {
            sessionStorage.setItem('RAD_VA_CONTEXT', carrierFilter);
        } else {
            sessionStorage.removeItem('RAD_VA_CONTEXT');
        }
        fetchRole(carrierFilter || undefined);
        // Notify monitoring to re-filter
        RADEventBus.emit('carrier:changed', { carrier: carrierFilter });
    }

    return {
        init: init,
        getRole: function() { return currentRole; },
        getDetectedRole: function() { return detectedRole; },
        isOverride: function() { return overrideActive; },
        getContext: function() { return currentContext; },
        getCarrier: function() { return carrierFilter; },
        can: function(cap) { return capabilities[cap] === true; },
        setOverride: setOverride,
        setVAContext: setVAContext,
        onRoleChanged: function(cb) { changeCallbacks.push(cb); },
        refresh: function() { fetchRole(); }
    };
})();
