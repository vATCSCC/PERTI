<?php
/**
 * NTML Protocol Entry Form - Streamlined Quick Entry
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
        :root {
            --ntml-green: #00ff00;
            --ntml-dark: #1a1a2e;
        }
        
        /* Quick Entry Styling */
        .quick-entry-container {
            background: var(--ntml-dark);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .quick-input {
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 1.1rem;
            background: #0d0d1a;
            border: 2px solid #333;
            color: var(--ntml-green);
            padding: 15px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .quick-input:focus {
            border-color: var(--ntml-green);
            box-shadow: 0 0 20px rgba(0, 255, 0, 0.2);
            outline: none;
        }
        
        .quick-input::placeholder {
            color: #555;
        }
        
        /* Batch Entry */
        .batch-textarea {
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 0.95rem;
            background: #0d0d1a;
            border: 2px solid #333;
            color: var(--ntml-green);
            min-height: 150px;
            resize: vertical;
        }
        
        .batch-textarea:focus {
            border-color: var(--ntml-green);
            box-shadow: 0 0 20px rgba(0, 255, 0, 0.2);
        }
        
        /* Preview Cards */
        .preview-card {
            background: #0d0d1a;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            font-family: monospace;
            position: relative;
        }
        
        .preview-card.valid {
            border-color: #28a745;
        }
        
        .preview-card.invalid {
            border-color: #dc3545;
            background: rgba(220, 53, 69, 0.1);
        }
        
        .preview-card .determinant {
            color: var(--ntml-green);
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .preview-card .details {
            color: #aaa;
            font-size: 0.9rem;
            margin-top: 5px;
        }
        
        .preview-card .remove-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 5px;
        }
        
        .preview-card .remove-btn:hover {
            color: #dc3545;
        }
        
        /* Templates */
        .template-btn {
            background: #2a2a4a;
            border: 1px solid #444;
            color: #ccc;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            margin: 3px;
        }
        
        .template-btn:hover {
            background: #3a3a5a;
            border-color: #007bff;
            color: #fff;
        }
        
        .template-btn.active {
            background: #007bff;
            border-color: #007bff;
        }
        
        /* Protocol Type Pills */
        .protocol-pill {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .protocol-pill.mit { background: #007bff; color: white; }
        .protocol-pill.minit { background: #17a2b8; color: white; }
        .protocol-pill.delay { background: #ffc107; color: black; }
        .protocol-pill.config { background: #28a745; color: white; }
        
        /* Mode Toggle */
        .mode-toggle {
            background: #2a2a4a;
            border-radius: 25px;
            padding: 4px;
            display: inline-flex;
        }
        
        .mode-toggle .mode-btn {
            background: none;
            border: none;
            color: #888;
            padding: 8px 20px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .mode-toggle .mode-btn.active {
            background: #007bff;
            color: white;
        }
        
        /* Autocomplete */
        .autocomplete-dropdown {
            position: absolute;
            background: #1a1a2e;
            border: 1px solid #444;
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            width: 100%;
            display: none;
        }
        
        .autocomplete-dropdown.show {
            display: block;
        }
        
        .autocomplete-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #333;
            color: #ccc;
        }
        
        .autocomplete-item:hover, .autocomplete-item.selected {
            background: #2a2a4a;
            color: white;
        }
        
        .autocomplete-item .facility-id {
            font-family: monospace;
            color: var(--ntml-green);
            font-weight: bold;
        }
        
        .autocomplete-item .facility-name {
            font-size: 0.85rem;
            color: #888;
        }
        
        /* Help Syntax */
        .syntax-help {
            background: #2a2a4a;
            border-radius: 8px;
            padding: 15px;
            font-size: 0.85rem;
            color: #aaa;
        }
        
        .syntax-help code {
            background: #1a1a2e;
            padding: 2px 6px;
            border-radius: 4px;
            color: var(--ntml-green);
        }
        
        /* Submit Area */
        .submit-area {
            background: linear-gradient(135deg, #1a1a2e 0%, #2a2a4a 100%);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .entry-count {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--ntml-green);
        }
        
        .entry-count small {
            font-size: 0.9rem;
            color: #888;
            font-weight: normal;
        }
        
        /* Production Warning */
        .production-warning {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            display: none;
        }
        
        .production-warning.show {
            display: block;
        }
        
        /* Keyboard shortcuts hint */
        .kbd {
            background: #333;
            border: 1px solid #555;
            border-radius: 4px;
            padding: 2px 6px;
            font-family: monospace;
            font-size: 0.8rem;
            color: #ccc;
        }
    </style>
</head>
<body>

<?php include('load/nav.php'); ?>

<section class="d-flex align-items-center position-relative bg-position-center fh-section overflow-hidden pt-6 jarallax bg-dark text-light" data-jarallax data-speed="0.3">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">
        <center>
            <h1><i class="fas fa-clipboard-list"></i> NTML Quick Entry</h1>
            <p class="text-white-50 mb-0">National Traffic Management Log</p>
        </center>
    </div>
</section>

<div class="container-fluid mt-4 mb-5">
    
    <?php if ($perm): ?>
    
    <!-- Header Controls -->
    <div class="row mb-3">
        <div class="col-md-6">
            <div class="mode-toggle">
                <button class="mode-btn active" data-mode="single">Single Entry</button>
                <button class="mode-btn" data-mode="batch">Batch Entry</button>
            </div>
        </div>
        <div class="col-md-6 text-right">
            <div class="custom-control custom-switch d-inline-block mr-3">
                <input type="checkbox" class="custom-control-input" id="productionMode">
                <label class="custom-control-label" for="productionMode">Production Mode</label>
            </div>
            <span class="badge badge-warning" id="modeIndicator">TEST</span>
        </div>
    </div>
    
    <!-- Production Warning -->
    <div class="production-warning mb-3" id="prodWarning">
        <i class="fas fa-exclamation-triangle"></i> <strong>Production Mode Active</strong> - Entries will post to LIVE Discord channels
    </div>

    <!-- Quick Entry Container -->
    <div class="quick-entry-container">
        
        <!-- Single Entry Mode -->
        <div id="singleMode">
            <div class="row">
                <div class="col-lg-8">
                    <label class="text-white-50 mb-2">
                        <i class="fas fa-terminal"></i> Quick Entry 
                        <small class="text-muted ml-2">Press <span class="kbd">Enter</span> to add, <span class="kbd">Ctrl+Enter</span> to submit all</small>
                    </label>
                    <input type="text" class="form-control quick-input" id="quickInput" 
                           placeholder="20MIT ZBW→ZNY JFK LENDY VOLUME" autocomplete="off">
                    <div class="autocomplete-dropdown" id="autocompleteDropdown"></div>
                </div>
                <div class="col-lg-4">
                    <label class="text-white-50 mb-2">Validity Period</label>
                    <div class="d-flex">
                        <input type="text" class="form-control quick-input mr-2" id="validFrom" placeholder="1400" maxlength="4" style="width: 80px;">
                        <span class="text-white-50 align-self-center mx-2">→</span>
                        <input type="text" class="form-control quick-input" id="validUntil" placeholder="1800" maxlength="4" style="width: 80px;">
                        <span class="text-white-50 align-self-center ml-2">Z</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Batch Entry Mode -->
        <div id="batchMode" style="display: none;">
            <label class="text-white-50 mb-2">
                <i class="fas fa-layer-group"></i> Batch Entry 
                <small class="text-muted ml-2">One entry per line</small>
            </label>
            <textarea class="form-control batch-textarea" id="batchInput" rows="6" 
                      placeholder="20MIT ZBW→ZNY JFK LENDY VOLUME 1400-1800
15MIT ZDC→ZNY EWR VOLUME 1400-1800
30MINIT ZOB→ZNY LGA WEATHER 1500-1900"></textarea>
        </div>
        
        <!-- Templates -->
        <div class="mt-3">
            <span class="text-white-50 mr-2"><i class="fas fa-magic"></i> Templates:</span>
            <button class="template-btn" data-template="mit-arr">MIT Arrival</button>
            <button class="template-btn" data-template="mit-dep">MIT Departure</button>
            <button class="template-btn" data-template="minit">MINIT</button>
            <button class="template-btn" data-template="delay">Delay</button>
            <button class="template-btn" data-template="config">Airport Config</button>
            <button class="template-btn" data-template="gs">Ground Stop</button>
        </div>
        
        <!-- Syntax Help (collapsible) -->
        <div class="mt-3">
            <a class="text-white-50" data-toggle="collapse" href="#syntaxHelp" style="text-decoration: none;">
                <i class="fas fa-question-circle"></i> Syntax Help <i class="fas fa-chevron-down ml-1"></i>
            </a>
            <div class="collapse mt-2" id="syntaxHelp">
                <div class="syntax-help">
                    <div class="row">
                        <div class="col-md-6">
                            <strong>MIT/MINIT:</strong><br>
                            <code>[distance]MIT [from]→[to] [airport/fix] [reason]</code><br>
                            <small>Example: <code>20MIT ZBW→ZNY JFK LENDY VOLUME</code></small>
                            <hr class="my-2 border-secondary">
                            <strong>Delay:</strong><br>
                            <code>DELAY [facility] [minutes]min [trend] [flights]flt</code><br>
                            <small>Example: <code>DELAY JFK 45min INC 12flt WEATHER</code></small>
                        </div>
                        <div class="col-md-6">
                            <strong>Airport Config:</strong><br>
                            <code>CONFIG [airport] [wx] ARR:[rwys] DEP:[rwys] AAR:[n] ADR:[n]</code><br>
                            <small>Example: <code>CONFIG JFK IMC ARR:22L/22R DEP:31L AAR:40 ADR:45</code></small>
                            <hr class="my-2 border-secondary">
                            <strong>Modifiers:</strong><br>
                            <code>HEAVY</code> <code>B757</code> <code>EACH</code> <code>AS_ONE</code> <code>PER_FIX</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Entry Queue Preview -->
    <div class="row">
        <div class="col-12">
            <h5 class="text-white mb-3">
                <i class="fas fa-list"></i> Entry Queue 
                <span class="badge badge-secondary" id="queueCount">0</span>
                <button class="btn btn-sm btn-outline-danger float-right" id="clearQueue" style="display: none;">
                    <i class="fas fa-trash"></i> Clear All
                </button>
            </h5>
            <div id="entryQueue">
                <div class="text-center text-muted py-4" id="emptyQueueMsg">
                    <i class="fas fa-inbox fa-2x mb-2"></i><br>
                    No entries queued. Type above to add entries.
                </div>
            </div>
        </div>
    </div>

    <!-- Submit Area -->
    <div class="submit-area mt-4" id="submitArea" style="display: none;">
        <div class="entry-count">
            <span id="submitCount">0</span> <small>entries ready</small>
        </div>
        <div>
            <button class="btn btn-outline-secondary mr-2" id="previewBtn">
                <i class="fas fa-eye"></i> Preview Messages
            </button>
            <button class="btn btn-success btn-lg" id="submitAllBtn">
                <i class="fas fa-paper-plane"></i> Submit All to NTML
            </button>
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

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Message Preview</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="previewContent" style="font-family: monospace; white-space: pre-wrap;">
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="submitFromPreview">
                    <i class="fas fa-paper-plane"></i> Submit All
                </button>
            </div>
        </div>
    </div>
</div>

<?php include('load/footer.php'); ?>
<script src="assets/js/ntml.js"></script>
</body>
</html>
