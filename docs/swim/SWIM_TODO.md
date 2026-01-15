# VATSIM SWIM Implementation Tracker

**Last Updated:** 2026-01-15 (Session 3)  
**Status:** Phase 1 - Foundation (API Complete, Normalized Schema)  
**Repository:** `VATSIM PERTI/PERTI/`

---

## Quick Status

| Category | Complete | Pending | Total |
|----------|----------|---------|-------|
| API Endpoints | 8 | 2 | 10 |
| Database Tables | 5 | 0 | 5 |
| Stored Procedures | 3 | 0 | 3 |
| Documentation | 5 | 1 | 6 |

---

## ✅ Completed Items

### Database Schema (001_swim_tables.sql)
- [x] `swim_api_keys` - API key management with tiered permissions
- [x] `swim_audit_log` - Request logging and performance metrics
- [x] `swim_subscriptions` - WebSocket subscription tracking
- [x] `swim_flight_cache` - Unified flight record cache
- [x] `swim_webhook_endpoints` - Webhook registration

### Stored Procedures
- [x] `sp_Swim_GetFlightByGufi` - Get unified flight by GUFI
- [x] `sp_Swim_GetActiveFlights` - Get active flights with filtering
- [x] `sp_Swim_CleanupAuditLog` - Audit log retention management

### API Endpoints (v2.0 - Normalized Schema)
- [x] `GET /api/swim/v1` - API info/router
- [x] `GET /api/swim/v1/flights` - List flights with filters
- [x] `GET /api/swim/v1/flight` - Single flight by GUFI/flight_uid/flight_key
- [x] `GET /api/swim/v1/positions` - Bulk positions (GeoJSON)
- [x] `GET /api/swim/v1/tmi/programs` - Active TMI programs (GS + GDP)
- [x] `GET /api/swim/v1/tmi/controlled` - TMI-controlled flights with stats
- [x] `POST /api/swim/v1/ingest/adl` - ADL data ingest

### Configuration & Middleware
- [x] `load/swim_config.php` - SWIM configuration
- [x] `api/swim/v1/auth.php` - Authentication middleware
- [x] SwimAuth class - API key validation
- [x] SwimResponse class - JSON response helpers
- [x] GUFI generation and parsing helpers

### Documentation
- [x] `docs/swim/README.md` - Quick overview
- [x] `docs/swim/VATSIM_SWIM_Design_Document_v1.md` - Full design spec
- [x] `docs/swim/SWIM_TODO.md` - This tracker
- [x] `docs/swim/ADL_NORMALIZED_SCHEMA_REFERENCE.md` - Normalized schema reference

---

## ⏳ Pending Items (Next Sprint)

### High Priority

| Item | Type | Effort | Notes |
|------|------|--------|-------|
| API Reference docs | Docs | 4h | OpenAPI/Swagger spec |
| Postman collection | Testing | 2h | For API testing |
| End-to-end testing | Testing | 2h | Verify all endpoints work |

### Medium Priority

| Item | Type | Effort | Notes |
|------|------|--------|-------|
| Track ingest endpoint | API | 3h | `POST /api/swim/v1/ingest/track` |
| Metering ingest endpoint | API | 3h | `POST /api/swim/v1/ingest/metering` |
| Hook ADL refresh to SWIM | Integration | 4h | Publish updates on refresh |
| Error handling improvements | Code | 2h | Better error messages |

### Low Priority (Phase 2)

| Item | Type | Effort | Notes |
|------|------|--------|-------|
| WebSocket server | Feature | 16h | Real-time distribution |
| Event publishing | Feature | 8h | Change detection |
| Subscription channels | Feature | 8h | Filtering logic |
| vNAS integration | Integration | 20h | External coordination |

---

## File Inventory

### API Files (`api/swim/v1/`)

| File | Status | Version | Description |
|------|--------|---------|-------------|
| `index.php` | ✅ Complete | 1.0 | API router/info endpoint |
| `auth.php` | ✅ Complete | 1.0 | Authentication middleware |
| `flights.php` | ✅ Complete | **2.0** | Flight list (normalized schema) |
| `flight.php` | ✅ Complete | **2.0** | Single flight (normalized schema) |
| `positions.php` | ✅ Complete | **2.0** | GeoJSON positions (normalized schema) |
| `tmi/programs.php` | ✅ Complete | 1.0 | GS + GDP programs |
| `tmi/controlled.php` | ✅ Complete | **2.0** | TMI-controlled flights (normalized schema) |
| `ingest/adl.php` | ✅ Complete | 1.0 | ADL data ingest |
| `ingest/track.php` | ⏳ Pending | - | Track data ingest |
| `ingest/metering.php` | ⏳ Pending | - | Metering data ingest |

### Documentation (`docs/swim/`)

| File | Status | Description |
|------|--------|-------------|
| `README.md` | ✅ Complete | Quick overview |
| `VATSIM_SWIM_Design_Document_v1.md` | ✅ Complete | Full design spec |
| `SWIM_TODO.md` | ✅ Complete | This tracker |
| `ADL_NORMALIZED_SCHEMA_REFERENCE.md` | ✅ **NEW** | Normalized schema reference |
| `API_Reference.md` | ⏳ Pending | OpenAPI spec |

