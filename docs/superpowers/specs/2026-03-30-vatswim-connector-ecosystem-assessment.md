# VATSWIM Connector Ecosystem Assessment

**Date:** 2026-03-30
**Author:** Claude (Opus 4.6) for vATCSCC
**Scope:** Comprehensive review of external tools, integration opportunities, and adoption strategy for VATSWIM

---

## Executive Summary

VATSWIM already has a mature API layer (~48 endpoints, 6-language SDK, WebSocket, 4-tier key system) and 8 registered connectors. The main barrier to ecosystem growth isn't technical infrastructure -- it's **connector adoption friction** and **lack of bidirectional integration with the tools ATC and pilots actually use daily**.

This document catalogs 15 external tools, maps each to VATSWIM integration opportunities, and proposes a phased adoption strategy organized by leverage and feasibility.

---

## Part 1: Current VATSWIM Connector State

### What's Built and Working

| Connector | Type | Status | Data Flow |
|-----------|------|--------|-----------|
| **vNAS** | Push | Production | Radar tracks, controller handoffs, tags -> SWIM |
| **SimTraffic** | Bidirectional | Production | TBFM metering, EDCT, scheduling <-> SWIM |
| **vACDM** | Bidirectional | Production | TOBT/TSAT/TTOT milestones <-> SWIM |
| **ECFMP** | Poll | Production | European flow measures -> SWIM |
| **Hoppie ACARS** | Push | Production | CPDLC/PDC/OOOI -> SWIM (via bridge.php cron) |
| **vATIS** | Push | Production | ATIS/runway/weather -> SWIM |
| **Virtual Airlines** | Push | Production | phpVMS/smartCARS/VAM PIREPs -> SWIM |
| **vIFF CDM** | Poll | Production | CTOT/ATFCM status -> SWIM (30s daemon) |

### What's Scaffolded but Thin

- **Connector health/status endpoints** exist (`/connectors/health`, `/connectors/status`) but no per-connector detail pages
- **ConnectorInterface** contract defined but minimal -- no versioning, no capability negotiation, no webhook delivery
- **No sandbox environment** -- all development happens against production (rate-limited)
- **SDK packages exist** in 6 languages but marked "Coming Soon" on the portal

### Key Technical Gaps

1. **No outbound webhook delivery** -- SWIM is pull-only for consumers. External tools must poll.
2. **No EuroScope plugin data pathway** -- vNAS covers radar, but EuroScope-specific plugin data (AMAN sequences, CPDLC state, flow tools) has no ingest route.
3. **No pilot-side CPDLC delivery** -- Hoppie bridge polls and ingests, but doesn't deliver EDCTs/clearances back to pilots.
4. **No AMAN/DMAN sequence ingestion** -- arrival/departure sequences from tools like Maestro have no SWIM representation.
5. **No European airspace management data** -- EAUP, SUA activation from European sources not integrated.

---

## Part 2: External Tool Assessment

### Tier 1: High-Leverage, Mature -- Integrate Now

#### 1. Hoppie ACARS Protocol (`hoppie.nl/acars/system/tech.html`)

**What it is:** The de facto standard for pilot-ATC datalink on VATSIM. Store-and-forward HTTP protocol.

**Current VATSWIM state:** Server-side polling via `HoppieClient.php` (30s cron). Ingests CPDLC, telex, OOOI, position reports.

