<!-- GDP Section - GDT Style Layout (FSM/TFMS) - 2-Column Design -->
<div class="container-fluid mt-4 mb-5" id="gdp_section">
    <div class="row">
        <!-- Left Column: GDT Setup Panel + Model Summary -->
        <div class="col-lg-5 mb-3">
            <!-- GDT Setup Component -->
            <div class="card shadow-sm border-info gdt-setup-panel" id="gdp_setup_panel">
                <div class="card-header bg-info text-white py-1 px-2 d-flex justify-content-between align-items-center">
                    <span class="font-weight-bold">
                        <i class="fas fa-cog mr-1"></i> GDT Setup: <span id="gdp_airport_label">---</span>
                    </span>
                    <span class="badge badge-light" id="gdp_status_badge">Draft</span>
                </div>
                
                <!-- Toolbar -->
                <div class="gdt-toolbar bg-light border-bottom px-2 py-1 d-flex flex-wrap align-items-center">
                    <div class="btn-group btn-group-sm mr-2 mb-1">
                        <button class="btn btn-outline-secondary" id="gdp_reload_btn" title="Reload ADL data">
                            <i class="fas fa-sync-alt"></i> Reload
                        </button>
                        <button class="btn btn-outline-primary" id="gdp_model_btn" title="Model with current parameters">
                            <i class="fas fa-calculator"></i> Model
                        </button>
                        <button class="btn btn-success" id="gdp_submit_tmi_btn" title="Run 'Model' first, then submit to TMI Publishing" disabled>
                            <i class="fas fa-paper-plane"></i> Submit to TMI
                        </button>
                        <button class="btn btn-outline-danger" id="gdp_purge_btn" title="Purge active GDP program" disabled>
                            <i class="fas fa-trash-alt"></i> Purge
                        </button>
                        <button class="btn btn-outline-secondary" id="gdp_purge_local_btn" title="Clear local model data">
                            <i class="fas fa-eraser"></i> Purge Local
                        </button>
                    </div>
                    <div class="btn-group btn-group-sm mb-1">
                        <button class="btn btn-outline-info" id="gdp_run_proposed_btn" title="Run model with proposed parameters">
                            <i class="fas fa-flask"></i> Run Proposed
                        </button>
                        <button class="btn btn-warning" id="gdp_run_actual_btn" title="Run model against actual traffic" disabled>
                            <i class="fas fa-play"></i> Run Actual
                        </button>
                    </div>
                </div>
                
                <!-- Program Type -->
                <div class="px-2 py-1 bg-white border-bottom">
                    <div class="form-row align-items-center">
                        <div class="col-auto">
                            <label class="mb-0 small font-weight-bold text-dark">Program Type</label>
                        </div>
                        <div class="col">
                            <select class="form-control form-control-sm" id="gdp_program_type">
                                <option value="GDP-DAS">GDP - DAS</option>
                                <option value="GDP-GAAP">GDP - GAAP</option>
                                <option value="GDP-UDP" selected>GDP - UDP</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Tabs -->
                <ul class="nav nav-tabs nav-fill small" id="gdp_tabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active py-1" id="gdp_params_tab" data-toggle="tab" href="#gdp_params_pane" role="tab">Parameters</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link py-1" id="gdp_scope_tab" data-toggle="tab" href="#gdp_scope_pane" role="tab">Scope</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link py-1" id="gdp_modeling_tab" data-toggle="tab" href="#gdp_modeling_pane" role="tab">Modeling Options</a>
                    </li>
                </ul>
                
                <!-- Tab Content -->
                <div class="tab-content gdt-tab-content" style="max-height: 55vh; overflow-y: auto;">
                    
                    <!-- Parameters Tab -->
                    <div class="tab-pane fade show active p-2" id="gdp_params_pane" role="tabpanel">
                        <!-- CTL Element -->
                        <div class="form-group mb-2">
                            <label class="small font-weight-bold mb-0 text-dark">CTL Element (Airport)</label>
                            <input type="text" class="form-control form-control-sm" id="gdp_ctl_element" placeholder="KATL">
                        </div>
                        
                        <!-- Program Time Options -->
                        <fieldset class="border rounded p-2 mb-2">
                            <legend class="w-auto px-2 small font-weight-bold mb-0 text-dark">Program Time Options</legend>
                            <div class="form-row">
                                <div class="form-group col-6 mb-1">
                                    <label class="small mb-0 text-dark">Start (ddhhmm)</label>
                                    <input type="text" class="form-control form-control-sm font-monospace" id="gdp_start_ddhhmm" placeholder="201800">
                                </div>
                                <div class="form-group col-6 mb-1">
                                    <label class="small mb-0 text-dark">End (ddhhmm)</label>
                                    <input type="text" class="form-control form-control-sm font-monospace" id="gdp_end_ddhhmm" placeholder="210600">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-6 mb-0">
                                    <label class="small mb-0 text-dark">Data Time</label>
                                    <input type="text" class="form-control form-control-sm font-monospace" id="gdp_data_time" readonly>
                                </div>
                                <div class="form-group col-6 mb-0">
                                    <label class="small mb-0 text-dark">Adv #</label>
                                    <input type="text" class="form-control form-control-sm" id="gdp_adv_number" placeholder="001">
                                </div>
                            </div>
                        </fieldset>
                        
                        <!-- General Options -->
                        <fieldset class="border rounded p-2 mb-2">
                            <legend class="w-auto px-2 small font-weight-bold mb-0 text-dark">General Options</legend>
                            <div class="form-row">
                                <div class="form-group col-4 mb-1">
                                    <label class="small mb-0 text-dark">Delay Limit</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control" id="gdp_delay_limit" value="180" min="15" max="999">
                                        <div class="input-group-append"><span class="input-group-text">min</span></div>
                                    </div>
                                </div>
                                <!-- Hidden: Target Delay (Issue #3) -->
                                <div class="form-group col-4 mb-1" style="display: none;">
                                    <label class="small mb-0 text-dark">Target Delay</label>
                                    <input type="number" class="form-control form-control-sm" id="gdp_target_delay" value="1" min="0.5" max="2" step="0.1">
                                </div>
                                <!-- Hidden: Earliest R-Slot (Issue #3) -->
                                <div class="form-group col-4 mb-1" style="display: none;">
                                    <label class="small mb-0 text-dark">Earliest R-Slot</label>
                                    <input type="number" class="form-control form-control-sm" id="gdp_earliest_rslot" value="0" min="0" max="120">
                                </div>
                            </div>
                            <div class="custom-control custom-checkbox small">
                                <input type="checkbox" class="custom-control-input" id="gdp_compress_to_last_cta" checked>
                                <label class="custom-control-label text-dark" for="gdp_compress_to_last_cta">Compress to Last CTA</label>
                            </div>
                        </fieldset>
                        
                        <!-- Program Rate Table -->
                        <fieldset class="border rounded p-2 mb-2">
                            <legend class="w-auto px-2 small font-weight-bold mb-0 text-dark">Program Rate</legend>
                            <div class="d-flex justify-content-between mb-1">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-secondary btn-sm" id="gdp_load_times_btn">Load Times</button>
                                    <button class="btn btn-outline-secondary btn-sm" id="gdp_load_aar_btn">Load ADL AAR</button>
                                    <button class="btn btn-outline-secondary btn-sm" id="gdp_hist_popups_btn">Historical Pop-Ups...</button>
                                </div>
                            </div>
                            <div class="form-row align-items-center mb-1">
                                <div class="col-auto">
                                    <label class="small mb-0 text-dark">Fill</label>
                                </div>
                                <div class="col-3">
                                    <select class="form-control form-control-sm" id="gdp_fill_row">
                                        <option value="PR">Program Rate</option>
                                        <option value="Reserve">Reserve</option>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <label class="small mb-0 text-dark">With</label>
                                </div>
                                <div class="col-auto">
                                    <input type="number" class="form-control form-control-sm gdt-fill-input" id="gdp_fill_value" value="40">
                                </div>
                                <div class="col-auto">
                                    <button class="btn btn-sm btn-outline-primary" id="gdp_fill_btn">Fill</button>
                                </div>
                            </div>
                            <div class="table-responsive" style="max-height: 120px; overflow-y: auto;">
                                <table class="table table-sm table-bordered mb-0 gdt-rate-table" id="gdp_rate_table">
                                    <thead class="thead-light">
                                        <tr id="gdp_rate_header"></tr>
                                    </thead>
                                    <tbody>
                                        <tr id="gdp_rate_pr"><td class="font-weight-bold small">PR</td></tr>
                                        <tr id="gdp_rate_popup"><td class="font-weight-bold small text-secondary">Pop-Up</td></tr>
                                        <tr id="gdp_rate_reserve"><td class="font-weight-bold small text-info">Reserve</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="form-row mt-1">
                                <div class="col">
                                    <div class="custom-control custom-radio custom-control-inline small">
                                        <input type="radio" id="gdp_aar_set" name="gdp_aar_mode" class="custom-control-input" checked>
                                        <label class="custom-control-label text-dark" for="gdp_aar_set">Set AAR to Program Rate</label>
                                    </div>
                                    <div class="custom-control custom-radio custom-control-inline small">
                                        <input type="radio" id="gdp_aar_retain" name="gdp_aar_mode" class="custom-control-input">
                                        <label class="custom-control-label text-dark" for="gdp_aar_retain">Retain Current ADL AAR</label>
                                    </div>
                                </div>
                            </div>
                        </fieldset>
                        
                        <!-- Include Only Options -->
                        <fieldset class="border rounded p-2 mb-0">
                            <legend class="w-auto px-2 small font-weight-bold mb-0 text-dark">Include Only Options</legend>
                            <div class="form-row">
                                <div class="form-group col-4 mb-0">
                                    <label class="small mb-0 text-dark">Arrival Fix</label>
                                    <select class="form-control form-control-sm" id="gdp_arrival_fix">
                                        <option value="ALL">All</option>
                                    </select>
                                </div>
                                <div class="form-group col-4 mb-0">
                                    <label class="small mb-0 text-dark">Aircraft Type</label>
                                    <select class="form-control form-control-sm" id="gdp_aircraft_type">
                                        <option value="ALL">All</option>
                                        <option value="JET">Jet</option>
                                        <option value="PROP">Prop</option>
                                    </select>
                                </div>
                                <div class="form-group col-4 mb-0">
                                    <label class="small mb-0 text-dark">Carrier</label>
                                    <input type="text" class="form-control form-control-sm" id="gdp_carrier_filter" placeholder="All">
                                </div>
                            </div>
                        </fieldset>
                    </div>
                    
                    <!-- Scope Tab -->
                    <div class="tab-pane fade p-2" id="gdp_scope_pane" role="tabpanel">
                        <!-- Scope Selection Method -->
                        <fieldset class="border rounded p-2 mb-2">
                            <legend class="w-auto px-2 small font-weight-bold mb-0 text-dark">Select By</legend>
                            <div class="custom-control custom-radio custom-control-inline">
                                <input type="radio" id="gdp_scope_tier" name="gdp_scope_method" class="custom-control-input" checked>
                                <label class="custom-control-label small text-dark" for="gdp_scope_tier">Tier</label>
                            </div>
                            <div class="custom-control custom-radio custom-control-inline">
                                <input type="radio" id="gdp_scope_distance" name="gdp_scope_method" class="custom-control-input">
                                <label class="custom-control-label small text-dark" for="gdp_scope_distance">Distance</label>
                            </div>
                            
                            <!-- Tier Panel -->
                            <div id="gdp_tier_panel" class="mt-2">
                                <select multiple class="form-control form-control-sm tmi-scope-select" id="gdp_scope_select" size="5"></select>
                            </div>
                            
                            <!-- Distance Panel -->
                            <div id="gdp_distance_panel" class="mt-2" style="display: none;">
                                <div class="input-group input-group-sm">
                                    <div class="input-group-prepend"><span class="input-group-text">Scope</span></div>
                                    <input type="number" class="form-control" id="gdp_scope_distance_nm" value="400" min="50" max="3000" step="50">
                                    <div class="input-group-append"><span class="input-group-text">nm</span></div>
                                </div>
                            </div>
                        </fieldset>
                        
                        <!-- Centers -->
                        <fieldset class="border rounded p-2 mb-2">
                            <legend class="w-auto px-2 small font-weight-bold mb-0 text-dark">Centers</legend>
                            <div class="form-group mb-1">
                                <label class="small mb-0 text-success">Exempt</label>
                                <input type="text" class="form-control form-control-sm" id="gdp_exempt_centers" placeholder="Centers within distance">
                            </div>
                            <div class="form-group mb-0">
                                <label class="small mb-0 text-danger">Non-Exempt</label>
                                <input type="text" class="form-control form-control-sm" id="gdp_nonexempt_centers" placeholder="Centers outside distance">
                            </div>
                        </fieldset>
                        
                        <!-- Airports - Origin -->
                        <fieldset class="border rounded p-2 mb-2">
                            <legend class="w-auto px-2 small font-weight-bold mb-0 text-dark">Airports - Origin</legend>
                            <div class="form-group mb-1">
                                <label class="small mb-0 text-success">Exempt</label>
                                <input type="text" class="form-control form-control-sm" id="gdp_exempt_airports" placeholder="e.g. KJFK KLGA">
                            </div>
                            <div class="form-group mb-1">
                                <label class="small mb-0 text-danger">Non-Exempt</label>
                                <input type="text" class="form-control form-control-sm" id="gdp_nonexempt_airports" placeholder="Additional non-exempt">
                            </div>
                            <div class="form-group mb-0">
                                <label class="small mb-0 text-warning">Non-Exempt If Distance (Manual)</label>
                                <input type="text" class="form-control form-control-sm" id="gdp_nonexempt_manual" placeholder="e.g. CYYZ CYUL">
                            </div>
                        </fieldset>
                        
                        <!-- Flights -->
                        <fieldset class="border rounded p-2 mb-0">
                            <legend class="w-auto px-2 small font-weight-bold mb-0 text-dark">Flights</legend>
                            <div class="custom-control custom-radio small">
                                <input type="radio" id="gdp_exempt_departing" name="gdp_exempt_mode" class="custom-control-input" checked>
                                <label class="custom-control-label text-dark" for="gdp_exempt_departing">
                                    Exempt All Flights Departing Within 
                                    <input type="number" class="form-control form-control-sm d-inline gdt-exempt-minutes-input" id="gdp_exempt_minutes" value="10"> Minutes
                                </label>
                            </div>
                            <div class="custom-control custom-radio small">
                                <input type="radio" id="gdp_exempt_individual" name="gdp_exempt_mode" class="custom-control-input">
                                <label class="custom-control-label text-dark" for="gdp_exempt_individual">Exempt Individual Flights</label>
                            </div>
                            <input type="text" class="form-control form-control-sm mt-1" id="gdp_exempt_callsigns" placeholder="Enter callsigns" disabled>
                            
                            <div class="mt-2">
                                <button class="btn btn-sm btn-outline-info" id="gdp_show_demand_btn">
                                    <i class="fas fa-chart-bar mr-1"></i> Show Demand
                                </button>
                            </div>
                        </fieldset>
                    </div>
                    
                    <!-- Modeling Options Tab -->
                    <div class="tab-pane fade p-2" id="gdp_modeling_pane" role="tabpanel">
                        <fieldset class="border rounded p-2 mb-2">
                            <legend class="w-auto px-2 small font-weight-bold mb-0 text-dark">Power Run By</legend>
                            <select class="form-control form-control-sm" id="gdp_power_run_by">
                                <option value="GDP_DISTANCE">GDP Distance</option>
                                <option value="GDP_DATA_TIME">GDP Data Time</option>
                                <option value="GDP_CENTER_GROUP">GDP Center Group</option>
                                <option value="GDP_RATE">GDP Rate</option>
                            </select>
                            
                            <!-- Distance Options (shown when GDP Distance selected) -->
                            <div id="gdp_powerrun_distance_opts" class="mt-2">
                                <div class="form-row">
                                    <div class="form-group col-4 mb-1">
                                        <label class="small mb-0 text-dark">Start Distance</label>
                                        <input type="number" class="form-control form-control-sm" id="gdp_pr_start_dist" value="199">
                                    </div>
                                    <div class="form-group col-4 mb-1">
                                        <label class="small mb-0 text-dark">End Distance</label>
                                        <input type="number" class="form-control form-control-sm" id="gdp_pr_end_dist" value="2600">
                                    </div>
                                    <div class="form-group col-4 mb-1">
                                        <label class="small mb-0 text-dark">Step Size</label>
                                        <input type="number" class="form-control form-control-sm" id="gdp_pr_step_size" value="200">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Rate Options (shown when GDP Rate selected) -->
                            <div id="gdp_powerrun_rate_opts" class="mt-2" style="display: none;">
                                <div class="form-row">
                                    <div class="form-group col-4 mb-1">
                                        <label class="small mb-0 text-dark">Start Rate</label>
                                        <input type="number" class="form-control form-control-sm" id="gdp_pr_start_rate" value="20">
                                    </div>
                                    <div class="form-group col-4 mb-1">
                                        <label class="small mb-0 text-dark">End Rate</label>
                                        <input type="number" class="form-control form-control-sm" id="gdp_pr_end_rate" value="80">
                                    </div>
                                    <div class="form-group col-4 mb-1">
                                        <label class="small mb-0 text-dark">Step Size</label>
                                        <input type="number" class="form-control form-control-sm" id="gdp_pr_rate_step" value="5">
                                    </div>
                                </div>
                            </div>
                        </fieldset>
                        
                        <fieldset class="border rounded p-2 mb-0">
                            <legend class="w-auto px-2 small font-weight-bold mb-0 text-dark">Program Cancellation Time</legend>
                            <div class="form-row align-items-center">
                                <div class="col">
                                    <input type="text" class="form-control form-control-sm font-monospace" id="gdp_cancel_time" placeholder="ddhhmm">
                                </div>
                                <div class="col-auto">
                                    <div class="custom-control custom-checkbox small">
                                        <input type="checkbox" class="custom-control-input" id="gdp_freeze_cancel_time">
                                        <label class="custom-control-label text-dark" for="gdp_freeze_cancel_time">Freeze Cancellation Time</label>
                                    </div>
                                </div>
                            </div>
                        </fieldset>
                    </div>
                    
                </div>
                
                <!-- Hidden fields for computed values -->
                <input type="hidden" id="gdp_origin_centers">
                <input type="hidden" id="gdp_dep_facilities">
                <input type="hidden" id="gdp_program_rate" value="40">
                <input type="hidden" id="gdp_reserve_rate" value="0">
                <input type="hidden" id="gdp_program_id" value="">
                <input type="hidden" id="gdp_origin_airports" value="">
                <input type="hidden" id="gdp_flt_incl_carrier" value="">
                <input type="hidden" id="gdp_flt_incl_type" value="ALL">
                <input type="hidden" id="gdp_impacting_condition" value="">
                <input type="hidden" id="gdp_prob_ext" value="">
                <input type="hidden" id="gdp_distance_nm" value="500">
                <input type="hidden" id="gdp_exempt_orig_airports" value="">
                <input type="hidden" id="gdp_exempt_orig_tracons" value="">
                <input type="hidden" id="gdp_exempt_orig_artccs" value="">
                <input type="hidden" id="gdp_exempt_carriers" value="">
                <input type="hidden" id="gdp_exempt_callsigns" value="">
                <input type="hidden" id="gdp_exempt_airborne" value="">
            </div>
            
            <!-- Model Summary Card (moved from right column) -->
            <div class="card shadow-sm border-info mt-3">
                <div class="card-header bg-info text-white py-1 px-2">
                    <span class="font-weight-bold small">
                        <i class="fas fa-tachometer-alt mr-1"></i> Model Summary
                    </span>
                </div>
                <div class="card-body p-2 small">
                    <div class="row">
                        <div class="col-3 text-center border-right">
                            <div class="text-secondary">Total Flights</div>
                            <div class="h5 mb-0" id="gdp_sum_total">--</div>
                        </div>
                        <div class="col-3 text-center border-right">
                            <div class="text-secondary">Delayed</div>
                            <div class="h5 mb-0 text-warning" id="gdp_sum_delayed">--</div>
                        </div>
                        <div class="col-3 text-center border-right">
                            <div class="text-secondary">Exempt</div>
                            <div class="h5 mb-0 text-success" id="gdp_sum_exempt">--</div>
                        </div>
                        <div class="col-3 text-center">
                            <div class="text-secondary">Slot Utilization</div>
                            <div class="h5 mb-0" id="gdp_sum_utilization">--%</div>
                        </div>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-sm btn-outline-info flex-fill mr-1" id="gdp_flight_list_btn">
                            <i class="fas fa-list mr-1"></i> Flight List
                        </button>
                        <button class="btn btn-sm btn-outline-info flex-fill ml-1" id="gdp_slots_list_btn">
                            <i class="fas fa-clock mr-1"></i> Slots List
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Bar Graph + Data Graph + Map + Demand -->
        <div class="col-lg-7 mb-3">
            <!-- GDT Bar Graph (full width at top) -->
            <div class="card shadow-sm border-secondary mb-3">
                <div class="card-header bg-info text-white py-1 px-2 d-flex justify-content-between align-items-center">
                    <span class="font-weight-bold small">
                        <i class="fas fa-chart-bar mr-1"></i> GDT Bar Graph: <span id="gdp_bargraph_label">---</span>
                    </span>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-light btn-sm active" id="gdp_bar_eta_btn">ETA</button>
                        <button class="btn btn-outline-light btn-sm" id="gdp_bar_cta_btn">CTA</button>
                    </div>
                </div>
                <div class="card-body p-2">
                    <div class="d-flex mb-1">
                        <div class="mr-3 small">
                            <span class="badge badge-primary">&nbsp;</span> Original
                            <span class="badge badge-warning ml-2" style="background: repeating-linear-gradient(45deg, #ffc107, #ffc107 2px, #fff 2px, #fff 4px);">&nbsp;</span> Modeled
                        </div>
                        <div class="small text-secondary">
                            <span class="border-bottom border-white" style="border-style: dashed !important;">---</span> AAR Line
                        </div>
                    </div>
                    <div id="gdp_bargraph_container" style="height: 200px; position: relative;">
                        <canvas id="gdp_bargraph_canvas"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- GDP Results Card -->
            <div class="card shadow-sm border-secondary mb-3" id="gdp_model_section" style="display: none;">
                <div class="card-header bg-info text-white py-1 px-2 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <span class="font-weight-bold small">
                            <i class="fas fa-poll mr-1"></i> GDP Results
                        </span>
                        <span class="badge badge-light ml-2"><span id="gdp_flight_count">0</span> flights</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <div class="btn-group btn-group-sm mr-2">
                            <button class="btn btn-light btn-sm active" id="gdp_view_chart_btn" title="Chart view">
                                <i class="fas fa-chart-area"></i> Chart
                            </button>
                            <button class="btn btn-outline-light btn-sm" id="gdp_view_table_btn" title="Flight table view">
                                <i class="fas fa-table"></i> Flights
                            </button>
                            <button class="btn btn-outline-light btn-sm" id="gdp_view_slots_btn" title="Slot table view">
                                <i class="fas fa-clock"></i> Slots
                            </button>
                        </div>
                        <button class="btn btn-sm btn-outline-light" id="gdp_model_close_btn" title="Close results">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-2">
                    <!-- Metrics Row -->
                    <div class="row small mb-2">
                        <div class="col-3 text-center border-right">
                            <div class="text-secondary">Total Flights</div>
                            <div class="font-weight-bold" id="gdp_metric_total">--</div>
                        </div>
                        <div class="col-3 text-center border-right">
                            <div class="text-secondary">Avg Delay</div>
                            <div class="font-weight-bold text-warning" id="gdp_metric_avg_delay">--</div>
                        </div>
                        <div class="col-3 text-center border-right">
                            <div class="text-secondary">Max Delay</div>
                            <div class="font-weight-bold text-danger" id="gdp_metric_max_delay">--</div>
                        </div>
                        <div class="col-3 text-center">
                            <div class="text-secondary">Utilization</div>
                            <div class="font-weight-bold text-info" id="gdp_metric_utilization">--%</div>
                        </div>
                    </div>

                    <!-- Chart Container (D3 demand/capacity chart) -->
                    <div id="gdp_chart_container" style="min-height: 250px; position: relative;"></div>

                    <!-- Flight Table Container (hidden by default) -->
                    <div id="gdp_table_container" style="display: none;">
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-sm table-hover table-striped mb-0 small">
                                <thead class="thead-dark sticky-top">
                                    <tr>
                                        <th>Callsign</th>
                                        <th>Origin</th>
                                        <th>ETA</th>
                                        <th>CTA</th>
                                        <th>CTD</th>
                                        <th>Delay</th>
                                        <th>Slot</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="gdp_flight_table_body">
                                    <tr><td colspan="8" class="text-center text-secondary">No data</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Slot Table Container (hidden by default) -->
                    <div id="gdp_slots_container" style="display: none;">
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-sm table-hover table-striped mb-0 small">
                                <thead class="thead-dark sticky-top">
                                    <tr>
                                        <th>Slot #</th>
                                        <th>Time</th>
                                        <th>Type</th>
                                        <th>Callsign</th>
                                        <th>Origin</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="gdp_slot_table_body">
                                    <tr><td colspan="6" class="text-center text-secondary">No data</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Counts Breakdown Tables (used by clearDisplay) -->
                    <div class="row mt-2">
                        <div class="col-md-4">
                            <div class="small font-weight-bold text-secondary mb-1">By Origin Center</div>
                            <div class="table-responsive" style="max-height: 150px; overflow-y: auto;">
                                <table class="table table-sm table-hover table-striped mb-0 small">
                                    <thead class="thead-light sticky-top">
                                        <tr><th>Center</th><th class="text-right">Count</th><th class="text-right">Avg Delay</th></tr>
                                    </thead>
                                    <tbody id="gdp_counts_origin_center"></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="small font-weight-bold text-secondary mb-1">By Hour</div>
                            <div class="table-responsive" style="max-height: 150px; overflow-y: auto;">
                                <table class="table table-sm table-hover table-striped mb-0 small">
                                    <thead class="thead-light sticky-top">
                                        <tr><th>Hour</th><th class="text-right">Count</th><th class="text-right">Avg Delay</th></tr>
                                    </thead>
                                    <tbody id="gdp_counts_hour"></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="small font-weight-bold text-secondary mb-1">By Carrier</div>
                            <div class="table-responsive" style="max-height: 150px; overflow-y: auto;">
                                <table class="table table-sm table-hover table-striped mb-0 small">
                                    <thead class="thead-light sticky-top">
                                        <tr><th>Carrier</th><th class="text-right">Count</th><th class="text-right">Avg Delay</th></tr>
                                    </thead>
                                    <tbody id="gdp_counts_carrier"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Model Statistics Card -->
            <div class="card shadow-sm border-secondary mb-3" id="gdp_model_stats_section" style="display: none;">
                <div class="card-header bg-info text-white py-1 px-2">
                    <span class="font-weight-bold small">
                        <i class="fas fa-chart-pie mr-1"></i> Model Statistics
                    </span>
                </div>
                <div class="card-body p-2">
                    <!-- Model Stats Summary -->
                    <div class="row small mb-2">
                        <div class="col text-center border-right">
                            <div class="text-secondary">Avg Delay</div>
                            <div class="font-weight-bold text-warning" id="gdp_model_stat_avg_delay">--</div>
                        </div>
                        <div class="col text-center border-right">
                            <div class="text-secondary">Max Delay</div>
                            <div class="font-weight-bold text-danger" id="gdp_model_stat_max_delay">--</div>
                        </div>
                        <div class="col text-center border-right">
                            <div class="text-secondary">Total Delay</div>
                            <div class="font-weight-bold" id="gdp_model_stat_total_delay">--</div>
                        </div>
                        <div class="col text-center border-right">
                            <div class="text-secondary">Total</div>
                            <div class="font-weight-bold" id="gdp_model_stat_total">--</div>
                        </div>
                        <div class="col text-center border-right">
                            <div class="text-secondary">Delayed</div>
                            <div class="font-weight-bold text-warning" id="gdp_model_stat_delayed">--</div>
                        </div>
                        <div class="col text-center border-right">
                            <div class="text-secondary">Capped</div>
                            <div class="font-weight-bold text-danger" id="gdp_model_stat_capped">--</div>
                        </div>
                        <div class="col text-center">
                            <div class="text-secondary">Utilization</div>
                            <div class="font-weight-bold text-info" id="gdp_model_stat_utilization">--%</div>
                        </div>
                    </div>

                    <!-- Delay Distribution -->
                    <div class="mb-2">
                        <div class="small font-weight-bold text-secondary mb-1">Delay Distribution</div>
                        <div class="table-responsive" style="max-height: 150px; overflow-y: auto;">
                            <table class="table table-sm table-hover table-striped mb-0 small">
                                <thead class="thead-light sticky-top">
                                    <tr><th>Delay Bucket</th><th class="text-right">Count</th><th class="text-right">% of Total</th></tr>
                                </thead>
                                <tbody id="gdp_model_delay_buckets"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Model Breakdown Tables -->
                    <div class="row mb-2">
                        <div class="col-md-4">
                            <div class="small font-weight-bold text-secondary mb-1">By ARTCC</div>
                            <div class="table-responsive" style="max-height: 150px; overflow-y: auto;">
                                <table class="table table-sm table-hover table-striped mb-0 small">
                                    <thead class="thead-light sticky-top">
                                        <tr><th>ARTCC</th><th class="text-right">Count</th><th class="text-right">Avg Delay</th></tr>
                                    </thead>
                                    <tbody id="gdp_model_by_artcc"></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="small font-weight-bold text-secondary mb-1">By Carrier</div>
                            <div class="table-responsive" style="max-height: 150px; overflow-y: auto;">
                                <table class="table table-sm table-hover table-striped mb-0 small">
                                    <thead class="thead-light sticky-top">
                                        <tr><th>Carrier</th><th class="text-right">Count</th><th class="text-right">Avg Delay</th></tr>
                                    </thead>
                                    <tbody id="gdp_model_by_carrier"></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="small font-weight-bold text-secondary mb-1">By Hour</div>
                            <div class="table-responsive" style="max-height: 150px; overflow-y: auto;">
                                <table class="table table-sm table-hover table-striped mb-0 small">
                                    <thead class="thead-light sticky-top">
                                        <tr><th>Hour</th><th class="text-right">Count</th><th class="text-right">Avg Delay</th></tr>
                                    </thead>
                                    <tbody id="gdp_model_by_hour"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Model Chart -->
                    <div class="mb-2">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <div class="small font-weight-bold text-secondary">Model Chart</div>
                            <div class="d-flex align-items-center">
                                <select class="form-control form-control-sm mr-1" id="gdp_model_chart_view" style="width: auto;">
                                    <option value="hourly">Hourly</option>
                                    <option value="orig_artcc">By Origin ARTCC</option>
                                    <option value="carrier">By Carrier</option>
                                </select>
                                <select class="form-control form-control-sm" id="gdp_model_metric" style="width: auto;">
                                    <option value="delay">Delay</option>
                                    <option value="count">Count</option>
                                </select>
                            </div>
                        </div>
                        <div style="height: 200px; position: relative;">
                            <canvas id="gdp_model_chart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Data Graph + Map/Demand Row -->
            <div class="row">
                <!-- Data Graph (left side) -->
                <div class="col-lg-7 mb-3">
                    <div class="card shadow-sm border-secondary h-100">
                        <div class="card-header bg-info text-white py-1 px-2 d-flex justify-content-between align-items-center">
                            <span class="font-weight-bold small">
                                <i class="fas fa-chart-line mr-1"></i> GDT Data Graph (Power Run)
                            </span>
                            <span class="badge badge-light" id="gdp_powerrun_label">GDP Distance</span>
                        </div>
                        <div class="card-body p-2">
                            <!-- Legend/Stats -->
                            <div class="row small mb-2">
                                <div class="col-3 text-center border-right">
                                    <div class="text-secondary">Avg Delay</div>
                                    <div class="font-weight-bold text-primary" id="gdp_stat_avg_delay">--</div>
                                </div>
                                <div class="col-3 text-center border-right">
                                    <div class="text-secondary">Max Delay</div>
                                    <div class="font-weight-bold text-danger" id="gdp_stat_max_delay">--</div>
                                </div>
                                <div class="col-3 text-center border-right">
                                    <div class="text-secondary">Total Delay</div>
                                    <div class="font-weight-bold text-warning" id="gdp_stat_total_delay">--</div>
                                </div>
                                <div class="col-3 text-center">
                                    <div class="text-secondary">Affected</div>
                                    <div class="font-weight-bold text-info" id="gdp_stat_affected">--</div>
                                </div>
                            </div>
                            <div id="gdp_datagraph_container" style="height: 180px; position: relative;">
                                <canvas id="gdp_datagraph_canvas"></canvas>
                            </div>
                            <div class="text-center small text-secondary mt-1">
                                <i class="fas fa-arrows-alt-h mr-1"></i> Click or drag to select scenario
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Map + Demand (right side, stacked) -->
                <div class="col-lg-5 mb-3">
                    <!-- GDT Map (Simplified Scope Visualization) -->
                    <div class="card shadow-sm border-secondary mb-3">
                        <div class="card-header bg-info text-white py-1 px-2">
                            <span class="font-weight-bold small">
                                <i class="fas fa-map mr-1"></i> GDT Scope Map
                            </span>
                        </div>
                        <div class="card-body p-1">
                            <div id="gdp_scope_map" style="height: 120px; background: #1a1a2e; border-radius: 4px; position: relative;">
                                <!-- SVG map will be rendered here -->
                            </div>
                            <div class="d-flex justify-content-around small mt-1">
                                <span><span class="badge badge-danger">&nbsp;</span> Non-Exempt</span>
                                <span><span class="badge badge-success">&nbsp;</span> Exempt</span>
                                <span class="text-secondary"><span style="background: #800020; display: inline-block; width: 12px; height: 12px; border-radius: 2px;"></span> Scope</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Demand by Center -->
                    <div class="card shadow-sm border-secondary">
                        <div class="card-header bg-info text-white py-1 px-2">
                            <span class="font-weight-bold small">
                                <i class="fas fa-sitemap mr-1"></i> Demand by Center
                            </span>
                        </div>
                        <div class="card-body p-0" style="max-height: 180px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0 small">
                                <thead class="thead-light sticky-top">
                                    <tr>
                                        <th>Centers</th>
                                        <th class="text-right text-danger">Non-Exempt</th>
                                        <th class="text-right text-success">Exempt</th>
                                    </tr>
                                </thead>
                                <tbody id="gdp_demand_by_center">
                                    <tr><td colspan="3" class="text-center text-secondary">Click Model to view</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Flight List Modal (Issue #2) -->
<div class="modal fade" id="gdp_flight_list_modal" tabindex="-1" role="dialog" aria-labelledby="gdp_flight_list_modal_label" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header py-2 bg-info text-white">
                <h6 class="modal-title" id="gdp_flight_list_modal_label">
                    <i class="fas fa-list mr-1"></i> GDP Flight List - <span id="gdp_flight_list_airport">---</span>
                </h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-0">
                <div class="p-2 bg-light border-bottom">
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-info active" id="gdp_fl_filter_all">All <span class="badge badge-light" id="gdp_fl_count_all">0</span></button>
                        <button class="btn btn-outline-warning" id="gdp_fl_filter_delayed">Delayed <span class="badge badge-warning" id="gdp_fl_count_delayed">0</span></button>
                        <button class="btn btn-outline-success" id="gdp_fl_filter_exempt">Exempt <span class="badge badge-success" id="gdp_fl_count_exempt">0</span></button>
                    </div>
                </div>
                <div class="table-responsive" style="max-height: 60vh;">
                    <table class="table table-sm table-hover table-striped mb-0 small">
                        <thead class="thead-dark sticky-top">
                            <tr>
                                <th>Callsign</th>
                                <th>Origin</th>
                                <th>Dest</th>
                                <th>ARTCC</th>
                                <th>Type</th>
                                <th>ETA</th>
                                <th>CTA</th>
                                <th>CTD</th>
                                <th>Delay</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="gdp_flight_list_tbody">
                            <tr><td colspan="10" class="text-center text-secondary">No flights loaded. Run Model first.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2">
                <span class="mr-auto small text-secondary" id="gdp_fl_status">Showing 0 flights</span>
                <button type="button" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-download mr-1"></i> Export CSV
                </button>
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Slots List Modal (Issue #7) -->
<div class="modal fade" id="gdp_slots_list_modal" tabindex="-1" role="dialog" aria-labelledby="gdp_slots_list_modal_label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header py-2 bg-info text-white">
                <h6 class="modal-title" id="gdp_slots_list_modal_label">
                    <i class="fas fa-clock mr-1"></i> GDP Slots List - <span id="gdp_slots_list_airport">---</span>
                </h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-0">
                <div class="p-2 bg-light border-bottom">
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-info active" id="gdp_sl_filter_all">All Slots <span class="badge badge-light" id="gdp_sl_count_all">0</span></button>
                        <button class="btn btn-outline-primary" id="gdp_sl_filter_assigned">Assigned <span class="badge badge-primary" id="gdp_sl_count_assigned">0</span></button>
                        <button class="btn btn-outline-secondary" id="gdp_sl_filter_open">Open <span class="badge badge-secondary" id="gdp_sl_count_open">0</span></button>
                    </div>
                </div>
                <div class="table-responsive" style="max-height: 60vh;">
                    <table class="table table-sm table-hover table-striped mb-0 small">
                        <thead class="thead-dark sticky-top">
                            <tr>
                                <th>Slot Time</th>
                                <th>Slot ID</th>
                                <th>Callsign</th>
                                <th>Origin</th>
                                <th>CTD</th>
                                <th>Delay</th>
                                <th>Type</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="gdp_slots_list_tbody">
                            <tr><td colspan="8" class="text-center text-secondary">No slots generated. Run Model first.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2">
                <span class="mr-auto small text-secondary" id="gdp_sl_status">Showing 0 slots</span>
                <button type="button" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-download mr-1"></i> Export CSV
                </button>
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
/* GDT Specific Styles */
.gdt-setup-panel {
    font-size: 0.85rem;
}
.gdt-setup-panel .form-control-sm {
    font-size: 0.8rem;
}
.gdt-setup-panel fieldset {
    background: #fafafa;
}
.gdt-setup-panel legend {
    font-size: 0.8rem;
}
.gdt-rate-table {
    font-size: 0.75rem;
}
.gdt-rate-table input {
    width: 45px;
    min-width: 45px;
    text-align: center;
    padding: 1px 2px;
    font-size: 0.75rem;
}
.gdt-rate-table td {
    padding: 2px 4px;
    vertical-align: middle;
}
.font-monospace {
    font-family: 'Courier New', Courier, monospace;
}
#gdt_scope_map {
    background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
}

/* Issue #1: Contrast fixes */
.gdt-setup-panel .text-muted {
    color: #5a6268 !important;
}
.text-secondary {
    color: #5a6268 !important;
}

/* Issue #4: Textbox sizing fixes */
.gdt-fill-input {
    width: 70px !important;
    min-width: 70px !important;
}
.gdt-exempt-minutes-input {
    width: 65px !important;
    min-width: 65px !important;
}

/* Modal sticky headers */
.modal .sticky-top {
    position: sticky;
    top: 0;
    z-index: 10;
}
</style>
