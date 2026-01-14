/**
 * NTML Protocol Form - Client-Side Logic
 * Handles form interactions, determinant code calculation, and form submission
 */

// ============================================
// GLOBAL STATE
// ============================================
let currentProtocol = null;
let productionMode = false;

// ============================================
// INITIALIZATION
// ============================================
$(document).ready(function() {
    initializeEventHandlers();
    initializeQualifierBadges();
    initializeFormWatchers();
    
    // Load saved mode preference
    const savedMode = localStorage.getItem('ntml_production_mode');
    if (savedMode === 'true') {
        $('#productionMode').prop('checked', true);
        productionMode = true;
        updateModeIndicator();
    }
});

// ============================================
// EVENT HANDLERS
// ============================================
function initializeEventHandlers() {
    // Protocol card selection
    $('.protocol-card').click(function() {
        const protocol = $(this).data('protocol');
        selectProtocol(protocol);
    });
    
    // Production mode toggle
    $('#productionMode').change(function() {
        productionMode = $(this).is(':checked');
        localStorage.setItem('ntml_production_mode', productionMode);
        updateModeIndicator();
        
        if (productionMode) {
            Swal.fire({
                icon: 'warning',
                title: 'Production Mode Enabled',
                text: 'NTML entries will now post to LIVE channels. Please review carefully before submitting.',
                confirmButtonColor: '#dc3545'
            });
        }
    });
    
    // Holding field toggle for Delay form
    $('#delay_holding').change(function() {
        const val = $(this).val();
        if (val === 'yes_initiating' || val === 'yes_15plus') {
            $('#holding_location').prop('disabled', false);
        } else {
            $('#holding_location').prop('disabled', true).val('');
        }
    });
    
    // Scenery issue toggle for Config form
    $('#config_scenery').change(function() {
        if ($(this).val() === 'yes') {
            $('#scenery_desc_group').slideDown();
        } else {
            $('#scenery_desc_group').slideUp();
        }
    });
    
    // Form submissions
    $('#mitForm').submit(function(e) {
        e.preventDefault();
        submitNTML('05', $(this));
    });
    
    $('#minitForm').submit(function(e) {
        e.preventDefault();
        submitNTML('06', $(this));
    });
    
    $('#delayForm').submit(function(e) {
        e.preventDefault();
        submitNTML('04', $(this));
    });
    
    $('#configForm').submit(function(e) {
        e.preventDefault();
        submitNTML('01', $(this));
    });
}

function initializeQualifierBadges() {
    $('.qualifier-badge').click(function() {
        const form = $(this).closest('form');
        const hiddenInput = form.find('input[name="qualifiers"]');
        
        $(this).toggleClass('selected');
        
        // Gather all selected qualifiers in this form
        const selected = [];
        form.find('.qualifier-badge.selected').each(function() {
            selected.push($(this).data('qualifier'));
        });
        
        hiddenInput.val(selected.join(','));
    });
}

function initializeFormWatchers() {
    // MIT form watchers
    $('#mit_distance, #mit_same_artcc').on('change keyup', function() {
        if (currentProtocol === '05') {
            updateDeterminantPreview();
        }
    });
    
    // MINIT form watchers
    $('#minit_minutes, #minit_same_artcc').on('change keyup', function() {
        if (currentProtocol === '06') {
            updateDeterminantPreview();
        }
    });
    
    // Delay form watchers
    $('#delay_longest, #delay_trend').on('change keyup', function() {
        if (currentProtocol === '04') {
            updateDeterminantPreview();
        }
    });
    
    // Config form watchers
    $('#config_weather, #config_single_rwy, #config_aar, #config_adr').on('change keyup', function() {
        if (currentProtocol === '01') {
            updateDeterminantPreview();
        }
    });
}

