# PERTI ETA/Trajectory/OOOI - Full Implementation Roadmap

**Created:** 2026-01-06  
**Current Status:** Phase 4 Complete (Zone Detection)

---

## Overall Progress

```
[████████████████░░░░░░░░] 65% Complete

Phase 1: Foundation          [██████████] 100% ✅
Phase 2: Trajectory Logging  [██████████] 100% ✅
Phase 3: ETA Engine          [██████████] 100% ✅
Phase 4: OOOI Zone Detection [██████████] 100% ✅ (code done, deployment pending)
Phase 5: Weather & Boundaries[░░░░░░░░░░]   0% ⏳
Phase 6: Testing & Polish    [░░░░░░░░░░]   0% ⏳
```

---

## ✅ COMPLETED (Phases 1-4)

### Phase 1: Foundation
- Normalized ADL table structure (core, position, plan, aircraft, times)
- Flight key generation and lifecycle management
- Basic OOOI columns on adl_flight_times

### Phase 2: Trajectory Logging
- 8-tier trajectory system
- adl_flight_trajectory table
- Tier evaluation logic
- Position history capture

### Phase 3: ETA Engine
- Route-based ETA calculation
- Aircraft performance factors
- Remaining distance calculation
- ETA confidence scoring

### Phase 4: OOOI Zone Detection
- airport_geometry table (OSM zones)
- adl_zone_events table (transition log)
- fn_DetectCurrentZone function
- sp_ProcessZoneDetectionBatch procedure
- sp_GenerateFallbackZones procedure
- ImportOSM.ps1 script
- 201 airport coverage defined

---

## 🔲 REMAINING WORK

### Immediate (Deploy What's Built)
| Task | Time | Priority |
|------|------|----------|
| Deploy 041_oooi_deploy.sql | 5 min | P0 |
| Run ImportOSM.ps1 | 7 min | P0 |
| Integrate zone detection into refresh proc | 15 min | P0 |
| Test with live VATSIM data | 30 min | P0 |

### Phase 4B: OOOI Polish (1-2 days)
| Task | Description | Effort |
|------|-------------|--------|
| UI Integration | Show OOOI times in flight detail panel | 4 hrs |
| API Endpoint | `/api/flight/{id}/oooi` returns times | 2 hrs |
| Zone Events API | `/api/flight/{id}/zone-history` | 2 hrs |
| Dashboard Widget | OOOI status summary (departures/arrivals) | 3 hrs |
| Taxi Time Display | Show taxi-out/taxi-in durations | 1 hr |

### Phase 5: Weather & Boundaries (1-2 weeks)
| Task | Description | Effort |
|------|-------------|--------|
| Weather Alert Import | Daemon to fetch TCF/eTCF/SIGMET | 8 hrs |
| TCF Parsing | Parse FAA Traffic Control Flow data | 4 hrs |
| eTCF Parsing | Extended TCF with polygon boundaries | 4 hrs |
| SIGMET Parsing | Convective/non-convective boundaries | 4 hrs |
| weather_alerts Table | Store active weather constraints | 2 hrs |
| Weather Proximity Check | Flights affected by weather | 4 hrs |
| Sector Boundary Import | Load ARTCC/sector polygons | 6 hrs |
| Boundary Crossing Detection | Log FIR/sector transitions | 4 hrs |
| Weather Tier Adjustment | Promote trajectory tier in weather | 2 hrs |

### Phase 6: Testing & Optimization (1 week)
| Task | Description | Effort |
|------|-------------|--------|
| End-to-End Testing | Full cycle: spawn → OOOI → despawn | 8 hrs |
| ETA Accuracy Validation | Compare ETA vs actual arrival | 4 hrs |
| Performance Profiling | Identify bottlenecks | 4 hrs |
| Index Optimization | Tune spatial/temporal queries | 4 hrs |
| Storage Monitoring | Track trajectory/zone growth | 2 hrs |
| 90-Day Retention | Implement cleanup procedures | 4 hrs |
| Documentation | User guide, API docs | 4 hrs |

### Phase 7: Advanced Features (Future)
| Task | Description | Effort |
|------|-------------|--------|
| Pattern Work Detection | Touch-and-go, stop-and-go | 6 hrs |
| Go-Around Detection | Missed approach identification | 4 hrs |
| Leg-Based Tracking | Multiple legs per flight | 8 hrs |
| Runway Identification | Specific runway (28L vs 28R) | 4 hrs |
| Ground Track Replay | Taxi path visualization | 8 hrs |
| Predictive Analytics | ML-based ETA improvement | 20 hrs |
| Historical Analysis | 5-year demand patterns | 16 hrs |

---

## 📋 Prioritized TODO List

