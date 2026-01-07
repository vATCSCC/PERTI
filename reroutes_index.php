<?php
/**
 * reroutes_index.php - Reroute Management Dashboard
 */
require_once 'sessions/handler.php';
require_once 'load/connect.php';
$page_title = "vATCSCC Reroutes";
require_once 'load/header.php';
require_once 'load/nav.php';
?>

<style>
.utc-clock { font-family: 'Courier New', monospace; font-size: 1.1rem; }
.section-header { font-size: 0.8rem; font-weight: 600; color: #737491; letter-spacing: 0.5px; }
.stats-card { text-align: center; }
.stats-card .value { font-size: 2rem; font-weight: 600; }
.stats-card .label { font-size: 0.75rem; text-transform: uppercase; color: #737491; }
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h4 class="mb-0">
                <i class="fas fa-route text-info"></i> Reroute Management
            </h4>
        </div>
        <div class="col-auto">
            <div class="border rounded px-3 py-2 d-flex align-items-center">
                <span class="text-muted small mr-2">CURRENT UTC</span>
                <span class="utc-clock" id="utc_clock">--:--:--Z</span>
                <i class="far fa-clock text-info ml-2"></i>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row mb-4">
        <div class="col">
            <div class="card shadow-sm h-100">
                <div class="card-body stats-card">
                    <div class="value text-success" id="count_active">-</div>
                    <div class="label">Active</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm h-100">
                <div class="card-body stats-card">
                    <div class="value text-warning" id="count_monitoring">-</div>
                    <div class="label">Monitoring</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm h-100">
                <div class="card-body stats-card">
                    <div class="value text-info" id="count_proposed">-</div>
                    <div class="label">Proposed</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm h-100">
                <div class="card-body stats-card">
                    <div class="value text-secondary" id="count_draft">-</div>
                    <div class="label">Drafts</div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm h-100">
                <div class="card-body stats-card">
                    <div class="value text-muted" id="count_expired">-</div>
                    <div class="label">Expired</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Card -->
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="section-header">
                    <i class="fas fa-list"></i> ALL REROUTES
                </span>
                <a href="reroutes.php" class="btn btn-success btn-sm">
                    <i class="fas fa-plus"></i> New Reroute
                </a>
            </div>
            
            <!-- Filters -->
            <div class="d-flex align-items-center mb-3">
                <div class="btn-group btn-group-sm mr-3">
                    <button class="btn btn-outline-secondary active" data-filter="">All</button>
                    <button class="btn btn-outline-success" data-filter="2,3">Active</button>
                    <button class="btn btn-outline-info" data-filter="0,1">Drafts</button>
                    <button class="btn btn-outline-secondary" data-filter="4,5">Expired</button>
                </div>
                <input type="text" class="form-control form-control-sm" id="searchInput" 
                       placeholder="Search by name..." style="max-width: 250px;">
                <button class="btn btn-sm btn-link ml-2" onclick="loadReroutes()">
                    <i class="fas fa-sync"></i>
                </button>
            </div>
            
            <!-- Table -->
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>STATUS</th>
                            <th>NAME</th>
                            <th>SCOPE</th>
                            <th>TIME WINDOW</th>
                            <th>PROTECTED</th>
                            <th>FLIGHTS</th>
                            <th>COMPLIANCE</th>
                            <th>UPDATED</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="reroutes_table_body">
                        <tr>
                            <td colspan="9" class="text-center py-4">
                                <i class="fas fa-spinner fa-spin"></i> Loading...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // UTC Clock
    function updateClock() {
        const now = new Date();
        const day = now.getUTCDate().toString().padStart(2, '0');
        const h = now.getUTCHours().toString().padStart(2, '0');
        const m = now.getUTCMinutes().toString().padStart(2, '0');
        const s = now.getUTCSeconds().toString().padStart(2, '0');
        document.getElementById('utc_clock').textContent = `${day} / ${h}:${m}:${s}Z`;
    }
    updateClock();
    setInterval(updateClock, 1000);
    
    // Load data
    loadReroutes();
    
    // Filter buttons
    document.querySelectorAll('[data-filter]').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('[data-filter]').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            loadReroutes();
        });
    });
    
    // Search
    let searchTimeout;
    document.getElementById('searchInput').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(loadReroutes, 300);
    });
    
    // Auto-refresh
    setInterval(loadReroutes, 30000);
});

