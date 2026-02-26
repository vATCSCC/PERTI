# Frequently Asked Questions

This page addresses common questions about PERTI from various user perspectives.

---

## General Questions

### What is PERTI?

PERTI (Plan, Execute, Review, Train, and Improve) is a web-based traffic flow management platform designed for VATSIM. It provides tools for virtual air traffic controllers to manage traffic flow, monitor incidents, coordinate operations, and analyze demand.

### Who can use PERTI?

PERTI is designed for multiple audiences within VATSIM:
- **National TMU Personnel** - Traffic management initiatives and planning
- **Facility TMU** - Local traffic management and coordination
- **ATC Personnel** - Situational awareness and coordination
- **Division/Facility Management** - Oversight and planning
- **Virtual Special Operations** - Military and special mission coordination
- **Virtual Airline Operators** - Flight planning and delay information
- **VATSIM Supervisors** - Network oversight

### Is PERTI free to use?

Yes. PERTI is provided as a service to the VATSIM community. The source code is available under the MIT License.

### Do I need a VATSIM account?

For viewing public pages (JATOC, NOD), no account is required. For authenticated features (GDT, Route Plotter, Planning tools), you must log in with your VATSIM account.

---

## Access & Authentication

### How do I log in?

1. Navigate to https://perti.vatcscc.org
2. Click "Login" or access any authenticated page
3. You will be redirected to VATSIM Connect
4. Authorize PERTI to access your VATSIM account
5. You will be returned to PERTI, logged in

### What permissions are required?

Basic access requires only VATSIM authentication. Additional roles (DCC, TMU) are assigned by administrators for traffic management functions.

### I can view but not edit. Why?

Editing capabilities for TMIs, incidents, and advisories require DCC role assignment. Contact the vATCSCC administration if you believe you should have elevated access.

### My session expired. What happened?

Sessions expire after 24 hours of inactivity. Simply log in again via VATSIM Connect.

---

## Traffic Management Features

### What is a Ground Stop?

A Ground Stop (GS) holds all departures to a specific airport. In PERTI, you can:
- Create a proposed GS
- Model affected flights
- Activate the GS (issue EDCTs)
- Extend or purge as conditions change

### What is a GDP?

A Ground Delay Program (GDP) assigns Expect Departure Clearance Times (EDCTs) to flights when arrival demand exceeds capacity. Unlike a Ground Stop, flights still depart but at controlled intervals.

### How does demand analysis work?

PERTI calculates demand based on:
- Filed flight plans to an airport
- ETA calculations from current positions
- Historical data from similar events
- Weather conditions affecting capacity

The system suggests appropriate acceptance rates (AAR/ADR) based on current conditions.

### What are TMI scope tiers?

Ground Stops use tiers to define affected traffic:
- **Tier 1**: Flights within a certain distance/time
- **Tier 2**: Extended scope
- **Tier 3**: All flights to the destination

### What is Adaptive Compression?

Adaptive Compression (ADPT) automatically moves flights up to fill slots that would otherwise be wasted (e.g., when a flight cancels or substitutes). It helps maximize efficiency during GDPs and AFPs.

---

## JATOC (Incident Monitor)

### What incidents does JATOC track?

- **ATC Zero** - Complete suspension of ATC services
- **ATC Alert** - Degraded services with significant impact
- **ATC Limited** - Reduced capacity or services
- **Non-Responsive** - Communication issues

### How are operations levels defined?

| Level | Description |
|-------|-------------|
| 1 | Normal operations |
| 2 | Degraded operations |
| 3 | Severely impacted operations |

### Can anyone create incidents?

Viewing is public. Creating and updating incidents requires DCC role authorization.

---

## NOD (NAS Operations Dashboard)

### What does NOD display?

- Active Traffic Management Initiatives with rich data cards:
  - **GS cards**: countdown timer, flights held, prob extension, origin centers
  - **GDP cards**: controlled/exempt counts, avg/max delay, compliance bar, GDT link
  - **Reroute cards**: assigned/compliant counts, compliance bar
  - **MIT/AFP**: restriction details and fix coordinates
  - **Delay Reports**: severity coloring and trend indicators
- Map TMI status layer: airport rings by severity, delay glow circles, MIT fix markers
- Facility flow configurations with FEA demand integration
- DCC Advisories
- Current operations status
- Weather impacts

### What are Facility Flows? (NEW v18)

