<?php
/**
 * NTML Protocol Entry Form
 * National Traffic Management Log - Phase 1: MIT, MINIT, Delay, Airport Config
 */

include("sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("load/config.php");
include("load/connect.php");

// Check Perms
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
        $cid = strip_tags($_SESSION['VATSIM_CID']);
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check) {
            $perm = true;
        }
    }
} else {
    $perm = true;
    $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php include("load/header.php"); ?>
    <style>
        .protocol-card {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .protocol-card:hover {
            border-color: #007bff;
            box-shadow: 0 4px 12px rgba(0,123,255,0.15);
        }
        .protocol-card.active {
            border-color: #28a745;
            background-color: rgba(40,167,69,0.05);
        }
        .form-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .form-section h5 {
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .determinant-preview {
            font-family: monospace;
            font-size: 1.5rem;
            font-weight: bold;
            background-color: #1a1a2e;
            color: #00ff00;
            padding: 15px 25px;
            border-radius: 8px;
            display: inline-block;
            min-width: 150px;
            text-align: center;
        }
        .qualifier-badge {
            cursor: pointer;
            user-select: none;
            transition: all 0.2s ease;
        }
        .qualifier-badge:hover {
            transform: scale(1.05);
        }
        .qualifier-badge.selected {
            background-color: #28a745 !important;
        }
        .test-mode-banner {
            background-color: #ffc107;
            color: #212529;
            padding: 8px 15px;
            border-radius: 4px;
            font-weight: 500;
        }
        .required-field::after {
            content: " *";
            color: #dc3545;
        }
        .artcc-select {
            font-family: monospace;
        }
    </style>
</head>
<body>

<?php include('load/nav.php'); ?>

<section class="d-flex align-items-center position-relative bg-position-center fh-section overflow-hidden pt-6 jarallax bg-dark text-light" data-jarallax data-speed="0.3">
    <div class="container-fluid pt-2 pb-5 py-lg-6">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">
        <center>
            <h1><i class="fas fa-clipboard-list"></i> NTML Protocol Entry</h1>
            <h4 class="text-white">National Traffic Management Log</h4>
        </center>
    </div>
</section>

<div class="container-fluid mt-4 mb-5">
    
    <?php if ($perm): ?>
    
    <!-- Test Mode Toggle -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex align-items-center justify-content-between bg-light p-3 rounded">
                <div>
                    <h5 class="mb-0"><i class="fas fa-broadcast-tower"></i> Posting Mode</h5>
                    <small class="text-muted">Select where NTML entries will be posted</small>
                </div>
                <div class="d-flex align-items-center">
                    <span class="mr-3 test-mode-banner" id="modeIndicator">
                        <i class="fas fa-flask"></i> TEST MODE
                    </span>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="productionMode">
                        <label class="custom-control-label" for="productionMode">Production Mode</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Protocol Type Selection -->
    <div class="row mb-4">
        <div class="col-12">
            <h4><i class="fas fa-list-ol"></i> Select Protocol Type</h4>
            <p class="text-muted">Choose the type of NTML entry to create</p>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card protocol-card h-100" data-protocol="05">
                <div class="card-body text-center">
                    <h2 class="text-primary"><i class="fas fa-ruler-horizontal"></i></h2>
                    <h5>MIT</h5>
                    <p class="text-muted mb-0">Miles-in-Trail</p>
                    <span class="badge badge-secondary">Protocol 05</span>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card protocol-card h-100" data-protocol="06">
                <div class="card-body text-center">
                    <h2 class="text-info"><i class="fas fa-clock"></i></h2>
                    <h5>MINIT</h5>
                    <p class="text-muted mb-0">Minutes-in-Trail</p>
                    <span class="badge badge-secondary">Protocol 06</span>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card protocol-card h-100" data-protocol="04">
                <div class="card-body text-center">
                    <h2 class="text-warning"><i class="fas fa-hourglass-half"></i></h2>
                    <h5>Delay</h5>
                    <p class="text-muted mb-0">Delay Reporting</p>
                    <span class="badge badge-secondary">Protocol 04</span>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card protocol-card h-100" data-protocol="01">
                <div class="card-body text-center">
                    <h2 class="text-success"><i class="fas fa-plane-arrival"></i></h2>
                    <h5>Airport Config</h5>
                    <p class="text-muted mb-0">Configuration Change</p>
                    <span class="badge badge-secondary">Protocol 01</span>
                </div>
            </div>
        </div>
    </div>

    <hr>

    <!-- Dynamic Form Container -->
    <div id="formContainer" style="display: none;">
        
        <!-- Determinant Code Preview -->
        <div class="row mb-4">
            <div class="col-12 text-center">
                <div class="bg-light p-4 rounded">
                    <h5 class="text-muted mb-3">Determinant Code</h5>
                    <div class="determinant-preview" id="determinantPreview">--</div>
                    <p class="text-muted mt-2 mb-0" id="determinantDescription">Select protocol options to generate code</p>
                </div>
            </div>
        </div>

        <!-- MIT Form (Protocol 05) -->
        <div id="form-05" class="protocol-form" style="display: none;">
            <form id="mitForm">
                <input type="hidden" name="protocol" value="05">
                
                <div class="form-section">
                    <h5><i class="fas fa-info-circle"></i> Condition Information</h5>
                    <div class="form-group">
                        <label class="required-field">Condition Description</label>
                        <input type="text" class="form-control" name="condition" placeholder="e.g., JFK via LENDY, KATL arrivals" required>
                        <small class="text-muted">Describe the traffic flow affected by this restriction</small>
                    </div>
                    <div class="form-group">
                        <label class="required-field">Restriction Type</label>
                        <select class="form-control" name="restriction_type" required>
                            <option value="">-- Select --</option>
                            <option value="arrival">Arrival</option>
                            <option value="departure">Departure</option>
                            <option value="enroute">En Route</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5><i class="fas fa-building"></i> Facilities</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Requesting Facility Type</label>
                                <select class="form-control" name="req_facility_type" required>
                                    <option value="">-- Select --</option>
                                    <option value="ARTCC">ARTCC (Center)</option>
                                    <option value="TRACON">TRACON</option>
                                    <option value="ATCT">ATCT (Tower)</option>
                                    <option value="ATCSCC">ATCSCC</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="required-field">Requesting Facility ID</label>
                                <input type="text" class="form-control artcc-select text-uppercase" name="req_facility_id" maxlength="4" placeholder="e.g., ZNY, N90, JFK" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Providing Facility Type</label>
                                <select class="form-control" name="prov_facility_type" required>
                                    <option value="">-- Select --</option>
                                    <option value="ARTCC">ARTCC (Center)</option>
                                    <option value="TRACON">TRACON</option>
                                    <option value="ATCT">ATCT (Tower)</option>
                                    <option value="ATCSCC">ATCSCC</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="required-field">Providing Facility ID</label>
                                <input type="text" class="form-control artcc-select text-uppercase" name="prov_facility_id" maxlength="4" placeholder="e.g., ZBW, A90, BOS" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="required-field">Same ARTCC?</label>
                        <select class="form-control" name="same_artcc" id="mit_same_artcc" required>
                            <option value="">-- Select --</option>
                            <option value="no">No - External (different ARTCCs)</option>
                            <option value="yes">Yes - Internal (same ARTCC)</option>
                        </select>
                        <small class="text-muted">Internal = within same ARTCC, External = between different ARTCCs</small>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5><i class="fas fa-ruler"></i> Restriction Parameters</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="required-field">Distance (nm)</label>
                                <input type="number" class="form-control" name="distance" id="mit_distance" min="1" max="999" placeholder="e.g., 20" required>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Qualifiers (click to select)</label>
                                <div class="d-flex flex-wrap">
                                    <span class="badge badge-secondary qualifier-badge m-1 p-2" data-qualifier="AS_ONE">AS ONE</span>
                                    <span class="badge badge-secondary qualifier-badge m-1 p-2" data-qualifier="EACH">EACH</span>
                                    <span class="badge badge-secondary qualifier-badge m-1 p-2" data-qualifier="PER_FIX">PER FIX</span>
                                    <span class="badge badge-secondary qualifier-badge m-1 p-2" data-qualifier="HEAVY">HEAVY</span>
                                    <span class="badge badge-secondary qualifier-badge m-1 p-2" data-qualifier="B757">B757</span>
                                    <span class="badge badge-secondary qualifier-badge m-1 p-2" data-qualifier="LARGE">LARGE</span>
                                    <span class="badge badge-secondary qualifier-badge m-1 p-2" data-qualifier="SMALL">SMALL</span>
                                </div>
                                <input type="hidden" name="qualifiers" id="mit_qualifiers" value="">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Speed Restriction (optional)</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="speed" placeholder="e.g., 250" min="100" max="500">
                                    <div class="input-group-append">
                                        <span class="input-group-text">kts</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Altitude Restriction (optional)</label>
                                <div class="input-group">
                                    <select class="form-control" name="alt_type" style="max-width: 100px;">
                                        <option value="">--</option>
                                        <option value="at">AT</option>
                                        <option value="above">AOA</option>
                                        <option value="below">AOB</option>
                                    </select>
                                    <input type="text" class="form-control" name="altitude" placeholder="e.g., FL240, 10000">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5><i class="fas fa-exclamation-triangle"></i> Reason &amp; Validity</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Reason</label>
                                <select class="form-control" name="reason" required>
                                    <option value="">-- Select --</option>
                                    <option value="WEATHER">Weather</option>
                                    <option value="VOLUME">Volume</option>
                                    <option value="RUNWAY">Runway</option>
                                    <option value="EQUIPMENT">Equipment</option>
                                    <option value="OTHER">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Exclusions</label>
                                <input type="text" class="form-control" name="exclusions" placeholder="e.g., Lifeguard, Military">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Valid From (Zulu)</label>
                                <input type="text" class="form-control" name="valid_from" placeholder="1400" maxlength="4" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Valid Until (Zulu)</label>
                                <input type="text" class="form-control" name="valid_until" placeholder="1800" maxlength="4" required>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section bg-primary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-paper-plane"></i> Submit MIT Entry</h5>
                        <button type="submit" class="btn btn-light btn-lg">
                            <i class="fas fa-check"></i> Submit to NTML
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- MINIT Form (Protocol 06) -->
        <div id="form-06" class="protocol-form" style="display: none;">
            <form id="minitForm">
                <input type="hidden" name="protocol" value="06">
                
                <div class="form-section">
                    <h5><i class="fas fa-info-circle"></i> Condition Information</h5>
                    <div class="form-group">
                        <label class="required-field">Condition Description</label>
                        <input type="text" class="form-control" name="condition" placeholder="e.g., JFK via LENDY, KATL arrivals" required>
                    </div>
                    <div class="form-group">
                        <label class="required-field">Restriction Type</label>
                        <select class="form-control" name="restriction_type" required>
                            <option value="">-- Select --</option>
                            <option value="arrival">Arrival</option>
                            <option value="departure">Departure</option>
                            <option value="enroute">En Route</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5><i class="fas fa-building"></i> Facilities</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Requesting Facility Type</label>
                                <select class="form-control" name="req_facility_type" required>
                                    <option value="">-- Select --</option>
                                    <option value="ARTCC">ARTCC (Center)</option>
                                    <option value="TRACON">TRACON</option>
                                    <option value="ATCT">ATCT (Tower)</option>
                                    <option value="ATCSCC">ATCSCC</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="required-field">Requesting Facility ID</label>
                                <input type="text" class="form-control artcc-select text-uppercase" name="req_facility_id" maxlength="4" placeholder="e.g., ZNY, N90, JFK" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Providing Facility Type</label>
                                <select class="form-control" name="prov_facility_type" required>
                                    <option value="">-- Select --</option>
                                    <option value="ARTCC">ARTCC (Center)</option>
                                    <option value="TRACON">TRACON</option>
                                    <option value="ATCT">ATCT (Tower)</option>
                                    <option value="ATCSCC">ATCSCC</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="required-field">Providing Facility ID</label>
                                <input type="text" class="form-control artcc-select text-uppercase" name="prov_facility_id" maxlength="4" placeholder="e.g., ZBW, A90, BOS" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="required-field">Same ARTCC?</label>
                        <select class="form-control" name="same_artcc" id="minit_same_artcc" required>
                            <option value="">-- Select --</option>
                            <option value="no">No - External (different ARTCCs)</option>
                            <option value="yes">Yes - Internal (same ARTCC)</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5><i class="fas fa-stopwatch"></i> Restriction Parameters</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="required-field">Minutes</label>
                                <input type="number" class="form-control" name="minutes" id="minit_minutes" min="1" max="999" placeholder="e.g., 15" required>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Qualifiers (click to select)</label>
                                <div class="d-flex flex-wrap">
                                    <span class="badge badge-secondary qualifier-badge m-1 p-2" data-qualifier="AS_ONE">AS ONE</span>
                                    <span class="badge badge-secondary qualifier-badge m-1 p-2" data-qualifier="EACH">EACH</span>
                                    <span class="badge badge-secondary qualifier-badge m-1 p-2" data-qualifier="PER_FIX">PER FIX</span>
                                    <span class="badge badge-secondary qualifier-badge m-1 p-2" data-qualifier="HEAVY">HEAVY</span>
                                    <span class="badge badge-secondary qualifier-badge m-1 p-2" data-qualifier="B757">B757</span>
                                    <span class="badge badge-secondary qualifier-badge m-1 p-2" data-qualifier="LARGE">LARGE</span>
                                    <span class="badge badge-secondary qualifier-badge m-1 p-2" data-qualifier="SMALL">SMALL</span>
                                </div>
                                <input type="hidden" name="qualifiers" id="minit_qualifiers" value="">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Speed Restriction (optional)</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="speed" placeholder="e.g., 250" min="100" max="500">
                                    <div class="input-group-append">
                                        <span class="input-group-text">kts</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Altitude Restriction (optional)</label>
                                <div class="input-group">
                                    <select class="form-control" name="alt_type" style="max-width: 100px;">
                                        <option value="">--</option>
                                        <option value="at">AT</option>
                                        <option value="above">AOA</option>
                                        <option value="below">AOB</option>
                                    </select>
                                    <input type="text" class="form-control" name="altitude" placeholder="e.g., FL240, 10000">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5><i class="fas fa-exclamation-triangle"></i> Reason &amp; Validity</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Reason</label>
                                <select class="form-control" name="reason" required>
                                    <option value="">-- Select --</option>
                                    <option value="WEATHER">Weather</option>
                                    <option value="VOLUME">Volume</option>
                                    <option value="RUNWAY">Runway</option>
                                    <option value="EQUIPMENT">Equipment</option>
                                    <option value="OTHER">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Exclusions</label>
                                <input type="text" class="form-control" name="exclusions" placeholder="e.g., Lifeguard, Military">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Valid From (Zulu)</label>
                                <input type="text" class="form-control" name="valid_from" placeholder="1400" maxlength="4" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Valid Until (Zulu)</label>
                                <input type="text" class="form-control" name="valid_until" placeholder="1800" maxlength="4" required>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section bg-info text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-paper-plane"></i> Submit MINIT Entry</h5>
                        <button type="submit" class="btn btn-light btn-lg">
                            <i class="fas fa-check"></i> Submit to NTML
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Delay Form (Protocol 04) -->
        <div id="form-04" class="protocol-form" style="display: none;">
            <form id="delayForm">
                <input type="hidden" name="protocol" value="04">
                
                <div class="form-section">
                    <h5><i class="fas fa-info-circle"></i> Delay Information</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Delay Type</label>
                                <select class="form-control" name="delay_type" required>
                                    <option value="">-- Select --</option>
                                    <option value="arrival">Arrival Delay</option>
                                    <option value="departure">Departure Delay</option>
                                    <option value="enroute">En Route Delay</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Timeframe</label>
                                <select class="form-control" name="timeframe" required>
                                    <option value="">-- Select --</option>
                                    <option value="now">Now (currently occurring)</option>
                                    <option value="just_occurred">Just occurred (within last 15 min)</option>
                                    <option value="non_recent">Non-recent (historical entry)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5><i class="fas fa-building"></i> Facilities</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Facility Where Flights Delayed</label>
                                <input type="text" class="form-control text-uppercase" name="delay_facility" maxlength="4" placeholder="e.g., JFK, ZNY" required>
                                <small class="text-muted">Airport or ARTCC where delays are occurring</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Facility to Charge Delay</label>
                                <input type="text" class="form-control text-uppercase" name="charge_facility" maxlength="4" placeholder="e.g., JFK, ZNY" required>
                                <small class="text-muted">Facility responsible for the delay cause</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5><i class="fas fa-chart-line"></i> Delay Metrics</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="required-field">Number of Flights Delayed</label>
                                <input type="number" class="form-control" name="flights_delayed" min="1" placeholder="e.g., 15" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="required-field">Longest Delay (minutes)</label>
                                <input type="number" class="form-control" name="longest_delay" id="delay_longest" min="1" max="999" placeholder="e.g., 45" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="required-field">Delay Trend</label>
                                <select class="form-control" name="delay_trend" id="delay_trend" required>
                                    <option value="">-- Select --</option>
                                    <option value="increasing">Increasing</option>
                                    <option value="steady">Steady</option>
                                    <option value="decreasing">Decreasing</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5><i class="fas fa-map-marker-alt"></i> Holding Information</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Holding in Air?</label>
                                <select class="form-control" name="holding" id="delay_holding" required>
                                    <option value="">-- Select --</option>
                                    <option value="no">No - Ground delays only</option>
                                    <option value="yes_initiating">Yes - Initiating holding</option>
                                    <option value="yes_15plus">Yes - Holding ≥15 minutes</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Holding Location</label>
                                <input type="text" class="form-control text-uppercase" name="holding_location" placeholder="e.g., CAMRN, JFK VOR" id="holding_location" disabled>
                                <small class="text-muted">Fix or NAVAID where holding is occurring</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5><i class="fas fa-exclamation-triangle"></i> Reason</h5>
                    <div class="form-group">
                        <label class="required-field">Delay Reason</label>
                        <select class="form-control" name="reason" required>
                            <option value="">-- Select --</option>
                            <option value="WEATHER">Weather</option>
                            <option value="VOLUME">Volume</option>
                            <option value="RUNWAY">Runway</option>
                            <option value="EQUIPMENT">Equipment</option>
                            <option value="OTHER">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Additional Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Any additional details about the delay situation"></textarea>
                    </div>
                </div>
                
                <div class="form-section bg-warning">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-paper-plane"></i> Submit Delay Entry</h5>
                        <button type="submit" class="btn btn-dark btn-lg">
                            <i class="fas fa-check"></i> Submit to NTML
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Airport Configuration Form (Protocol 01) -->
        <div id="form-01" class="protocol-form" style="display: none;">
            <form id="configForm">
                <input type="hidden" name="protocol" value="01">
                
                <div class="form-section">
                    <h5><i class="fas fa-plane"></i> Airport Information</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="required-field">Airport Code</label>
                                <input type="text" class="form-control text-uppercase" name="airport" maxlength="4" placeholder="e.g., JFK, KJFK" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="required-field">Weather Conditions</label>
                                <select class="form-control" name="weather" id="config_weather" required>
                                    <option value="">-- Select --</option>
                                    <option value="VMC">VMC - Visual Meteorological</option>
                                    <option value="LVMC">LVMC - Low Visibility VMC</option>
                                    <option value="IMC">IMC - Instrument Meteorological</option>
                                    <option value="LIMC">LIMC - Low Instrument MC</option>
                                    <option value="VLIMC">VLIMC - Very Low IMC</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="required-field">Fleet Mix</label>
                                <select class="form-control" name="fleet_mix" required>
                                    <option value="">-- Select --</option>
                                    <option value="heavy">≥50% Heavy aircraft</option>
                                    <option value="light">&lt;50% Heavy aircraft</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5><i class="fas fa-road"></i> Runway Configuration</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Arriving Runways</label>
                                <input type="text" class="form-control" name="arr_runways" placeholder="e.g., 22L/31R" required>
                                <small class="text-muted">Separate multiple runways with /</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required-field">Departing Runways</label>
                                <input type="text" class="form-control" name="dep_runways" placeholder="e.g., 22R/31L" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="required-field">Single-Runway Operation?</label>
                                <select class="form-control" name="single_runway" id="config_single_rwy" required>
                                    <option value="">-- Select --</option>
                                    <option value="no">No</option>
                                    <option value="yes">Yes</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Closely-Separated Parallels?</label>
                                <select class="form-control" name="close_parallels">
                                    <option value="no">No</option>
                                    <option value="yes">Yes</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>FAA Separation Waiver?</label>
                                <select class="form-control" name="faa_waiver">
                                    <option value="no">No</option>
                                    <option value="yes">Yes</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5><i class="fas fa-cloud-rain"></i> Weather &amp; Scenery</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Major Scenery Issues?</label>
                                <select class="form-control" name="scenery_issue" id="config_scenery">
                                    <option value="no">No</option>
                                    <option value="yes">Yes</option>
                                </select>
                            </div>
                            <div class="form-group" id="scenery_desc_group" style="display: none;">
                                <label>Scenery Issue Description</label>
                                <input type="text" class="form-control" name="scenery_description" placeholder="Describe the issue">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Inclement Weather Conditions</label>
                                <div class="d-flex flex-wrap">
                                    <div class="custom-control custom-checkbox mr-3">
                                        <input type="checkbox" class="custom-control-input" name="wx_wind" id="wx_wind" value="1">
                                        <label class="custom-control-label" for="wx_wind">High Winds</label>
                                    </div>
                                    <div class="custom-control custom-checkbox mr-3">
                                        <input type="checkbox" class="custom-control-input" name="wx_ice" id="wx_ice" value="1">
                                        <label class="custom-control-label" for="wx_ice">Ice/Snow</label>
                                    </div>
                                    <div class="custom-control custom-checkbox mr-3">
                                        <input type="checkbox" class="custom-control-input" name="wx_fog" id="wx_fog" value="1">
                                        <label class="custom-control-label" for="wx_fog">Fog</label>
                                    </div>
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" name="wx_tstorm" id="wx_tstorm" value="1">
                                        <label class="custom-control-label" for="wx_tstorm">Thunderstorms</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h5><i class="fas fa-tachometer-alt"></i> Rates</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="required-field">VATSIM AAR</label>
                                <input type="number" class="form-control" name="aar" id="config_aar" min="0" max="999" placeholder="e.g., 45" required>
                                <small class="text-muted">Airport Arrival Rate</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="required-field">VATSIM ADR</label>
                                <input type="number" class="form-control" name="adr" id="config_adr" min="0" max="999" placeholder="e.g., 50" required>
                                <small class="text-muted">Airport Departure Rate</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Operating Mode</label>
                                <select class="form-control" name="op_mode">
                                    <option value="">-- Select --</option>
                                    <option value="normal">Normal Operations</option>
                                    <option value="reduced">Reduced Operations</option>
                                    <option value="limited">Limited Operations</option>
                                    <option value="closed">Airport Closed</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section bg-success text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-paper-plane"></i> Submit Airport Config</h5>
                        <button type="submit" class="btn btn-light btn-lg">
                            <i class="fas fa-check"></i> Submit to NTML
                        </button>
                    </div>
                </div>
            </form>
        </div>

    </div>
    
    <?php else: ?>
    
    <div class="alert alert-danger">
        <h4><i class="fas fa-lock"></i> Access Denied</h4>
        <p>You must be logged in with appropriate permissions to access the NTML Protocol Form.</p>
        <a href="login/" class="btn btn-primary">Login with VATSIM</a>
    </div>
    
    <?php endif; ?>

</div>

<?php include('load/footer.php'); ?>
<script src="assets/js/ntml.js"></script>
</body>
</html>
