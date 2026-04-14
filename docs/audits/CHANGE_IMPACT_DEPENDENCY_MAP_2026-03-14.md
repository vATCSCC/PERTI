# Change Impact Dependency Map (Verified)

Generated: 2026-03-14

## Purpose
This map shows how a change in one file, API, function area, database object, or table propagates to other files/databases/tables, including docs/wiki references.

## Verification Scope
- Source scan only (no runtime DB introspection).
- SQL table mapping built from SQL DDL + SQL-pattern table references in `.sql` and `.php` code.
- Doc/wiki references are explicitly matched table-style references (backtick-safe for simple names).
- SQL system catalog objects (`sys.*`, `information_schema.*`) are excluded from this map.
- This map excludes self-reference from `docs/CHANGE_IMPACT_DEPENDENCY_MAP_2026-03-14.md` in docs/wiki counts.
- All full edge lists are provided in machine-readable CSV under `artifacts/dependency-map-final/` for auditing.

## Coverage Metrics
- SQL files scanned: **435**
- PHP files scanned: **659**
- JS files scanned: **118**
- Docs/wiki files scanned: **176**
- Canonical table identifiers: **421**
- Tables with code references: **363**
- Tables with docs/wiki references: **140**
- Include/require edges: **586**
- Page->JS edges: **72**
- File->API edges: **416**
- API->Connection->Table edges: **849**

## Full Map Artifacts (Authoritative)
- `artifacts/dependency-map-final/meta.json`: Coverage and edge counts.
- `artifacts/dependency-map-final/file_to_file_sanitized.csv`: PHP include/require dependency edges.
- `artifacts/dependency-map-final/include_hotspots.csv`: Most-included shared files.
- `artifacts/dependency-map-final/page_to_js.csv`: Root page to JS asset edges.
- `artifacts/dependency-map-final/file_to_api.csv`: File to API call edges (PHP+JS).
- `artifacts/dependency-map-final/file_to_connection.csv`: Files that explicitly use DB connection symbols.
- `artifacts/dependency-map-final/api_to_connection_table.csv`: API endpoint to connection symbol to table edges.
- `artifacts/dependency-map-final/file_to_table.csv`: Code file to table edges from SQL patterns.
- `artifacts/dependency-map-final/table_to_code.csv`: Table to all code references.
- `artifacts/dependency-map-final/table_to_doc_wiki.csv`: Table to docs/wiki references.
- `artifacts/dependency-map-final/table_summary.csv`: Raw table identifier summary.
- `artifacts/dependency-map-final/table_group_summary.csv`: Grouped table summary (schema variants merged by base name).
- `artifacts/dependency-map-final/page_impact_summary.csv`: Page-level change impact summary (JS/API/table footprint).
- `artifacts/dependency-map-final/api_domain_summary.csv`: API domain-level footprint summary.
- `artifacts/dependency-map-final/connection_summary.csv`: Connection usage by API endpoints.
- `artifacts/dependency-map-final/doc_wiki_impact_summary.csv`: Docs/wiki files ranked by table references.

## Shared File Dependency Hotspots
| Included Path | Included By Files |
| --- | --- |
| ../../../load/config.php | 107 |
| ../../../load/connect.php | 106 |
| ../../load/config.php | 55 |
| ../../load/connect.php | 30 |
| load/footer.php | 30 |
| load/header.php | 30 |
| load/config.php | 29 |
| sessions/handler.php | 29 |
| load/i18n.php | 17 |
| load/nav.php | 17 |
| load/connect.php | 15 |
| ../../load/input.php | 14 |
| ../../../../load/config.php | 13 |
| ../../../../load/connect.php | 13 |
| load/nav_public.php | 13 |
| ../../sessions/handler.php | 11 |
| ../../../load/input.php | 8 |
| load/org_context.php | 6 |
| ../load/config.php | 4 |
| ../load/connect.php | 4 |

Interpretation: changing these shared files has high blast radius and should trigger targeted regression checks.