**Integration opportunity:**
- **Bidirectional EDCT delivery.** VATSWIM generates EDCTs via GDP algorithm but has no path to deliver them to pilots as Hoppie CPDLC messages. Adding a `HoppieWriter` that sends `type=cpdlc` messages with DCL/PDC content would close the loop: GDP issues EDCT -> SWIM formats CPDLC -> Hoppie delivers to pilot client.
- **Progress message parsing.** Hoppie `progress` type contains OOOI events in structured format. Current bridge handles this but could be enhanced to extract ETAs and fuel state.
- **Source:** [Hoppie Tech Spec](https://www.hoppie.nl/acars/system/tech.html) | [Community Docs](https://github.com/devHazz/hoppie-acars-docs/)

**Effort:** Medium (PHP writer class + EDCT delivery integration)
**Impact:** Completes the GDP->pilot feedback loop

---

#### 2. SimTraffic (`simtraffic.net`)

**What it is:** FAA TBFM emulator for VATUSA/VATCAN. Provides metering, EDCT scheduling, TMU tools.

**Current VATSWIM state:** Bidirectional connector. SWIM polls SimTraffic for metering data; reverse sync pushes ADL data back. `simtraffic_swim_poll.php` (2min) + `swim_adl_reverse_sync_daemon.php` (2min).

**Integration opportunity:**
- **Already strong.** SimTraffic is the primary TBFM data source for North America.
- **Enhancement:** Expose SimTraffic's TBFM ladder/planview data through SWIM's WebSocket channel so third-party tools can subscribe to real-time metering updates without polling SimTraffic directly.
- **Enhancement:** SimTraffic's ground event alerts (SMES-equivalent) could feed into CDM milestone tracking.
- **Source:** [SimTraffic](https://simtraffic.net/)

**Effort:** Low (WebSocket event forwarding)
**Impact:** Makes SWIM the single pane of glass for TBFM data

---

#### 3. vIFF CDM (`cdm.vatsimspain.es`)

**What it is:** Community-built ATFCM and CDM platform. 13 vACCs participating, ~12 more in progress. Provides CTOT, TOBT, flight-level flow restrictions, ATFCM status.

**Current VATSWIM state:** Poll daemon (30s) hitting 3 vIFF endpoints (`/etfms/relevant`, `/etfms/restricted`, `/ifps/allStatus`). Writes directly to `swim_flights`. 3-tier cascade flight matching.

**Integration opportunity:**
- **CTOT delivery to pilots.** vIFF assigns CTOTs but has limited delivery mechanisms. VATSWIM could bridge vIFF CTOTs to pilots via Hoppie CPDLC (see #1 above) or via SWIM WebSocket to pilot clients.
- **Regulation data.** vIFF's `/ifps/allStatus` provides ATFCM regulation IDs -- these could map to SWIM TMI entries for a unified flow measure view (ECFMP + vIFF + VATSWIM native).
- **Source:** [vIFF CDM](https://cdm.vatsimspain.es/)

**Effort:** Low-Medium (CTOT->Hoppie bridge, regulation mapping)
**Impact:** Unifies European and North American flow management in SWIM

---

#### 4. vACDM (`vacdm.net`)

**What it is:** Dockerized A-CDM system for any vACC. EuroScope plugin + web UI. Tracks TOBT/TSAT/TTOT/ASAT.

**Current VATSWIM state:** Bidirectional -- daemon polls providers, ingest endpoint accepts pushes. Multi-provider discovery via `tmi_flow_providers` table.

**Integration opportunity:**
- **Provider auto-discovery.** New vACDM instances currently require manual registration in `tmi_flow_providers`. A self-registration API endpoint would let new vACCs onboard without admin intervention.
- **ECFMP MDI integration.** vACDM already supports ECFMP MDI/ADI for departure intervals. VATSWIM could feed its own GDP rates into vACDM instances as departure constraints.
- **Source:** [vACDM GitHub](https://github.com/vACDM) | [vACDM Docs](https://vacdm.net/docs/what-is-vacdm/)

**Effort:** Low (self-registration endpoint + GDP rate export)
**Impact:** Scales CDM adoption across vACCs

---

#### 5. ECFMP Flow API (`ecfmp.vatsim.net/docs/v1`)

**What it is:** Official VATSIM European flow management API. Provides flow measures (MDI, ADI, rate limits, ground stops, mandatory routes, speed restrictions) with FIR mapping.

**Current VATSWIM state:** Poll daemon (5min) syncs measures to `tmi_flow_measures`. Maps ECFMP measure types to PERTI TMI types.

**Integration opportunity:**
- **Bidirectional flow coordination.** Currently SWIM reads ECFMP but never writes. VATSWIM's own TMI programs (GDPs, ground stops, reroutes) could be published to ECFMP as measures, giving European controllers visibility into North American flow actions.
- **NOTAM-style advisory generation.** ECFMP measures could auto-generate NTML advisories for cross-regional awareness.
- **Source:** [ECFMP Flow API](https://ecfmp.vatsim.net/docs/v1)

**Effort:** Medium (write API integration + advisory generation)
**Impact:** True cross-Atlantic flow coordination

---

### Tier 2: High-Leverage, Moderate Effort -- Build Next

#### 6. AMAN Maestro / aman-dman (`github.com/EvenAR/aman-dman`)

**What it is:** Arrival/departure manager for EuroScope. C++/Kotlin. Calculates ETAs, sequences arrivals, provides "time to lose/gain" advisories to controllers. Used by VATSIM Scandinavia. Master/slave mode for multi-controller ops. 334 commits, v1.0.0 released March 2026.

**Integration opportunity: HIGH PRIORITY**
- **Sequence data ingestion.** Maestro calculates STA (Scheduled Time of Arrival) and delay advisories per flight. SWIM has no arrival sequence representation today. A new ingest endpoint (`/ingest/aman`) could accept sequence data (flight, STA, delay, runway, sequence_position).
- **Feed SWIM data to Maestro.** Maestro currently uses its own ETA calculations. SWIM's `adl_flight_times` has superior ETAs from waypoint-level calculation. Exposing a Maestro-compatible feed would improve sequence accuracy.
- **Metering fix data.** Maestro sequences at fixes; SWIM's crossing predictions (`adl_flight_planned_crossings`) could provide fix-level ETAs to Maestro.
- **Source:** [aman-dman](https://github.com/EvenAR/aman-dman) | [Maestro Wiki](https://wiki.vatsim-scandinavia.org/books/general/page/aman-maestro)

**Effort:** Medium (new ingest endpoint + ETA feed)
**Impact:** Connects arrival management to network-wide flow picture

---

#### 7. vatiris (`github.com/minsulander/vatiris`)

**What it is:** Swedish IRIS (Integrated Real-time Information System) adaptation. Vue3/Express/PostgreSQL. Provides operational overview for controllers. Connects to vIFF CDM, VATSIM Connect. 295 commits, active development.

**Integration opportunity:**
- **SWIM as data source.** vatiris currently pulls from vIFF and VATSIM directly. It could consume SWIM's unified API instead, getting flight data + TMI + CDM + flow measures in one call.
- **CDM data relay.** vatiris's CDM proxy (`VIFF_BASE_URL`) could be supplemented or replaced by SWIM's CDM endpoints, which aggregate vACDM + vIFF + native CDM.
- **Reference implementation.** vatiris's Vue3/Express architecture is a natural fit for a "SWIM-powered ATC information display" reference app, demonstrating SDK usage.
- **Source:** [vatiris](https://github.com/minsulander/vatiris)

**Effort:** Low (SDK integration, documentation)
**Impact:** Proves SWIM value to Scandinavian ATC community

---

#### 8. VATCAN Slots-Plugin (`github.com/VATSIMCanada/Slots-Plugin`)

**What it is:** EuroScope C++ plugin that pulls CTOT (Calculated Take-Off Time) from a database and displays it to controllers on the radar scope. 25 commits, last release v1.10 (April 2021). Appears dormant.

**Integration opportunity:**
- **SWIM as CTOT source.** Instead of pulling from VATCAN's internal database, this plugin could query SWIM's `/flights` endpoint (filtered by `tmi_controlled=true`) to get EDCTs/CTOTs. This would give VATCAN controllers visibility into both SimTraffic EDCTs and vIFF CTOTs.
- **Plugin modernization.** The plugin's VATSIM API integration (`VatsimAPI.cpp`) could be replaced with SWIM SDK calls, making it a reference EuroScope-to-SWIM integration.
- **Source:** [Slots-Plugin](https://github.com/VATSIMCanada/Slots-Plugin)

**Effort:** Medium (C++ SDK integration, coordination with VATCAN)
**Impact:** Demonstrates EuroScope plugin pattern for SWIM consumption

---

#### 9. EasyCPDLC (`github.com/quassbutreally/EasyCPDLC`)

**What it is:** C# pilot CPDLC client for VATSIM. 76 stars, 71 commits, 12 releases. Uses Hoppie ACARS for datalink. Makes CPDLC usable for pilots without native aircraft support.

**Integration opportunity:**
- **EDCT delivery target.** If VATSWIM writes EDCTs to Hoppie (see #1), EasyCPDLC users automatically receive them as CPDLC uplinks. No plugin modification needed -- just ensure SWIM's CPDLC message format matches what EasyCPDLC expects.
- **Direct SWIM integration (optional).** EasyCPDLC could optionally query SWIM for flight data (current EDCT, TMI status, ground stop info) to display alongside CPDLC messages, using the C# SDK.
- **Source:** [EasyCPDLC](https://github.com/quassbutreally/EasyCPDLC)

**Effort:** Low (Hoppie bridge covers it) to Medium (direct C# SDK)
**Impact:** Every GDP-affected pilot gets EDCT notification automatically

---

#### 10. EuroscopeACARS (`github.com/lancard/EuroscopeACARS`)

**What it is:** EuroScope C++ plugin for Hoppie ACARS send/receive. 37 commits, 7 releases (latest Dec 2025). Thread-safe async message handling.

**Integration opportunity:**
- **ATC-side CPDLC visibility.** When SWIM delivers EDCTs via Hoppie, controllers using EuroscopeACARS see the messages in their EuroScope chat window. This completes the visibility loop.
- **Outbound CPDLC capture.** If EuroscopeACARS sends CPDLC uplinks (clearances, altitude assignments), those could be captured by SWIM's Hoppie poller and recorded as clearance events.
- **Source:** [EuroscopeACARS](https://github.com/lancard/EuroscopeACARS)

**Effort:** None (passive benefit from Hoppie bridge) to Low (enhanced capture)
**Impact:** Controller-side visibility of SWIM-delivered messages

---

### Tier 3: Niche/Architectural -- Integrate Opportunistically

#### 11. UACPlugin (`github.com/pierr3/UACPlugin`)

**What it is:** EuroScope plugin emulating European upper area control HMI. C++, 59 commits, active nightly builds. Features: custom radar tags, separation tools, STCA, MTCD, Mode S data.

**Integration opportunity:**
- **Limited direct integration.** UACPlugin is a display/HMI tool, not a data source. However:
- **SWIM flight data overlay.** UACPlugin's custom tags could display SWIM-sourced TMI status, EDCT, or flow measure restrictions alongside radar data.
- **MTCD enhancement.** UACPlugin's conflict detection could be enhanced with SWIM's planned crossing data (`adl_flight_planned_crossings`) for trajectory-based conflict prediction.
- **Source:** [UACPlugin](https://github.com/pierr3/UACPlugin)

**Effort:** Medium-High (EuroScope plugin development)
**Impact:** Niche -- upper area control enhancement

---

#### 12. Portugal EAUP (`gitlab.com/portugal-vacc/eaup`)

**What it is:** European Airspace Use Plan tool for Portugal vACC. API at `eaup.vatsim.pt/api/docs`. Manages SUA activation/deactivation schedules.

**Integration opportunity:**
- **SUA data source.** VATSWIM has SUA display (`sua.php`) and tables (`sua_*` in VATSIM_ADL) but limited European SUA data. EAUP's API could feed European airspace restriction data into SWIM.
- **Cross-Atlantic SUA picture.** Combined with PERTI's existing North American SUA data, this creates a unified airspace restriction view.
- **Source:** [EAUP](https://gitlab.com/portugal-vacc/eaup) (API docs at `eaup.vatsim.pt/api/docs`)

**Effort:** Medium (new poll daemon, SUA schema mapping)
**Impact:** European SUA awareness for cross-Atlantic operations

---

#### 13. ZAB ARTCC API + Data Parser (`github.com/zabartcc/api`, `github.com/zabartcc/data-parser`)

**What it is:** Express.js backend for Albuquerque ARTCC website. Separate VATSIM data parser microservice. 334 commits, v1.4.0 (June 2025).

**Integration opportunity:**
- **ARTCC-level SWIM consumer pattern.** ZAB's architecture (API + data parser) mirrors what any ARTCC wanting to consume SWIM would build. This could become a reference implementation.
- **Data parser enhancement.** Instead of parsing raw VATSIM datafiles, the parser could consume SWIM's richer dataset (with TMI, CDM, metering data included).
- **Source:** [ZAB API](https://github.com/zabartcc/api) | [ZAB Data Parser](https://github.com/zabartcc/data-parser)

**Effort:** Low (documentation + SDK guidance)
**Impact:** Template for ARTCC-level SWIM adoption

---

#### 14. OpenSkyToEuroscope (`github.com/aap007freak/OpenSkyToEuroscope`)

**What it is:** Python bridge that fetches OpenSky Network ADS-B data and converts to FSD protocol for EuroScope display. Streams position updates every 10s.

**Integration opportunity:**
- **Pattern replication for SWIM.** The same FSD bridge pattern could display SWIM flight data in EuroScope, useful for observers or training scenarios. A "SWIMToEuroscope" bridge would let any EuroScope user see VATSIM traffic enhanced with SWIM metadata.
- **Architectural reference.** Demonstrates the FSD protocol bridge pattern that SWIM-to-EuroScope integrations would follow.
- **Source:** [OpenSkyToEuroscope](https://github.com/aap007freak/OpenSkyToEuroscope)

**Effort:** Medium (Python FSD bridge using SWIM SDK)
**Impact:** Enables EuroScope display of SWIM-enriched traffic

---

#### 15. FSD Protocol Reference (`github.com/kuroneko/fsd`)

**What it is:** Last public copy of Marty Bochane's FSD 2 source code. C++. The underlying protocol that VATSIM clients speak.

**Integration opportunity:**
- **Protocol reference.** Understanding FSD is essential for any tool that bridges SWIM data into VATSIM client display (EuroScope, vPilot, xPilot). Not a direct integration target, but a critical reference.
- **Source:** [FSD](https://github.com/kuroneko/fsd)

**Effort:** N/A (reference only)
**Impact:** Foundational knowledge for client integrations

---

#### 16. VRC Live Traffic (`github.com/Sequal32/vrclivetraffic`)

**What it is:** Rust program that bridges FlightRadar24/ADSBExchange data into VRC/EuroScope. 53 commits, last release May 2021 (dormant).

**Integration opportunity:**
- **Historical interest only.** The project demonstrates the same "external data -> VATSIM client" pattern as OpenSkyToEuroscope but is inactive.
- **SWIM replacement.** If revived, it could use SWIM as its data source instead of FlightRadar24, providing richer flight data including TMI status.
- **Source:** [VRC Live Traffic](https://github.com/Sequal32/vrclivetraffic)

**Effort:** High (Rust, dormant project)
**Impact:** Low priority

---

## Part 3: Integration Architecture

### The Hub-and-Spoke Model

```
                          ECFMP ----poll----> VATSWIM <----poll---- vIFF CDM
                                                |
   EasyCPDLC <--Hoppie-- HoppieWriter <--------+--------> SWIM WebSocket --> vatiris
                                                |                            Maestro
   EuroscopeACARS --Hoppie--> HoppiePoller ----+--------> REST API -------> ZAB API
                                                |                            Slots-Plugin
   vACDM instances ---push/poll--------------->+--------> Ingest API ------> SimTraffic
                                                |
   EAUP --------poll--> SUA Daemon -----------+
                                                |
   vNAS ---------push----> Track Ingest -------+
```

### Data Authority Hierarchy (Extended)

| Data Domain | Primary Source | Secondary | SWIM Role |
|-------------|---------------|-----------|-----------|
| Flight identity | VATSIM Core | -- | Pass-through |
| Radar position | vNAS | Hoppie position | Aggregate + enrich |
| Flight plan/route | VATSIM | SimBrief | Parse + expand (PostGIS) |
| OOOI times | Hoppie ACARS | VA systems | Ingest + validate |
| Metering/TBFM | SimTraffic | -- | Relay + historize |
| EDCT/CTOT (NA) | SimTraffic GDP | VATSWIM GDP | Generate + deliver |
| EDCT/CTOT (EU) | vIFF | vACDM | Aggregate + relay |
| CDM milestones | vACDM | vIFF | Aggregate + score |
| Flow measures (EU) | ECFMP | vIFF | Ingest + unify |
| Flow measures (NA) | VATSWIM native | SimTraffic | Authoritative |
| Arrival sequence | Maestro/AMAN | SimTraffic TBFM | **NEW: Ingest** |
| SUA (NA) | FAA/PERTI | -- | Authoritative |
| SUA (EU) | EAUP | -- | **NEW: Ingest** |
| Weather/ATIS | vATIS | Hoppie inforeq | Ingest + correlate |

---

## Part 4: Adoption Strategy

### Phase 1: Close the Feedback Loops (Weeks 1-4)

**Goal:** Make SWIM bidirectional where it's currently read-only.

1. **HoppieWriter class** -- Deliver EDCTs/CTOTs as CPDLC messages to pilots via Hoppie
   - Covers: EasyCPDLC, EuroscopeACARS (passive recipients)
   - Unlocks: GDP -> pilot notification without any pilot tool changes

2. **vACDM self-registration endpoint** -- Let new vACDM instances register as SWIM CDM providers
   - Covers: Any vACC deploying vACDM
   - Unlocks: Scales CDM without admin bottleneck

3. **SimTraffic TBFM WebSocket forwarding** -- Relay metering data via SWIM WebSocket
   - Covers: Any tool wanting real-time metering without SimTraffic API access
   - Unlocks: SWIM as single metering data source

### Phase 2: Connect Arrival Management (Weeks 5-8)

**Goal:** Bring AMAN/DMAN sequence data into the SWIM picture.

4. **AMAN ingest endpoint** (`/api/swim/v1/ingest/aman.php`) -- Accept sequence data from Maestro and similar tools
   - Fields: flight_uid, airport, runway, sta_utc, sequence_position, delay_seconds, source
   - Covers: Maestro, future AMAN tools

5. **SWIM ETA feed for Maestro** -- Expose crossing predictions in Maestro-compatible format
   - Endpoint: `/api/swim/v1/metering/{airport}/aman-feed`
   - Covers: Any AMAN tool wanting network-wide ETAs

6. **Slots-Plugin SWIM adapter** -- Help VATCAN modernize Slots-Plugin to use SWIM as CTOT source
   - Covers: VATCAN event operations
   - Unlocks: EuroScope-to-SWIM plugin reference pattern

### Phase 3: European Airspace Integration (Weeks 9-12)

**Goal:** Bring European operational data into unified SWIM view.

7. **EAUP SUA polling daemon** -- Poll Portuguese/European EAUP APIs for airspace restrictions
   - Covers: Cross-Atlantic SUA awareness

8. **ECFMP write integration** -- Publish VATSWIM TMI programs as ECFMP measures
   - Covers: European controller visibility into NA flow actions

9. **vIFF regulation mapping** -- Map vIFF ATFCM regulations to SWIM TMI entries
   - Covers: Unified flow measure view across all sources

### Phase 4: Community Enablement (Ongoing)

**Goal:** Make it easy for anyone to build on SWIM.

10. **Publish SDK packages** -- The 6 SDKs exist in `sdk/` but aren't on package registries yet
    - Python -> PyPI, JS -> npm, C# -> NuGet, Java -> Maven, PHP -> Packagist

11. **SWIMToEuroscope bridge** -- Reference implementation using OpenSkyToEuroscope pattern
    - Demonstrates: FSD protocol bridge for SWIM data in EuroScope

12. **ZAB-style reference app** -- Document how an ARTCC website consumes SWIM
    - Template for: Any ARTCC/vACC wanting SWIM-powered traffic displays

13. **Developer sandbox** -- Staging environment with synthetic data for risk-free testing

---

## Part 5: Priority Matrix

| Tool | Leverage | Effort | Phase | Integration Type |
|------|----------|--------|-------|-----------------|
| **Hoppie (write)** | Very High | Medium | 1 | New: EDCT delivery via CPDLC |
| **SimTraffic (WS)** | High | Low | 1 | Enhancement: WebSocket relay |
| **vACDM (self-reg)** | High | Low | 1 | Enhancement: Provider onboarding |
| **vIFF (CTOT relay)** | High | Low-Med | 1 | Enhancement: CTOT->Hoppie bridge |
| **Maestro/AMAN** | Very High | Medium | 2 | New: Sequence ingest + ETA feed |
| **Slots-Plugin** | Medium | Medium | 2 | Modernization: SWIM as CTOT source |
| **EasyCPDLC** | High | None-Low | 1 | Passive: Benefits from Hoppie write |
| **EuroscopeACARS** | Medium | None | 1 | Passive: Benefits from Hoppie write |
| **vatiris** | Medium | Low | 2 | SDK integration: SWIM as data source |
| **ECFMP (write)** | High | Medium | 3 | New: Publish NA TMI as ECFMP measures |
| **EAUP** | Medium | Medium | 3 | New: European SUA polling |
| **UACPlugin** | Low | Med-High | 4 | Enhancement: SWIM data in radar tags |
| **ZAB API** | Low | Low | 4 | Documentation: Reference pattern |
| **OpenSky bridge** | Low | Medium | 4 | Reference: FSD bridge pattern |
| **FSD protocol** | N/A | N/A | N/A | Reference material only |
| **VRC Live Traffic** | Very Low | High | N/A | Dormant -- monitor only |

---

## Part 6: Where to Kickstart

The single highest-leverage action is **completing the Hoppie write path**. Here's why:

1. **Zero adoption friction.** Every pilot using EasyCPDLC, MSFS CPDLC, or any Hoppie client automatically receives SWIM-delivered EDCTs. No plugin installation, no API keys, no code changes.

2. **Proves bidirectionality.** Today SWIM is predominantly a data sink. Delivering EDCTs via Hoppie makes it a data source that pilots experience directly.

3. **Cascading value.** Once EDCTs flow through Hoppie:
   - Controllers using EuroscopeACARS see the messages
   - vACDM instances can compare EDCT vs TOBT
   - SimTraffic compliance tracking gets pilot acknowledgment data
   - Virtual airlines can detect EDCT-impacted flights

4. **Minimal new infrastructure.** The `HoppieClient.php` already knows how to talk to Hoppie. A `HoppieWriter` class using `type=cpdlc` with the same protocol is straightforward.

The second action is **AMAN sequence ingestion** -- it's the biggest data gap in SWIM today and Maestro's March 2026 v1.0.0 release makes this timely.

The third is **publishing the SDKs to package registries** -- the code exists, it just needs `npm publish`, `pip upload`, etc. This removes the biggest friction for external developers.

---

## Appendix A: Source Links

| Tool | Repository / Docs |
|------|-------------------|
| EasyCPDLC | https://github.com/quassbutreally/EasyCPDLC |
| EuroscopeACARS | https://github.com/lancard/EuroscopeACARS |
| Hoppie ACARS Tech | https://www.hoppie.nl/acars/system/tech.html |
| Hoppie Community Docs | https://github.com/devHazz/hoppie-acars-docs/ |
| SimTraffic | https://simtraffic.net/ |
| VATCAN Slots-Plugin | https://github.com/VATSIMCanada/Slots-Plugin |
| UACPlugin | https://github.com/pierr3/UACPlugin |
| AMAN/DMAN (Maestro) | https://github.com/EvenAR/aman-dman |
| Maestro Wiki | https://wiki.vatsim-scandinavia.org/books/general/page/aman-maestro |
| vatiris | https://github.com/minsulander/vatiris |
| EAUP | https://gitlab.com/portugal-vacc/eaup |
| ZAB ARTCC API | https://github.com/zabartcc/api |
| ZAB Data Parser | https://github.com/zabartcc/data-parser |
| FSD Protocol | https://github.com/kuroneko/fsd |
| VRC Live Traffic | https://github.com/Sequal32/vrclivetraffic |
| OpenSkyToEuroscope | https://github.com/aap007freak/OpenSkyToEuroscope |
| vACDM | https://github.com/vACDM |
| vIFF CDM | https://cdm.vatsimspain.es/ |
| ECFMP Flow API | https://ecfmp.vatsim.net/docs/v1 |
| VATSIM Core API | https://vatsim.dev/api/core-api/ |

## Appendix B: Validation Notes

All findings in this document were validated against:
- **Codebase review:** Existing connector code in `/lib/connectors/sources/`, `/integrations/`, `/scripts/`
- **GitHub/web fetch:** Each external tool's repository was fetched and analyzed (March 30, 2026)
- **SWIM API audit:** Cross-referenced against existing endpoint status (controllers, TMI entries, TMI routes endpoints confirmed fixed since March 23 audit)
- **Connector registry:** All 8 registered connectors verified in `ConnectorRegistry.php`
- **Hoppie protocol:** Validated against official tech spec at hoppie.nl

Items NOT verified (require live testing):
- EAUP API (403 on docs page -- may require auth)
- vACDM API endpoint schema (not publicly documented in detail)
- SimTraffic API specifics (docs not public)