Facility Flow Configurations define traffic flow patterns for ATC facilities. Each config contains:
- **Elements**: Fixes, procedures, routes, and gates that define a flow pattern
- **Visual controls**: Color, line weight, visibility toggles per element
- **FEA Integration**: Elements can be linked to Flow Evaluation Areas for demand monitoring
- **Map layers**: 8 layer types including boundaries, procedure/route lines, fix markers

Flows are managed in the Flows tab of the NOD sidebar, with per-facility and per-config selectors.

### How often is NOD updated?

NOD data refreshes automatically every 30 seconds for active TMIs and every minute for other data.

---

## Route Plotter / TSD

### How do I plot a route?

1. Access Route Plotter at `/route.php`
2. Enter origin and destination airports
3. Enter route string (optional)
4. Click "Plot" to display on map

### What is a public route?

Public routes are shared route advisories visible to all users. They can be used for coordination during weather events or special operations.

### Does weather radar show real weather?

The weather radar displays NEXRAD/MRMS data from the Iowa Environmental Mesonet. This is real-world radar data, not VATSIM simulation weather.

---

## Traffic Management Review (TMR) - NEW v18

### What is a TMR Report?

A Traffic Management Review (TMR) report is a structured post-event analysis following the NTMO Guide format. It includes sections for:
- **Triggers** - What initiated the review
- **Overview** - Event summary
- **Airport Conditions** - Configuration, rates, weather
- **Weather** - Weather impacts and forecasts
- **TMIs** - Traffic management initiatives applied (with historical TMI lookup and bulk NTML paste parser)
- **Equipment** - System/equipment issues
- **Personnel** - Staffing considerations
- **Findings** - Conclusions and recommendations

### How do I create a TMR Report?

Navigate to the Review page and select "Create TMR Report" for an existing plan. The report auto-saves as you work, and can be exported in Discord format for sharing.

### What is the embedded demand chart?

TMR reports include per-airport demand charts (DemandChartCore) that visualize arrival/departure demand during the event period.

---

## Internationalization (i18n) - NEW v18

### Is PERTI available in other languages?

PERTI has a full i18n infrastructure with 450+ translation keys. Supported locales: `en-US` (full), `fr-CA` (near-complete), `en-CA` (overlay), `en-EU` (overlay). Overlay locales inherit from `en-US` and only override specific keys (e.g., terminology differences).

### How does locale detection work?

1. URL parameter (`?locale=en-US`)
2. localStorage (`PERTI_LOCALE`)
3. Browser language (`navigator.language`)
4. Fallback: `en-US`

---

## Playbook - NEW v18

### What is the Playbook?

The Playbook is a pre-coordinated route play catalog for traffic management. It stores collections of routes organized by scenario (weather, volume, construction) that can be quickly activated during events.

### Where do plays come from?

Plays originate from four sources:
- **FAA** — Imported from national playbook data
- **DCC** — Custom plays authored by the Command Center
- **ECFMP** — EUROCONTROL-style flow measures from European divisions
- **CANOC** — Canadian Network Operations Centre plays

### Can I share a play?

Yes. Use shareable links: `https://perti.vatcscc.org/playbook.php?play=PLAY_NAME`. When loaded, the page auto-selects and displays the referenced play.

### How do I add routes in bulk?

Use the **Bulk Paste** feature in the edit modal. Paste ECFMP or CANOC format route text, and the parser automatically structures the routes and detects the source format.

See [[Playbook]] for full documentation.

---

## Splits & Sectors - NEW v18

### What are splits?

Splits define how an ARTCC's airspace is divided into sectors. Each configuration assigns positions to sector areas and can be activated for events or scheduled to auto-activate at specific times.

### What is strata filtering?

Strata filtering lets you view sectors by altitude stratum: **low** (surface to FL230), **high** (FL230 to FL370), or **superhigh** (FL370+). This is controlled via checkboxes on the splits map.

### Which facilities are supported?

23 US ARTCCs and 7 Canadian FIRs (CZYZ, CZWG, CZEG, CZUL, CZVR, CZQM, CZQX) with 1,379 total sector boundaries. Additional international FIRs (Mexico, Caribbean) are supported but may have limited sector data.

### Can splits be scheduled?

Yes. Scheduled splits have start/end times (UTC) and are automatically activated/deactivated by the `scheduler_daemon.php`.

See [[Splits]] for full documentation.

---

## Technical Questions

### What browsers are supported?

PERTI supports modern browsers:
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