async function loadReroutes() {
    const filter = document.querySelector('[data-filter].active')?.dataset.filter || '';
    const search = document.getElementById('searchInput').value.trim().toLowerCase();
    
    try {
        let url = 'api/data/tmi/reroutes.php?limit=100';
        if (filter) url += `&status=${filter}`;
        
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.status !== 'ok') throw new Error(data.message);
        
        // Update counts
        const counts = data.counts || {};
        document.getElementById('count_active').textContent = counts['Active'] || 0;
        document.getElementById('count_monitoring').textContent = counts['Monitoring'] || 0;
        document.getElementById('count_proposed').textContent = counts['Proposed'] || 0;
        document.getElementById('count_draft').textContent = counts['Draft'] || 0;
        document.getElementById('count_expired').textContent = (counts['Expired'] || 0) + (counts['Cancelled'] || 0);
        
        // Filter by search
        let reroutes = data.reroutes || [];
        if (search) {
            reroutes = reroutes.filter(r => 
                (r.name || '').toLowerCase().includes(search) ||
                (r.origin_centers || '').toLowerCase().includes(search) ||
                (r.dest_centers || '').toLowerCase().includes(search)
            );
        }
        
        renderTable(reroutes);
        
    } catch (e) {
        console.error('Load error:', e);
        document.getElementById('reroutes_table_body').innerHTML = 
            `<tr><td colspan="9" class="text-center text-danger py-4">Error: ${e.message}</td></tr>`;
    }
}

function renderTable(reroutes) {
    const tbody = document.getElementById('reroutes_table_body');
    
    if (!reroutes.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">No reroutes found</td></tr>';
        return;
    }
    
    const statusClasses = {
        0: 'secondary', 1: 'info', 2: 'success', 3: 'warning', 4: 'dark', 5: 'danger'
    };
    
    tbody.innerHTML = reroutes.map(r => {
        const scope = [];
        if (r.origin_centers) scope.push(r.origin_centers);
        else if (r.origin_airports) scope.push(r.origin_airports);
        scope.push('→');
        if (r.dest_centers) scope.push(r.dest_centers);
        else if (r.dest_airports) scope.push(r.dest_airports);
        
        const timeWindow = r.start_utc && r.end_utc 
            ? `${formatTime(r.start_utc)} - ${formatTime(r.end_utc)}`
            : '--';
        
        return `
            <tr class="${r.status === 2 ? 'table-success' : r.status === 3 ? 'table-warning' : ''}">
                <td>
                    <span class="badge badge-${statusClasses[r.status] || 'secondary'}">
                        ${r.status_label || 'Unknown'}
                    </span>
                </td>
                <td>
                    <a href="reroutes.php?id=${r.id}" class="font-weight-bold">
                        ${escapeHtml(r.name)}
                    </a>
                    ${r.adv_number ? `<br><small class="text-muted">${r.adv_number}</small>` : ''}
                </td>
                <td><small>${scope.join(' ') || '--'}</small></td>
                <td><small>${timeWindow}</small></td>
                <td><small class="text-muted">${r.protected_fixes || '--'}</small></td>
                <td><span class="badge badge-secondary" id="flights_${r.id}">--</span></td>
                <td><span class="badge badge-secondary" id="compliance_${r.id}">--</span></td>
                <td><small>${r.updated_utc ? r.updated_utc.substring(5, 16) : '--'}</small></td>
                <td>
                    <a href="reroutes.php?id=${r.id}" class="btn btn-sm btn-outline-primary" title="Edit">
                        <i class="fas fa-edit"></i>
                    </a>
                </td>
            </tr>
        `;
    }).join('');
    
    // Load stats for active reroutes
    reroutes.filter(r => r.status >= 2 && r.status <= 3).forEach(r => loadRerouteStats(r.id));
}

async function loadRerouteStats(id) {
    try {
        const response = await fetch(`api/tmi/rr_stats.php?id=${id}`);
        const data = await response.json();
        
        if (data.status === 'ok' && data.statistics) {
            const s = data.statistics;
            const flightsEl = document.getElementById(`flights_${id}`);
            const compEl = document.getElementById(`compliance_${id}`);
            
            if (flightsEl) {
                flightsEl.textContent = s.total_flights || 0;
                flightsEl.className = 'badge badge-info';
            }
            
            if (compEl && s.compliance_rate !== null) {
                const rate = Math.round(s.compliance_rate);
                compEl.textContent = rate + '%';
                compEl.className = 'badge badge-' + (rate >= 80 ? 'success' : rate >= 50 ? 'warning' : 'danger');
            }
        }
    } catch (e) {
        console.error('Stats error:', e);
    }
}

function formatTime(dateStr) {
    if (!dateStr) return '--';
    // Extract HH:mm from datetime string
    const match = dateStr.match(/(\d{2}):(\d{2})/);
    if (match) return match[0] + 'Z';
    return dateStr.substring(11, 16) + 'Z';
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
</script>

<?php require_once 'load/footer.php'; ?>
