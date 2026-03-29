# ICAO SWIM & GUFI Standards Analysis
## Applicability to VATSWIM and PERTI

**Version:** 1.0
**Date:** 2026-03-29
**Author:** vATCSCC Engineering

---

## Table of Contents

1. [Source Documents](#1-source-documents)
2. [GUFI: Globally Unique Flight Identifier](#2-gufi-globally-unique-flight-identifier)
   - 2.1 [Definition and Purpose](#21-definition-and-purpose)
   - 2.2 [Functional Requirements](#22-functional-requirements)
   - 2.3 [Format/Structure Requirements](#23-formatstructure-requirements)
   - 2.4 [GUFI Correlation and Synchronization](#24-gufi-correlation-and-synchronization)
   - 2.5 [Transition Strategy](#25-transition-strategy)
   - 2.6 [Lessons from Mini Global Demonstrations](#26-lessons-from-mini-global-demonstrations)
3. [SWIM: System Wide Information Management](#3-swim-system-wide-information-management)
   - 3.1 [Definition and Scope](#31-definition-and-scope)
   - 3.2 [Five-Layer Global Interoperability Framework](#32-five-layer-global-interoperability-framework)
   - 3.3 [SWIM Core Services](#33-swim-core-services)
   - 3.4 [SWIM Registry and Service Lifecycle](#34-swim-registry-and-service-lifecycle)
   - 3.5 [Information Exchange Models](#35-information-exchange-models)
   - 3.6 [Transition and Mixed Environments](#36-transition-and-mixed-environments)
   - 3.7 [SWIM Enterprises and Regions](#37-swim-enterprises-and-regions)
4. [Flight Object Architecture](#4-flight-object-architecture)
   - 4.1 [Architecture Spectrum](#41-architecture-spectrum)
   - 4.2 [Fully Distributed (FOIPS Model)](#42-fully-distributed-foips-model)
   - 4.3 [Fully Centralized](#43-fully-centralized)
   - 4.4 [Hierarchical (Recommended Blend)](#44-hierarchical-recommended-blend)
   - 4.5 [Regional Hierarchical (Best Overall)](#45-regional-hierarchical-best-overall)
   - 4.6 [The Authoritative Data Problem](#46-the-authoritative-data-problem)
5. [Applicability to VATSWIM/PERTI](#5-applicability-to-vatswimperti)
   - 5.1 [Architectural Alignment](#51-architectural-alignment)
   - 5.2 [GUFI Implementation Assessment](#52-gufi-implementation-assessment)
   - 5.3 [SWIM Layer Mapping](#53-swim-layer-mapping)
   - 5.4 [Core Services Compliance](#54-core-services-compliance)
   - 5.5 [Information Exchange Model Alignment](#55-information-exchange-model-alignment)
   - 5.6 [Structural Advantages](#56-structural-advantages)
   - 5.7 [Gap Analysis and Opportunities](#57-gap-analysis-and-opportunities)
   - 5.8 [Recommended Actions](#58-recommended-actions)

---

## 1. Source Documents

Five ICAO/FAA documents were analyzed for this assessment:

| # | Document | Source | Pages | Date |
|---|----------|--------|-------|------|
| 1 | **WP549** - Globally Unique Flight Identifier (GUFI) Requirements | ATMRPP WG23, Brussels | 8 | Mar 2013 |
| 2 | **WP604** - GUFI in FIXM v3.0 | ATMRPP WG25, Toulouse | 6 | Mar 2014 |
| 3 | **WP697** - SWIM Next Steps / Mini Global GUFI Implementation | ATMRPP WG30, Brussels | 10 | Apr 2016 |
| 4 | **Doc 10039** - Manual on System Wide Information Management (SWIM) Concept | ICAO (interim advance edition) | 88 | 2015 |
| 5 | **High-level Architecture v1** - Flight Object High-level Architecture (FAA Engineering Memo) | Ken Howard, FAA/Volpe | 17 | May 2011 |

All documents are ICAO Air Traffic Management Requirements and Performance Panel (ATMRPP) working papers or ICAO manuals, except Document 5 which is an FAA engineering memo that informed the ICAO Flight Object concept.

---

## 2. GUFI: Globally Unique Flight Identifier

### 2.1 Definition and Purpose

A **Globally Unique Flight Identifier (GUFI)** is a persistent, immutable identifier assigned to a single flight (one takeoff, one landing) that travels with every data transaction, enabling unambiguous correlation across all systems and stakeholders.

**What a flight is:** A single operation of an aircraft from takeoff to touchdown. A ground return (pushback and return without takeoff) is not a flight. A multi-leg itinerary (A to B to C) is two separate flights, each with its own GUFI.

**What a GUFI identifies:** A *flight*, not a *flight plan*. FIXM supports multiple alternative flight plans under a single GUFI. This allows stakeholders to distinguish between one flight with two alternative plans and two different flights.

**Why natural identifiers fail** (WP549 Section 2.3):

| Approach | Problem |
|----------|---------|
| Callsign (ACID) alone | Can change; same ACID used for multiple legs |
| Tail/registration number | Aircraft substitution breaks correlation |
| ACID + Origin + Destination | ACID/destination can change; same combo used multiple times/day |
| ACID + Origin + Dest + Departure Time | Departure times can change |

The core problem: natural identifiers are composed of attributes that can change after flight creation. Once a GUFI is constructed, it becomes immutable even if its source attributes change.

### 2.2 Functional Requirements

Nine requirements from WP549 Section 3 (codified in WP604 Appendix A, Table A-1):

| # | Requirement | Explanation |
|---|-------------|-------------|
| 1 | Every unique flight shall have a GUFI | One takeoff + one landing = one GUFI |
| 2 | Only one GUFI shall ever be assigned to a flight | No dual assignment by separate ANSPs |
| 3 | Once established, a GUFI never changes | Even if ACID, departure time, or destination change |
| 4 | Every data transaction must include the GUFI | All stakeholders, not just ANSPs |
| 5 | Every system shall use the GUFI to correlate flight data | Primary mechanism for data matching |
| 6 | GUFI must be unique across all ANSPs and organizations | Prevents independent GUFI schemes |
| 7 | GUFI must be unique over all time | Archival queries remain valid indefinitely |
| 8 | ANSP implementing GUFI must maintain a transition period | Support legacy + GUFI interfaces simultaneously |
| 9 | ANSP must enable stakeholders to convert all interfaces to GUFI at once | Insert GUFIs into all data feeds via adapters |

### 2.3 Format/Structure Requirements

Eight design-level requirements from WP604 Appendix A, Table A-2:

| # | Requirement | Explanation |
|---|-------------|-------------|
| 1 | Independent generation with guaranteed uniqueness | Multiple systems may generate GUFIs independently; include a unique organization code |
| 2 | Must identify who generated the GUFI | Useful for dispute resolution and auditing |
| 3 | Generator flexibly defined | Could be airline ICAO code, ANSP designator, or registration number |
| 4 | Must indicate date/time of generation | Aids auditing and satisfies uniqueness-over-time |
| 5 | No constraint on GUFIs per time period | Variable-length sequence number using delimiters |
| 6 | "User friendly" for manual filers | Not pure UUID; use familiar items (registration, date) as components |
| 7 | Expressive enough for any flight type | Military, GA, commercial all supported |
| 8 | Use international standards | ICAO, IATA, ISO formats |

### 2.4 GUFI Correlation and Synchronization

The Mini Global II demonstration (WP697) proved a multi-GUFI-service architecture where multiple ANSP systems independently generate and synchronize GUFIs.

**Architecture:**
- **GEMS (Global Enterprise Messaging Services)** - regional messaging hubs (Harris, Indra) interconnecting ANSPs
- Each ANSP either operates its own GUFI Service (FAA) or uses a GEMS provider's service (NAV Canada -> Harris GEMS, NAV Portugal -> Indra GEMS)

**Synchronization Business Rules** (WP697 Section 2.6):
1. All GUFI Services use a common format per agreed standard
2. Each GUFI Service must correlate a GUFI to a unique flight while flight info is updated
3. ANSPs disseminate new/updated flight information to all interacting ANSPs
4. GUFI Services store GUFIs and flight info generated by other services
5. GUFI is correlated using 5 fields: Aircraft ID, Original Departure Date/Time, Departure Airport, Arrival Airport, Country Code

**Monitoring Responsibilities:**
- ANSP-specific services (e.g., FAA GUFI Service) monitor flights departing/arriving/crossing their domain
- GEMS services monitor all flights for their served ANSPs

### 2.5 Transition Strategy

The burden of GUFI transition falls on ANSPs, not airlines or other stakeholders (WP549 Section 2.6):

1. ANSPs must maintain legacy data-matching algorithms during transition
2. ANSPs should insert GUFIs into **all** data feeds via FIXM Data Adapters
3. A **GUFI Service** coordinates between TFM and Data Adapters to ensure consistent GUFI usage
4. Mixed mode: some sources send GUFIs, others don't; ANSPs handle both
5. Stakeholders (airlines) can convert all interfaces at once, maximizing benefit

### 2.6 Lessons from Mini Global Demonstrations

**From Mini Global II (WP697 Section 3):**
- Systems generating data post-departure may lack original departure time. GUFI services must accept alternative departure times: estimated runway time, gate departure time, or first boundary crossing time
- Multiple GUFI Services reduce the need for centralized services but require synchronization rules
- The architecture follows SOA principles: loosely coupled, standards-based protocols

**From FAA Practical Experience (WP549 Section 4):**
- EAV Trial: FIXM adapters converted legacy data at system boundary; GUFI Service allocated and correlated GUFIs with incoming GUFI-less data
- Airservices Australia: Lack of "natural" elements in GUFI is not detrimental to legacy matching
- FAA FDPS: Uses ERAM identifiers + natural data internally, assigns GUFI per unique flight, includes GUFI on every outgoing FIXM transaction

---

## 3. SWIM: System Wide Information Management

### 3.1 Definition and Scope

**Definition** (Doc 10039, Section 2.3): SWIM consists of standards, infrastructure, and governance enabling the management of ATM-related information and its exchange between qualified parties via interoperable services.

**What SWIM is NOT:**
- Not a single global system or database
- Not a stand-alone concept (justified by the applications it enables)
- Not prescriptive about internal implementation

**Key SWIM Principles** (Doc 10039, Section 2.7.1):
- Separation of information provision and consumption
- Loose system coupling (minimize inter-component knowledge)
- Use of open standards
- Use of interoperable services

### 3.2 Five-Layer Global Interoperability Framework

Doc 10039 Chapter 3 defines a five-layer framework:

```
Layer 1: SWIM-enabled Applications        (outside SWIM scope)
         ATC, ATFM, airline ops, airport systems

Layer 2: Information Exchange Services     (SWIM scope)
         Service definitions, behavior, performance, access patterns

Layer 3: Information Exchange Models       (SWIM scope)
         FIXM, AIXM, WXXM/iWXXM, AIDX + AIRM semantic reference

Layer 4: SWIM Infrastructure              (SWIM scope)
         Core services: interface mgmt, messaging, security, ESM

Layer 5: Network Connectivity             (outside SWIM scope)
         IPv4/IPv6, DNS, identity management
```

**SWIM scope** = Layers 2-4 plus governance of those layers. Applications (Layer 1) and network (Layer 5) are outside SWIM scope but essential to its operation.

### 3.3 SWIM Core Services

Four core service categories (Doc 10039, Section 3.7, Table 2):

**Interface Management:**
- Service Exposure (registry-based publication)
- Service Discovery (search, browse, alerts)
- Metadata Management (versioning, SLAs)

**Messaging:**
- Publish/Subscribe pattern
- Request/Response pattern
- Reliable Messaging (delivery guarantees)
- Message Routing
- Mediation (format transformation)
- Message Transport (multi-protocol)

**Security Services:**
- Message Confidentiality and Integrity
- Transport-level Protections (TLS/SSL)
- Identity Management
- Data Access Management
- Security Policy Management and Enforcement
- Security Monitoring and Auditing

**Enterprise Service Management (ESM):**
- Asset Management
- Configuration Management
- Event and Performance Management
- Service Desk Support
- Policy Management

**Boundary Protection** spans messaging and network layers: prevents malicious content between internal and external applications.

### 3.4 SWIM Registry and Service Lifecycle

The SWIM Registry is central to governance and discoverability. It contains:
- Service instances (available services from providers)
- Service description documents
- Reference models (AIRM, exchange models)
- Information exchange standards (AIXM, WXXM, FIXM)
- Policies (security, compliance)
- Participants (service providers)

**Service Lifecycle Stages** (Doc 10039, Section 2.6):
1. Identification (business need)
2. Proposal (as SWIM information service)
3. Definition (of the service)
4. Development
5. Verification
6. Production and Deployment
7. Deprecation
8. Retirement

**Update Types:**
- **Minor updates**: backward-compatible, covered by SLA (e.g., bug fixes)
- **Major updates**: not backward-compatible, require SLA changes and consumer coordination

### 3.5 Information Exchange Models

Candidate standards and representative technologies (Doc 10039, Table 1):

| Layer | Functions | Candidate Standards |
|-------|-----------|-------------------|
| Information Exchange Services | Service Interoperability | No global standards yet |
| | Interface Definition | OGC CS-W, WSDL, WADL, WFS, WMS, WCS |
| Information Exchange Models | Aeronautical, MET, Flight | AIXM, WXXM, iWXXM, FIXM |
| | Semantic Interoperability | AIRM, RDF/RDFS, OWL, SKOS |
| SWIM Infrastructure | Enterprise Service Management | DDS, JMX, SNMP |
| | Security | WS-Security, SSL |
| | Interface Management | OASIS/ebXML |
| | Data Representation | XML, XSD, GML |
| | Messaging | SOAP, JMS, DDS |
| | Transport | HTTP, JMS, MQ |
| | Service Registry | UDDI |
| Network Connectivity | Secure Connectivity | IPv4, IPv6 |
| | Naming/Addressing | DNS |

**ATM Information Reference Model (AIRM)** provides the semantic reference across individual exchange models, ensuring consistent terminology and meaning.

### 3.6 Transition and Mixed Environments

Three transition patterns for specific services (Doc 10039, Chapter 4):

**Pattern 1: Application-level Interoperability**
SWIM-enabled system supports both new SWIM services AND legacy formats simultaneously. Example: New NOTAM system provides both digital NOTAM SWIM service and legacy AFTN NOTAMs.

**Pattern 2: Gateway Interoperability**
SWIM/AFTN-AMHS gateways translate between formats where straightforward mapping exists. Both SWIM and legacy systems function normally through the gateway.

**Pattern 3: SWIM-only Services**
New services with no legacy equivalent, available only to SWIM-enabled systems. Example: new business logic not supported by legacy systems.

**ASBU Roadmap:**
- Block 0 (current): Legacy AFTN/AMHS
- Block 1 (2018): Ground SWIM on IP networks
- Block 2 (2023): Aircraft as SWIM access point
- Block 3 (>2028): Full air-ground SWIM

### 3.7 SWIM Enterprises and Regions

**SWIM Enterprise**: An ASP, a group of ASPs, an airspace user, or an ATM support industry that has full control of implementation planning and execution within the enterprise.

**SWIM Region**: A collection of SWIM enterprises that have agreed upon common regional governance and internal standards.

Enterprises have full internal autonomy. Interoperability between enterprises within a region uses regional standards. Interoperability between regions uses global standards, with gateways/adapters as needed.

---

## 4. Flight Object Architecture

### 4.1 Architecture Spectrum

The FAA engineering memo (Document 5) evaluates a spectrum of approaches for distributing flight data and services, from fully distributed to fully centralized:

**Two primary architectural questions:**
1. Where is Flight Object data stored?
2. Where are Flight Object services hosted?

**Key asymmetry**: ANSPs are servers (provide Flight Object Servers); airspace users are clients only (consume services but never host FOSes).

### 4.2 Fully Distributed (FOIPS Model)

Each system deploys its own Flight Object Server (FOS). FOSes are networked via distributed Enterprise Service Bus (ESB). One FOS is designated Manager/Publisher per flight at any given time; the role passes as the flight traverses airspace.

| Pros | Cons |
|------|------|
| Direct access to authoritative source (low latency) | Massive duplication of functionality (filtering, reconstitution, auth) |
| Client controls data source selection | Complex handshaking between FOSes |
| No centralized dependency | Risk of conflicting/redundant publications |
| | Manager role is burdensome and inconsistent across systems |
| | Hard to detect failures or provide backup |
| | Piecemeal deployment; clients face mixed FOS + legacy interfaces |
| | Client must subscribe to many individual sources |
| | Hard to assure consistent implementation across developers |

### 4.3 Fully Centralized

Single global FOS collects all data via legacy interfaces (FOIs), stores it centrally, and redistributes via standard SOA services. Individual systems change nothing internally.

| Pros | Cons |
|------|------|
| Zero duplication of functionality | Increased data latency through central FOS |
| No handshaking/ambiguity on authority | Must develop/maintain many legacy FOIs |
| Central FOS resolves discrepancies and provides backup | Who builds/maintains a single global FOS? |
| No burden on individual systems | Even rarely-shared data routes through central FOS |
| Easy transition (legacy + new in parallel) | |
| Single subscription point for clients | |
| Consistent, standardized data output | |

### 4.4 Hierarchical (Recommended Blend)

Two tiers: distributed FOSes (dFOSes) per system for tightly-coupled clients, plus a centralized FOS (cFOS) that aggregates data from all dFOSes for generic clients.

| Pros | Cons |
|------|------|
| Privileged clients get direct source access (low latency) | Still requires a single global cFOS |
| Tightly-coupled services stay local in dFOSes | |
| Generic/shared services centralized once in cFOS | |
| Each system only provides services related to its core mission | |
| Easy data aggregation with deduplication | |
| cFOS gets data in standard formats from dFOSes | |

**Example**: An airport system connects directly to its ATC system's dFOS for low-latency, tightly-coupled data (flight plan clearances). A limo company connects to the cFOS for high-level arrival status across any airport.

### 4.5 Regional Hierarchical (Best Overall)

Splits the world into regions (one ANSP or a group under common authority). Each region implements internally however it wants. Regional cFOSes network together via distributed ESB to form a global virtual Flight Object.

| Pros | Cons |
|------|------|
| ANSPs/groups work autonomously | Re-introduces authoritative-source problem between cFOSes |
| Manageable procurement (regional scope) | Risk of inconsistent implementations across regions |
| Natural governance model (existing authorities) | Requires cross-region handshaking or regional-authority model |
| All benefits of hierarchical within a region | |

### 4.6 The Authoritative Data Problem

The most critical architectural challenge: how to ensure a single authoritative source of data for each flight.

| Architecture | Solution | Weakness |
|---|---|---|
| Fully Distributed | Manager/Publisher role per flight, handed off between FOSes | Complex, error-prone, hard to generalize beyond ATC |
| Fully Centralized | Central FOS applies business rules to choose most authoritative source | Single point of failure, latency |
| Hierarchical | cFOS resolves discrepancies; dFOSes supply raw data | Requires one global cFOS |
| Regional | Each cFOS authoritative for its region; cFOSes subscribe to each other | Data may diverge between regions |

**Data partitioning by domain** (TFM data, ATC data, airport data) could allow different Managers for different data types, but cross-domain data (e.g., expected departure time) makes this difficult.

---

## 5. Applicability to VATSWIM/PERTI

### 5.1 Architectural Alignment

PERTI/VATSWIM's architecture maps directly to the **regional hierarchical model** recommended by the FAA Flight Object architecture paper, with significant structural advantages due to VATSIM's single-source ecosystem.

| Paper Concept | PERTI/VATSWIM Implementation |
|---|---|
| **cFOS** (centralized Flight Object Server) | `VATSIM_ADL` (8-table normalized architecture) + `SWIM_API.swim_flights` (denormalized view) |
| **dFOS** (distributed per-system FOS) | ADL ingest daemon, parse queue daemon, boundary daemon, crossing daemon, waypoint ETA daemon |
| **Legacy FOI adapters** | ADL daemon's VATSIM JSON feed parser, ATIS fetcher, SimTraffic poll daemon |
| **Authoritative source logic** | ADL daemon applies business rules: freshness, source priority, OOOI phase logic |
| **Manager/Publisher** | ADL is always the single manager; no handshaking needed |
| **ESB / messaging** | SWIM WebSocket (pub/sub at `ws://port-8090`) + REST API (request/response) |
| **Service Registry** | OpenAPI spec at `/api-docs/openapi.yaml` |
| **Data Reconstitution** | SWIM API provides full flight snapshot; `adl_flight_changelog` enables recovery |
| **Custom filtering** | API query parameters (`departure_aerodrome`, `arrival_aerodrome`, `phase`, etc.) + WebSocket topic subscriptions |
| **Flight Instance** | `flight_uid` (bigint) -- immutable per flight |
| **GUFI** | `VAT-YYYYMMDD-{callsign}-{dept}-{dest}` (computed column in `swim_flights`) |

### 5.2 GUFI Implementation Assessment

VATSWIM already implements a GUFI. Here is a compliance assessment against the 9 functional requirements:

| # | Requirement | VATSWIM Status | Notes |
|---|-------------|---------------|-------|
| 1 | Every unique flight shall have a GUFI | **Compliant** | Every `swim_flights` row has a `gufi` computed column |
| 2 | Only one GUFI per flight | **Compliant** | Single-source ecosystem; one ADL ingestion point |
| 3 | GUFI never changes once established | **Partially Compliant** | `flight_uid` is immutable; the `gufi` computed column uses callsign/airports which *can* change on VATSIM (flight plan amendments). See [Recommendation A](#recommendation-a-gufi-immutability). |
| 4 | Every transaction includes the GUFI | **Compliant** | `gufi` and `flight_uid` are on every SWIM API response |
| 5 | Systems use GUFI for data correlation | **Compliant** | `flight_uid` is the join key across all 8 ADL normalized tables |
| 6 | GUFI unique across all organizations | **Compliant** | `VAT-` prefix + date + natural key guarantees uniqueness within VATSIM |
| 7 | GUFI unique over all time | **Compliant** | Date component + `flight_uid` (auto-increment bigint) ensures uniqueness |
| 8 | Transition period for legacy systems | **N/A** | No legacy systems to transition from (greenfield) |
| 9 | Enable stakeholder conversion at once | **N/A** | No legacy systems; all consumers use SWIM API from inception |

**Format assessment against 8 structure requirements:**

| # | Requirement | VATSWIM Status | Notes |
|---|-------------|---------------|-------|
| 1 | Independent generation, guaranteed unique | **Compliant** | VAT prefix + date + uid is guaranteed unique |
| 2 | Indicates who generated it | **Compliant** | `VAT-` prefix identifies VATSWIM as generator |
| 3 | Generator flexibly defined | **Partially Compliant** | Fixed to VATSWIM; no provision for external generators |
| 4 | Includes generation date/time | **Compliant** | `YYYYMMDD` date component |
| 5 | No constraint on generation rate | **Compliant** | Bigint `flight_uid` component has no practical limit |
| 6 | User-friendly for manual use | **Compliant** | Human-readable: `VAT-20260329-UAL123-KJFK-KLAX` |
| 7 | Expressive for any flight type | **Compliant** | Works for airlines, GA (registration-based callsigns), military |
| 8 | Uses international standards | **Partially Compliant** | Uses ICAO airport codes and callsign format; not ISO UUID |

<a name="recommendation-a-gufi-immutability"></a>
**Recommendation A: GUFI Immutability**

The current `gufi` is a computed column using mutable attributes (callsign, airports). Per ICAO Requirement 3, a GUFI must never change even if these attributes change. Two options:

1. **Freeze on first computation**: Persist the GUFI value at flight creation time in a non-computed column. If the pilot changes callsign or destination, the GUFI remains the original value.
2. **Switch to UID-based format**: Use `VAT-YYYYMMDD-{flight_uid}` which contains no mutable attributes. Less human-friendly but fully immutable.

The current approach works in practice because VATSIM flight plan amendments are rare and the GUFI is primarily used for API correlation (where `flight_uid` is the actual join key). However, for strict ICAO compliance, Option 1 is recommended.

### 5.3 SWIM Layer Mapping

VATSWIM maps cleanly to all five layers of the ICAO Global Interoperability Framework:

| ICAO Layer | VATSWIM Component | Status |
|---|---|---|
| **Layer 1: Applications** | PERTI web pages (GDT, Demand, Route Plotter, NOD, Playbook), vNAS, SimTraffic, pilot clients, virtual airline systems | **Active** -- 15+ internal apps, 16 SWIM API consumers |
| **Layer 2: Exchange Services** | SWIM REST API (`/api/swim/v1/`), SWIM WebSocket, TMI Controlled endpoint, Metering endpoint, Ingest endpoints | **Active** -- 6 service categories, documented in OpenAPI |
| **Layer 3: Exchange Models** | FIXM 4.3.0-aligned field naming (98 API fields across 8 object groups), `vATCSCC:` extension namespace | **Active** -- Full FIXM field mapping documented in `VATSWIM_FIXM_Field_Mapping.md` |
| **Layer 4: Infrastructure** | Azure App Service (nginx + PHP-FPM), Azure SQL databases, SWIM WebSocket server (port 8090), API key authentication | **Active** -- Full SOA infrastructure deployed |
| **Layer 5: Network** | Azure networking, HTTPS/TLS, DNS (`perti.vatcscc.org`) | **Active** |

### 5.4 Core Services Compliance

Assessment against Doc 10039 Table 2 (SWIM Core Service Functions):

**Interface Management:**

| Function | VATSWIM Status | Implementation |
|----------|---------------|----------------|
| Service Exposure | **Implemented** | OpenAPI spec at `/api-docs/openapi.yaml`; API documentation at `/swim-doc.php` |
| Service Discovery | **Implemented** | Documentation portal; OpenAPI spec is machine-readable |
| Metadata Management | **Partial** | API versioning via URL path (`/v1/`); no formal SLA registry |

**Messaging:**

| Function | VATSWIM Status | Implementation |
|----------|---------------|----------------|
| Publish/Subscribe | **Implemented** | SWIM WebSocket server with topic-based subscriptions |
| Request/Response | **Implemented** | REST API with JSON responses |
| Reliable Messaging | **Partial** | WebSocket reconnection logic; no formal delivery guarantees |
| Message Routing | **N/A** | Single-region system; no inter-region routing needed |
| Mediation | **Implemented** | `formatFlightRecordFIXM()` transforms internal DB schema to FIXM-aligned API output |
| Message Transport | **Implemented** | HTTPS (REST) + WSS (WebSocket) |

**Security Services:**

| Function | VATSWIM Status | Implementation |
|----------|---------------|----------------|
| Message Confidentiality | **Implemented** | TLS/HTTPS on all endpoints |
| Message Integrity | **Implemented** | HTTPS provides transport-layer integrity |
| Identity Management | **Implemented** | API keys in `swim_api_keys` table; VATSIM OAuth for user sessions |
| Data Access Management | **Implemented** | API key scoping; rate limiting |
| Security Policy Enforcement | **Implemented** | API key validation middleware; session-based auth for web UI |
| Security Monitoring | **Implemented** | `swim_audit_log` table; monitoring daemon |
| Security Auditing | **Implemented** | Audit log with request metadata |

**Enterprise Service Management:**

| Function | VATSWIM Status | Implementation |
|----------|---------------|----------------|
| Asset Management | **Partial** | Azure resource management; no formal SWIM asset inventory |
| Configuration Management | **Implemented** | `load/config.php` + Azure App Settings; `swim_config.php` |
| Event/Performance Management | **Implemented** | Monitoring daemon (60s cycle); `status.php` health dashboard |
| Service Desk Support | **Partial** | Discord-based support; no formal SWIM service desk |
| Policy Management | **Partial** | Policies implemented in code; no externalized policy store |

### 5.5 Information Exchange Model Alignment

VATSWIM's FIXM alignment is documented in detail in `VATSWIM_FIXM_Field_Mapping.md` and `Aviation_Standards_Cross_Reference.md`. Summary:

| Standard | VATSWIM Alignment | Coverage |
|----------|-------------------|----------|
| **FIXM 4.3.0** | Primary data model for API output | 98 fields across 8 object groups; `vATCSCC:` namespace for VATSIM extensions |
| **AIXM** | Not applicable | VATSWIM focuses on flight data; aeronautical info in `VATSIM_REF` but not exposed via AIXM format |
| **WXXM/iWXXM** | Not applicable | Weather data consumed but not produced by VATSWIM |
| **AIDX** | Cross-referenced | Field mapping documented but AIDX format not implemented |
| **AIRM** | Conceptual alignment | FIXM field naming follows AIRM semantic conventions |

**VATSWIM extension fields** use the `vATCSCC:` prefix per FIXM extensibility guidelines:
- `vATCSCC:pilotCid`, `vATCSCC:pilotName`, `vATCSCC:pilotRating`
- `vATCSCC:departureTracon`, `vATCSCC:arrivalTracon`
- `vATCSCC:parsedRoute`, `vATCSCC:routeQuality`
- `vATCSCC:flightPhase`, `vATCSCC:tmiStatus`

**Supported output formats:** `fixm` (JSON, default), `xml`, `geojson`, `csv`, `kml`, `ndjson`

### 5.6 Structural Advantages

VATSWIM benefits from several structural advantages that the ICAO documents identify as challenging in the real-world ATM environment:

**1. Single Source of Truth (No Authoritative Data Problem)**

The papers' most recurring challenge -- determining which system is the authoritative source for each flight at each moment -- does not exist in VATSWIM. VATSIM provides a single canonical data feed. ADL ingests it, applies business rules, and becomes the sole authoritative source. No Manager/Publisher handshaking, no GUFI conflicts, no data divergence between regions.

**2. No Transition Burden**

VATSWIM is greenfield SOA from inception. There are no legacy AFTN/AMHS interfaces, no CIDIN networks, no point-to-point custom protocols to maintain during transition. All consumers use the standard SWIM API.

**3. Natural Regional Fit**

VATSWIM serves the VATSIM "region" as a single cFOS. The architecture already supports the regional model: if other VATSIM divisions wanted independent SWIM systems, they could federate via the existing API with their own GUFIs and data stores.

**4. Full Vertical Integration**

Doc 10039 notes that SWIM scope is Layers 2-4. VATSWIM controls all five layers, enabling end-to-end optimization that fragmented real-world implementations cannot achieve.

**5. CDM Infrastructure Ready**

The `swim_flights` table already has `tobt_utc`, `tsat_utc`, `ttot_utc` columns (migration 014). These are the exact milestones described in FF-ICE (Doc 9965) for collaborative departure management. Populating them would make VATSWIM one of the first VATSIM systems to implement FF-ICE collaborative milestones.

### 5.7 Gap Analysis and Opportunities

| Area | ICAO Expectation | VATSWIM Current State | Gap | Priority |
|------|------------------|-----------------------|-----|----------|
| **GUFI immutability** | GUFI must never change (Req 3) | Computed from mutable attributes | Persist on first creation | Medium |
| **Service Registry** | Formal SWIM registry with lifecycle management | OpenAPI spec + documentation site | No formal registry with versioning/deprecation workflow | Low |
| **Reliable Messaging** | Delivery guarantees for pub/sub | WebSocket with reconnect logic | No formal message acknowledgment or replay | Low |
| **Service Level Agreements** | Documented SLAs per service | Informal availability targets | No formal SLA documents | Low |
| **Multi-region Federation** | Inter-region GUFI synchronization | Single-region system | N/A unless VATSIM divisions want independent systems | None |
| **FF-ICE Milestones** | TOBT/TSAT/TTOT/ASAT populated | Columns exist but are empty | Requires CDM data flow implementation | Medium |
| **Air-Ground SWIM** | Aircraft as SWIM access point (Block 2+) | Pilot client integrations exist via SWIM API | SDK support for vPilot/xPilot/MSFS already available | Implemented |
| **AIRM Semantic Reference** | Cross-model semantic alignment | FIXM-aligned with documented mappings | No formal AIRM model published | Low |
| **Cross-domain Composites** | Composite services spanning domains | TMI + Flight + Weather data available via separate endpoints | No composite service combining all domains in one request | Low |
| **Policy Externalization** | WS-Policy, externalized policy store | Policies in application code | Would enable runtime policy changes without deployment | Low |

### 5.8 Recommended Actions

Based on this analysis, the following actions are recommended in priority order:

**Near-term (actionable now):**

1. **Freeze GUFI on creation**: Change the `gufi` column from a computed column to a persisted value written once at flight creation. This achieves full ICAO Requirement 3 compliance while maintaining human-readable format. Impact: schema change + sync daemon update.

2. **Document SWIM service descriptions**: Create formal service description documents for each SWIM API endpoint following ICAO service definition guidelines: what the service provides, message structure, behavior, performance levels, and access method. The existing OpenAPI spec is a strong foundation.

**Medium-term (planned work):**

3. **Populate FF-ICE milestone columns**: The TOBT/TSAT/TTOT columns in `swim_flights` are ready. Implementing CDM data flows (per the CDM Adaptation plan in `memory/cdm-adaptation.md`) would activate FF-ICE functionality and further align with ICAO's collaborative environment vision.

4. **Add SWIM API versioning header**: Include a `SWIM-Version` response header with semantic version. This supports the service lifecycle management described in Doc 10039 Section 2.6.

**Low-priority (future consideration):**

5. **Formal SLA documentation**: Document availability, latency, and throughput commitments per SWIM endpoint.

6. **Message replay capability**: Add a replay endpoint for WebSocket consumers who miss messages, enabling data reconstitution per Flight Object architecture requirements.

7. **Composite service endpoint**: A single endpoint returning flight + TMI + weather data in one response, following the ICAO concept of cross-domain composite services.

---

## Appendix: Key Terminology Cross-Reference

| ICAO Term | VATSWIM Equivalent | Notes |
|---|---|---|
| GUFI | `gufi` field / `flight_uid` | Computed column + internal bigint key |
| Flight Instance | `swim_flights` row | Full flight record with 213 columns |
| Flight Object Server (FOS) | SWIM API + ADL processing pipeline | Centralized model |
| Flight Object Interface (FOI) | ADL ingest daemon + data source adapters | VATSIM JSON, SimTraffic, ATIS |
| Enterprise Service Bus (ESB) | WebSocket server + REST API layer | Messaging infrastructure |
| Manager/Publisher | ADL daemon (always the sole manager) | No role handoff needed |
| SWIM Access Point | Azure App Service instance | Bundles messaging, security, interface mgmt |
| SWIM Enterprise | vATCSCC/VATSWIM | Single enterprise with full implementation control |
| SWIM Region | VATSIM ecosystem | Collection of enterprises with common governance |
| Information Exchange Service | `/api/swim/v1/*` endpoints | RESTful + WebSocket services |
| Information Exchange Model | FIXM 4.3.0 + `vATCSCC:` extensions | Documented in field mapping docs |
| SWIM Registry | `/api-docs/openapi.yaml` + `/swim-doc.php` | Machine-readable + human-readable |
| Service Delivery Management | Monitoring daemon + `status.php` | Performance monitoring and alerting |
| ATM SDM | PERTI planning cycle (Plan, Execute, Review, Train, Improve) | Operational planning framework |
| FF-ICE | CDM milestone columns (TOBT/TSAT/TTOT) | Infrastructure ready, data flow pending |
| AIRM | FIXM field naming conventions | Semantic alignment through FIXM |

---

*This document consolidates findings from five ICAO/FAA standards documents and maps them to the VATSWIM/PERTI architecture. It should be updated as the system evolves and additional ICAO standards are adopted.*