Internet Explorer is not supported.

### Why is flight data delayed?

PERTI receives data from VATSIM approximately every 15 seconds. Processing adds minimal latency. Total delay from real-time is typically 15-30 seconds.

### Can I access PERTI via API?

Yes. See [[API Reference]] for available endpoints. Most endpoints require authentication.

### Where is PERTI hosted?

PERTI runs on Microsoft Azure App Service (P1v2) with 7 databases across 3 engines:
- **Azure SQL** (vatsim.database.windows.net): VATSIM_ADL (Hyperscale Serverless), VATSIM_TMI, SWIM_API, VATSIM_REF, VATSIM_STATS
- **MySQL** (vatcscc-perti.mysql.database.azure.com): perti_site (General Purpose D2ds_v4)
- **PostgreSQL/PostGIS** (vatcscc-gis.postgres.database.azure.com): vatcscc_gis (Burstable B2s)

Total infrastructure cost is approximately $3,500/month. See the [transparency page](https://perti.vatcscc.org/transparency.php) for details.

---

## Data & Privacy

### What data does PERTI collect?

- VATSIM CID (from OAuth login)
- Name (from VATSIM profile)
- Session data (temporary)
- No additional personal information is collected

### How long is flight data retained?

- Current flight state: Updated every 15 seconds
- Historical snapshots: Retained for 30 days
- Trajectory data: Retained for 7 days

### Is my data shared?

Flight data visible in PERTI is derived from VATSIM's public data feed. No additional personal data is shared with third parties.

---

## Troubleshooting

### Pages are loading slowly

- Check your internet connection
- Try refreshing the page
- Clear browser cache
- Try a different browser

### Map is not displaying

- Ensure JavaScript is enabled
- Check browser console for errors
- Try disabling browser extensions
- Verify WebGL is supported

### Login isn't working

- Verify VATSIM Connect is operational
- Clear cookies and try again
- Try incognito/private browsing mode
- Contact support if issue persists

### Data appears outdated

- Refresh the page
- Check VATSIM API status
- Data may lag up to 30 seconds during high traffic

---

## Getting Help

### Where can I report bugs?

Report bugs on [GitHub Issues](https://github.com/vATCSCC/PERTI/issues) with:
- Description of the issue
- Steps to reproduce
- Expected behavior
- Screenshots if applicable

### How do I request a feature?

Open a GitHub Issue with the "feature request" label, describing:
- The feature you'd like
- The problem it solves
- Your use case

### Who maintains PERTI?

PERTI is maintained by the vATCSCC development team. For operational questions, contact division administration.

---

## Glossary

This is a quick reference. For comprehensive acronym definitions, see [[Acronyms]].

| Term | Definition |
|------|------------|
| **AAR** | Airport Arrival Rate - maximum arrivals per hour |
| **ADL** | Aggregate Demand List - flight data feed from TFMS containing schedules and NAS information |
| **ADR** | Airport Departure Rate - maximum departures per hour |
| **AFP** | Airspace Flow Program - controls departure times for flights to an FCA |
| **ARTA/ARTD** | Actual Runway Time of Arrival/Departure (formerly AGTA/AGTD) |
| **ARTCC** | Air Route Traffic Control Center |
| **CTA/CTD** | Controlled Time of Arrival/Departure |
| **DCC** | Command Center |
| **EDCT** | Expect Departure Clearance Time |
| **ETA/ETD** | Estimated Time of Arrival/Departure |
| **FCA** | Flow Constrained Area - airspace element used with AFPs |
| **FSM** | Flight Schedule Monitor |
| **GDP** | Ground Delay Program |
| **GS** | Ground Stop |
| **JATOC** | Joint Air Traffic Operations Command |
| **NOD** | NAS Operations Dashboard |
| **OOOI** | Out-Off-On-In (flight phase tracking) |
| **ORTA/ORTD** | Original Runway Time of Arrival/Departure (formerly OGTA/OGTD) |
| **SUA** | Special Use Airspace |
| **TFMS** | Traffic Flow Management System |
| **TFR** | Temporary Flight Restriction |
| **TMI** | Traffic Management Initiative |
| **TMU** | Traffic Management Unit |
| **TSD** | Traffic Situation Display |

---

## See Also

- [[Getting Started]] - Setup guide
- [[Acronyms]] - Complete acronym reference
- [[API Reference]] - API documentation
- [[Troubleshooting]] - Detailed troubleshooting guide