// ============================================
// PROTOCOL SELECTION
// ============================================
function selectProtocol(protocol) {
    currentProtocol = protocol;
    
    // Update card styling
    $('.protocol-card').removeClass('active');
    $(`.protocol-card[data-protocol="${protocol}"]`).addClass('active');
    
    // Show form container
    $('#formContainer').slideDown();
    
    // Hide all forms, show selected
    $('.protocol-form').hide();
    $(`#form-${protocol}`).fadeIn();
    
    // Reset determinant preview
    updateDeterminantPreview();
    
    // Scroll to form
    $('html, body').animate({
        scrollTop: $('#formContainer').offset().top - 100
    }, 500);
}

// ============================================
// DETERMINANT CODE CALCULATION
// ============================================
function updateDeterminantPreview() {
    let code = '--';
    let description = 'Select protocol options to generate code';
    
    switch (currentProtocol) {
        case '05':
            const mitResult = calculateMITCode();
            code = mitResult.code;
            description = mitResult.description;
            break;
        case '06':
            const minitResult = calculateMINITCode();
            code = minitResult.code;
            description = minitResult.description;
            break;
        case '04':
            const delayResult = calculateDelayCode();
            code = delayResult.code;
            description = delayResult.description;
            break;
        case '01':
            const configResult = calculateConfigCode();
            code = configResult.code;
            description = configResult.description;
            break;
    }
    
    $('#determinantPreview').text(code);
    $('#determinantDescription').text(description);
}

/**
 * MIT Code Logic (Protocol 05)
 * | Distance | External | Internal |
 * |----------|----------|----------|
 * | ≥60nm    | 05D01/02 | 05D04/05 |
 * | 40-59nm  | 05C01/02 | 05C04/05 |
 * | 25-39nm  | 05B01/02 | 05B04/05 |
 * | 15-24nm  | 05A01/02 | 05A04/05 |
 * | <15nm    | 05O01/02/03 | -     |
 */
function calculateMITCode() {
    const distance = parseInt($('#mit_distance').val()) || 0;
    const sameArtcc = $('#mit_same_artcc').val();
    
    if (!distance || !sameArtcc) {
        return { code: '05---', description: 'Enter distance and ARTCC relationship' };
    }
    
    const isInternal = sameArtcc === 'yes';
    let level = '';
    let subcode = '';
    
    if (distance >= 60) {
        level = 'D';
        subcode = isInternal ? '04' : '01';
    } else if (distance >= 40) {
        level = 'C';
        subcode = isInternal ? '04' : '01';
    } else if (distance >= 25) {
        level = 'B';
        subcode = isInternal ? '04' : '01';
    } else if (distance >= 15) {
        level = 'A';
        subcode = isInternal ? '04' : '01';
    } else {
        // <15nm - only external, different codes
        level = 'O';
        subcode = '01';
    }
    
    const code = `05${level}${subcode}`;
    const levelDesc = getLevelDescription(level);
    const typeDesc = isInternal ? 'Internal' : 'External';
    
    return {
        code: code,
        description: `${levelDesc} priority - ${typeDesc} ${distance}MIT`
    };
}

/**
 * MINIT Code Logic (Protocol 06)
 * | Minutes  | External | Internal |
 * |----------|----------|----------|
 * | ≥30min   | 06D01/02 | 06D04/05 |
 * | 20-29min | 06C01/02 | 06C04/05 |
 * | 13-19min | 06B01/02 | 06B04/05 |
 * | 7-12min  | 06A01/02 | 06A04/05 |
 * | <7min    | 06O01/02 | 06A04/05 |
 */