---

## Normalized Schema Reference

### Tables Used (JOINed by flight_uid)

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `adl_flight_core` | Master registry | flight_uid, callsign, cid, phase, is_active |
| `adl_flight_position` | Position/velocity | lat, lon, altitude_ft, groundspeed_kts |
| `adl_flight_plan` | Route, O/D | fp_dept_icao, fp_dest_icao, fp_route |
| `adl_flight_times` | All times | eta_utc, out/off/on/in_utc |
| `adl_flight_tmi` | TMI assignments | ctl_type, slot_time_utc, gs_held |
| `adl_flight_aircraft` | Aircraft info | aircraft_icao, weight_class, airline_name |

### Standard JOIN Pattern
```sql
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_position pos ON pos.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_tmi tmi ON tmi.flight_uid = c.flight_uid
LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
WHERE c.is_active = 1
```

### Key Column Mappings (from legacy adl_flights)

| Legacy | Normalized Table | Normalized Column |
|--------|------------------|-------------------|
| gs_flag | adl_flight_tmi | gs_held |
| gdp_program_id | adl_flight_tmi | program_id |
| gdp_slot_time_utc | adl_flight_tmi | slot_time_utc |
| ac_cat | adl_flight_aircraft | wake_category |
| major_carrier | adl_flight_aircraft | airline_icao |
| gcd_nm | adl_flight_plan | gcd_nm |
| (calculated) | adl_flight_position | dist_to_dest_nm |
| (calculated) | adl_flight_position | pct_complete |

---

## Testing Checklist

### API Testing
- [ ] `GET /api/swim/v1` - Returns API info (no auth required)
- [ ] `GET /api/swim/v1/flights` - Returns 401 without auth
- [ ] `GET /api/swim/v1/flights` with valid Bearer token - Returns flights
- [ ] `GET /api/swim/v1/flight?flight_uid=...` - Returns single flight or 404
- [ ] `GET /api/swim/v1/flight?gufi=...` - Returns single flight or 404
- [ ] `GET /api/swim/v1/positions` - Returns GeoJSON FeatureCollection
- [ ] `GET /api/swim/v1/tmi/programs` - Returns GS + GDP programs
- [ ] `GET /api/swim/v1/tmi/controlled` - Returns controlled flights with stats

### Test Commands
```bash
# API Info (no auth)
curl https://perti.vatcscc.org/api/swim/v1/

# Flights list (with auth)
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/flights?status=active&per_page=10"

# Single flight by flight_uid
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/flight?flight_uid=12345"

# Single flight by GUFI
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/flight?gufi=VAT-20260115-UAL123-KJFK-KLAX"

# Positions (GeoJSON)
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/positions?artcc=ZNY"

# TMI Programs
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/tmi/programs"

# TMI Controlled Flights (all)
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/tmi/controlled"

# TMI Controlled Flights (Ground Stops only)
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/tmi/controlled?type=gs"

# TMI Controlled Flights (GDPs by airport)
curl -H "Authorization: Bearer swim_dev_test_001" \
     "https://perti.vatcscc.org/api/swim/v1/tmi/controlled?type=gdp&airport=KJFK"
```

---

## Change Log

### 2026-01-15 Session 3 - Normalized Schema Migration
- ✅ **MAJOR:** Updated all SWIM API endpoints to use normalized ADL tables
- ✅ APIs now JOIN across 6 tables: core, position, plan, times, tmi, aircraft
- ✅ Verified all column names against production schema
- ✅ Created `ADL_NORMALIZED_SCHEMA_REFERENCE.md` documentation
- ✅ Updated API version to 2.0 for affected endpoints
- ✅ Added `flight_uid` as primary lookup key (in addition to GUFI/flight_key)
- ✅ Enhanced response structure with new normalized columns:
  - `progress.distance_remaining_nm` (from pos.dist_to_dest_nm)
  - `progress.pct_complete` (from pos.pct_complete)
  - `progress.next_waypoint` (from pos.next_waypoint_name)
  - `airspace.current_artcc/tracon/zone` (from core)
  - `tmi.reroute.status/id` (from tmi)

### 2026-01-15 Session 2 - Endpoints Complete
- ✅ Database migration deployed and verified (5 tables confirmed)
- ✅ Created `flight.php` - Single flight by GUFI or flight_key
- ✅ Created `tmi/controlled.php` - TMI-controlled flights with statistics
- ✅ Updated index.php router with new endpoints

### 2026-01-15 Session 1 - Initial Implementation
- Created SWIM directory structure
- Implemented core API endpoints
- Created database migration
- Added comprehensive documentation

### Next Session
- Test all API endpoints against production
- Create API reference documentation (OpenAPI spec)
- Create Postman collection for testing

---

## Contact

- **Project:** vATCSCC PERTI
- **Email:** dev@vatcscc.org
- **Docs:** `docs/swim/`