## Frontend Page Change Impact Matrix
| Page | JS Files | API Endpoints | Tables Touched | Primary JS Assets (sample) |
| --- | --- | --- | --- | --- |
| nod.php | 5 | 19 | 38 | assets/js/config/filter-colors.js; assets/js/config/phase-colors.js; assets/js/lib/artcc-hierarchy.js; assets/js/nod-demand-layer.js |
| gdt.php | 8 | 43 | 36 | assets/js/advisory-config.js; assets/js/config/phase-colors.js; assets/js/config/rate-colors.js; assets/js/demand.js |
| review.php | 7 | 28 | 34 | assets/js/config/filter-colors.js; assets/js/config/phase-colors.js; assets/js/demand.js; assets/js/review.js |
| tmi-publish.php | 5 | 17 | 30 | assets/js/advisory-config.js; assets/js/facility-hierarchy.js; assets/js/tmi-active-display.js; assets/js/tmi-gdp.js |
| ctp.php | 1 | 25 | 20 | assets/js/ctp.js |
| demand.php | 4 | 12 | 19 | assets/js/config/filter-colors.js; assets/js/config/phase-colors.js; assets/js/config/rate-colors.js; assets/js/demand.js |
| playbook.php | 9 | 18 | 12 | assets/js/awys.js; assets/js/lib/artcc-hierarchy.js; assets/js/lib/route-advisory-parser.js; assets/js/playbook-cdr-search.js |
| plan.php | 6 | 5 | 7 | assets/js/advisory-config.js; assets/js/config/facility-roles.js; assets/js/initiative_timeline.js; assets/js/plan-splits-map.js |
| splits.php | 2 | 6 | 6 | assets/js/lib/artcc-labels.js; assets/js/splits.js |
| cdm.php | 1 | 1 | 6 | assets/js/cdm.js |
| route.php | 11 | 8 | 5 | assets/js/awys.js; assets/js/config/filter-colors.js; assets/js/config/phase-colors.js; assets/js/lib/artcc-hierarchy.js |
| data.php | 3 | 2 | 4 | assets/js/plan-splits-map.js; assets/js/plan-tables.js; assets/js/sheet.js |
| event-aar.php | 0 | 2 | 3 |  |
| sheet.php | 1 | 1 | 2 | assets/js/sheet.js |
| sua.php | 2 | 8 | 1 | assets/js/lib/artcc-hierarchy.js; assets/js/sua.js |
| airport_config.php | 0 | 1 | 1 |  |
| hibernation.php | 0 | 1 | 1 |  |
| status.php | 1 | 1 | 1 | assets/js/config/phase-colors.js |
| jatoc.php | 4 | 1 | 0 | assets/js/config/facility-roles.js; assets/js/jatoc-facility-patch.js; assets/js/jatoc.js; assets/js/lib/artcc-hierarchy.js |
| navdata.php | 1 | 0 | 0 | assets/js/navdata.js |
| schedule.php | 1 | 0 | 0 | assets/js/schedule.js |

Use `page_impact_summary.csv` for the full per-page API and table lists.

## API Domain Footprint
| API Domain | API Count | Table Count | Sample APIs |
| --- | --- | --- | --- |
| mgt | 103 | 59 | api/mgt/comments/delete.php; api/mgt/comments/post.php; api/mgt/comments/update.php |
| data | 52 | 54 | api/data/cdm/status.php; api/data/configs.php; api/data/hibernation_stats.php |
| swim | 38 | 50 | api/swim/v1/auth.php; api/swim/v1/cdm/airport-status.php; api/swim/v1/cdm/compliance.php |
| tmi | 28 | 32 | api/tmi/AdvisoryNumber.php; api/tmi/active.php; api/tmi/advisories.php |
| ctp | 24 | 20 | api/ctp/audit_log.php; api/ctp/boundaries.php; api/ctp/changelog.php |
| gdt | 20 | 11 | api/gdt/common.php; api/gdt/demand/hourly.php; api/gdt/flights/list.php |
| adl | 18 | 29 | api/adl/AdlQueryHelper.php; api/adl/airway.php; api/adl/atis-debug.php |
| nod | 13 | 25 | api/nod/advisories.php; api/nod/advisory_import.php; api/nod/discord-post.php |
| demand | 9 | 16 | api/demand/active_config.php; api/demand/airports.php; api/demand/atis.php |
| splits | 8 | 6 | api/splits/active.php; api/splits/areas.php; api/splits/configs.php |
| stats | 8 | 20 | api/stats/StatsHelper.php; api/stats/boundary_debug.php; api/stats/flight_phase_history.php |
| analysis | 4 | 9 | api/analysis/escape_desert_archive.php; api/analysis/get_plan_ids.php; api/analysis/perti_events.php |
| user | 4 | 4 | api/user/configs/update.php; api/user/dcc/update.php; api/user/enroute_staffing/update.php |
| discord | 3 | 2 | api/discord/announcements.php; api/discord/channels.php; api/discord/messages.php |
| routes | 3 | 1 | api/routes/public.php; api/routes/public_delete.php; api/routes/public_update.php |
| simulator | 3 | 9 | api/simulator/navdata.php; api/simulator/routes.php; api/simulator/traffic.php |
| statsim | 3 | 5 | api/statsim/fetch.php; api/statsim/plan_info.php; api/statsim/save_rates.php |
| admin | 2 | 1 | api/admin/check_dtu_usage.php; api/admin/cleanup_stale_flights.php |
| event-aar | 2 | 3 | api/event-aar/list.php; api/event-aar/update.php |
| events | 2 | 1 | api/events/list.php; api/events/sync.php |