function calculateMINITCode() {
    const minutes = parseInt($('#minit_minutes').val()) || 0;
    const sameArtcc = $('#minit_same_artcc').val();
    
    if (!minutes || !sameArtcc) {
        return { code: '06---', description: 'Enter minutes and ARTCC relationship' };
    }
    
    const isInternal = sameArtcc === 'yes';
    let level = '';
    let subcode = '';
    
    if (minutes >= 30) {
        level = 'D';
        subcode = isInternal ? '04' : '01';
    } else if (minutes >= 20) {
        level = 'C';
        subcode = isInternal ? '04' : '01';
    } else if (minutes >= 13) {
        level = 'B';
        subcode = isInternal ? '04' : '01';
    } else if (minutes >= 7) {
        level = 'A';
        subcode = isInternal ? '04' : '01';
    } else {
        // <7min
        if (isInternal) {
            level = 'A';
            subcode = '04';
        } else {
            level = 'O';
            subcode = '01';
        }
    }
    
    const code = `06${level}${subcode}`;
    const levelDesc = getLevelDescription(level);
    const typeDesc = isInternal ? 'Internal' : 'External';
    
    return {
        code: code,
        description: `${levelDesc} priority - ${typeDesc} ${minutes}MINIT`
    };
}

/**
 * Delay Code Logic (Protocol 04)
 * | Delay Duration | Increasing | Steady | Decreasing |
 * |----------------|------------|--------|------------|
 * | ≥600min        | 04D01      | 04D02  | 04D03      |
 * | 360-599min     | 04D04      | 04D05  | 04D06      |
 * | 180-359min     | 04C01      | 04C02  | 04C03      |
 * | 120-179min     | 04C04      | 04C05  | 04C06      |
 * | 90-119min      | 04B01      | 04B02  | 04B03      |
 * | 60-89min       | 04B04      | 04B05  | 04B06      |
 * | 30-59min       | 04A01      | 04A02  | 04A03      |
 * | 15-29min       | 04A04      | 04A05  | 04A06      |
 */
function calculateDelayCode() {
    const delay = parseInt($('#delay_longest').val()) || 0;
    const trend = $('#delay_trend').val();
    
    if (!delay || !trend) {
        return { code: '04---', description: 'Enter delay duration and trend' };
    }
    
    let level = '';
    let baseCode = 0;
    
    if (delay >= 600) {
        level = 'D';
        baseCode = 1;
    } else if (delay >= 360) {
        level = 'D';
        baseCode = 4;
    } else if (delay >= 180) {
        level = 'C';
        baseCode = 1;
    } else if (delay >= 120) {
        level = 'C';
        baseCode = 4;
    } else if (delay >= 90) {
        level = 'B';
        baseCode = 1;
    } else if (delay >= 60) {
        level = 'B';
        baseCode = 4;
    } else if (delay >= 30) {
        level = 'A';
        baseCode = 1;
    } else if (delay >= 15) {
        level = 'A';
        baseCode = 4;
    } else {
        // <15min - typically not reported
        level = 'O';
        baseCode = 1;
    }
    
    // Trend offset: increasing=0, steady=1, decreasing=2
    const trendOffset = trend === 'increasing' ? 0 : (trend === 'steady' ? 1 : 2);
    const subcode = String(baseCode + trendOffset).padStart(2, '0');
    
    const code = `04${level}${subcode}`;
    const levelDesc = getLevelDescription(level);
    const trendDesc = trend.charAt(0).toUpperCase() + trend.slice(1);
    
    return {
        code: code,
        description: `${levelDesc} priority - ${delay}min delay, ${trendDesc}`
    };
}

/**
 * Airport Config Code Logic (Protocol 01) - Simplified
 * Complex logic based on runway config, weather, and rates
 */
