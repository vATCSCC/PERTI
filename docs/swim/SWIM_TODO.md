# VATSIM SWIM Implementation Tracker

**Last Updated:** 2026-01-16  
**Status:** Phase 0 - Infrastructure Migration (BLOCKING)  
**Repository:** `VATSIM PERTI/PERTI/`

---

## ⚠️ CRITICAL: Infrastructure Migration Required

**Current Problem:** API endpoints are querying VATSIM_ADL Serverless directly, which will cause:
- High costs under API load ($500-7,500+/month with heavy traffic)
- Serverless cold starts affecting API latency
- Risk of impacting internal ADL processing

**Solution:** Create dedicated SWIM_API database (Azure SQL Basic, $5/month fixed)

---

## Quick Status

| Category | Complete | Blocked | Pending | Total |
|----------|----------|---------|---------|-------|
| Infrastructure | 0 | **5** | 0 | 5 |
| API Endpoints | 6 | 0 | 2 | 8 |
| Database Tables | 5 | 0 | 2 | 7 |
| Documentation | 5 | 0 | 1 | 6 |

---

## 🚨 Phase 0: Infrastructure (BLOCKING)

These tasks MUST be completed before the API can handle public traffic.

| Task | Priority | Effort | Status | Notes |
|------|----------|--------|--------|-------|
| Create Azure SQL Basic database `SWIM_API` | **CRITICAL** | 1h | ⏳ | $5/month fixed cost - See instructions below |
| Run `002_swim_api_database.sql` migration | **CRITICAL** | 30m | ⏳ | After DB creation, run via SSMS |
| Configure cross-database access | **CRITICAL** | 15m | ⏳ | Grant adl_api_user access to both DBs |
| Add SWIM_API connection to config | **CRITICAL** | 30m | ✅ | Added to `config.php` and `connect.php` |
| Update all API endpoints to use SWIM_API | **CRITICAL** | 2h | ✅ | All endpoints updated with fallback |
| Add `swim_trigger_sync()` helper | **CRITICAL** | 15m | ✅ | Added to `connect.php` - call after ADL refresh |

### Target Architecture

```
┌─────────────────────┐      ┌─────────────────────┐      ┌─────────────────────┐
│    VATSIM_ADL       │      │     SWIM_API        │      │    Public API       │
│  (Serverless $$$)   │─────▶│   (Basic $5/mo)     │─────▶│    Endpoints        │
│  Internal only      │ sync │  Dedicated for API  │      │                     │
└─────────────────────┘ 15s  └─────────────────────┘      └─────────────────────┘
```

---

## ✅ Completed Items

### API Endpoints (Functional, but need DB switch)

| Endpoint | Version | Status | Notes |
|----------|---------|--------|-------|
| `GET /api/swim/v1` | 1.0 | ✅ Working | API info |
| `GET /api/swim/v1/flights` | 2.0 | ⚠️ Needs DB switch | Queries VATSIM_ADL |
| `GET /api/swim/v1/flight` | 2.0 | ⚠️ Needs DB switch | Queries VATSIM_ADL |
| `GET /api/swim/v1/positions` | 2.0 | ⚠️ Needs DB switch | Queries VATSIM_ADL |
| `GET /api/swim/v1/tmi/controlled` | 2.0 | ⚠️ Needs DB switch | Queries VATSIM_ADL |
| `GET /api/swim/v1/tmi/programs` | 1.2 | ✅ Fixed | Fixed MySQL connection bug + DB switch |
| `POST /api/swim/v1/ingest/adl` | 1.0 | ✅ Working | Writes to VATSIM_ADL (correct) |

### Database Tables (in VATSIM_ADL - need to move API tables)

| Table | Location | Status | Notes |
|-------|----------|--------|-------|
| `swim_api_keys` | VATSIM_ADL | ✅ Deployed | Move to SWIM_API |
| `swim_audit_log` | VATSIM_ADL | ✅ Deployed | Move to SWIM_API |
| `swim_subscriptions` | VATSIM_ADL | ✅ Deployed | Move to SWIM_API |
| `swim_flight_cache` | VATSIM_ADL | ✅ Deployed | Replace with `swim_flights` |
| `swim_webhook_endpoints` | VATSIM_ADL | ✅ Deployed | Move to SWIM_API |

### Configuration & Middleware

| File | Status | Notes |
|------|--------|-------|
| `load/swim_config.php` | ⚠️ Needs update | Add `$conn_swim` connection |
| `api/swim/v1/auth.php` | ✅ Complete | May need connection switch |

### Documentation

| File | Status | Notes |
|------|--------|-------|
| `docs/swim/README.md` | ⚠️ Needs update | Add infrastructure info |
| `docs/swim/VATSIM_SWIM_Design_Document_v1.md` | ✅ Updated | v1.2 with architecture |
| `docs/swim/SWIM_TODO.md` | ✅ Updated | This file |
| `docs/swim/ADL_NORMALIZED_SCHEMA_REFERENCE.md` | ✅ Complete | Source schema |

---

## ⏳ Phase 1: Remaining Tasks

| Task | Priority | Effort | Status |
|------|----------|--------|--------|
| Fix `tmi/programs.php` error | High | 1h | ✅ Fixed |
| Create OpenAPI/Swagger spec | Medium | 4h | ❌ |
| Create Postman collection | Medium | 2h | ❌ |
| Implement `ingest/track.php` | Low | 3h | ❌ |
| Implement `ingest/metering.php` | Low | 3h | ❌ |