## DB Connection Footprint (Explicit Symbols)
| Connection Symbol | API Count | Table Count | Sample APIs |
| --- | --- | --- | --- |
| unknown | 259 | 175 | api/adl/AdlQueryHelper.php; api/adl/atis-debug.php; api/adl/boundaries.php |
| get_conn_tmi | 52 | 43 | api/ctp/audit_log.php; api/ctp/changelog.php; api/ctp/common.php |
| conn_pdo_mysql | 34 | 42 | api/ctp/routes/suggest.php; api/data/hibernation_stats.php; api/data/review/tmr_export.php |
| get_conn_adl | 26 | 46 | api/ctp/boundaries.php; api/ctp/common.php; api/ctp/flights/compliance.php |
| get_conn_gis | 5 | 11 | api/adl/airway.php; api/ctp/common.php; api/ctp/flights/modify_route.php |
| get_conn_ref | 2 | 4 | api/adl/airway.php; api/nod/flows/suggestions.php |
| get_conn_swim | 1 | 5 | api/swim/v1/playbook/plays.php |

Note: `unknown` means the endpoint does not explicitly call a connection symbol in that file (often delegated to helpers/includes).

## Highest-Impact Table Groups
Grouped by base table name so schema variants (for example `dbo.adl_flight_core` and `adl_flight_core`) are tracked together.