function calculateConfigCode() {
    const weather = $('#config_weather').val();
    const singleRwy = $('#config_single_rwy').val();
    const aar = parseInt($('#config_aar').val()) || 0;
    const adr = parseInt($('#config_adr').val()) || 0;
    
    if (!weather || !singleRwy) {
        return { code: '01---', description: 'Enter weather and runway configuration' };
    }
    
    let level = '';
    let subcode = '01';
    
    // Emergency level conditions
    if (singleRwy === 'yes') {
        if (weather === 'VLIMC' || weather === 'LIMC') {
            level = 'E';
            subcode = '01';
        } else if (weather === 'IMC' && (aar <= 30 || adr <= 30)) {
            level = 'E';
            subcode = '02';
        } else if (aar <= 45 || adr <= 45) {
            level = 'E';
            subcode = '03';
        } else {
            level = 'D';
            subcode = '01';
        }
    } else {
        // Multiple runway
        if (weather === 'VLIMC' || weather === 'LIMC') {
            level = 'C';
            subcode = '01';
        } else if (weather === 'IMC') {
            level = 'B';
            subcode = '01';
        } else if (weather === 'LVMC') {
            level = 'A';
            subcode = '01';
        } else {
            level = 'O';
            subcode = '01';
        }
    }
    
    const code = `01${level}${subcode}`;
    const levelDesc = getLevelDescription(level);
    const rwyDesc = singleRwy === 'yes' ? 'Single-Runway' : 'Multi-Runway';
    
    return {
        code: code,
        description: `${levelDesc} priority - ${rwyDesc} ${weather}`
    };
}

function getLevelDescription(level) {
    const descriptions = {
        'E': 'Emergency/Critical',
        'D': 'High',
        'C': 'Moderate',
        'B': 'Low',
        'A': 'Informational',
        'O': 'Other/Recordkeeping',
        'X': 'Cancellation'
    };
    return descriptions[level] || 'Unknown';
}

// ============================================
// MODE INDICATOR
// ============================================
function updateModeIndicator() {
    const indicator = $('#modeIndicator');
    if (productionMode) {
        indicator.removeClass('test-mode-banner')
                 .addClass('bg-danger text-white')
                 .html('<i class="fas fa-broadcast-tower"></i> PRODUCTION MODE');
    } else {
        indicator.removeClass('bg-danger text-white')
                 .addClass('test-mode-banner')
                 .html('<i class="fas fa-flask"></i> TEST MODE');
    }
}

// ============================================
// FORM SUBMISSION
// ============================================
function submitNTML(protocol, form) {
    // Validate determinant code is calculated
    const determinant = $('#determinantPreview').text();
    if (determinant === '--' || determinant.includes('---')) {
        Swal.fire({
            icon: 'error',
            title: 'Incomplete Form',
            text: 'Please fill in all required fields to generate a valid determinant code.'
        });
        return;
    }
    
    // Confirm if production mode
    if (productionMode) {
        Swal.fire({
            icon: 'warning',
            title: 'Confirm Production Submission',
            html: `You are about to post <strong>${determinant}</strong> to LIVE Discord channels.<br><br>This action cannot be undone.`,
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Submit to Production',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                doSubmit(protocol, form, determinant);
            }
        });
    } else {
        doSubmit(protocol, form, determinant);
    }
}

function doSubmit(protocol, form, determinant) {
    // Show loading state
    Swal.fire({
        title: 'Submitting...',
        html: `Posting ${determinant} to Discord`,
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Prepare form data
    const formData = form.serialize();
    const fullData = formData + '&determinant=' + encodeURIComponent(determinant) + '&production=' + (productionMode ? '1' : '0');
    
    $.ajax({
        type: 'POST',
        url: 'api/mgt/ntml/post.php',
        data: fullData,
        success: function(response) {
            try {
                const data = JSON.parse(response);
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'NTML Entry Posted',
                        html: `<strong>${determinant}</strong> has been posted successfully.<br><br>
                               <small class="text-muted">Channel: ${data.channel || 'Discord'}</small>`,
                        confirmButtonColor: '#28a745'
                    }).then(() => {
                        // Reset form
                        form[0].reset();
                        form.find('.qualifier-badge').removeClass('selected');
                        form.find('input[name="qualifiers"]').val('');
                        updateDeterminantPreview();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Submission Failed',
                        text: data.error || 'An unknown error occurred.'
                    });
                }
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'Submission Failed',
                    text: 'Invalid response from server.'
                });
            }
        },
        error: function(xhr, status, error) {
            Swal.fire({
                icon: 'error',
                title: 'Submission Failed',
                text: 'Could not connect to server. Please try again.'
            });
        }
    });
}