### This Week (P0 - Critical)
- [ ] Deploy OOOI schema (041_oooi_deploy.sql)
- [ ] Run OSM import (ImportOSM.ps1)
- [ ] Add zone detection to refresh procedure
- [ ] Verify zone detection working with live data
- [ ] Verify OOOI times being set correctly

### Next Week (P1 - High)
- [ ] Add OOOI times to flight detail UI
- [ ] Create OOOI API endpoint
- [ ] Add taxi time display
- [ ] Monitor zone_events table growth
- [ ] Fix any edge cases discovered

### Following Weeks (P2 - Medium)
- [ ] Weather alert integration
- [ ] Sector boundary import
- [ ] ETA accuracy validation
- [ ] Performance optimization
- [ ] 90-day retention policy

### Future (P3 - Nice to Have)
- [ ] Pattern work detection
- [ ] Go-around detection
- [ ] Ground track replay
- [ ] ML-based ETA improvement

---

## 🔗 Integration Points Remaining

### 1. Refresh Procedure Integration
```sql
-- Add to sp_Adl_RefreshFromVatsim_Normalized
-- After: trajectory logging
-- Before: completion check

DECLARE @zone_transitions INT;
EXEC dbo.sp_ProcessZoneDetectionBatch @zone_transitions OUTPUT;
```

### 2. UI Components Needed
| Component | Location | Data Source |
|-----------|----------|-------------|
| OOOI Badge | Flight list | adl_flight_times |
| Times Panel | Flight detail | adl_flight_times |
| Zone History | Flight detail | adl_zone_events |
| Taxi Times | Flight detail | Calculated columns |

### 3. API Endpoints Needed
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/flight/{uid}/oooi` | GET | OOOI times + durations |
| `/api/flight/{uid}/zones` | GET | Zone transition history |
| `/api/airport/{icao}/oooi-summary` | GET | Recent departures/arrivals |

---

## 📊 Data Flow (Complete Picture)

```
VATSIM API (15s)
      │
      ▼
┌─────────────────────────────────────────────────────────┐
│          sp_Adl_RefreshFromVatsim_Normalized            │
│                                                         │
│  1. Parse JSON → staging tables                         │
│  2. MERGE → normalized tables (core, position, plan)    │
│  3. Calculate ETA → adl_flight_times.eta_utc            │
│  4. Evaluate trajectory tier → adl_flight_trajectory    │
│  5. Detect zones → adl_zone_events, OOOI times    ← NEW │
│  6. Check completion → archive departed flights         │
└─────────────────────────────────────────────────────────┘
      │
      ├──► adl_flight_core (current state)
      ├──► adl_flight_position (location)
      ├──► adl_flight_times (OOOI, ETA)
      ├──► adl_flight_trajectory (history)
      ├──► adl_zone_events (transitions)      ← NEW
      │
      ▼
┌─────────────────────────────────────────────────────────┐
│                    PHP API Layer                        │
│                                                         │
│  Flight data → TSD display                              │
│  OOOI times → Flight detail panel               ← TODO  │
│  Zone events → Zone history view                ← TODO  │
│  ETA → Arrival list sorting                             │
└─────────────────────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────┐
│                   Browser UI                            │
│                                                         │
│  TSD Map (aircraft positions)                           │
│  Flight strips (callsign, altitude, speed)              │
│  OOOI badges (OUT/OFF/ON/IN status)             ← TODO  │
│  Taxi time display                              ← TODO  │
│  Zone transition timeline                       ← TODO  │
└─────────────────────────────────────────────────────────┘
```

---

## 🎯 Success Metrics

| Metric | Target | How to Measure |
|--------|--------|----------------|
| Zone detection accuracy | >90% | Manual spot checks |
| OOOI time capture rate | >95% of flights | COUNT where out_utc IS NOT NULL |
| Zone detection latency | <5 sec/cycle | Timing in batch proc |
| False transitions | <5% | Review zone_events for flip-flops |
| ETA accuracy (cruise) | ±5 min | Compare eta_utc vs actual on_utc |
| Storage growth | <3GB/90 days | Monitor table sizes |

---

## 📝 Notes for Future Sessions

1. **OSM Data Quality** varies by airport - US majors are excellent, smaller international airports may need fallback zones

2. **Zone Detection Edge Cases** to watch for:
   - Aircraft parked on taxiway (flight school)
   - Runway crossings during taxi
   - Holding patterns near airport
   - Go-arounds (touch runway then climb)

3. **Performance Considerations**:
   - Spatial queries can be slow without proper indexing
   - Batch processing preferred over per-flight triggers
   - Consider caching current_zone on adl_flight_core

4. **Weather Integration** (Phase 5) will require:
   - External data source for TCF/SIGMET
   - Polygon parsing for convective areas
   - Integration with trajectory tier system

---

*Last Updated: 2026-01-06*