| Table Base | Schema Variants | Code Refs | Docs/Wiki Refs |
| --- | --- | --- | --- |
| adl_flight_core | adl_flight_core, dbo.adl_flight_core | 160 | 31 |
| adl_flight_plan | dbo.adl_flight_plan | 109 | 8 |
| adl_flight_position | dbo.adl_flight_position | 85 | 5 |
| adl_flight_times | dbo.adl_flight_times | 85 | 4 |
| apts | VATSIM_ADL.apts, dbo.apts | 57 | 5 |
| tmi_programs | dbo.tmi_programs, tmi_programs | 57 | 30 |
| adl_flight_tmi | dbo.adl_flight_tmi | 38 | 4 |
| adl_flight_aircraft | dbo.adl_flight_aircraft | 34 | 2 |
| airport_config | dbo.airport_config | 32 | 0 |
| tmi_flight_control | dbo.tmi_flight_control | 32 | 0 |
| adl_flight_waypoints | dbo.adl_flight_waypoints | 30 | 4 |
| swim_flights | dbo.swim_flights | 28 | 3 |
| nav_fixes | dbo.nav_fixes, nav_fixes | 27 | 21 |
| playbook_routes | dbo.playbook_routes, playbook_routes | 25 | 15 |
| adl_boundary | adl_boundary, dbo.adl_boundary | 24 | 4 |
| airport_config_runway | dbo.airport_config_runway | 23 | 0 |
| p_plans | p_plans | 23 | 10 |
| organizations | organizations | 22 | 1 |
| tmi_entries | dbo.tmi_entries, tmi_entries | 22 | 18 |
| airport_config_rate | dbo.airport_config_rate | 21 | 0 |
| tmi_slots | dbo.tmi_slots | 21 | 1 |
| playbook_plays | playbook_plays | 20 | 9 |
| tmi_reroutes | dbo.tmi_reroutes, tmi_reroutes | 20 | 14 |
| artcc_boundaries | artcc_boundaries | 19 | 11 |
| vw_adl_flights | dbo.vw_adl_flights | 19 | 2 |
| vatsim_atis | dbo.vatsim_atis | 17 | 0 |
| adl_flight_trajectory | dbo.adl_flight_trajectory | 16 | 2 |
| ctp_flight_control | dbo.ctp_flight_control | 16 | 0 |
| ntml | dbo.ntml | 16 | 2 |
| tmi_advisories | dbo.tmi_advisories, tmi_advisories | 16 | 22 |
| tracon_boundaries | tracon_boundaries | 16 | 10 |
| sector_boundaries | sector_boundaries | 15 | 9 |
| tmi_events | dbo.tmi_events | 15 | 1 |
| adl_parse_queue | dbo.adl_parse_queue | 14 | 2 |
| adl_flight_changelog | dbo.adl_flight_changelog | 13 | 1 |
| aircraft_performance_profiles | dbo.aircraft_performance_profiles | 13 | 0 |
| coded_departure_routes | dbo.coded_departure_routes | 13 | 2 |
| p_configs | p_configs | 13 | 7 |
| user_orgs | user_orgs | 13 | 3 |
| vatusa_event_airport | dbo.vatusa_event_airport | 13 | 0 |
| adl_flight_planned_crossings | dbo.adl_flight_planned_crossings | 12 | 0 |
| swim_api_keys | dbo.swim_api_keys | 12 | 3 |
| vatusa_event | dbo.vatusa_event | 12 | 0 |
| adl_zone_events | dbo.adl_zone_events | 11 | 1 |
| nav_procedures | dbo.nav_procedures | 11 | 2 |
| playbook_changelog | playbook_changelog | 11 | 8 |
| tmi_public_routes | dbo.tmi_public_routes, tmi_public_routes | 11 | 10 |
| vw_airport_config_summary | dbo.vw_airport_config_summary | 11 | 1 |
| adl_boundary_grid | adl_boundary_grid, dbo.adl_boundary_grid | 10 | 5 |
| airways | dbo.airways | 10 | 1 |
| atis_config_history | dbo.atis_config_history | 10 | 0 |
| flight_phase_snapshot | dbo.flight_phase_snapshot | 10 | 0 |
| vatusa_event_hourly | dbo.vatusa_event_hourly | 10 | 0 |
| adl_flight_boundary_log | adl_flight_boundary_log, dbo.adl_flight_boundary_log | 9 | 4 |
| airport_geometry | dbo.airport_geometry | 9 | 1 |
| airports | airports, dbo.airports | 9 | 2 |
| ctp_sessions | dbo.ctp_sessions | 9 | 0 |
| p_terminal_init_times | p_terminal_init_times | 9 | 3 |
| perti_events | dbo.perti_events | 9 | 1 |
| swim_audit_log | dbo.swim_audit_log | 9 | 2 |
| airport_weather_impact | dbo.airport_weather_impact | 8 | 0 |
| airway_segments | dbo.airway_segments | 8 | 0 |
| config_modifier | dbo.config_modifier | 8 | 0 |
| p_dcc_staffing | p_dcc_staffing | 8 | 4 |
| p_enroute_init_times | p_enroute_init_times | 8 | 3 |
| p_terminal_init | p_terminal_init | 8 | 4 |
| public_routes | dbo.public_routes | 8 | 0 |
| runway_in_use | dbo.runway_in_use | 8 | 0 |
| tmi_flow_providers | dbo.tmi_flow_providers | 8 | 0 |
| tmi_proposals | dbo.tmi_proposals, tmi_proposals | 8 | 14 |
| adl_flights_history | dbo.adl_flights_history | 7 | 0 |
| p_enroute_staffing | p_enroute_staffing | 7 | 4 |
| p_terminal_staffing | p_terminal_staffing | 7 | 4 |
| splits_configs | dbo.splits_configs, splits_configs | 7 | 6 |
| sua_activations | sua_activations | 7 | 2 |
| tmi_discord_posts | dbo.tmi_discord_posts | 7 | 1 |
| tmi_flow_events | dbo.tmi_flow_events | 7 | 0 |
| tmi_reroute_flights | dbo.tmi_reroute_flights, tmi_reroute_flights | 7 | 8 |
| tmi_reroute_routes | dbo.tmi_reroute_routes | 7 | 0 |
| vw_current_runways_in_use | dbo.vw_current_runways_in_use | 7 | 0 |
| weather_alerts | dbo.weather_alerts | 7 | 0 |
| wind_grid | dbo.wind_grid | 7 | 0 |
| adl_flights_gdp | dbo.adl_flights_gdp | 6 | 0 |
| adl_flights_gs | dbo.adl_flights_gs | 6 | 0 |
| flight_stats_job_config | dbo.flight_stats_job_config | 6 | 0 |
| p_op_goals | p_op_goals | 6 | 4 |
| tmi_popup_queue | dbo.tmi_popup_queue | 6 | 0 |
| tmi_proposal_facilities | dbo.tmi_proposal_facilities | 6 | 0 |
| vw_airport_config_rates | dbo.vw_airport_config_rates | 6 | 1 |
| ACD_Data | dbo.ACD_Data | 5 | 0 |
| adl_flights | dbo.adl_flights | 5 | 0 |
| adl_region_group | dbo.adl_region_group | 5 | 0 |
| adl_tmi_trajectory | dbo.adl_tmi_trajectory | 5 | 1 |
| adl_trajectory_archive | dbo.adl_trajectory_archive | 5 | 2 |
| airlines | dbo.airlines | 5 | 0 |
| airport_geometry_import_log | dbo.airport_geometry_import_log | 5 | 0 |
| artcc_facilities | dbo.artcc_facilities | 5 | 0 |
| artcc_tier_group_members | dbo.artcc_tier_group_members | 5 | 0 |
| assigned | assigned | 5 | 0 |
| boundary_hierarchy | boundary_hierarchy | 5 | 0 |
| ctp_audit_log | dbo.ctp_audit_log | 5 | 1 |
| facility_tier_config_members | dbo.facility_tier_config_members | 5 | 0 |
| facility_tier_configs | dbo.facility_tier_configs | 5 | 0 |
| flight_stats_airport | dbo.flight_stats_airport | 5 | 0 |
| flight_stats_hourly | dbo.flight_stats_hourly | 5 | 0 |
| gdp_log | dbo.gdp_log | 5 | 0 |
| ntml_info | dbo.ntml_info | 5 | 1 |
| p_enroute_constraints | p_enroute_constraints | 5 | 3 |
| p_enroute_init | p_enroute_init | 5 | 4 |
| p_group_flights | p_group_flights | 5 | 3 |
| p_terminal_constraints | p_terminal_constraints | 5 | 3 |
| adl_archive_config | dbo.adl_archive_config | 4 | 0 |
| adl_archive_log | dbo.adl_archive_log | 4 | 1 |
| adl_flight_archive | dbo.adl_flight_archive | 4 | 0 |
| adl_flight_weather_impact | dbo.adl_flight_weather_impact | 4 | 0 |
| adl_region_airports | dbo.adl_region_airports | 4 | 0 |
| adl_slots_gdp | dbo.adl_slots_gdp | 4 | 0 |
| adl_staging_pilots | dbo.adl_staging_pilots | 4 | 0 |
| adl_staging_prefiles | dbo.adl_staging_prefiles | 4 | 0 |
| area_centers | dbo.area_centers | 4 | 0 |

