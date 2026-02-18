/**
 * JATOC Facility Type Handling Patch
 * Extends JATOC with updated facility dropdown logic
 *
 * This file should be included AFTER jatoc.js
 *
 * Changes:
 * - US ARTCC -> ARTCC (same facilities)
 * - US TRACON -> TRACON (static FAA list)
 * - US ATCT -> Local (user specifies ICAO)
 * - FIRs sorted with Canadian/Mexican/Caribbean first
 * - DCC now has DCC Services hierarchy (JATOC, Operations, Security, etc.)
 * - ECFMP, WF, CTP - single orgs, no facility dropdown
 * - VATUSA, VATSIM - identifier input instead of dropdown
 * - Removed Military (covered in VSO)
 */

(function() {
    'use strict';

    // Wait for JATOC to be defined
    if (typeof JATOC === 'undefined') {
        console.error('JATOC not defined - jatoc-facility-patch.js must be loaded after jatoc.js');
        return;
    }

    // Store original function if it exists
    const originalOnFacilityTypeChange = JATOC.onFacilityTypeChange;

    /**
     * Handle facility type change in user profile modal
     */
    JATOC.onFacilityTypeChange = function() {
        const facType = document.getElementById('profileFacilityType').value;
        const facilitySelectRow = document.getElementById('facilitySelectRow');
        const facilitySelect = document.getElementById('profileFacility');
        const customFacilityRow = document.getElementById('customFacilityRow');
        const orgIdentifierRow = document.getElementById('orgIdentifierRow');
        const localAirportRow = document.getElementById('localAirportRow');
        const roleSelect = document.getElementById('profileRole');

        const DATA = window.JATOC_FACILITY_DATA || {};

        // Reset all conditional rows
        facilitySelectRow.style.display = 'block';
        customFacilityRow.classList.remove('show');
        orgIdentifierRow.classList.remove('show');
        localAirportRow.classList.remove('show');

        // Clear facility select
        facilitySelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.select') + '</option>';

        // Clear role select
        roleSelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.selectRole') + '</option>';

        if (!facType) {
            facilitySelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.selectFacilityTypeFirst') + '</option>';
            roleSelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.selectFacilityTypeFirst') + '</option>';
            JATOC.updateProfilePreview();
            return;
        }

        // Check if this is a DCC Services type (hierarchical)
        if (DATA.DCC_SERVICES_TYPE && DATA.DCC_SERVICES_TYPE.includes(facType)) {
            // Show DCC Services dropdown
            const services = DATA.DCC_SERVICES || [];
            if (services.length === 0) {
                facilitySelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.noServicesAvailable') + '</option>';
            } else {
                facilitySelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.selectDccService') + '</option>';

                // Group services by group
                const groups = {};
                services.forEach(svc => {
                    const group = svc.group || 'Other';
                    if (!groups[group]) {groups[group] = [];}
                    groups[group].push(svc);
                });

                // Add grouped options
                Object.keys(groups).forEach(groupName => {
                    const optgroup = document.createElement('optgroup');
                    optgroup.label = groupName;
                    groups[groupName].forEach(svc => {
                        const opt = document.createElement('option');
                        opt.value = svc.code;
                        opt.textContent = svc.name;
                        optgroup.appendChild(opt);
                    });
                    facilitySelect.appendChild(optgroup);
                });
            }

            // Roles will be populated when a service is selected
            roleSelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.selectDccServiceFirst') + '</option>';
        }
        // Check if this is a single org type (no facility dropdown needed)
        else if (DATA.SINGLE_ORG_TYPES && DATA.SINGLE_ORG_TYPES.includes(facType)) {
            facilitySelectRow.style.display = 'none';
            // Set a default facility value for single orgs
            facilitySelect.innerHTML = `<option value="${facType}" selected>${facType}</option>`;

            // Populate roles for single org types
            const roles = DATA.ROLES && DATA.ROLES[facType] ? DATA.ROLES[facType] : [];
            if (roles.length > 0) {
                roleSelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.selectRole') + '</option>';
                roles.forEach(role => {
                    const opt = document.createElement('option');
                    opt.value = role.code;
                    opt.textContent = `${role.code} - ${role.name}`;
                    roleSelect.appendChild(opt);
                });
            }
        }
        // Check if this needs identifier input (VATUSA/VATSIM)
        else if (DATA.IDENTIFIER_TYPES && DATA.IDENTIFIER_TYPES.includes(facType)) {
            facilitySelectRow.style.display = 'none';
            orgIdentifierRow.classList.add('show');
            // Set placeholder
            const placeholder = facType === 'VATUSA' ? 'VATUSA3' : 'VATGOV1, VATEUD2';
            document.getElementById('orgIdentifier').placeholder = `e.g., ${placeholder}`;

            // Populate roles
            const roles = DATA.ROLES && DATA.ROLES[facType] ? DATA.ROLES[facType] : [];
            if (roles.length > 0) {
                roleSelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.selectRole') + '</option>';
                roles.forEach(role => {
                    const opt = document.createElement('option');
                    opt.value = role.code;
                    opt.textContent = `${role.code} - ${role.name}`;
                    roleSelect.appendChild(opt);
                });
            }
        }
        // Check if this needs local airport input
        else if (DATA.LOCAL_TYPES && DATA.LOCAL_TYPES.includes(facType)) {
            facilitySelectRow.style.display = 'none';
            localAirportRow.classList.add('show');

            // Populate roles for LOCAL (use FACILITY roles)
            const roles = DATA.ROLES && DATA.ROLES.FACILITY ? DATA.ROLES.FACILITY : [];
            if (roles.length > 0) {
                roleSelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.selectRole') + '</option>';
                roles.forEach(role => {
                    const opt = document.createElement('option');
                    opt.value = role.code;
                    opt.textContent = `${role.code} - ${role.name}`;
                    roleSelect.appendChild(opt);
                });
            }
        }
        // Check if this needs custom facility input
        else if (DATA.CUSTOM_TYPES && DATA.CUSTOM_TYPES.includes(facType)) {
            facilitySelectRow.style.display = 'none';
            customFacilityRow.classList.add('show');

            // Populate roles
            const roles = DATA.ROLES && DATA.ROLES[facType] ? DATA.ROLES[facType] : [];
            if (roles.length > 0) {
                roleSelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.selectRole') + '</option>';
                roles.forEach(role => {
                    const opt = document.createElement('option');
                    opt.value = role.code;
                    opt.textContent = `${role.code} - ${role.name}`;
                    roleSelect.appendChild(opt);
                });
            }
        }
        // Standard facility select dropdown (ARTCC, TRACON, FIR)
        else {
            const facilities = DATA[facType] || [];

            if (facilities.length === 0) {
                facilitySelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.noFacilitiesAvailable') + '</option>';
            } else {
                facilitySelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.selectFacility') + '</option>';
                facilities.forEach(fac => {
                    const opt = document.createElement('option');
                    opt.value = fac.code;
                    opt.textContent = `${fac.code} - ${fac.name}`;
                    facilitySelect.appendChild(opt);
                });
            }

            // Populate roles
            // ATC facility types use FACILITY roles
            const atcTypes = DATA.ATC_FACILITY_TYPES || ['ARTCC', 'TRACON', 'LOCAL', 'FIR'];
            const roleKey = atcTypes.includes(facType) ? 'FACILITY' : facType;
            const roles = DATA.ROLES && DATA.ROLES[roleKey] ? DATA.ROLES[roleKey] : [];
            if (roles.length > 0) {
                roleSelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.selectRole') + '</option>';
                roles.forEach(role => {
                    const opt = document.createElement('option');
                    opt.value = role.code;
                    opt.textContent = `${role.code} - ${role.name}`;
                    roleSelect.appendChild(opt);
                });
            } else {
                roleSelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.noRolesAvailable') + '</option>';
            }
        }

        JATOC.updateProfilePreview();
    };

    /**
     * Handle facility/service selection change (for DCC Services hierarchy)
     */
    JATOC.onFacilityChange = function() {
        const facType = document.getElementById('profileFacilityType').value;
        const facility = document.getElementById('profileFacility').value;
        const roleSelect = document.getElementById('profileRole');
        const DATA = window.JATOC_FACILITY_DATA || {};

        // Check if this is DCC type - roles depend on selected service
        if (DATA.DCC_SERVICES_TYPE && DATA.DCC_SERVICES_TYPE.includes(facType)) {
            roleSelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.selectRole') + '</option>';

            if (facility && DATA.DCC_ROLES && DATA.DCC_ROLES[facility]) {
                const roles = DATA.DCC_ROLES[facility];
                roles.forEach(role => {
                    const opt = document.createElement('option');
                    opt.value = role.code;
                    opt.textContent = `${role.code} - ${role.name}`;
                    roleSelect.appendChild(opt);
                });
            } else if (facility) {
                roleSelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.noRolesForService') + '</option>';
            } else {
                roleSelect.innerHTML = '<option value="">' + PERTII18n.t('jatoc.facilityPatch.selectDccServiceFirst') + '</option>';
            }
        }

        JATOC.updateProfilePreview();
    };

    /**
     * Update profile preview text
     */
    JATOC.updateProfilePreview = function() {
        const preview = document.getElementById('profilePreview');
        const name = document.getElementById('profileName').value.trim();
        const ois = document.getElementById('profileOIs').value.trim().toUpperCase();
        const facType = document.getElementById('profileFacilityType').value;
        const DATA = window.JATOC_FACILITY_DATA || {};

        let facility = '';

        // Determine facility value based on type
        if (DATA.SINGLE_ORG_TYPES && DATA.SINGLE_ORG_TYPES.includes(facType)) {
            facility = facType;
        } else if (DATA.IDENTIFIER_TYPES && DATA.IDENTIFIER_TYPES.includes(facType)) {
            facility = document.getElementById('orgIdentifier').value.trim().toUpperCase();
        } else if (DATA.LOCAL_TYPES && DATA.LOCAL_TYPES.includes(facType)) {
            facility = document.getElementById('localAirportIcao').value.trim().toUpperCase();
        } else if (DATA.CUSTOM_TYPES && DATA.CUSTOM_TYPES.includes(facType)) {
            facility = document.getElementById('customFacilityCode').value.trim().toUpperCase();
        } else {
            facility = document.getElementById('profileFacility').value;
        }

        const role = document.getElementById('profileRole').value;

        if (!name || !ois || !facType) {
            preview.textContent = '-';
            return;
        }

        // Build preview
        let previewText = `${ois}`;
        if (facility) {previewText += ` / ${facility}`;}
        if (role) {previewText += ` / ${role}`;}
        previewText += ` - ${name}`;

        preview.textContent = previewText;
    };

    /**
     * Override saveProfile to handle new field types
     */
    const originalSaveProfile = JATOC.saveProfile;
    JATOC.saveProfile = function() {
        const name = document.getElementById('profileName').value.trim();
        const cid = document.getElementById('profileCID').value.trim();
        const ois = document.getElementById('profileOIs').value.trim().toUpperCase();
        const facType = document.getElementById('profileFacilityType').value;
        const role = document.getElementById('profileRole').value;
        const DATA = window.JATOC_FACILITY_DATA || {};

        // Determine facility value based on type
        let facility = '';
        let customName = '';

        if (DATA.SINGLE_ORG_TYPES && DATA.SINGLE_ORG_TYPES.includes(facType)) {
            facility = facType;
        } else if (DATA.IDENTIFIER_TYPES && DATA.IDENTIFIER_TYPES.includes(facType)) {
            facility = document.getElementById('orgIdentifier').value.trim().toUpperCase();
            if (!facility) {
                alert(PERTII18n.t('jatoc.facilityPatch.validation.orgIdentifierRequired'));
                return;
            }
        } else if (DATA.LOCAL_TYPES && DATA.LOCAL_TYPES.includes(facType)) {
            facility = document.getElementById('localAirportIcao').value.trim().toUpperCase();
            if (!facility || facility.length < 3) {
                alert(PERTII18n.t('jatoc.facilityPatch.validation.invalidIcao'));
                return;
            }
        } else if (DATA.CUSTOM_TYPES && DATA.CUSTOM_TYPES.includes(facType)) {
            facility = document.getElementById('customFacilityCode').value.trim().toUpperCase();
            customName = document.getElementById('customFacilityName').value.trim();
            if (!facility) {
                alert(PERTII18n.t('jatoc.facilityPatch.validation.facilityCodeRequired'));
                return;
            }
        } else if (DATA.DCC_SERVICES_TYPE && DATA.DCC_SERVICES_TYPE.includes(facType)) {
            facility = document.getElementById('profileFacility').value;
            if (!facility) {
                alert(PERTII18n.t('jatoc.facilityPatch.validation.selectDccService'));
                return;
            }
        } else {
            facility = document.getElementById('profileFacility').value;
        }

        // Validation
        if (!name) {
            alert(PERTII18n.t('jatoc.facilityPatch.validation.nameRequired'));
            return;
        }
        if (!ois || ois.length !== 2) {
            alert(PERTII18n.t('jatoc.facilityPatch.validation.oisRequired'));
            return;
        }
        if (!facType) {
            alert(PERTII18n.t('jatoc.facilityPatch.validation.facilityTypeRequired'));
            return;
        }
        if (!role) {
            alert(PERTII18n.t('jatoc.facilityPatch.validation.roleRequired'));
            return;
        }

        // Save to localStorage
        // Note: Use 'facilityType' to match isDCC() check in jatoc.js
        // Also include 'facType' for backwards compatibility
        const profile = {
            name: name,
            cid: cid,
            ois: ois,
            facilityType: facType,  // Required for isDCC() check
            facType: facType,        // Backwards compatibility
            facility: facility,
            customName: customName,
            role: role,
            roleCode: role,           // Backwards compatibility with original jatoc.js
        };

        localStorage.setItem('jatoc_user_profile', JSON.stringify(profile));

        // Update display
        JATOC.loadUserProfile();

        // Close modal
        $('#userProfileModal').modal('hide');
    };

    /**
     * Override loadUserProfile to update display and store currentUser
     * The original jatoc.js loadUserProfile handles setting state.userProfile
     */
    const originalLoadUserProfile = JATOC.loadUserProfile;
    JATOC.loadUserProfile = function() {
        // Call the original function FIRST to set internal state.userProfile
        // This is critical for isDCC() and hasProfile() checks to work
        if (typeof originalLoadUserProfile === 'function') {
            originalLoadUserProfile();
        }

        // Also store in JATOC.currentUser for external access
        const stored = localStorage.getItem('jatoc_user_profile');
        if (stored) {
            try {
                JATOC.currentUser = JSON.parse(stored);
            } catch (e) {
                console.warn('Failed to parse user profile:', e);
            }
        }
    };

    /**
     * Override showUserProfile to populate new fields
     */
    const originalShowUserProfile = JATOC.showUserProfile;
    JATOC.showUserProfile = function() {
        const DATA = window.JATOC_FACILITY_DATA || {};

        // Pre-populate from session if available
        if (JATOC_CONFIG.sessionUserName) {
            document.getElementById('profileName').value = JATOC_CONFIG.sessionUserName;
        }
        if (JATOC_CONFIG.sessionUserCid) {
            document.getElementById('profileCID').value = JATOC_CONFIG.sessionUserCid;
        }

        // Load stored profile
        const stored = localStorage.getItem('jatoc_user_profile');
        if (stored) {
            try {
                const profile = JSON.parse(stored);
                document.getElementById('profileName').value = profile.name || '';
                document.getElementById('profileCID').value = profile.cid || '';
                document.getElementById('profileOIs').value = profile.ois || '';

                // Handle both facType and facilityType for backwards compatibility
                const facType = profile.facType || profile.facilityType || '';
                document.getElementById('profileFacilityType').value = facType;

                // Trigger facility type change to populate dropdowns
                JATOC.onFacilityTypeChange();

                // Now set the facility/identifier value
                if (DATA.IDENTIFIER_TYPES && DATA.IDENTIFIER_TYPES.includes(facType)) {
                    document.getElementById('orgIdentifier').value = profile.facility || '';
                } else if (DATA.LOCAL_TYPES && DATA.LOCAL_TYPES.includes(facType)) {
                    document.getElementById('localAirportIcao').value = profile.facility || '';
                } else if (DATA.CUSTOM_TYPES && DATA.CUSTOM_TYPES.includes(facType)) {
                    document.getElementById('customFacilityCode').value = profile.facility || '';
                    document.getElementById('customFacilityName').value = profile.customName || '';
                } else if (DATA.DCC_SERVICES_TYPE && DATA.DCC_SERVICES_TYPE.includes(facType)) {
                    document.getElementById('profileFacility').value = profile.facility || '';
                    // Trigger facility change to populate DCC roles
                    JATOC.onFacilityChange();
                } else if (!DATA.SINGLE_ORG_TYPES || !DATA.SINGLE_ORG_TYPES.includes(facType)) {
                    document.getElementById('profileFacility').value = profile.facility || '';
                }

                // Set role (handle both role and roleCode)
                document.getElementById('profileRole').value = profile.role || profile.roleCode || '';

                // Update preview
                JATOC.updateProfilePreview();
            } catch (e) {
                console.warn('Failed to parse stored profile:', e);
            }
        }

        // Show modal
        $('#userProfileModal').modal('show');
    };

    /**
     * Initialize event listeners
     */
    function initEventListeners() {
        // Facility type change
        const facTypeSelect = document.getElementById('profileFacilityType');
        if (facTypeSelect) {
            facTypeSelect.addEventListener('change', JATOC.onFacilityTypeChange);
        }

        // Facility/Service change (for DCC roles)
        const facilitySelect = document.getElementById('profileFacility');
        if (facilitySelect) {
            facilitySelect.addEventListener('change', JATOC.onFacilityChange);
        }

        // Other inputs for preview updates
        const previewInputs = ['profileName', 'profileOIs', 'profileRole', 'orgIdentifier', 'localAirportIcao', 'customFacilityCode'];
        previewInputs.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', JATOC.updateProfilePreview);
                el.addEventListener('change', JATOC.updateProfilePreview);
            }
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initEventListeners);
    } else {
        initEventListeners();
    }

    console.log('JATOC Facility Patch loaded');
})();
