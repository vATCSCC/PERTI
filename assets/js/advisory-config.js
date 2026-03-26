/**
 * Advisory Organization Configuration
 * Allows users to switch between US DCC (vATCSCC), Canadian NOC (CANOC),
 * and European ECFMP advisory formats.
 */
window.AdvisoryConfig = (function() {
    'use strict';

    const STORAGE_KEY = 'perti_advisory_org';
    const ORG_TYPES = {
        DCC:   { prefix: 'vATCSCC', facility: 'DCC',   nameKey: 'advisoryConfig.usDcc',       fallback: 'US DCC' },
        NOC:   { prefix: 'CANOC',   facility: 'NOC',   nameKey: 'advisoryConfig.canadianNoc', fallback: 'Canadian NOC' },
        ECFMP: { prefix: 'ECFMP',   facility: 'ECFMP', nameKey: 'advisoryConfig.ecfmp',       fallback: 'ECFMP' },
    };
    const DEFAULT_ORG = 'DCC';

    function getOrgType() {
        // Auto-detect from session org context if available
        if (window.PERTI_ORG && window.PERTI_ORG.code === 'canoc') {
            return 'NOC';
        }
        if (window.PERTI_ORG && window.PERTI_ORG.code === 'ecfmp') {
            return 'ECFMP';
        }
        return localStorage.getItem(STORAGE_KEY) || DEFAULT_ORG;
    }

    function setOrgType(type) {
        if (ORG_TYPES[type]) {
            localStorage.setItem(STORAGE_KEY, type);
        }
    }

    function getPrefix() {
        return ORG_TYPES[getOrgType()].prefix;
    }

    function getFacility() {
        return ORG_TYPES[getOrgType()].facility;
    }

    function getOrgName() {
        var org = ORG_TYPES[getOrgType()];
        if (typeof PERTII18n !== 'undefined') {
            var translated = PERTII18n.t(org.nameKey);
            if (translated !== org.nameKey) return translated;
        }
        return org.fallback;
    }

    function showConfigModal() {
        // Set the current selection
        const currentOrg = getOrgType();
        document.getElementById('orgDCC').checked = (currentOrg === 'DCC');
        document.getElementById('orgNOC').checked = (currentOrg === 'NOC');
        $('#advisoryOrgModal').modal('show');
    }

    function saveOrg() {
        const selectedOrg = document.querySelector('input[name="advisoryOrg"]:checked');
        if (selectedOrg) {
            setOrgType(selectedOrg.value);
            updateDisplay();
            $('#advisoryOrgModal').modal('hide');
        }
    }

    function updateDisplay() {
        const displayEl = document.getElementById('advisoryOrgDisplay');
        if (displayEl) {
            displayEl.textContent = getOrgType();
        }
    }

    function initUI() {
        updateDisplay();

        // Bind modal save button if exists
        const saveBtn = document.getElementById('advisoryOrgSaveBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', saveOrg);
        }
    }

    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUI);
    } else {
        initUI();
    }

    // Public API
    return {
        getOrgType: getOrgType,
        setOrgType: setOrgType,
        getPrefix: getPrefix,
        getFacility: getFacility,
        getOrgName: getOrgName,
        showConfigModal: showConfigModal,
        saveOrg: saveOrg,
        initUI: initUI,
    };
})();