Full list: `table_group_summary.csv` (361 grouped table entries).

## Docs/Wiki Update Hotspots
When changing table contracts, prioritize these docs/wiki pages for update checks:

| Doc/Wiki File | Table Refs |
| --- | --- |
| docs/plans/2026-02-15-vatcan-interop-design.md | 35 |
| docs/plans/2026-02-15-vatcan-interop-implementation.md | 35 |
| docs/DEPLOYMENT_GUIDE.md | 34 |
| wiki/Navigation-Helper.md | 29 |
| wiki/Database-Schema.md | 25 |
| docs/tmi/DATABASE.md | 21 |
| docs/COMPUTATIONAL_REFERENCE.md | 19 |
| docs/VATSIM_STATS_DATABASE.md | 19 |
| docs/STATUS.md | 17 |
| docs/fmds-comparison.md | 17 |
| wiki/FMDS-Comparison.md | 17 |
| wiki/Architecture.md | 13 |
| docs/QUICK_REFERENCE.md | 12 |
| docs/swim/VATSWIM_Release_Documentation.md | 12 |
| docs/tmi/ARCHITECTURE.md | 12 |
| docs/plans/2026-02-02-tmi-trajectory.md | 11 |
| docs/tmi/README.md | 10 |
| wiki/Data-Flow.md | 10 |
| docs/superpowers/specs/2026-03-12-ctp-e26-integration-design.md | 9 |
| wiki/AIRAC-Update.md | 8 |
| docs/adl-ingest-outage-2026-02-17-claude-brief.md | 7 |
| docs/gdt-tmi-workflow-plan.md | 7 |
| docs/swim/ADL_NORMALIZED_SCHEMA_REFERENCE.md | 7 |
| docs/tmi/COST_ANALYSIS.md | 7 |
| wiki/Algorithm-Route-Parsing.md | 6 |
| wiki/TMI-Historical-Import-Statistics.md | 6 |
| docs/plans/2026-02-09-nod-tmi-facility-flows-design.md | 5 |
| docs/plans/2026-02-16-holding-detection.md | 5 |
| docs/route_distance_transition.md | 5 |
| docs/swim/VATSWIM_API_Documentation.md | 5 |
| docs/vATCSCC_Use_Case_Digestible_v5.md | 5 |
| wiki/Algorithm-Zone-Detection.md | 5 |
| wiki/Changelog.md | 5 |
| wiki/GIS-API.md | 5 |
| wiki/Troubleshooting.md | 5 |
| docs/plans/2026-02-02-adl-raw-data-lake-design.md | 4 |
| docs/simbrief_parsing_transition.md | 4 |
| docs/superpowers/specs/2026-03-13-swim-playbook-cdr-routes-design.md | 4 |
| wiki/API-Reference.md | 4 |
| wiki/Splits.md | 4 |
| docs/GDT_Unified_Design_Document_v1.md | 3 |
| docs/mysql-8-upgrade-analysis.md | 3 |
| docs/plans/2026-02-11-gdt-workflow-enhancement-design.md | 3 |
| docs/tmi/GDT_REBUILD_DESIGN.md | 3 |
| docs/tmi/GDT_Session_20260121.md | 3 |
| docs/tmi/SESSION_TRANSITION_20260117.md | 3 |
| docs/tmi/TMI_Publisher_v1.8.0_Transition.md | 3 |
| docs/tmi/Unified_TMI_Publisher_Design.md | 3 |
| wiki/Algorithm-Trajectory-Tiering.md | 3 |
| wiki/Analysis.md | 3 |

