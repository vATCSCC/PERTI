<?php
/**
 * Airspace Elements Management Page
 * Admin interface for creating/editing custom airspace elements for crossing analysis
 */

include("sessions/handler.php");
// Session Start
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
        $cid = session_get('VATSIM_CID', '');
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
        if ($p_check) {
            $perm = true;
        }
    }
} else {
    $perm = true;
    $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
}

// Require authentication
if (!$perm) {
    header("Location: /login");
    exit;
}

?>

<!DOCTYPE html>
<html>

<head>
    <?php
        $page_title = "Airspace Elements - PERTI";
        include("load/header.php");
    ?>
    <style>
        .element-card {
            border-left: 4px solid #6c757d;
            transition: all 0.2s ease;
        }
        .element-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .element-card.type-VOLUME { border-left-color: #28a745; }
        .element-card.type-POINT { border-left-color: #007bff; }
        .element-card.type-LINE { border-left-color: #ffc107; }
        .badge-VOLUME { background-color: #28a745; }
        .badge-POINT { background-color: #007bff; }
        .badge-LINE { background-color: #ffc107; color: #212529; }
        .stats-badge {
            font-size: 0.75rem;
            padding: 0.2em 0.5em;
        }
        .filter-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .element-inactive {
            opacity: 0.6;
        }
    </style>
</head>

<body>

<?php include('load/nav.php'); ?>

<div class="container-fluid mt-5 mb-5 pt-5">

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="fas fa-draw-polygon text-primary"></i> <?= __('airspace.page.title') ?></h2>
            <p class="text-muted"><?= __('airspace.page.subtitle') ?></p>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="row mb-3">
        <div class="col-12">
            <button class="btn btn-success" data-toggle="modal" data-target="#createElementModal">
                <i class="fas fa-plus"></i> <?= __('airspace.page.createElement') ?>
            </button>
            <button class="btn btn-outline-secondary ml-2" onclick="refreshElements()">
                <i class="fas fa-sync-alt"></i> <?= __('airspace.page.refresh') ?>
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <div class="row">
            <div class="col-md-3">
                <label class="small text-muted"><?= __('airspace.page.elementType') ?></label>
                <select id="filterType" class="form-control form-control-sm" onchange="refreshElements()">
                    <option value=""><?= __('airspace.page.allTypes') ?></option>
                    <option value="VOLUME"><?= __('airspace.page.volume') ?></option>
                    <option value="POINT"><?= __('airspace.page.point') ?></option>
                    <option value="LINE"><?= __('airspace.page.line') ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="small text-muted"><?= __('airspace.page.category') ?></label>
                <select id="filterCategory" class="form-control form-control-sm" onchange="refreshElements()">
                    <option value=""><?= __('airspace.page.allCategories') ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="small text-muted"><?= __('common.status') ?></label>
                <select id="filterActive" class="form-control form-control-sm" onchange="refreshElements()">
                    <option value="1"><?= __('airspace.page.activeOnly') ?></option>
                    <option value="0"><?= __('airspace.page.inactiveOnly') ?></option>
                    <option value="">All</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="small text-muted"><?= __('common.search') ?></label>
                <input type="text" id="filterSearch" class="form-control form-control-sm" placeholder="<?= __('airspace.page.searchPlaceholder') ?>" onkeyup="debounceSearch()">
            </div>
        </div>
    </div>

    <!-- Elements List -->
    <div id="elementsList" class="row">
        <div class="col-12 text-center py-5">
            <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
            <p class="text-muted mt-2"><?= __('airspace.page.loadingElements') ?></p>
        </div>
    </div>

</div>

<!-- Create Element Modal -->
<div class="modal fade" id="createElementModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-plus"></i> <?= __('airspace.page.createAirspaceElement') ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="createElementForm">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('airspace.page.elementName') ?> <span class="text-danger">*</span></label>
                                <input type="text" name="element_name" class="form-control" required placeholder="e.g., FCA_KJFK_WEST">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><?= __('airspace.page.elementType') ?> <span class="text-danger">*</span></label>
                                <select name="element_type" class="form-control" required onchange="updateSubtypeOptions(this.value, 'create')">
                                    <option value="">Select...</option>
                                    <option value="VOLUME"><?= __('airspace.page.volume') ?></option>
                                    <option value="POINT"><?= __('airspace.page.point') ?></option>
                                    <option value="LINE"><?= __('airspace.page.line') ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><?= __('airspace.page.subtype') ?></label>
                                <select name="element_subtype" id="createSubtype" class="form-control">
                                    <option value=""><?= __('airspace.page.selectTypeFirst') ?></option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('airspace.page.category') ?></label>
                                <select name="category" class="form-control">
                                    <option value="">None</option>
                                    <option value="TMI">TMI</option>
                                    <option value="FCA">FCA</option>
                                    <option value="AFP">AFP</option>
                                    <option value="REROUTE">REROUTE</option>
                                    <option value="CUSTOM">CUSTOM</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('airspace.page.descriptionLabel') ?></label>
                                <input type="text" name="description" class="form-control" placeholder="<?= __('airspace.page.descriptionPlaceholder') ?>">
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h6 class="text-muted"><?= __('airspace.page.reference') ?></h6>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('airspace.page.referenceBoundary') ?></label>
                                <select name="reference_boundary_id" class="form-control selectpicker" data-live-search="true" data-size="10">
                                    <option value=""><?= __('airspace.page.noneCustom') ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><?= __('airspace.page.referenceFix') ?></label>
                                <input type="text" name="reference_fix_name" class="form-control" placeholder="e.g., JFK">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><?= __('airspace.page.referenceAirway') ?></label>
                                <input type="text" name="reference_airway" class="form-control" placeholder="e.g., J60">
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h6 class="text-muted"><?= __('airspace.page.constraints') ?></h6>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><?= __('airspace.page.radiusNm') ?></label>
                                <input type="number" name="radius_nm" class="form-control" step="0.1" placeholder="For points">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><?= __('airspace.page.floorFl') ?></label>
                                <input type="number" name="floor_fl" class="form-control" placeholder="e.g., 240">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><?= __('airspace.page.ceilingFl') ?></label>
                                <input type="number" name="ceiling_fl" class="form-control" placeholder="e.g., 450">
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h6 class="text-muted"><?= __('airspace.page.customGeometry') ?></h6>
                    <div class="form-group">
                        <textarea name="geometry_wkt" class="form-control" rows="3" placeholder="POLYGON((-77.0 38.9, -77.1 38.9, -77.1 38.8, -77.0 38.8, -77.0 38.9))"></textarea>
                        <small class="form-text text-muted"><?= __('airspace.page.wktHelp') ?></small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __('common.cancel') ?></button>
                <button type="button" class="btn btn-success" onclick="createElement()">
                    <i class="fas fa-plus"></i> <?= __('airspace.page.createElement') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Element Modal -->
<div class="modal fade" id="editElementModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-edit"></i> <?= __('airspace.page.editAirspaceElement') ?></h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="editElementForm">
                    <input type="hidden" name="element_id" id="editElementId">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('airspace.page.elementName') ?> <span class="text-danger">*</span></label>
                                <input type="text" name="element_name" id="editElementName" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><?= __('airspace.page.elementType') ?> <span class="text-danger">*</span></label>
                                <select name="element_type" id="editElementType" class="form-control" required onchange="updateSubtypeOptions(this.value, 'edit')">
                                    <option value="VOLUME"><?= __('airspace.page.volume') ?></option>
                                    <option value="POINT"><?= __('airspace.page.point') ?></option>
                                    <option value="LINE"><?= __('airspace.page.line') ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><?= __('airspace.page.subtype') ?></label>
                                <select name="element_subtype" id="editSubtype" class="form-control"></select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('airspace.page.category') ?></label>
                                <select name="category" id="editCategory" class="form-control">
                                    <option value="">None</option>
                                    <option value="TMI">TMI</option>
                                    <option value="FCA">FCA</option>
                                    <option value="AFP">AFP</option>
                                    <option value="REROUTE">REROUTE</option>
                                    <option value="CUSTOM">CUSTOM</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('airspace.page.descriptionLabel') ?></label>
                                <input type="text" name="description" id="editDescription" class="form-control">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><?= __('airspace.page.radiusNm') ?></label>
                                <input type="number" name="radius_nm" id="editRadius" class="form-control" step="0.1">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><?= __('airspace.page.floorFl') ?></label>
                                <input type="number" name="floor_fl" id="editFloor" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><?= __('airspace.page.ceilingFl') ?></label>
                                <input type="number" name="ceiling_fl" id="editCeiling" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="is_active" id="editActive" class="form-control">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><?= __('airspace.page.customGeometry') ?></label>
                        <textarea name="geometry_wkt" id="editGeometry" class="form-control" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger mr-auto" onclick="deleteElement()">
                    <i class="fas fa-trash"></i> <?= __('common.delete') ?>
                </button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __('common.cancel') ?></button>
                <button type="button" class="btn btn-primary" onclick="updateElement()">
                    <i class="fas fa-save"></i> <?= __('common.saveChanges') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php include('load/footer.php'); ?>

<script>
// Subtype options by element type
const subtypeOptions = {
    VOLUME: {
        'ARTCC': 'Air Route Traffic Control Center',
        'SECTOR_HIGH': 'High-altitude Sector',
        'SECTOR_LOW': 'Low-altitude Sector',
        'TRACON': 'Terminal Radar Approach Control',
        'FCA': 'Flow Constrained Area',
        'AFP': 'Airspace Flow Program',
        'CUSTOM': 'Custom Volume'
    },
    POINT: {
        'FIX': 'Navigation Fix',
        'NAVAID': 'Navigation Aid (VOR/NDB)',
        'AIRPORT': 'Airport',
        'METER_FIX': 'Metering Fix',
        'CUSTOM': 'Custom Point'
    },
    LINE: {
        'AIRWAY': 'Published Airway',
        'STAR': 'Standard Terminal Arrival Route',
        'SID': 'Standard Instrument Departure',
        'ROUTE': 'Published Route',
        'CUSTOM': 'Custom Line'
    }
};

let searchTimeout = null;

function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(refreshElements, 300);
}

function updateSubtypeOptions(type, formPrefix) {
    const select = document.getElementById(formPrefix + 'Subtype');
    select.innerHTML = '<option value="">None</option>';

    if (type && subtypeOptions[type]) {
        for (const [value, label] of Object.entries(subtypeOptions[type])) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            select.appendChild(option);
        }
    }
}

function refreshElements() {
    const type = document.getElementById('filterType').value;
    const category = document.getElementById('filterCategory').value;
    const active = document.getElementById('filterActive').value;
    const search = document.getElementById('filterSearch').value;

    let url = '/api/data/airspace_elements/list.php?';
    if (type) url += `type=${type}&`;
    if (category) url += `category=${encodeURIComponent(category)}&`;
    if (active !== '') url += `active=${active}&`;
    if (search) url += `search=${encodeURIComponent(search)}&`;

    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderElements(data.data);
                updateCategoryFilter(data.categories);
            } else {
                showError('Failed to load elements: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => showError('Network error: ' + err.message));
}

function updateCategoryFilter(categories) {
    const select = document.getElementById('filterCategory');
    const current = select.value;

    // Keep "All Categories" option
    select.innerHTML = '<option value="">All Categories</option>';

    categories.forEach(cat => {
        const option = document.createElement('option');
        option.value = cat;
        option.textContent = cat;
        if (cat === current) option.selected = true;
        select.appendChild(option);
    });
}

function renderElements(elements) {
    const container = document.getElementById('elementsList');

    if (!elements || elements.length === 0) {
        container.innerHTML = `
            <div class="col-12 text-center py-5">
                <i class="fas fa-inbox fa-3x text-muted"></i>
                <p class="text-muted mt-3">No airspace elements found.</p>
                <button class="btn btn-success" data-toggle="modal" data-target="#createElementModal">
                    <i class="fas fa-plus"></i> Create First Element
                </button>
            </div>
        `;
        return;
    }

    let html = '';
    elements.forEach(el => {
        const inactiveClass = el.is_active == 0 ? 'element-inactive' : '';
        html += `
            <div class="col-md-6 col-lg-4 mb-3">
                <div class="card element-card type-${el.element_type} ${inactiveClass}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="card-title mb-1">${escapeHtml(el.element_name)}</h5>
                                <span class="badge badge-${el.element_type}">${el.element_type}</span>
                                ${el.element_subtype ? `<span class="badge badge-secondary">${el.element_subtype}</span>` : ''}
                                ${el.category ? `<span class="badge badge-info">${el.category}</span>` : ''}
                                ${el.is_active == 0 ? '<span class="badge badge-dark">Inactive</span>' : ''}
                            </div>
                            <button class="btn btn-sm btn-outline-primary" onclick="editElement(${el.element_id})">
                                <i class="fas fa-edit"></i>
                            </button>
                        </div>
                        ${el.description ? `<p class="card-text small text-muted mt-2 mb-1">${escapeHtml(el.description)}</p>` : ''}
                        <div class="mt-2">
                            ${el.ref_boundary_code ? `<small class="text-muted"><i class="fas fa-link"></i> ${el.ref_boundary_code}</small><br>` : ''}
                            ${el.reference_fix_name ? `<small class="text-muted"><i class="fas fa-map-marker-alt"></i> ${el.reference_fix_name}</small><br>` : ''}
                            ${el.floor_fl || el.ceiling_fl ? `<small class="text-muted"><i class="fas fa-arrows-alt-v"></i> FL${el.floor_fl || '000'}-${el.ceiling_fl || '999'}</small><br>` : ''}
                            ${el.radius_nm ? `<small class="text-muted"><i class="fas fa-circle"></i> ${el.radius_nm}nm radius</small>` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function createElement() {
    const form = document.getElementById('createElementForm');
    const formData = new FormData(form);
    const data = {};
    formData.forEach((value, key) => {
        if (value !== '') data[key] = value;
    });

    if (!data.element_name || !data.element_type) {
        Swal.fire('Error', 'Element name and type are required', 'error');
        return;
    }

    fetch('/api/mgt/airspace_elements/post.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            Swal.fire('Success', 'Element created successfully', 'success');
            $('#createElementModal').modal('hide');
            form.reset();
            refreshElements();
        } else {
            Swal.fire('Error', result.error || 'Failed to create element', 'error');
        }
    })
    .catch(err => Swal.fire('Error', 'Network error: ' + err.message, 'error'));
}

function editElement(elementId) {
    fetch(`/api/data/airspace_elements/get.php?element_id=${elementId}`)
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                const el = result.data;
                document.getElementById('editElementId').value = el.element_id;
                document.getElementById('editElementName').value = el.element_name || '';
                document.getElementById('editElementType').value = el.element_type || '';
                updateSubtypeOptions(el.element_type, 'edit');
                document.getElementById('editSubtype').value = el.element_subtype || '';
                document.getElementById('editCategory').value = el.category || '';
                document.getElementById('editDescription').value = el.description || '';
                document.getElementById('editRadius').value = el.radius_nm || '';
                document.getElementById('editFloor').value = el.floor_fl || '';
                document.getElementById('editCeiling').value = el.ceiling_fl || '';
                document.getElementById('editActive').value = el.is_active;
                document.getElementById('editGeometry').value = el.geometry_wkt || '';

                $('#editElementModal').modal('show');
            } else {
                Swal.fire('Error', result.error || 'Failed to load element', 'error');
            }
        })
        .catch(err => Swal.fire('Error', 'Network error: ' + err.message, 'error'));
}

function updateElement() {
    const form = document.getElementById('editElementForm');
    const formData = new FormData(form);
    const data = {};
    formData.forEach((value, key) => {
        data[key] = value;
    });

    fetch('/api/mgt/airspace_elements/update.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            Swal.fire('Success', 'Element updated successfully', 'success');
            $('#editElementModal').modal('hide');
            refreshElements();
        } else {
            Swal.fire('Error', result.error || 'Failed to update element', 'error');
        }
    })
    .catch(err => Swal.fire('Error', 'Network error: ' + err.message, 'error'));
}

function deleteElement() {
    const elementId = document.getElementById('editElementId').value;

    Swal.fire({
        title: 'Delete Element?',
        text: 'This will deactivate the element. Use hard delete for permanent removal.',
        icon: 'warning',
        showCancelButton: true,
        showDenyButton: true,
        confirmButtonText: 'Deactivate',
        denyButtonText: 'Delete Permanently',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed || result.isDenied) {
            const hard = result.isDenied ? 1 : 0;
            fetch(`/api/mgt/airspace_elements/delete.php?element_id=${elementId}&hard=${hard}`, {
                method: 'POST'
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    Swal.fire('Success', res.message, 'success');
                    $('#editElementModal').modal('hide');
                    refreshElements();
                } else {
                    Swal.fire('Error', res.error || 'Failed to delete', 'error');
                }
            })
            .catch(err => Swal.fire('Error', 'Network error: ' + err.message, 'error'));
        }
    });
}

function showError(message) {
    document.getElementById('elementsList').innerHTML = `
        <div class="col-12 text-center py-5">
            <i class="fas fa-exclamation-triangle fa-3x text-danger"></i>
            <p class="text-danger mt-3">${escapeHtml(message)}</p>
            <button class="btn btn-primary" onclick="refreshElements()">
                <i class="fas fa-redo"></i> Retry
            </button>
        </div>
    `;
}

// Load boundaries for reference dropdown
function loadBoundaries() {
    fetch('/api/data/airspace_elements/lookup.php?type=boundaries&limit=500')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const select = document.querySelector('select[name="reference_boundary_id"]');
                data.data.forEach(b => {
                    const option = document.createElement('option');
                    option.value = b.boundary_id;
                    option.textContent = `${b.boundary_code} (${b.boundary_type})`;
                    select.appendChild(option);
                });
                $(select).selectpicker('refresh');
            }
        })
        .catch(err => console.error('Failed to load boundaries:', err));
}

// Initialize on page load
$(document).ready(function() {
    refreshElements();
    loadBoundaries();
});
</script>

</body>
</html>
