/**
 * TMI GS/GDP Module
 *
 * Handles the GS/GDP tab in TMI Publisher.
 * Receives handoff data from GDT page via sessionStorage.
 *
 * Handoff keys:
 *   - tmi_gs_handoff: Ground Stop data
 *   - tmi_gdp_handoff: GDP data
 *
 * @version 1.1.0
 * @date 2026-01-30
 */

(function() {
    'use strict';

    // Module state
    var state = {
        handoffType: null,      // 'GS' or 'GDP'
        handoffData: null,      // Parsed handoff data
        programId: null,
        flightsVisible: false
    };

    // API endpoints
    var API = {
        submitCoordination: 'api/gdt/programs/submit_proposal.php',
        publishDirect: 'api/gdt/programs/publish.php',
        cancelProgram: 'api/gdt/programs/cancel.php',
        flightList: 'api/gdt/programs/flight_list.php'
    };

    /**
     * Initialize module on page load
     */
    function init() {
        // Check URL parameters
        var params = new URLSearchParams(window.location.search);
        var tab = params.get('tab');
        var source = params.get('source');
        var type = params.get('type');

        // Only process if coming from GDT
        if (source !== 'gdt') {
            return;
        }

        // Auto-switch to GS/GDP tab if specified
        if (tab === 'gdp' || tab === 'gsgdp') {
            setTimeout(function() {
                var tabLink = document.getElementById('gsgdp-tab');
                if (tabLink) {
                    tabLink.click();
                }
            }, 100);
        }

        // Try to load handoff data
        loadHandoffData(type);

        // Bind event handlers
        bindEvents();
    }

    /**
     * Load handoff data from sessionStorage
     */
    function loadHandoffData(preferredType) {
        var gsData = null;
        var gdpData = null;

        try {
            var gsStr = sessionStorage.getItem('tmi_gs_handoff');
            var gdpStr = sessionStorage.getItem('tmi_gdp_handoff');

            if (gsStr) gsData = JSON.parse(gsStr);
            if (gdpStr) gdpData = JSON.parse(gdpStr);
        } catch (e) {
            console.error('GSGDP: Error parsing handoff data:', e);
        }

        // Prefer the type specified in URL, otherwise use whichever exists
        if (preferredType === 'gs' && gsData) {
            state.handoffType = 'GS';
            state.handoffData = gsData;
        } else if (preferredType === 'gdp' && gdpData) {
            state.handoffType = 'GDP';
            state.handoffData = gdpData;
        } else if (gsData) {
            state.handoffType = 'GS';
            state.handoffData = gsData;
        } else if (gdpData) {
            state.handoffType = 'GDP';
            state.handoffData = gdpData;
        }

        if (state.handoffData) {
            state.programId = state.handoffData.program_id || null;
            renderHandoffData();
        } else {
            showNoHandoffWarning();
        }
    }

    /**
     * Render the handoff data in the UI
     */
    function renderHandoffData() {
        var data = state.handoffData;
        if (!data) return;

        // Hide warning, show main content
        document.getElementById('gsgdpNoHandoff').style.display = 'none';
        document.getElementById('gsgdpMainContent').style.display = '';

        // Show source info
        var sourceInfo = document.getElementById('gsgdpSourceInfo');
        var sourceDetails = document.getElementById('gsgdpSourceDetails');
        if (sourceInfo && sourceDetails) {
            sourceInfo.style.display = '';
            sourceDetails.textContent = ' - ' + state.handoffType + ' for ' + (data.ctl_element || 'Unknown') +
                                        ' received at ' + formatTime(data.created_at);
        }

        // Update header
        var typeBadge = document.getElementById('gsgdpTypeBadge');
        var programTitle = document.getElementById('gsgdpProgramTitle');
        var headerEl = document.getElementById('gsgdpProgramHeader');

        if (state.handoffType === 'GS') {
            typeBadge.className = 'badge badge-lg badge-danger';
            typeBadge.textContent = 'GROUND STOP';
            programTitle.textContent = 'Ground Stop Details';
            headerEl.className = 'card-header d-flex justify-content-between align-items-center bg-danger text-white';
        } else {
            typeBadge.className = 'badge badge-lg badge-warning';
            typeBadge.textContent = data.program_type || 'GDP';
            programTitle.textContent = 'Ground Delay Program Details';
            headerEl.className = 'card-header d-flex justify-content-between align-items-center bg-warning';
        }

        // Common fields
        document.getElementById('gsgdpCtlElement').textContent = data.ctl_element || '--';
        document.getElementById('gsgdpStartTime').textContent = formatDateTime(data.start_time);
        document.getElementById('gsgdpEndTime').textContent = formatDateTime(data.end_time);

        // Type-specific fields
        if (state.handoffType === 'GS') {
            document.getElementById('gsgdpGsFields').style.display = '';
            document.getElementById('gsgdpGdpFields').style.display = 'none';

            var scope = data.element_type === 'AIRPORT' ? 'Single Airport' :
                        (data.airports || data.ctl_element);
            document.getElementById('gsgdpScope').textContent = scope;
            document.getElementById('gsgdpAffectedFlights').textContent =
                (data.flights && data.flights.length) || (data.simulation_data && data.simulation_data.flights_affected) || '0';
            document.getElementById('gsgdpDuration').textContent = calculateDuration(data.start_time, data.end_time);
        } else {
            document.getElementById('gsgdpGsFields').style.display = 'none';
            document.getElementById('gsgdpGdpFields').style.display = '';

            var summary = data.summary || {};
            document.getElementById('gsgdpProgramRate').textContent =
                (data.program_rate || summary.program_rate || '--') + '/hr';
            document.getElementById('gsgdpAvgDelay').textContent =
                (summary.avg_delay_min || summary.avgDelay || '--') + ' min';
            document.getElementById('gsgdpMaxDelay').textContent =
                (summary.max_delay_min || summary.maxDelay || '--') + ' min';
        }

        // Flight list
        renderFlightList(data.flights || []);

        // Advisory preview
        generateAdvisoryPreview();

        // Auto-select facilities based on CTL element
        autoSelectFacilities(data.ctl_element);
    }

    /**
     * Render flight list table
     */
    function renderFlightList(flights) {
        var countEl = document.getElementById('gsgdpFlightCount');
        var tbody = document.getElementById('gsgdpFlightListBody');

        countEl.textContent = flights.length;

        if (!flights || flights.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No flights</td></tr>';
            return;
        }

        var html = '';
        var maxDisplay = 100;
        var displayFlights = flights.slice(0, maxDisplay);

        displayFlights.forEach(function(f) {
            var delay = f.delay_minutes || f.delayMin || 0;
            var delayClass = delay > 60 ? 'text-danger' : (delay > 30 ? 'text-warning' : '');

            html += '<tr>' +
                '<td class="font-weight-bold">' + escapeHtml(f.callsign || f.acid || '--') + '</td>' +
                '<td>' + escapeHtml(f.dep_airport || f.origin || f.dep || '--') + '</td>' +
                '<td>' + escapeHtml(f.arr_airport || f.destination || f.arr || '--') + '</td>' +
                '<td>' + escapeHtml(f.aircraft_type || f.acType || '--') + '</td>' +
                '<td>' + formatTime(f.original_etd_utc || f.etd || f.scheduledDep) + '</td>' +
                '<td class="font-weight-bold">' + formatTime(f.edct_utc || f.edct || f.ctd) + '</td>' +
                '<td class="' + delayClass + '">' + delay + '</td>' +
                '</tr>';
        });

        if (flights.length > maxDisplay) {
            html += '<tr><td colspan="7" class="text-center text-muted py-2">' +
                    '... and ' + (flights.length - maxDisplay) + ' more flights</td></tr>';
        }

        tbody.innerHTML = html;
    }

    /**
     * Generate advisory preview text
     */
    function generateAdvisoryPreview() {
        var data = state.handoffData;
        if (!data) return;

        var previewEl = document.getElementById('gsgdpAdvisoryPreview');
        var text = '';

        if (state.handoffType === 'GS') {
            text = generateGsAdvisory(data);
        } else {
            text = generateGdpAdvisory(data);
        }

        previewEl.textContent = text;
    }

    /**
     * Generate Ground Stop advisory text
     */
    function generateGsAdvisory(data) {
        var ctlElement = data.ctl_element || 'UNKN';
        var startTime = formatAdvisoryTime(data.start_time);
        var endTime = formatAdvisoryTime(data.end_time);
        var reason = document.getElementById('gsgdpReason')?.value || 'WEATHER';
        var remarks = document.getElementById('gsgdpRemarks')?.value || '';

        var scope = data.airports || ctlElement;

        var text = 'DCC GROUND STOP ADVZY [###]\n\n' +
                   'CTL ELEMENT.................. ' + ctlElement + '\n' +
                   'START TIME................... ' + startTime + '\n' +
                   'END TIME..................... ' + endTime + '\n' +
                   'SCOPE........................ ' + scope + '\n' +
                   'REASON....................... ' + reason + '\n';

        if (remarks) {
            text += 'REMARKS...................... ' + remarks + '\n';
        }

        text += '\nJO/DCC';

        return text;
    }

    /**
     * Generate GDP advisory text
     */
    function generateGdpAdvisory(data) {
        var ctlElement = data.ctl_element || 'UNKN';
        var programType = data.program_type || 'GDP-UDP';
        var startTime = formatAdvisoryTime(data.start_time);
        var endTime = formatAdvisoryTime(data.end_time);
        var programRate = data.program_rate || data.summary?.program_rate || '--';
        var reason = document.getElementById('gsgdpReason')?.value || 'WEATHER';
        var remarks = document.getElementById('gsgdpRemarks')?.value || '';

        var summary = data.summary || {};
        var avgDelay = summary.avg_delay_min || summary.avgDelay || '--';
        var maxDelay = summary.max_delay_min || summary.maxDelay || '--';
        var totalFlights = data.flights?.length || summary.total_flights || '--';

        var text = 'DCC GROUND DELAY PROGRAM ADVZY [###]\n\n' +
                   'CTL ELEMENT.................. ' + ctlElement + '\n' +
                   'PROGRAM TYPE................. ' + programType + '\n' +
                   'START TIME................... ' + startTime + '\n' +
                   'END TIME..................... ' + endTime + '\n' +
                   'PROGRAM RATE................. ' + programRate + '/HR\n' +
                   'REASON....................... ' + reason + '\n' +
                   '\n' +
                   'DELAY PARAMETERS:\n' +
                   '  AVG DELAY.................. ' + avgDelay + ' MIN\n' +
                   '  MAX DELAY.................. ' + maxDelay + ' MIN\n' +
                   '  CONTROLLED FLIGHTS......... ' + totalFlights + '\n';

        if (remarks) {
            text += '\nREMARKS...................... ' + remarks + '\n';
        }

        text += '\nJO/DCC';

        return text;
    }

    /**
     * Auto-select facilities based on CTL element
     */
    function autoSelectFacilities(ctlElement) {
        if (!ctlElement) return;

        // Clear all first
        document.querySelectorAll('.gsgdp-facility-cb').forEach(function(cb) {
            cb.checked = false;
        });

        // Map airports to their overlying ARTCCs
        var airportToArtcc = {
            'KJFK': ['ZNY', 'ZBW'], 'KEWR': ['ZNY'], 'KLGA': ['ZNY'],
            'KATL': ['ZTL'], 'KORD': ['ZAU'], 'KDEN': ['ZDV'],
            'KDFW': ['ZFW'], 'KLAX': ['ZLA'], 'KSFO': ['ZOA'],
            'KMIA': ['ZMA'], 'KBOS': ['ZBW'], 'KPHL': ['ZNY', 'ZDC'],
            'KIAD': ['ZDC'], 'KDCA': ['ZDC'], 'KBWI': ['ZDC'],
            'KMSP': ['ZMP'], 'KDTW': ['ZOB'], 'KCLT': ['ZTL'],
            'KPHX': ['ZAB'], 'KLAS': ['ZLA'], 'KIAH': ['ZHU'],
            'KHOU': ['ZHU'], 'KMCO': ['ZJX'], 'KSEA': ['ZSE']
        };

        var artcc = ctlElement.substring(0, 3);

        // If it's an airport, get overlying ARTCC(s)
        if (ctlElement.length === 4 && ctlElement.startsWith('K')) {
            var artccs = airportToArtcc[ctlElement] || [];
            artccs.forEach(function(a) {
                var cb = document.getElementById('gsgdp_fac_' + a);
                if (cb) cb.checked = true;
            });
        }
        // If it's already an ARTCC
        else if (artcc.startsWith('Z')) {
            var cb = document.getElementById('gsgdp_fac_' + artcc);
            if (cb) cb.checked = true;
        }
    }

    /**
     * Show no handoff warning
     */
    function showNoHandoffWarning() {
        document.getElementById('gsgdpNoHandoff').style.display = '';
        document.getElementById('gsgdpMainContent').style.display = 'none';
        document.getElementById('gsgdpSourceInfo').style.display = 'none';
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Toggle flight list
        var toggleBtn = document.getElementById('gsgdpToggleFlights');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                var container = document.getElementById('gsgdpFlightListContainer');
                state.flightsVisible = !state.flightsVisible;
                container.style.display = state.flightsVisible ? '' : 'none';
                this.innerHTML = state.flightsVisible ?
                    '<i class="fas fa-chevron-up"></i> Hide' :
                    '<i class="fas fa-chevron-down"></i> Show';
            });
        }

        // Copy preview
        var copyBtn = document.getElementById('gsgdpCopyPreview');
        if (copyBtn) {
            copyBtn.addEventListener('click', function() {
                var text = document.getElementById('gsgdpAdvisoryPreview').textContent;
                navigator.clipboard.writeText(text).then(function() {
                    showToast('Copied to clipboard', 'success');
                });
            });
        }

        // Back to GDT
        var backBtn = document.getElementById('gsgdpBackToGdt');
        if (backBtn) {
            backBtn.addEventListener('click', function() {
                window.location.href = 'gdt.php';
            });
        }

        // Discard
        var discardBtn = document.getElementById('gsgdpDiscard');
        if (discardBtn) {
            discardBtn.addEventListener('click', function() {
                if (confirm('Discard this program handoff? You will need to re-submit from GDT.')) {
                    sessionStorage.removeItem('tmi_gs_handoff');
                    sessionStorage.removeItem('tmi_gdp_handoff');
                    state.handoffData = null;
                    state.handoffType = null;
                    showNoHandoffWarning();
                }
            });
        }

        // Reason/remarks change - update preview
        var reasonEl = document.getElementById('gsgdpReason');
        var remarksEl = document.getElementById('gsgdpRemarks');
        if (reasonEl) {
            reasonEl.addEventListener('change', generateAdvisoryPreview);
        }
        if (remarksEl) {
            remarksEl.addEventListener('input', debounce(generateAdvisoryPreview, 300));
        }

        // Submit for Coordination
        var coordBtn = document.getElementById('gsgdpSubmitCoord');
        if (coordBtn) {
            coordBtn.addEventListener('click', handleSubmitCoordination);
        }

        // Publish Direct
        var publishBtn = document.getElementById('gsgdpPublishDirect');
        if (publishBtn) {
            publishBtn.addEventListener('click', handlePublishDirect);
        }

        // Refresh flight list
        var refreshBtn = document.getElementById('gsgdpRefreshFlights');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', handleRefreshFlightList);
        }
    }

    /**
     * Handle refresh flight list button click
     */
    async function handleRefreshFlightList() {
        if (!state.programId) {
            showToast('No program loaded', 'warning');
            return;
        }

        var btn = document.getElementById('gsgdpRefreshFlights');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        try {
            var response = await fetch(API.flightList + '?program_id=' + state.programId + '&include_stats=1');
            var result = await response.json();

            if (result.status === 'ok' && result.data) {
                var flights = result.data.flights || [];
                var stats = result.data.stats || {};

                // Update flight list
                renderFlightList(flights);

                // Update stats badge
                var statsEl = document.getElementById('gsgdpFlightStats');
                if (statsEl && stats.total > 0) {
                    var avgDelay = Math.round(stats.avg_delay_min || 0);
                    var maxDelay = stats.max_delay_min || 0;
                    statsEl.textContent = 'Avg: ' + avgDelay + ' / Max: ' + maxDelay + ' min';
                    statsEl.style.display = '';
                }

                // Update handoff data with fresh flights
                if (state.handoffData) {
                    state.handoffData.flights = flights;
                    state.handoffData.summary = state.handoffData.summary || {};
                    state.handoffData.summary.avg_delay_min = stats.avg_delay_min;
                    state.handoffData.summary.max_delay_min = stats.max_delay_min;
                    state.handoffData.summary.total_flights = stats.total;
                }

                // Update GS/GDP specific displays
                if (state.handoffType === 'GDP') {
                    document.getElementById('gsgdpAvgDelay').textContent = Math.round(stats.avg_delay_min || 0) + ' min';
                    document.getElementById('gsgdpMaxDelay').textContent = (stats.max_delay_min || 0) + ' min';
                } else {
                    document.getElementById('gsgdpAffectedFlights').textContent = stats.total || 0;
                }

                // Regenerate preview
                generateAdvisoryPreview();

                showToast('Flight list refreshed: ' + flights.length + ' flights', 'success');
            } else {
                showToast(result.message || 'Failed to refresh flight list', 'error');
            }
        } catch (err) {
            console.error('GSGDP: Refresh flight list error:', err);
            showToast('Network error refreshing flight list', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt"></i>';
        }
    }

    /**
     * Handle Submit for Coordination
     */
    async function handleSubmitCoordination() {
        if (!state.handoffData || !state.programId) {
            showToast('No program data to submit', 'error');
            return;
        }

        // Get selected facilities
        var facilities = [];
        document.querySelectorAll('.gsgdp-facility-cb:checked').forEach(function(cb) {
            facilities.push(cb.value);
        });

        if (facilities.length === 0) {
            showToast('Select at least one facility for coordination', 'warning');
            return;
        }

        var deadline = parseInt(document.getElementById('gsgdpCoordDeadline').value) || 30;
        var reason = document.getElementById('gsgdpReason').value || 'WEATHER';
        var remarks = document.getElementById('gsgdpRemarks').value || '';

        // Determine coordination mode based on deadline
        var coordMode = 'STANDARD';
        if (deadline <= 15) coordMode = 'EXPEDITED';

        // Build advisory text from preview
        var advisoryText = document.getElementById('gsgdpAdvisoryPreview')?.textContent || '';

        var payload = {
            program_id: state.programId,
            coordination_mode: coordMode,
            deadline_minutes: deadline,
            facilities: facilities,
            advisory_text: advisoryText,
            user_cid: window.TMI_PUBLISHER_CONFIG?.userCid || null,
            user_name: window.TMI_PUBLISHER_CONFIG?.userName || 'Unknown'
        };

        var btn = document.getElementById('gsgdpSubmitCoord');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Submitting...';

        try {
            var response = await fetch(API.submitCoordination, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            var result = await response.json();

            if (result.status === 'ok') {
                showToast('Submitted for coordination - ' + (result.data?.advisory_number || ''), 'success');

                // Clear handoff data
                sessionStorage.removeItem('tmi_gs_handoff');
                sessionStorage.removeItem('tmi_gdp_handoff');

                // Switch to Coordination tab
                setTimeout(function() {
                    var coordTab = document.getElementById('coordination-tab');
                    if (coordTab) coordTab.click();
                }, 500);
            } else {
                showToast(result.message || 'Submission failed', 'error');
            }
        } catch (err) {
            console.error('GSGDP: Coordination submission error:', err);
            showToast('Network error', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-handshake mr-1"></i> Submit for Coordination';
        }
    }

    /**
     * Handle Publish Direct (DCC override)
     */
    async function handlePublishDirect() {
        if (!state.handoffData || !state.programId) {
            showToast('No program data to publish', 'error');
            return;
        }

        // Confirm DCC override
        if (!confirm('PUBLISH DIRECT: This will bypass facility coordination and publish the ' +
                     state.handoffType + ' immediately. Continue?')) {
            return;
        }

        var reason = document.getElementById('gsgdpReason').value || 'WEATHER';
        var remarks = document.getElementById('gsgdpRemarks').value || '';

        // Get selected Discord orgs
        var orgs = [];
        document.querySelectorAll('.discord-org-checkbox-gsgdp:checked').forEach(function(cb) {
            orgs.push(cb.value);
        });

        var payload = {
            program_id: state.programId,
            program_type: state.handoffType,
            ctl_element: state.handoffData.ctl_element,
            start_time: state.handoffData.start_time,
            end_time: state.handoffData.end_time,
            reason: reason,
            remarks: remarks,
            dcc_override: true,
            organizations: orgs,
            flights: state.handoffData.flights || [],
            user_cid: window.TMI_PUBLISHER_CONFIG?.userCid || null,
            user_name: window.TMI_PUBLISHER_CONFIG?.userName || 'Unknown'
        };

        // Add type-specific data
        if (state.handoffType === 'GDP') {
            payload.program_rate = state.handoffData.program_rate;
            payload.slots = state.handoffData.slots || [];
            payload.summary = state.handoffData.summary || {};
        }

        var btn = document.getElementById('gsgdpPublishDirect');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Publishing...';

        try {
            var response = await fetch(API.publishDirect, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            var result = await response.json();

            if (result.status === 'ok') {
                showToast(state.handoffType + ' published successfully', 'success');

                // Clear handoff data
                sessionStorage.removeItem('tmi_gs_handoff');
                sessionStorage.removeItem('tmi_gdp_handoff');

                // Switch to Active TMIs tab
                setTimeout(function() {
                    var activeTab = document.getElementById('active-tab');
                    if (activeTab) activeTab.click();
                }, 500);
            } else {
                showToast(result.message || 'Publish failed', 'error');
            }
        } catch (err) {
            console.error('GSGDP: Publish error:', err);
            showToast('Network error', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-broadcast-tower mr-1"></i> Publish Direct';
        }
    }

    // ============================================================================
    // Utility Functions
    // ============================================================================

    function formatDateTime(dateStr) {
        if (!dateStr) return '--';
        try {
            var d = new Date(dateStr);
            return d.toISOString().slice(0, 16).replace('T', ' ') + 'Z';
        } catch (e) {
            return dateStr;
        }
    }

    function formatTime(dateStr) {
        if (!dateStr) return '--';
        try {
            var d = new Date(dateStr);
            return d.toISOString().slice(11, 16) + 'Z';
        } catch (e) {
            return dateStr;
        }
    }

    function formatAdvisoryTime(dateStr) {
        if (!dateStr) return '--/----Z';
        try {
            var d = new Date(dateStr);
            var day = String(d.getUTCDate()).padStart(2, '0');
            var hr = String(d.getUTCHours()).padStart(2, '0');
            var min = String(d.getUTCMinutes()).padStart(2, '0');
            return day + '/' + hr + min + 'Z';
        } catch (e) {
            return dateStr;
        }
    }

    function calculateDuration(start, end) {
        if (!start || !end) return '--';
        try {
            var s = new Date(start);
            var e = new Date(end);
            var diffMs = e - s;
            var diffMin = Math.round(diffMs / 60000);
            if (diffMin < 60) return diffMin + ' min';
            var hrs = Math.floor(diffMin / 60);
            var mins = diffMin % 60;
            return hrs + 'h ' + mins + 'm';
        } catch (e) {
            return '--';
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function debounce(fn, delay) {
        var timer;
        return function() {
            var args = arguments;
            var context = this;
            clearTimeout(timer);
            timer = setTimeout(function() {
                fn.apply(context, args);
            }, delay);
        };
    }

    function showToast(message, type) {
        if (window.Swal) {
            window.Swal.fire({
                toast: true,
                position: 'top-end',
                icon: type || 'info',
                title: message,
                showConfirmButton: false,
                timer: 3000
            });
        } else {
            console.log('[' + (type || 'info').toUpperCase() + '] ' + message);
        }
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // ============================================================================
    // Cancellation Modal Functions
    // ============================================================================

    var cancelState = {
        programId: null,
        programType: null,
        ctlElement: null
    };

    /**
     * Open cancellation modal for a program
     */
    function openCancelModal(programId, programType, ctlElement) {
        cancelState.programId = programId;
        cancelState.programType = programType || 'GS';
        cancelState.ctlElement = ctlElement || 'UNKN';

        // Populate modal fields
        document.getElementById('cancelProgramType').textContent = programType || '--';
        document.getElementById('cancelCtlElement').textContent = ctlElement || '--';
        document.getElementById('cancelProgramId').textContent = programId || '--';

        // Reset form
        document.getElementById('cancelReason').value = '';
        document.getElementById('cancelNotes').value = '';
        document.querySelector('input[name="edctAction"][value="DISREGARD"]').checked = true;
        document.getElementById('edctAfterTimeGroup').style.display = 'none';

        // Generate preview
        updateCancelPreview();

        // Bind events
        bindCancelModalEvents();

        // Show modal
        $('#gsgdpCancelModal').modal('show');
    }

    /**
     * Bind events for the cancel modal
     */
    function bindCancelModalEvents() {
        // EDCT action radio buttons
        document.querySelectorAll('input[name="edctAction"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                var timeGroup = document.getElementById('edctAfterTimeGroup');
                if (this.value === 'DISREGARD_AFTER') {
                    timeGroup.style.display = '';
                    // Set default time to now + 1 hour
                    var now = new Date();
                    now.setHours(now.getHours() + 1);
                    now.setMinutes(0);
                    document.getElementById('edctAfterTime').value = now.toISOString().slice(0, 16);
                } else {
                    timeGroup.style.display = 'none';
                }
                updateCancelPreview();
            });
        });

        // Reason/Notes change
        document.getElementById('cancelReason').addEventListener('change', updateCancelPreview);
        document.getElementById('cancelNotes').addEventListener('input', debounce(updateCancelPreview, 300));
        document.getElementById('edctAfterTime').addEventListener('change', updateCancelPreview);

        // Confirm button
        document.getElementById('confirmCancelBtn').onclick = handleConfirmCancel;
    }

    /**
     * Update the cancellation advisory preview
     */
    function updateCancelPreview() {
        var ctlElement = cancelState.ctlElement || 'UNKN';
        var programType = cancelState.programType || 'GS';
        var isGdp = programType.indexOf('GDP') !== -1;
        var typeName = isGdp ? 'GROUND DELAY PROGRAM' : 'GROUND STOP';

        var reason = document.getElementById('cancelReason')?.value || 'OPERATIONAL_NEED';
        var edctAction = document.querySelector('input[name="edctAction"]:checked')?.value || 'DISREGARD';
        var notes = document.getElementById('cancelNotes')?.value || '';

        var now = new Date();
        var cancelTimeStr = formatAdvisoryTime(now.toISOString());

        // Build EDCT line
        var edctLine = '';
        if (edctAction === 'DISREGARD') {
            edctLine = 'DISREGARD EDCTS FOR DEST ' + ctlElement;
        } else if (edctAction === 'DISREGARD_AFTER') {
            var afterTime = document.getElementById('edctAfterTime')?.value;
            var afterTimeStr = afterTime ? formatAdvisoryTime(afterTime) : '--/----Z';
            edctLine = 'DISREGARD EDCTS FOR DEST ' + ctlElement + ' AFTER ' + afterTimeStr;
        } else if (edctAction === 'AFP_ACTIVE') {
            edctLine = 'FLIGHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE AFP';
        }

        var preview = 'DCC ' + typeName + ' CANCELLATION ADVZY [###]\n\n' +
                      'CTL ELEMENT.................. ' + ctlElement + '\n' +
                      'CANCEL TIME.................. ' + cancelTimeStr + '\n' +
                      'REASON....................... ' + reason.replace(/_/g, ' ') + '\n\n' +
                      edctLine;

        if (notes) {
            preview += '\n\nREMARKS: ' + notes;
        }

        preview += '\n\nJO/DCC';

        document.getElementById('cancelAdvisoryPreview').textContent = preview;
    }

    /**
     * Handle confirm cancel button click
     */
    async function handleConfirmCancel() {
        var reason = document.getElementById('cancelReason')?.value;
        if (!reason) {
            showToast('Please select a cancellation reason', 'warning');
            return;
        }

        var edctAction = document.querySelector('input[name="edctAction"]:checked')?.value || 'DISREGARD';
        var edctActionTime = null;
        if (edctAction === 'DISREGARD_AFTER') {
            edctActionTime = document.getElementById('edctAfterTime')?.value;
            if (!edctActionTime) {
                showToast('Please specify the DISREGARD AFTER time', 'warning');
                return;
            }
        }

        var notes = document.getElementById('cancelNotes')?.value || '';

        var payload = {
            program_id: cancelState.programId,
            cancel_reason: reason,
            cancel_notes: notes,
            edct_action: edctAction,
            edct_action_time: edctActionTime,
            user_cid: window.TMI_PUBLISHER_CONFIG?.userCid || null,
            user_name: window.TMI_PUBLISHER_CONFIG?.userName || 'Unknown'
        };

        var btn = document.getElementById('confirmCancelBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Cancelling...';

        try {
            var response = await fetch(API.cancelProgram, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            var result = await response.json();

            if (result.status === 'ok') {
                showToast('Program cancelled - ' + (result.data?.advisory_number || ''), 'success');
                $('#gsgdpCancelModal').modal('hide');

                // Refresh active TMIs if function exists
                if (typeof window.TMIActiveDisplay !== 'undefined' && window.TMIActiveDisplay.refresh) {
                    window.TMIActiveDisplay.refresh();
                }

                // Clear handoff data if this was the program being edited
                if (state.programId === cancelState.programId) {
                    sessionStorage.removeItem('tmi_gs_handoff');
                    sessionStorage.removeItem('tmi_gdp_handoff');
                    showNoHandoffWarning();
                }
            } else {
                showToast(result.message || 'Cancellation failed', 'error');
            }
        } catch (err) {
            console.error('GSGDP: Cancel error:', err);
            showToast('Network error', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-times-circle mr-1"></i> Cancel Program';
        }
    }

    // Expose for debugging and external use
    window.TMI_GSGDP = {
        getState: function() { return state; },
        reload: loadHandoffData,
        openCancelModal: openCancelModal
    };

})();