## Change Propagation Playbooks
### 1) If you change a table (schema/columns/semantics)
1. Find all code impact: `table_group_summary.csv` then `table_to_code.csv`.
2. Find API impact: `api_to_connection_table.csv` filtered by the table (and table variants).
3. Find page/feature impact: map impacted APIs into `page_impact_summary.csv`.
4. Find docs/wiki impact: `table_to_doc_wiki.csv` and `doc_wiki_impact_summary.csv`.
5. Validate shared includes if API behavior changed: `file_to_file_sanitized.csv` + `include_hotspots.csv`.

### 2) If you change an API endpoint
1. Find caller files via `file_to_api.csv` (JS + PHP callers).
2. Find page-level blast radius in `page_impact_summary.csv`.
3. Find table impact via `api_to_connection_table.csv`.
4. Update docs: domain docs in `docs/`, plus `wiki/API-Reference.md` where applicable.

### 3) If you change a frontend page or JS file
1. Start from `page_impact_summary.csv` to enumerate downstream APIs/tables.
2. Validate endpoint contract changes against `api_to_connection_table.csv`.
3. Update user docs/wiki pages for that feature area (see `doc_wiki_impact_summary.csv`).

### 4) If you change shared bootstrap/config/session files
1. Use `include_hotspots.csv` to assess fan-out impact.
2. Regression-check all top-level pages touching affected areas (`page_impact_summary.csv`).
3. Re-verify DB connection assumptions (`connection_summary.csv`).

## Rebuild Command
Re-run the extraction scripts used in this session to refresh this map after major refactors/migrations.