---

## 📋 Phase 2: Real-Time (Future)

| Task | Priority | Effort |
|------|----------|--------|
| WebSocket server | Medium | 16h |
| Event publishing on ADL refresh | Medium | 8h |
| Subscription channel filtering | Medium | 8h |
| vNAS integration | Low | 20h |

---

## 💰 Cost Summary

### Current (TEMPORARY - EXPENSIVE)

| Component | Cost | Risk |
|-----------|------|------|
| VATSIM_ADL queries | Variable | **HIGH** - $500-7,500+/mo under load |

### Target (AFTER MIGRATION)

| Component | Cost | Notes |
|-----------|------|-------|
| SWIM_API (Azure SQL Basic) | $5/mo | Fixed, unlimited queries |
| Azure Redis (optional) | $16/mo | For high-traffic caching |
| Storage | $2-3/mo | Archives |
| **TOTAL** | **$7-24/mo** | Predictable, scalable |

---

## 🧪 Testing Checklist

### Pre-Migration (Current - VATSIM_ADL with fallback)
- [x] `GET /api/swim/v1` - Returns API info
- [x] `GET /api/swim/v1/flights` - Returns flights (uses SWIM_API when available)
- [x] `GET /api/swim/v1/flight?flight_uid=...` - Returns single flight (uses ADL for detail)
- [x] `GET /api/swim/v1/positions` - Returns GeoJSON (uses SWIM_API when available)
- [x] `GET /api/swim/v1/tmi/controlled` - Returns controlled flights
- [x] `GET /api/swim/v1/tmi/programs` - **FIXED** (was using wrong MySQL variable)

### Post-Migration (SWIM_API)
- [ ] All endpoints use `$conn_swim` instead of `$conn_adl`
- [ ] Sync procedure running every 15 seconds
- [ ] Data freshness within 30 seconds of VATSIM_ADL
- [ ] No queries hitting VATSIM_ADL from API endpoints

---

## 📁 File Inventory

### API Files (`api/swim/v1/`)

| File | DB Connection | Status |
|------|---------------|--------|
| `index.php` | None | ✅ OK |
| `auth.php` | `$conn_swim ?: $conn_adl` | ✅ Updated with fallback |
| `flights.php` | `$conn_swim ?: $conn_adl` | ✅ Updated with SWIM_API queries |
| `flight.php` | `$conn_adl ?: $conn_swim` | ✅ Updated (prefers ADL for full detail) |
| `positions.php` | `$conn_swim ?: $conn_adl` | ✅ Updated with SWIM_API queries |
| `tmi/programs.php` | `$conn_sqli` + `$conn_sql` | ✅ Fixed MySQL bug + DB switch |
| `tmi/controlled.php` | `$conn_swim ?: $conn_adl` | ✅ Updated with fallback |
| `ingest/adl.php` | `$conn_adl` | ✅ OK (writes to source) |

---

## 📝 Change Log

### 2026-01-16 Session 2 - Code Migration Complete
- ✅ Updated `config.php` with SWIM_API database credentials
- ✅ Updated `connect.php` with `$conn_swim` connection and `swim_trigger_sync()` helper
- ✅ Updated `auth.php` with SWIM_API connection fallback
- ✅ Updated `flights.php` with SWIM_API single-table queries
- ✅ Updated `positions.php` with SWIM_API single-table queries
- ✅ Updated `flight.php` with connection fallback (prefers ADL for full detail)
- ✅ Updated `tmi/controlled.php` with connection fallback
- ✅ **FIXED** `tmi/programs.php` - was using undefined `$con` instead of `$conn_sqli`
- 📝 Added instructions for Azure database creation

### 2026-01-16 - Infrastructure Architecture Update
- ⚠️ **CRITICAL:** Documented that API currently queries VATSIM_ADL (expensive)
- 📋 Added Phase 0 infrastructure migration tasks
- 📝 Updated design document to v1.2 with proper architecture
- 💰 Added cost comparison showing $5/mo vs $500-7,500+/mo

### 2026-01-15 Session 4 - Normalized Schema Migration
- ✅ Updated all API endpoints to use normalized ADL tables
- ✅ APIs now JOIN across 6 tables: core, position, plan, times, tmi, aircraft
- ✅ Created `ADL_NORMALIZED_SCHEMA_REFERENCE.md`

### 2026-01-15 Session 3 - API Testing
- ✅ Tested all endpoints against production
- ❌ Found `tmi/programs.php` returns 500 error

### 2026-01-15 Session 2 - Endpoints Complete
- ✅ Database migration deployed (5 SWIM tables)
- ✅ Created `flight.php` and `tmi/controlled.php`

### 2026-01-15 Session 1 - Initial Implementation
- ✅ Created SWIM directory structure
- ✅ Implemented core API endpoints
- ✅ Created database migration

---

## 🔗 Quick Links

- [Design Document](./VATSIM_SWIM_Design_Document_v1.md)
- [Normalized Schema](./ADL_NORMALIZED_SCHEMA_REFERENCE.md)
- [API Base URL](https://perti.vatcscc.org/api/swim/v1/)

---

**Contact:** dev@vatcscc.org
