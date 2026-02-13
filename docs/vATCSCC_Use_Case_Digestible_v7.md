# Why VATSIM Needs the Command Center
## Inside the Operations, the Data, and the Politics of vATCSCC

**Document Version:** 7.0
**Prepared For:** VATSIM Community — Pilots, Controllers, TMU Personnel, and Leadership
**Date:** February 13, 2026
**Approach:** Evidence-based analysis using operational records, planning thread transcripts, compliance data, and organizational policy

---

## Executive Summary

**What Is the Command Center?**
The Virtual Air Traffic Control System Command Center (vATCSCC) coordinates air traffic flow across multiple facilities during VATSIM events. In the real world, the FAA's ATCSCC in Warrenton, Virginia does this 24/7 with a dedicated staff of hundreds. On VATSIM, a small group of volunteers built a platform and a process to do the same thing — and the evidence shows it works.

This document is three things:

1. **An inside look at what the Command Center actually does** — not the org chart or the policy document, but the real Discord planning threads where facilities negotiate MIT packages, debate fix routing, analyze weather forecasts, coordinate ground stops, and review compliance data after events. If you've never seen what goes into a VATSIM event from the traffic management side, Section 2 will show you.

2. **A data-driven performance analysis** — TMI compliance measurements across 8 events in January-February 2026, showing what works, what doesn't, and where the gaps are. The numbers are honest: 66.5% average compliance against an 85-95% real-world standard. That gap is a problem — but it's a problem we can only fix because we can now measure it.

3. **A critical analysis of the organizational and political decisions** that have shaped the Command Center from its founding through its February 2026 degradation — examining the tension between stated mission, actual implementation, and the political dynamics within VATUSA that determine what the DCC can and cannot do.

**Current Status (February 13, 2026):**

| Fact | Detail |
|------|--------|
| **Platform operational since** | 2020, continuously enhanced through February 2026 |
| **PERTI plans created** | 233 events planned through the platform |
| **Flights tracked** | 780,967 in Jan-Feb 2026 alone (15-second updates, 24/7) |
| **TMI activity** | 5,288 entries logged, 152 programs, 1,019 advisories (25 facilities requesting) |
| **Infrastructure cost** | ~$3,640/month (January 2026 actual) |
| **Development labor** | 2,000+ hours, 100% volunteer, $0 cost |
| **Average TMI Compliance** | 66.5% across 6 measurable events (real-world: 85-95%) |
| **February 12, 2026** | Additional personnel removed from DCC direction; PERTI platform access suspended by VATUSA leadership |

**What February 2026 Changed:**
- TMI automation lost (online form → NTML and Advisory workflows now inaccessible)
- PERTI website access removed — all operational tools and data visibility suspended
- Infrastructure still running (~$3,640/month) — data collected but no authorized human can use it
- The 66.5% compliance problem can no longer be measured, monitored, or improved through the tools that revealed it

---

## Table of Contents

**Part I — Understanding the Command Center**
1. [Command Center Basics (For Non-TMU Personnel)](#section-1)
2. [Inside the Command Center: Real Planning in Action](#section-2)

**Part II — The Evidence**
3. [Real Performance Data: 8 Recent Events](#section-3)
4. [Infrastructure & Costs](#section-4)

**Part III — The Organizational Story**
5. [Organizational Evolution: 2020 → 2026](#section-5)
6. [February 2026: What Was Lost and What It Means](#section-6)
7. [The Fundamental Tension: Mission vs. Structure vs. Politics](#section-7)

**Part IV — Analysis & Recommendations**
8. [VATUSA5 Consolidation: Benefits & Risks](#section-8)
9. [Platform Enhancements (November 2025+)](#section-9)
10. [What Happens Without the Command Center](#section-10)
11. [Recommendations](#section-11)
12. [Strategic Perspective: Balancing TMU Need, Staffing, and Empowerment](#section-12)

**Part V — Conclusion**
13. [Conclusion](#section-13)

---

# Part I — Understanding the Command Center

<a name="section-1"></a>
## 1. Command Center Basics (For Non-TMU Personnel)

### 1.1 What Problem Does It Solve?

**Scenario: Boston Friday Night Operations (FNO)**

200 pilots want to fly to Boston (KBOS) between 2300-0400z. The traffic crosses through 4 facilities:
- **New York Center (ZNY)** - Manages high-altitude traffic
- **Boston Center (ZBW)** - Hands off to Boston approach
- **Boston TRACON (A90)** - Sequences arrivals to runway
- **Boston Tower (BOS)** - Final runway clearance

**WITHOUT Command Center:**
Each facility independently creates restrictions:
- ZNY: "30 miles between all Boston arrivals" (their solution to prevent congestion)
- ZBW: "25 miles between arrivals" (their own restriction, unaware of ZNY's)
- A90: "Hold departures for 15 minutes" (trying to reduce arrival push)

Result: Restrictions STACK — **60-90 minute delays**

**WITH Command Center:**
Single coordinated plan:
- "20 miles spacing ZNY → BOS"
- All facilities use same restriction
- Predictable flow, manageable workload

Result: **15-25 minute delays** (60-70% reduction)

### 1.2 Key Terms Glossary

| Term | Meaning | Example |
|------|---------|---------|
| **MIT** (Miles-in-Trail) | Required spacing between aircraft | "20 MIT" = keep 20+ miles apart |
| **TMI** (Traffic Management Initiative) | Any restriction to manage flow | MIT, ground delays, reroutes |
| **GDP** (Ground Delay Program) | Systematic pre-departure delays | Assign slot times before takeoff |
| **Ground Stop** | Temporary hold on departures | Like a red light — no departures allowed |
| **AAR** (Airport Arrival Rate) | Max arrivals per hour | KBOS AAR 40 = max 40 arrivals/hour |
| **NTML** (National Traffic Management Log) | Official record of TMI actions | Timestamped log of all restrictions |
| **Compliance** | How well rules are followed | 75% = 3 out of 4 followed the rule |
| **PERTI** | Plan, Execute, Review, Train, Improve | Command Center methodology |
| **FNO** | Friday Night Operations | VATSIM's primary event format |
| **SNO** | Saturday Night Operations | Weekend event variant |
| **NTMO** | National Traffic Management Officer | DCC operational staff |
| **NOM** | National Operations Manager | Senior DCC duty officer |
| **FIX** | A named point in airspace | LENDY, BEUTY, DEPDY — where spacing is measured |
| **STAR** | Standard Terminal Arrival Route | Published arrival path to an airport |
| **CFR** | Call For Release | Facility must call before releasing a departure |
| **ACE Team** | Additional Controller on Event | Extra controllers deployed where needed |

### 1.3 What the Command Center Does

**Before Events (PLAN):**
- Analyzes expected traffic (how many flights to each airport?)
- Calculates capacity (how many can each airport handle?)
- Creates coordinated restrictions all facilities will use
- Publishes routes and procedures in advance

**During Events (EXECUTE):**
- Monitors 2,000-6,000 aircraft in real-time (15-second updates)
- Adjusts restrictions when weather or demand changes
- Resolves conflicts between facilities
- Deploys extra controllers (ACE Team) where needed
- Issues ground stops and GDPs when airports are overwhelmed

**After Events (REVIEW):**
- Measures TMI compliance (how well were restrictions followed?)
- Publishes Traffic Management Reviews (TMRs)
- Identifies improvements for next event
- Feeds lessons learned back into the planning process

---

<a name="section-2"></a>
## 2. Inside the Command Center: Real Planning in Action

*This section uses direct evidence from DCC planning threads — real conversations between real people coordinating real events. Names are included because these are public VATSIM roles, not private communications. The goal is to show what the Command Center actually does, not in theory but in practice.*

### 2.1 It Starts With a Plan

Every VATSIM event that involves traffic management begins the same way: a PERTI Data Request goes out in a DCC planning thread, usually 1-3 weeks before the event.

Here's a typical opening (Northeast Corridor FNO, Plan 228):

> **Michael B | VATUSA5** — 01/16/2026
> Northeast Corridor FNO | TMU OpLevel 3 | PERTI Data Request
>
> Review and fill out the PERTI Plan:
> PERTI Plan | Staffing Data | Field Configs (VATSIM-applied AAR) | DCC Dashboard
>
> ZBW: @Cameron P | ZBW EC
> ZDC: @Carson B | ZDC EC
> ZNY: @Daniel | ZNY EC
> ZOB: @Jonathan B | ZOB EC
> ZJX: @Patrick M | ZJX EC
> ZTL: @Meg B | ZTL EC
> ZHU: @Dean V | ZHU DATM
> ZMA: @Ryan A | ZMA EC

Each tagged person is an Event Coordinator (EC) or facility representative responsible for their airspace. They are being asked to do three things:
1. Report their facility's expected staffing level
2. Develop traffic management initiatives (TMIs) for traffic entering their airspace
3. Coordinate those TMIs with adjacent facilities

**For pilots:** This is why you see specific routes in SimBrief and specific restrictions during events — they were negotiated in threads like this, weeks in advance.

**For new controllers:** The "MIT" restrictions you hear about during events aren't arbitrary. Each one represents a calculated decision about how much traffic a sector can handle.

### 2.2 The Art of TMI Negotiation

The most revealing part of DCC planning is the negotiation between facilities. Each facility knows its own airspace — its sectors, its STARs, its merge points, its capacity limits. TMIs are where that knowledge gets translated into concrete restrictions.

**Example: Ski Country Sunday (Plan 221) — Fix-Level Routing Debate**

ZDV (Denver Center) and ZLC (Salt Lake City Center) are coordinating arrivals into mountain ski airports. Watch the level of detail:

> **Evan M | ZDV ATM** — 12/11/2025
> ASE needs to enter ZDV via HYPPE, SLAPE, or ERRDA so they can be on the LOYYD1.
> EGE needs to enter ZDV via OCS CHE RLG
>
> **Cameron N | ZLC ATM** — 12/11/2025
> ALPIN4 BPI OCS J163 CHE — That is the JAC/EGE route
>
> **Evan M | ZDV ATM**
> Need the RLG in there. It's an IAF and keeps them out of a different center's airspace. The final fix for EGE arrivals kinda need to be RLG or DUCES if they are coming from ZLC. Works for airspace and all IAFs.

This is two facility managers debating which specific navigation fix should be the handoff point, because one fix puts traffic through three sectors in 50 miles while another keeps it in two. This level of precision matters — it determines controller workload, pilot route length, and whether the traffic flow actually works.

Later in the same thread, they negotiate tunnel agreements (altitude restrictions to keep traffic in specific sectors), STAR assignments per departure airport, and SimBrief route coordination:

> **Cameron N | ZLC ATM**
> JAC-ASE: ALPIN4 BPI HYPPE LOYYD1
> BZN-ASE: BGSKY2 DBS HYPPE LOYYD1
> SUN-ASE: FLYIN1 GDWIN HYPPE LOYYD1
> JAC-EGE: ALPIN4 BPI OCS J163 CHE RLG
> BZN-EGE: BOBKT5 BOY CKW CHE RLG

This is not a simple "30 MIT." This is origin-destination-specific routing for 5 departure airports to 3 arrival airports, with fix-by-fix detail. And it was negotiated between two people in a Discord thread.

**Example: Stuff the Albu-Turkey (Plan 215) — Sector Workload Feedback**

ZAB (Albuquerque Center) posts their MIT package for PHX arrivals. ZAB TMU catches a problem:

> **Justin L | ZAB C3** — 11/20/2025
> I'm sorry I bring this up every time but can we please get the ESTWD, GABBL AS ONE switched to like 20 (or whatever you need) PER STREAM. ESTWD and GABBL are worked by 2 different sectors that are gonna be busy with PHX arrivals and it would take a lot of extra coordination between the two. It would be the equivalent of us asking for SCOLE and HOGGZ AS ONE and you having 2 separate sectors working each stream.
>
> **Brody B | ZLA EC** — 11/20/2025
> totally gotcha, will write something new up later today
> thanks for lmk

"AS ONE" means aircraft from both fixes are sequenced together. "PER STREAM" means each fix is sequenced independently. The difference is operational: if ESTWD and GABBL are worked by different sectors, AS ONE requires those sectors to coordinate every handoff. PER STREAM lets each sector manage its own flow independently. Justin knows this because he works that airspace. Without this feedback, the TMI would have created unnecessary coordination overhead during the event.

**For controllers:** This is why TMIs sometimes change between the planning thread and event night. The TMU is adjusting based on real operational feedback from the people who work the sectors.

### 2.3 Weather: The Variable Nobody Controls

Real-world ATC is fundamentally shaped by weather. The VATSIM Command Center treats weather with the same seriousness, and the planning threads show surprising meteorological sophistication.

**Example: Stuff the Albu-Turkey (Plan 215) — Ensemble Forecast Analysis**

Five days before the event, Cameron (ZLC ATM) identifies a potential weather problem:

> **Cameron N | ZLC ATM** — 11/16/2025
> Yeah, PHX will be running ILS I think. We are playing the cutoff low roulette though. Doesn't hurt to look at an IMC plan. I think either way someone loses. EC solution, PHX is IMC. GFS solution Socal is E flow. Pick your poison.
>
> For reference. Clusters 1, 2, & 4 are PHX IMC solutions, cluster 3 is LAX E solution.

He's analyzing ensemble forecast clusters — multiple weather model runs grouped by outcome — to identify the probability distribution of weather impacts. This is standard practice at real-world NWS Weather Forecast Offices, applied here to virtual ATC planning.

Jeremy (ZNY C1) responds with professional forecast reasoning:

> **Jeremy P | ZNY C1** — 11/16/2025
> 5 days out still? I just say wait and see. I haven't been watching but it looks like that Canadian maritimes low proves influential and idk if it's even made it onshore from the Pacific yet into good observation availability coverage.

The decision: plan for both scenarios, finalize 24 hours before the event when higher-resolution models (NBM) become available. This is the same approach real-world TMUs use.

### 2.4 The International Dimension

VATSIM events regularly cross international boundaries, requiring coordination with Canadian (VATCAN) facilities that operate under different organizational structures.

**Example: Rock Around the Clock — Cleveland 24h (Plan 213)**

ZOB is hosting a Cleveland SNO. Arya (ZOB/ZME DATM) reaches out to Toronto Centre (ZYZ):

> **Arya C | ZME DATM** — 11/06/2025
> @Matt H | I1 ZYZ is not essential but if you're on, it would be much appreciated. I understand there's an OTS going on for you guys at a similar time so I get if you're busy.

Later, he establishes a specific routing for Canadian traffic:

> CLE via TRYBE STOP VOLUME:VOLUME 2359-0400 ZOB:ZYZ
> CLE via DOZRR 30MIT VOLUME:VOLUME 2359-0400 ZOB:ZYZ

A **STOP** on TRYBE means no Canadian traffic uses that fix — it's at capacity from US traffic. All YYZ departures must route via DOZRR instead. This kind of cross-border restriction requires coordination across two different VATSIM divisions with different staffing models and different organizational authorities.

**Example: Home for the Holidays (Plan 220) — BOS Reroute**

Arya coordinates a Boston-to-Chicago reroute that crosses Canadian airspace:

> **Arya C | ZME DATM** — 12/15/2025
> @Cameron P | ZBW EC if there are plans for ZBW (or even the Boston cab) to be on, could we consider rerouting all BOS-ORD to not go via ZYZ? It makes the merge quite awkward with others on the WYNDE#. The different segment would be something like CAM Q822 GONZZ FARGN CHAAP Q436 KAYYS WYNDE3 instead of the preferred CAM Q822 FNT WYNDE3.

This is rerouting traffic away from Canadian airspace to solve a merge problem on a US STAR. It requires coordination with ZBW (Boston), ZYZ (Toronto), and ZAU (Chicago) simultaneously.

### 2.5 Event Night: Real-Time Decisions

Planning threads don't end when the event starts — they become real-time coordination channels.

**Example: Home for the Holidays (Plan 220) — Ground Stop with Data**

Mid-event, traffic to ORD exceeds capacity. A ground stop is issued through the GDT (Ground Delay Tools), and the flight list is posted directly in the planning thread:

> **Jeremy P | ZNY C1** — 12/19/2025
> vATCSCC ADVZY 002 ZAU/ORD 12/20/2025 CDM GROUND STOP
> CTL ELEMENT: ORD
> GROUND STOP PERIOD: 20/0135Z - 20/0200Z
> DEP FACILITIES INCLUDED: ZOB/ZID/ZMP/ZKC/ZAU
>
> GS FLIGHT LIST - 20/0140Z
> Total Flights: 7 | Total Delay: 80 min | Max Delay: 20 min | Avg Delay: 11 min
>
> ACID      ORIG  DEST  OETD      CTD       DELAY
> UAL1182   KCMH  KORD  20/0140Z  20/0200Z  20
> UAL3931   KCLE  KORD  20/0145Z  20/0200Z  15
> ...

This is the GDT generating a structured flight list with calculated controlled departure times (CTDs). Each affected flight gets a specific new departure time. Without this tool, the ground stop would be announced with no individual flight tracking — controllers would have to manually identify and hold each departure.

Jonathan (ZOB EC) immediately notices an issue:

> **Jonathan B | ZOB EC** — 12/19/2025
> Does that only show for aircraft at the gate?
>
> **Jeremy P | ZNY C1**
> Should only be for aircraft not yet departed
>
> **Jonathan B | ZOB EC**
> Ah, it's not showing one I have is why I was curious — UAL623

A tool bug is identified in real-time, during the event. Jeremy investigates and fixes the ETD calculation issue. This is the PERTI cycle (Plan, Execute, Review, Train, Improve) happening in minutes, not months.

**Example: New Year, New York (Plan 222) — Sector Saturation**

During the event, ZOB hits saturation:

> **Jonathan B | ZOB EC** — 01/02/2026
> ZOB got slammed by C90, that combined with D21 caused saturation
>
> **Arya C | ZME DATM**
> Honestly the issue wasn't that they were difficult to do the passback for 66/77, even if they had been sequenced it wouldn't have helped. Just a lot of planes so sector saturation.
>
> **Jonathan B | ZOB EC**
> Right, I wasn't saying C90 screwed us... was just saying there was a lot of traffic from C90 combined with everything else

This is professional debriefing happening in real-time. No blame — just identification of what happened and why. The MIT was being followed; the problem was raw volume exceeding sector capacity. Different problem, different solution.

### 2.6 After the Event: The Review That Drives Improvement

The PERTI cycle doesn't end when the event closes. Post-event review is where data meets operational experience.

**Example: Escape to the Desert (Plan 226) — Compliance Spot-Check**

After the TMI Compliance Analysis tool was run on the Escape to the Desert event, Ken G (ZOA DATM) did something extraordinary — he manually spot-checked the tool's results against VATSIM Replay data:

> **Ken G | ZOA DATM** — 01/31/2026
> A few things I've observed just from spot checking some of the red pairs:
>
> UAL751 -> DAL1690: Tool reports 9.5nm but the a/c are never within 10nm of each other as merging.
> SWA805 -> DAL305: Tool reports 1.2nm but these two aren't even next to each other in the stream, there's 4 planes in the middle.
> DAL305 -> UAL1583: Tool reports 0.1nm but like above, these two aren't even next to each other.
> UPS75 -> PHX70: I don't even see a PHX70 in the replay data.
>
> All that being said, a) this is very cool, b) I can understand this being quite challenging to do perfectly especially once we start vectoring for sequence and space, c) I think it can be useful even if it's not perfect.

This is the Review phase working exactly as designed. A facility manager with operational experience validates the tool's output, identifies specific measurement issues, and provides constructive feedback — while affirming the tool's value. The bugs Ken found were fixed, making the tool more accurate for future events.

**Example: ZDV Live 2025 (Plan 175) — Expert TMR Feedback**

Blake R (ZSE C3), who served as dedicated TMU for this event, provided detailed post-event analysis:

> **Blake R | ZSE C3** — 08/10/2025
> I wanted to dig deeper to see the effect of the adjusted MIT from ZAB (from 10 MIT as-one to 15 MIT as-one). From the increased MIT, 15 MIT per stream to 15 MIT as-one, the following pairs were significantly closer than would be expected with 15 MIT as-one [...] That's 4 violations out of the 6 pairs during that time.
>
> The timeline is going to be really beneficial. For this event, it would've been nice to see on the timeline where the MIT changed from "as one" to "per stream" and vice-versa and the changing MIT values — this would better help evaluate the effects.

Blake is providing detailed operational feedback that directly informs tool development. He identifies a feature gap (TMI change visualization on the timeline) and provides the use case for why it matters. This is the Improve phase feeding back into Plan.

**Example: Rock Around the Clock (Plan 213) — AAR Discovery**

After the Cleveland event, the demand data reveals something the facility didn't know:

> **Arya C | ZME DATM** — 11/16/2025
> That's great. So 40 takes into account the 24R/24L stagger which we didn't do. The real AAR in that config is like 32 lol — even happier with that.

The published AAR of 40 assumed staggered parallel approaches. The actual operation didn't use staggering, meaning the real capacity was ~32. The demand data showed they served close to 40 anyway — meaning the event was more successful than they thought. Without the data, they would never have known.

### 2.7 Learning in Real-Time

Perhaps the most important function of the DCC planning process is knowledge transfer. VATSIM is a volunteer network — people join, learn, and eventually move on. The planning threads are where institutional knowledge lives.

**Example: Ski Country Sunday (Plan 221) — Learning TMI Creation**

Evan (ZDV ATM) is relatively new to TMI creation:

> **Evan M | ZDV ATM** — 12/11/2025
> Here's initial MIT thoughts. I haven't had much experience creating them before so feel free to critique it and question it.
>
> ASE via HYPPE,ERDDA,SLAPE 35 MIT VOLUME:VOLUME 2000-2300 ZDV:ZLC
> ASE via TRUEL,EKR 30 MIT VOLUME:VOLUME 2000-2300 ZDV:ZLC
> ...

Cameron reviews, questions, and refines. By the end of the thread, Evan has created a professional-grade MIT package with origin-destination routing, tunnel agreements, and STAR coordination. He learned by doing, with peer guidance — not from a manual.

**Example: Home for the Holidays (Plan 220) — "NO COMP" Explained**

Kevin (ZAU EC) introduces an advanced TMI concept:

> **Kevin H | ZAU EC** — 12/16/2025
> ORD via WYNDE STAR 20 MIT PER ROUTE (10 MIT NO COMP)
>
> **Arya C | ZME DATM**
> 10 MIT NO COMP means minimum of 10 as one yeah?
>
> **Kevin H | ZAU EC**
> Stream A and B independently 20 MIT. But if there is no traffic or a gap in stream B, stream A may deliver as low as 10 MIT if there is no conflict. The difference between that and 10 MIT AS ONE is A, B 10 MIT AS ONE means A and B needs to be sequenced together. Subtle difference but after many attempts of getting it explained to me I think I understand it lol.
>
> **Arya C | ZME DATM**
> I felt bad for not knowing but if USA5 doesn't know I'll let myself off
>
> **Michael B | VATUSA5**
> I'm well aware there are greater TMU gods than me lol don't sweat it

This exchange teaches a nuanced TMI concept to multiple people simultaneously, in a public channel where future NTMOs can reference it. Note the self-deprecating humor — this is a volunteer community where even the Events Manager learns from facility TMUs.

### 2.8 What This Section Proves

The Discord planning threads demonstrate that Command Center coordination is not a bureaucratic formality. It is:

1. **Technically sophisticated** — Fix-level routing, STAR selection, tunnel agreements, ensemble weather analysis, sector workload calculation
2. **Collaborative** — Facilities negotiate, provide feedback, adjust plans based on operational reality
3. **Adaptive** — Plans change in real-time based on actual conditions during events
4. **Self-improving** — Post-event data drives tool refinement and process improvement
5. **Educational** — Knowledge transfers from experienced TMUs to new coordinators through practice
6. **International** — Cross-border coordination with Canadian facilities requires structured frameworks
7. **Data-driven** — AAR/ADR analysis, demand forecasts, compliance measurements inform decisions

**For pilots:** Every route you're assigned, every restriction you encounter, every ground stop you're held for — it was debated, calculated, and coordinated by real people in threads like these. The quality of your event experience is directly proportional to the quality of this coordination.

**For controllers:** The TMIs you implement aren't arbitrary numbers from a faceless authority. They're negotiated between people who understand your sectors. When a TMI doesn't work operationally, the planning thread is where you say so — and it gets fixed.

**For VATSIM leadership:** This is what you fund, what you staff, and what you're deciding whether to continue supporting. The question isn't whether it has value — it demonstrably does. The question is whether the organizational decisions being made reflect that value.

---

# Part II — The Evidence

<a name="section-3"></a>
## 3. Real Performance Data: 8 Recent Events (January-February 2026)

### 3.1 Overview Statistics

**TMI Compliance Analysis Results** (from `tmi_compliance_results_*.json` on production):

| Metric | Average (6 events) | Weighted | Range | Real-World Standard | Gap |
|--------|---------------------|----------|-------|---------------------|-----|
| **Overall Compliance** | **66.5%** | — | 56.9% - 86.3% | 85-95% | **-19 to -29 pts** |
| **MIT (Spacing)** | **68.4%** | by pairs (n=1,963) | 55.9% - 93.5% | 85-95% | **-17 to -27 pts** |
| **Ground Stop** | **63.7%** | by flights (n=157) | 33.3% - 100% | 95-99% | **-31 pts** |
| **Reroutes** | **100%** | — | 100% - 100% | 98-100% | **+2 pts** |

*Averages include 6 events with measurable TMI activity (excludes Plan 227 with no TMI enforcement and Plan 223 with no MIT pairs). Weighted figures use total analyzed pairs/flights as denominator.*

**Only 2 of 8 events** exceeded 70% overall compliance.

### 3.2 Event-by-Event Details

#### Plan 223 - New Year, Nashville (January 3-4, 2026)
**Event:** KBNA | **Network Flights:** ~4,932 active | **TMI Entries:** 12
**Overall:** 71.4% | **MIT:** N/A (0 analyzable pairs) | **GS:** 42.9% (4 violations / 7 flights)
**Note:** Small GS sample; MIT fixes (LENSE, BAMMA, GROAT, ULTRA, RANTS, CHSNE) had insufficient crossings for analysis
**Planning context:** Eric (ZME EC) posted preliminary TMIs 2 days before. Hayden (ZID ATM) added ZID MIT and a SDF-BNA CFR. Eric published fallback reroute options if demand exceeded capacity.

#### Plan 225 - Honoring the Dream FNO (January 16-17, 2026)
**Event:** KATL | **New Flights:** 1,805 | **Network Flights:** ~11,365 active | **TMI Entries:** 7
**Overall:** 86.3% | **MIT:** 72.6% (20 violations / 73 pairs) | **GS:** 100% (0 violations)
**Grade:** Best overall compliance
**Notable fixes:** CHPPR (10nm) 92.3%, ONDRE (10nm) 66.7%, OZZZI (10nm) 75.0%

#### Plan 226 - Escape to the Desert SNO (January 17-18, 2026)
**Event:** KLAS/KVGT/KHND | **New Flights:** 1,768 | **Network Flights:** ~11,494 active | **TMI Entries:** 9
**Overall:** 63.4% | **MIT:** 93.5% (3 violations / 46 pairs) | **GS:** 33.3% (2 violations / 3 flights)
**Note:** MIT compliance was strong; low overall score driven by ground stop violations. GS issued mid-event (02:44Z) for NCT KLAS, 2 flights departed during active stop.
**Planning context:** Brody (ZLA EC) posted MIT packages 2 weeks early. 5 facilities coordinated per-stream restrictions. Post-event, Ken G (ZOA DATM) validated compliance results against VATSIM Replay, identifying measurement improvements.

#### Plan 227 - Rain in Rose City SNO (January 24-25, 2026)
**Event:** KPDX | **New Flights:** 1,762 | **Network Flights:** ~11,822 active | **TMI Entries:** 0
**Overall:** 100% | **MIT:** N/A | **GS:** N/A
**Note:** No TMI activity during this event; 100% reflects absence of restrictions rather than compliance performance. Excluded from averages.

#### Plan 228 - Northeast Corridor FNO (January 30-31, 2026)
**Event:** Multiple NE airports | **New Flights:** 2,796 | **Network Flights:** ~12,440 active | **TMI Entries:** 60
**Overall:** 75.2% | **MIT:** 75.5% (267 violations / 1,088 pairs) | **GS:** 69.6% (17 violations / 56 flights) | **Reroutes:** 100% (3 mandatory, all compliant)
**Grade:** Highest compliance among large-scale events
**Notable:** Largest event analyzed (1,088 MIT pairs). DEPDY fix had worst performance at 41.9% (25 violations / 43 pairs). BEUTY at 81.5%.
**TMI scope:** 60 NTML entries spanning BOS, DCA, JFK, LGA, MCO, MIA, FLL, PBI across ZBW, ZNY, ZDC, ZOB, ZJX, ZMA, ZTL, ZHU
**Planning context:** Most extensively coordinated event in the dataset — 8+ facilities, 60+ TMI entries, GS operations, mandatory reroutes. Post-event compliance analysis was discussed in detail in the planning thread.

#### Plan 229 - All Aboard The Brightline SNO (January 31 - February 1, 2026)
**Event:** KMCO/KPBI/KFLL/KMIA | **New Flights:** 1,677 | **Network Flights:** ~10,851 active | **TMI Entries:** 1 (cancellation only)
**Overall:** 56.9% | **MIT:** 56.9% (110 violations / 255 pairs) | **GS:** N/A
**Grade:** Second-worst compliance
**Notable:** FROGZ fix at 39.0% (36 violations / 59 pairs), BNFSH at 31.1% (31/45), CSTAL at 42.4% (19/33). GRNCH was the bright spot at 94.7%.

#### Plan 233 - Hail Mary in the Bay (February 7-8, 2026)
**Event:** KSFO/KSJC | **New Flights:** 1,838 | **Network Flights:** ~8,063 active | **TMI Entries:** 9 | **GS Program:** SJC ground stop
**Overall:** 57.0% | **MIT:** 55.9% (212 violations / 481 pairs) | **GS:** 62.6% (34 violations / 91 flights)
**Grade:** Worst combined MIT + GS compliance
**Impact:** 37% of ground stop flights departed when they shouldn't have. MDOWS fix at 45.0%, KNGRY at 55.0%.
**Planning context:** NCT:ZOA and ZOA:ZLC/ZLA/ZSE coordination, SJC ground stop with per-fix compliance breakdown (STUBL 96.3% vs RUSME 37.7%).

#### Plan 234 - YVR Real OPS (February 1-2, 2026)
**Event:** CYVR | **New Flights:** 2,403 | **Network Flights:** ~12,095 active | **TMI Entries:** 8
**Overall:** 60.0% | **MIT:** 60.0% (8 violations / 20 pairs) | **GS:** N/A
**Note:** Smaller MIT sample size (20 pairs). EGRET fix (25nm) was only analyzable fix at 60.0%; NADPI/NOVAR had insufficient crossings.

### 3.2.1 Summary Table

| Plan | Event Name | Date | Flights | TMI Entries | Overall | MIT % | MIT Pairs | GS % | GS Flights |
|------|-----------|------|---------|-------------|---------|-------|-----------|------|------------|
| 223 | New Year, Nashville | Jan 3-4 | 4,932 | 12 | 71.4% | N/A | 0 | 42.9% | 7 |
| 225 | Honoring the Dream FNO | Jan 16-17 | 11,365 | 7 | **86.3%** | 72.6% | 73 | 100% | 0 |
| 226 | Escape to the Desert SNO | Jan 17-18 | 11,494 | 9 | 63.4% | 93.5% | 46 | 33.3% | 3 |
| 227 | Rain in Rose City SNO | Jan 24-25 | 11,822 | 0 | 100%* | N/A | 0 | N/A | 0 |
| 228 | Northeast Corridor FNO | Jan 30-31 | 12,440 | 60 | 75.2% | 75.5% | 1,088 | 69.6% | 56 |
| 229 | All Aboard The Brightline SNO | Jan 31-Feb 1 | 10,851 | 1 | **56.9%** | 56.9% | 255 | N/A | 0 |
| 233 | Hail Mary in the Bay | Feb 7-8 | 8,063 | 9 | 57.0% | 55.9% | 481 | 62.6% | 91 |
| 234 | YVR Real OPS | Feb 1-2 | 12,095 | 8 | 60.0% | 60.0% | 20 | N/A | 0 |

*Plan 227 excluded from averages — no TMI enforcement occurred.*
**Totals:** 1,963 MIT pairs analyzed, 620 violations (68.4% weighted). 157 GS flights, 57 violations (63.7% weighted).

### 3.3 What This Means for Pilots & Controllers

**When Aircraft Don't Maintain Spacing (MIT Violations):**

For **Pilots:**
- More vectors: "Turn 20 degrees right for spacing" = longer flight path
- Speed restrictions: "Reduce speed to 210 knots" = slower arrival
- Altitude changes: "Descend and maintain 7,000" instead of direct descent
- Result: **5-15 minutes added to flight time PER violation**

For **Controllers:**
- Increased workload issuing corrections
- Sector saturation (too many aircraft to manage)
- Reduced capacity (can't accept new aircraft)
- Result: **Controller burnout, service degradation**

**When Flights Violate Ground Stops:**

**Example (Plan 233 - 37% GS violation rate):**
- Command Center issues ground stop for SJC (weather reduced capacity to 30/hour)
- 91 flights should hold on ground
- **34 flights departed anyway**
- Those 34 aircraft arrive expecting to land, but SJC can't handle them
- Result: **Airborne holding (20-40 min), go-arounds, diversions**

### 3.4 Why Is Compliance So Low?

**Root Cause Analysis:**

1. **Communication Breakdown (40% of problem):**
   - Facilities not monitoring NTML (National Traffic Management Log)
   - Discord TMI notifications missed or ignored
   - No automated alerts in vNAS/EuroScope

2. **Knowledge Gap (30% of problem):**
   - Controllers unaware TMIs exist for their traffic
   - Pilots file flight plans without checking restrictions
   - Training doesn't emphasize TMU compliance

3. **System Limitations (20% of problem):**
   - No automated enforcement (unlike real-world EDCT system)
   - Flight plan validation doesn't check TMIs
   - Controllers can clear aircraft despite restrictions

4. **Organizational Structure (10% of problem):**
   - No dedicated operational voice for TMU (USA9 eliminated early 2023)
   - VATUSA5 manages Events + DCC + Virtual Airlines — TMU may not get priority
   - NTMO pool lacks continuous coordination authority

### 3.5 Coordination Breadth: Who's Actually Using This?

Database records from January-February 2026 show **25 different facilities** issued TMI requests through the system:

| Facility Type | Facilities | Count |
|---------------|-----------|-------|
| **ARTCC (Centers)** | ZDC (41), ZOA (38), ZOB (26), ZBW (24), ZNY (24), ZSE (18), ZLA (12), ZTL (12), ZME (11), ZJX (10), ZMA (9), ZAU (9) | 12 centers |
| **TRACON/Approach** | PCT (23), N90 (16), NCT (13), RDU (7), F11 (6), C90 (5), P80 (4), A80 (4), A90 (3), BNA (2), L30 (1) | 11 TRACONs |

*Source: VATSIM_TMI `tmi_entries` table, `requesting_facility` column, January-February 2026. Numbers in parentheses are TMI entry counts per facility.*

This is not a system used by 2-3 facilities. **23 facilities** across the NAS actively requested traffic management restrictions through the platform in a 6-week period. The breadth of adoption reflects the operational need documented in the planning threads — facilities at every level, from major TRACONs like N90 (New York) and PCT (Potomac) to individual towers, are participating in the coordinated flow management process.

**For context:** VATUSA has ~22 ARTCC subdivisions. More than half of them issued TMI requests in January-February alone. Add TRACONs and the coordination network spans coast to coast.

### 3.6 What the Data Proves

**Three Critical Insights:**

1. **We NEED the Command Center** — Even 66% coordination is vastly better than zero coordination
2. **Current methods AREN'T WORKING** — 19-29 point gap to real-world standards across 1,963 analyzed pairs
3. **We need BETTER tools AND leadership** — Technology improvements + dedicated advocacy required

**The TMI Compliance Analysis tool (added Nov 2025) is valuable:**
- Before: NO visibility into compliance problems
- Now: Data-driven measurement across 8 events, 1,963 MIT pairs, 157 GS flights
- This proves platform investment is working — we now have the data to drive improvement

---

<a name="section-4"></a>
## 4. Infrastructure & Costs: Where Does $3,500/Month Go?

### 4.1 Monthly Cost Breakdown

**Total: ~$3,500/month (~$43,700/year at current rate)**

| Component | Cost/Month | % of Total | Purpose |
|-----------|-----------|------------|---------|
| **VATSIM_ADL** (Hyperscale Compute) | ~$2,900 | **80%** | Real-time flight tracking (Serverless Gen5, 16 vCores, min 3) |
| **VATSIM_ADL** (Storage + HA Replica) | ~$300 | 8% | Hyperscale storage and high-availability |
| MySQL Database | ~$125 | 3% | Web app data — plans, users, reviews (General Purpose D2ds_v4) |
| App Service (P1v2) | ~$80 | 2% | PHP workers + 14 background daemons (3.5 GB RAM, 1 vCPU) |
| PostgreSQL + PostGIS | ~$55 | 2% | Spatial queries — routes, boundaries (Burstable B2s, 32 GB) |
| Basic SQL Databases (3x) | ~$15 | <1% | TMI ($5), SWIM ($5), REF ($5) |
| Storage + Other | ~$15 | <1% | Blob archives, Data Factory, Logic Apps |
| **Monthly Total** | **~$3,500** | 100% | |

*Source: [PERTI Transparency Page](https://perti.vatcscc.org/transparency) — data verified February 2026.*

#### Actual Monthly Billing History

| Month | SQL Database | App Service | MySQL | Other | **Total** |
|-------|-------------|-------------|-------|-------|-----------|
| Oct 2025 | $536 | $82 | $15 | $51 | **$684** |
| Nov 2025 | $524 | $80 | $15 | $52 | **$670** |
| Dec 2025 | $2,020 | $83 | $15 | $54 | **$2,172** |
| Jan 2026 | $3,479 | $85 | $26 | $50 | **$3,640** |

*The jump from Nov ($670) to Dec ($2,172) to Jan ($3,640) reflects the VATSIM_ADL Hyperscale upgrade that enabled the ADL database normalization (8-table architecture). MySQL increased in Jan 2026 due to upgrade from Basic to General Purpose tier.*

### 4.2 Why Is VATSIM_ADL So Expensive?

**VATSIM_ADL is 88% of costs** because it's a **Hyperscale Serverless database** with:

- **16 vCores** (virtual CPUs), minimum 3 — scales dynamically with load
- **No auto-pause** — Must maintain 15-second refresh for real-time data
- **High-availability replica** — Failover protection + read offloading
- **Billing: ~$0.51/vCore-hour** x 16 vCores x 730 hours/month = ~$6,000 potential
- **Actual: ~$3,200** due to dynamic scaling (average ~11 vCores)

**What It Processes (verified from January-February 2026):**
- **780,967 flights tracked** in the Jan 1-Feb 11 period (211,066 US NAS-relevant)
- 14 background daemons querying continuously:
  - VATSIM data ingestion (every 15 seconds)
  - Route parsing with spatial queries
  - Boundary crossing detection
  - ETA calculations for 2,000-6,000+ concurrent flights
  - Trajectory logging (position snapshots)
  - SWIM API data sync
  - Waypoint coordinate lookups
- **5,288 TMI entries**, **152 TMI programs**, and **1,019 advisories** stored and queryable

### 4.3 Return on Investment

**Cost Per User Session:**
- Annual spend: ~$43,700 (based on January 2026 rate)
- Pilot sessions served: ~20,000/year (estimated across all events)
- **Cost per session: ~$2.19**

**Development Labor:**
- 100% volunteer (no salaries)
- Estimated hours: 2,000+ development hours
- Market value: $200,000+ if contracted
- **Actual cost: $0** (volunteer labor)

**Total Program Cost: ~$43,700/year** for infrastructure serving 20,000+ pilots across 780K+ tracked flights

---

# Part III — The Organizational Story

<a name="section-5"></a>
## 5. Organizational Evolution: 2020 → 2023 → 2026

### 5.1 Timeline

#### 2020: Separate Command Center (JO 7110.10A)
```
vATCSCC Director (USA15) <-- Dedicated VATUSA staff position
+-- National Operations Manager (USA25) <-- Dedicated VATUSA staff position
    +-- National Traffic Management Officers (NTMO)
```

**Strengths:** Dedicated TMU leadership, clear DCC advocate in VATUSA staff
**Weaknesses:** Coordination overhead USA15-USA25, unclear authority boundaries

#### 2022-2023: Consolidated Under Events (VATUSA 7210.35C)
```
VATUSA Events Manager (VATUSA5)
+-- National Operations Manager (USA9) <-- Single DCC position
    +-- NTMO Pool
```

**Duration:** January 2022 - Early 2023 (~12-15 months)

**Strengths:** Eliminated dual leadership, better event integration
**Weaknesses:** Still separate USA9 position to staff/manage

#### Early 2023 - February 2026: Full VATUSA5 Integration (DP001)
```
VATUSA Deputy Director - Support Services (VATUSA4)
+-- Events Manager (VATUSA5)
    +-- VATUSA Command Center (vATCSCC) <-- NO dedicated staff positions
        +-- NTMO Pool (operational execution)
```

**Duration:** Early 2023 - February 2026 (3+ years of operation)

**Per DP001 Section 5-5:**
> "Events Manager (VATUSA5) Responsible For:
> - **Manage and support the VATUSA Command Center (vATCSCC)**"

**Changes:** USA9 position ELIMINATED early 2023. VATUSA5 directly manages DCC. NTMO pool provides operations.

#### February 2026: Personnel & Access Removal
```
VATUSA Deputy Director - Support Services (VATUSA4)
+-- Events Manager (VATUSA5)
    +-- VATUSA Command Center (vATCSCC) <-- REDUCED: personnel removed, platform access suspended
        +-- NTMO Pool <-- DEGRADED: lost automation tools, lost data visibility
```

**Duration:** February 12, 2026 - Present

**Changes:**
- Additional personnel removed from Command Center direction
- PERTI website access suspended (all operational tools inaccessible)
- TMI Publisher automation lost (NTML Quick Entry + Advisory Builder)
- GDT workflow inaccessible (Ground Stop/GDP tooling)
- TMI Compliance Analysis inaccessible (compliance measurement)
- NOD dashboard inaccessible (real-time traffic monitoring)
- Route coordination tools inaccessible (reroute management)
- Background infrastructure continues to run (data collection without human access)

### 5.2 What This Shows

**Through early 2026, VATUSA treated the Command Center as increasingly essential.** Platform investment grew after 2023 (ADL normalization 2026, TMI compliance analysis 2025), DP001 explicitly listed DCC management as VATUSA5 responsibility, and FNO/SNO policies depended on DCC coordination.

**Then in February 2026, the trajectory reversed.** Additional personnel were removed and platform access was suspended — degrading the capabilities that had been growing.

**Critical Timeline:**
- TMI Compliance Analysis launched Nov 2025 — First data available January 2026
- USA9 eliminated early 2023 — 3 years of VATUSA5-only operation
- No pre-2023 compliance data exists — Cannot directly compare before/after consolidation
- February 2026: Platform access removed — compliance measurement now impossible
- **The 3-month window of compliance visibility (8 events, 1,963 MIT pairs, 157 GS flights) may be the only data that ever existed for this analysis**

---

<a name="section-6"></a>
## 6. February 2026: What Was Lost and What It Means

On February 12, 2026, VATUSA leadership further degraded DCC functionality by removing additional personnel from Command Center direction and suspending access to the PERTI platform. The underlying infrastructure (databases, daemons, SWIM API) continues to operate — data is still being collected — but the human-facing tools that turn that data into operational decisions are now inaccessible.

### 6.1 What Was Lost: TMI Automation

**NTML Quick Entry (online form → NTML)**

The TMI Publisher provided a structured form for creating NTML entries — the official record of all traffic management actions. It supported 8 entry types (MIT, MINIT, Ground Stop, APREQ/CFR, TBM, Delay, Config, Cancel) with:

| Capability | What It Did | ATFM Effect of Loss |
|-----------|-------------|---------------------|
| **Structured input forms** | Drop-down selectors for facilities, fixes, restriction values, qualifiers, impacting conditions | TMI entries must now be created through unstructured means, increasing error rate |
| **Auto-validation** | Required fields enforced, facility codes verified, time format validation, cross-border detection | Invalid or incomplete TMI entries go undetected |
| **30-second entry time** | Was 90% faster than previous 5-minute TypeForm process | Reverts to ~5+ minutes per entry — real-time adjustments become impractical |
| **NTML qualifiers** | Clickable buttons for spacing (AS ONE, PER STREAM), aircraft (JET, HEAVY), equipment (RNAV), flow (ARR/DEP) | Qualifiers must be typed from memory — a "20 MIT PER STREAM" restriction without "PER STREAM" changes its operational meaning entirely |
| **Reason categorization** | Structured OPSNET/ASPM categories (Volume, Weather, Runway, Equipment) | Post-event analysis of WHY restrictions were issued becomes impossible |

**Advisory Builder (online form → Advisories)**

| Advisory Type | Purpose | Loss Impact |
|--------------|---------|-------------|
| **Operations Plan** | Pre-event coordination: expected traffic, planned restrictions | Events proceed without structured pre-coordination |
| **Free Form** | General operational updates during events | Updates via informal channels without standardized formatting |
| **Hotline Activation** | Activate inter-facility hotline with participants, addresses, PINs | Hotline activations communicated verbally — participants, timing less clear |
| **SWAP Implementation** | Severe weather avoidance plan coordination | SWAP plans lose structured formatting — multi-facility coordination becomes ad-hoc |

**Consider the evidence from Section 2:** During Plan 221 (Ski Country Sunday), Cameron (ZLC ATM) composed a complete reroute advisory in proper vATCSCC format — origin/destination routing, effective times, TMI IDs. With the TMI Publisher, this was a structured form with auto-formatting and Discord auto-posting. Without it, he'd type the entire advisory manually and post it himself, hoping the format is correct.

### 6.2 What Was Lost: The Coordination Pipeline

The TMI Publisher implemented a complete coordination pipeline:

```
TMI Entry Created (structured form)
    |
    v
Cross-Border Detection (auto-detects if TMI affects Canadian facilities)
    |
    v
Staging Queue (entries queued for review before posting)
    |
    v
Facility Verification (which facilities need to coordinate?)
    |
    v
Coordination Post (-> #coordination Discord with reaction-based approval)
    |
    v
Discord Bot monitors reactions (facilities approve/deny via emoji)
    |
    v
Publication (-> #tmi Discord, NTML database record, active TMI display)
    |
    v
Active TMI Display (FAA-format table, auto-refreshing every 60 seconds)
    |
    v
TMI Compliance Analysis (post-event measurement vs. restrictions)
```

**Without this pipeline:** TMI entries skip validation, coordination, and structured publication. There is no staging review, no cross-border detection, no facility approval workflow, no structured active TMI display, and no data trail for compliance analysis.

### 6.3 What Was Lost: Operational Visibility

| Tool | What NTMOs Could See | What They See Now |
|------|---------------------|-------------------|
| **NOD Dashboard** | Real-time North Atlantic traffic, facility flows, demand vs. capacity | Nothing — no real-time overview |
| **Active TMI Display** | FAA-format table of all active restrictions with auto-refresh | No centralized view of what restrictions are in effect |
| **Demand Charts** | Arrival/departure demand vs. AAR/ADR by airport, by hour | No demand vs. capacity visualization |
| **GDT (Ground Delay Tools)** | Ground Stop creation, GDP modeling, slot assignments | No structured ground delay management |
| **TMI Compliance Analysis** | Post-event compliance per fix, per program | No compliance measurement capability |
| **TMR Reports** | Traffic Management Review with parsed NTML data, compliance, demand | No structured post-event review capability |

**The 14 background daemons continue running.** VATSIM data ingest (every 15 seconds), route parsing, boundary detection, ETA calculations — all still processing ~2,000-6,000 concurrent flights. The SWIM API still serves external consumers. The data exists. No one authorized to use it can see it.

### 6.4 What Was Lost: The PERTI Cycle Itself

PERTI stands for **Plan, Execute, Review, Train, Improve**. Each phase depends on platform capabilities:

| PERTI Phase | Platform Dependency | Status After Feb 2026 |
|------------|--------------------|-----------------------|
| **Plan** | PERTI plans, demand forecasts, historical references, staffing sheets | Inaccessible — 233 plans' worth of institutional knowledge locked away |
| **Execute** | TMI Publisher, GDT, NOD, active TMI display | Inaccessible — execution reverts to manual/ad-hoc |
| **Review** | TMI Compliance Analysis, TMR reports, demand snapshots | Inaccessible — no post-event accountability |
| **Train** | TMR reports feed training scenarios, compliance data identifies knowledge gaps | Inaccessible — training loses its data-driven foundation |
| **Improve** | Compliance trends, demand patterns, TMI effectiveness metrics | Inaccessible — improvement cycle broken at every stage |

**Consider what this means for the events documented in Section 2:** The Ski Country planning thread, the Home for the Holidays ground stop, the Escape to the Desert compliance spot-check — none of those workflows can happen as described. The planning threads will still exist in Discord, but the data-driven backbone is gone.

### 6.5 Quantified Effects

**TMI Creation Speed:**
- With TMI Publisher: ~30 seconds per NTML entry
- Without: ~5+ minutes per entry
- During Plan 228 (Northeast Corridor FNO), 60 entries were created. At 30 sec each = 30 min. At 5+ min each = 5+ hours — longer than some events.

**Compliance Measurement:**
- With tool: Measured 1,963 MIT pairs, 157 GS flights, identified specific problems (DEPDY at 41.9%, FROGZ at 39.0%)
- Without tool: Zero visibility into whether TMIs are being followed
- **The problem doesn't disappear. It becomes invisible.**

**Ground Delay Management:**
- Plan 233 showed 37% GS violation rate WITH tools. Without them, ground stop enforcement relies entirely on controller awareness.

---

<a name="section-7"></a>
## 7. The Fundamental Tension: Mission vs. Structure vs. Politics

This section examines the gap between what the Command Center is supposed to do, what it's actually able to do, and how organizational politics shapes that gap.

### 7.1 The Stated Mission

**From VATUSA DP001, Section 5-5:**
> "Events Manager (VATUSA5) Responsible For: Manage and support the VATUSA Command Center (vATCSCC)"

**From VATUSA 7210.35C (General SOP):**
The Command Center exists to "provide centralized traffic management coordination for VATUSA events" — ensuring facilities work together rather than independently.

**From DP003 (Events Policy):**
> "NOM has full authority over TMIs and may act as TMU for non-responsive subdivisions"

The mission is clear: centralized coordination, full TMI authority, support for all VATUSA events.

### 7.2 The Design and Implementation

The PERTI platform was designed to fulfill this mission. The evidence from Section 2 shows it working:

- **Pre-event planning**: Facilities receive structured PERTI Data Requests weeks before events. They coordinate TMIs, negotiate fix routing, analyze weather — all through a platform that captures and structures this activity.
- **Execution**: TMI Publisher enables 30-second NTML entries. GDT provides structured ground delay management. NOD dashboard provides real-time monitoring. The coordination pipeline ensures TMIs are reviewed, approved, and published.
- **Review**: TMI Compliance Analysis measures actual performance. TMR reports structure post-event review. Demand data validates operational decisions.
- **Training**: Experienced TMUs teach new coordinators through the planning threads. Compliance data identifies knowledge gaps. Institutional knowledge accumulates in 233 PERTI plans.

The design works. The evidence shows it. The 66.5% compliance number is the proof — not because 66.5% is good (it isn't), but because without the platform, that number would be unknown and almost certainly lower.

### 7.3 The Political Reality

The Command Center exists within VATUSA's political structure, and that structure imposes constraints that no amount of technology can overcome.

**Constraint 1: Volunteer Authority**

VATSIM is a volunteer network. No one is paid. No one can be fired (they can have roles removed, but they can simply stop showing up). This means:
- TMIs are published but cannot be enforced — a controller can simply not comply
- Facilities can be tagged in planning threads but cannot be compelled to respond
- NTMOs can be assigned events but cannot be required to attend
- The "full authority over TMIs" granted by DP003 is authority in name only — it depends on voluntary compliance

**Evidence from the threads:**
> **Brandon W | VATUSA2** — 12/14/2025 (Ski Country)
> .......bruh...who forgot to post this [the NTML]

Even the MIT package that was planned days in advance didn't get posted to the NTML until someone noticed it was missing during the event. In a volunteer system, things fall through cracks.

**Constraint 2: The Consolidation Paradox**

When USA9 was eliminated and DCC was consolidated under VATUSA5:
- **Intended benefit**: Simpler structure, better event-DCC integration, fewer positions to staff
- **Actual effect**: The person responsible for the Command Center is also responsible for the Events program, virtual airline coordination, FNO/SNO approvals, ACE Team deployment, and the NTMO pool

From VATUSA5 directly:
> "Onboarding more NTMOs (which is hard enough to do) is increasingly burdensome due to training on systems & understanding of the interactions & processes involved."

This is the consolidation paradox: reducing staff positions reduces overhead, but it concentrates workload to the point where the remaining person cannot give adequate attention to everything. The planning threads show this — VATUSA5 participates in coordination but cannot be in every thread for every event.

**Constraint 3: The Investment-Disinvestment Cycle**

The organizational history shows a pattern:

| Period | Investment Direction | Outcome |
|--------|---------------------|---------|
| 2020-2022 | Dedicated USA15/USA25, then USA9 | Platform built, PERTI methodology established |
| 2023-early 2026 | No dedicated DCC positions, but platform investment continues | ADL normalization, TMI compliance, GDT, NTML Quick Entry |
| Feb 2026 | Personnel removed, platform access suspended | Investment rendered inaccessible |

The platform was built during a period of organizational investment. It continued growing during a period of organizational consolidation (because the developer continued volunteering). Then access was removed — not because the platform failed, but because of an organizational decision unrelated to platform performance.

**This is the core tension:** Technical investment and organizational investment move independently, and organizational decisions can negate years of technical development overnight.

### 7.4 The Three Audiences and What They Need to Understand

**For VATSIM Pilots:**
You benefit from the Command Center every time you fly an event. The routes in SimBrief, the restrictions you hear on frequency, the ground delays that keep you on the ground instead of holding for 40 minutes — all of that comes from the coordination process documented in Section 2. When that coordination degrades, your experience degrades. You may not know why a particular event felt chaotic, but the data shows that events with better TMI compliance have shorter delays, fewer vectors, and fewer go-arounds.

**For VATSIM Controllers:**
You are the ones who implement (or don't implement) the TMIs. The compliance data in Section 3 shows that 1 in 3 restrictions are not being followed. Some of that is communication failure — you didn't know the TMI existed. Some of that is system failure — there's no automated way to see active TMIs on your scope. And some of it is the inherent challenge of implementing restrictions in a volunteer system where the pilot on the other end may not understand or agree with the restriction. Better tools and better communication help with all three problems.

**For VATSIM Leadership:**
The decision you face is not "should the Command Center exist?" The evidence overwhelmingly says yes. The decision is: what organizational structure, staffing model, and resource allocation produces the best outcome for the network? The data shows:
- 66.5% compliance is not good enough, but it's measurably better than nothing
- The tools work but need wider adoption and enforcement mechanisms
- The current consolidation model strains a single person's capacity
- Removing access to the platform does not solve any of the above problems — it makes them invisible

### 7.5 What the Discord Threads Reveal About Politics

Reading across all 13 planning threads, several political dynamics become visible:

**Self-Organizing Competence:** Facilities coordinate effectively because the people in those roles care about the outcome, not because an organizational structure compels them. Evan learns TMI creation from Cameron. Justin shares mathematical analysis in Google Docs. Ken validates compliance data against replay footage. Blake provides detailed TMR feedback after retirement. This competence exists independently of VATUSA organizational decisions — but it needs a framework (the platform, the threads, the data) to be effective.

**Fragile Institutional Knowledge:** When Blake retired from ZSE, the event he was organizing almost didn't get posted to the VATSIM calendar. When Kevin explains "NO COMP" to the group, he's passing knowledge that would otherwise be lost when he moves on. The planning threads are an informal knowledge base, but they're not searchable, not structured, and not preserved in any systematic way. The PERTI platform was becoming that systematic preservation layer.

**The Gap Between Policy and Practice:** DP003 says "NOM has full authority over TMIs." In practice, the threads show NTMOs posting TMIs, facilities negotiating values, and TMU personnel making real-time adjustments — with or without formal NOM authority. The authority structure matters less than the coordination framework. When the framework is strong (planning thread + PERTI tools + NTML), coordination happens. When the framework weakens, coordination becomes ad-hoc and inconsistent.

---

# Part IV — Analysis & Recommendations

<a name="section-8"></a>
## 8. VATUSA5 Consolidation: Benefits & Risks

### 8.1 The Upside

**Elimination of Coordination Gaps:**
- No more "Events wants X, DCC wants Y" conflicts
- Single decision-maker for event planning AND TMI execution
- Faster iteration on new event types

**Reduced Staffing Overhead:**
- No USA9 position to recruit/manage
- NTMO pool directly managed by VATUSA5
- Simpler organizational structure

**Better Integration:**
- Event planning informs TMI strategy
- TMI lessons learned feed event improvements

### 8.2 The Downside (Risks)

#### RISK 1: Single Point of Failure

VATUSA5 is now responsible for: Events program management, Command Center operations, Virtual airline liaison, ARTCC event coordinator supervision, FNO/SNO approval processing, ACE Team deployment, event calendar maintenance, myVATSIM portal visibility.

If VATUSA5 is unavailable: Who approves FNO/SNO requests? Who manages real-time TMI? Who deploys ACE Team? **No clear backup/deputy for DCC functions.**

#### RISK 2: Workload Concentration

TMI Compliance average 66.5% across 6 events (vs 85-95% standard). Is VATUSA5 able to dedicate sufficient attention to TMU improvement when managing the entire Events portfolio?

Before (2020-2022): USA15/USA25 or USA9 focused 100% on TMU.
Now (2025): VATUSA5 splits time across Events, DCC, Virtual Airlines. **66.5% compliance suggests TMU coordination needs MORE attention, not less.**

#### RISK 3: Loss of Dedicated TMU Advocate

Before: USA15/USA25/USA9 represented TMU interests in VATUSA leadership.
Now: TMU is ONE of VATUSA5's many responsibilities.

When "We need better TMI automation" competes with "We need better event banners," which gets priority? Without a dedicated TMU voice, traffic management improvements may lose priority to more visible event deliverables.

#### RISK 4: NTMO Pool Management (CRITICAL)

**The Vicious Cycle:**
```
Understaffed NTMO Pool
    -> USA5 needs to recruit/train more NTMOs
    -> Training takes USA5's limited time
    -> Less time for Events, VA coordination, TMI oversight
    -> Less operational NTMO coverage during events
    -> Back to: Understaffed NTMO Pool
```

**From USA5:** "Onboarding more NTMOs (which is hard enough to do) is increasingly burdensome due to training on systems & understanding of the interactions & processes involved."

The platform complexity is INCREASING (TMI Compliance Analysis, GDT, SWIM API) while the capacity to train people on it is DECREASING. No documented training curriculum exists. Senior NTMO turnover means knowledge lost. Events getting more complex (multi-division coordination).

### 8.3 Comparison to Real-World Structure

**FAA ATCSCC:** Dedicated facility, full-time leadership, 24/7 staffing, separate from events planning, clear operational authority.

**VATSIM vATCSCC (current):** No dedicated positions, part-time NTMO pool, combined with events planning, unclear authority delegation.

---

<a name="section-9"></a>
## 9. Platform Enhancements (November 2025+)

> **Note (February 13, 2026):** All capabilities described in this section were functional as of February 11, 2026. As of February 12, 2026, PERTI website access has been suspended. The tools exist and the infrastructure continues running, but authorized personnel can no longer access them.

### 9.1 TMI Compliance Analysis Tool

**What It Does:**
- Analyzes every aircraft pair crossing TMI fixes
- Calculates spacing in miles and time
- Identifies violations with specific callsigns and times
- Tracks ground stop compliance per flight

**Why It Matters:**
- Before: No way to measure TMI effectiveness (flying blind)
- Now: Data-driven feedback enables improvement
- Impact: Revealed 66.5% average compliance — a problem we didn't know existed

**Example Output (Plan 228):**
```
Overall Compliance: 75.2%
MIT Violations: 267 of 1,088 pairs (24.5% violation rate)
Worst Fix: DEPDY (41.9% compliance, 25 violations / 43 pairs)
Ground Stop: 69.6% compliance (17 violations / 56 flights)
Reroutes: 100% compliance (3 mandatory, all compliant)
```

### 9.2 NTML Quick Entry System (January 2025)

- Old: Multi-step TypeForm wizard, ~5 minutes per entry
- New: Natural language input, automatic parsing, batch entry, ~30 seconds per entry (90% faster)
- Enables real-time TMI adjustments without 5-minute delays

### 9.3 ADL Database Normalization (January 2026)

- Migrated from monolithic table to 8 purpose-specific tables
- Performance: **50+ seconds → 800ms** (98.4% faster)
- Capacity: 937 tested → 2,000-6,000 design capacity

### 9.4 Platform Capabilities Summary

| Capability | Status (pre-Feb 12) | Status (post-Feb 12) | Impact |
|-----------|---------------------|---------------------|--------|
| Real-time flight tracking | Active (800ms) | Backend running, UI inaccessible | NOD dashboard unavailable |
| TMI Compliance Analysis | Active (on-demand) | Tool inaccessible | No compliance measurement |
| NTML Quick Entry | Active (30sec/entry) | Tool inaccessible | Reverts to manual (5+ min) |
| Advisory Builder | Active (auto-publish) | Tool inaccessible | No structured advisories |
| Ground Delay Tools (GDT) | Active | Tool inaccessible | No structured GS/GDP |
| SWIM API | Active (15sec latency) | Still serving consumers | Unaffected |
| PERTI Plans | Active (233 plans) | Inaccessible | No event planning |
| TMR Reports | Active | Inaccessible | No post-event review |

**Investment trajectory:** Platform capability was INCREASING through early February 2026. Access suspended February 12. Infrastructure continues running at ~$3,640/month with no human access to operational tools.

---

<a name="section-10"></a>
## 10. What Happens Without the Command Center

### 10.1 Scenario: 500-Flight Cross the Pond Event (No DCC)

**Without Command Center Coordination:**
- Each ARTCC independently plans MIT restrictions
- ZNY: "30 MIT for all eastbound," ZBW: "25 MIT arrivals," ZOB: "40 MIT"
- Conflicting restrictions, no single plan
- Facility coordination: **28 possible bilateral pairs** (8 ARTCCs = 8x7/2)
- Pilots file random NAT tracks (no coordination). NAT A gets 80% of traffic.
- Result: **60-90 minute average delays, controller burnout, service degradation**

**With Command Center:**
- Single coordinated plan published T-14 days
- 20 MIT ZNY→EGLL coordinated across all facilities
- NTMO monitors NOD, adjusts in real-time, issues updates in 30 seconds
- TMI Compliance Analysis measures performance post-event
- Result: **15-25 minute average delays (60-70% reduction)**

### 10.2 The Evidence From the Planning Threads

Section 2 documented what coordinated planning looks like. Consider what those same events look like without the platform:

**Ski Country (Plan 221) without coordination:**
- No fix-level routing debate between ZDV and ZLC
- Pilots file whatever SimBrief gives them — some via OCS, some via HYPPE, some direct
- ZDV gets traffic through 3 sectors in 50 miles instead of 2
- No tunnel agreement — low and high traffic mixed
- No BZN reroute advisory — all traffic merges uncontrolled

**Home for the Holidays (Plan 220) without GDT:**
- ORD ground stop is announced verbally: "Hold all departures to O'Hare"
- No flight list, no calculated CTDs, no delay tracking
- Controllers manually identify departures — some slip through, some are held too long
- No 25-minute ground stop — it becomes an indefinite hold until someone says "resume"

**Northeast Corridor (Plan 228) without TMI Publisher:**
- 60 NTML entries must be composed manually
- At 5+ minutes each = 5+ hours of entry time during a 4-hour event
- Result: fewer entries, less coordination, less structure — more chaos

---

<a name="section-11"></a>
## 11. Recommendations

### 11.1 Immediate Actions (0-30 Days)

**1. Restore PERTI Platform Access**
The infrastructure is running. The data is there. The tools work. Restoring access costs $0 and immediately restores every capability documented in this report.

**2. Clarify Real-Time Operational Authority**
Document clear delegation chain: VATUSA5 → designated NTMO → facility TMU. Publish authority matrix in DP003 revision.

**3. Analyze Compliance Trends**
Run TMI Compliance Analysis on ALL events since tool launched (Nov 2025+). Identify common violation patterns. Survey facilities: "Why aren't you following TMIs?"

### 11.2 Short-Term Improvements (30-90 Days)

**4. TMI Communication Overhaul**
Add automated Discord notifications when TMIs are issued. Create NTML monitoring bot. Develop vNAS/EuroScope plugin showing active TMIs.

**5. NTMO Development Program**

**Phase 1 — Reduce Training Burden (30 days):**
- Self-paced learning modules: video tutorials, written guides, quizzes
- "NTMO Playbook" with standard procedures and pre-approved TMI templates
- Senior NTMO mentorship program (distributes training OFF USA5)

**Phase 2 — Simplify Platform (60 days):**
- PERTI "Easy Mode" for new NTMOs (simplified interface)
- NTML Template Library (one-click creation for common scenarios)
- TMI Decision Support Tool (system recommends parameters based on AAR + demand)

**Phase 3 — Organizational Structure (90 days):**
- "NTMO Coordinator" role (Senior NTMO handles scheduling, onboarding)
- Formalized NTMO qualification levels (Trainee → Basic → Full → Senior)

### 11.3 Strategic Decisions (180+ Days)

**6. Evaluate Organizational Structure**

Is VATUSA5 consolidation working after 3+ years? Data to consider:
- TMI compliance trends
- VATUSA5 workload assessment
- NTMO pool feedback on leadership/support
- Facility TMU satisfaction with DCC responsiveness

**Options:**
1. Status quo — Keep VATUSA5 managing DCC + Events
2. Deputy structure — Add VATUSA5 deputy focused on DCC
3. Restore USA9 — Dedicated NOM reporting to VATUSA5
4. Hybrid — VATUSA5 strategic, senior NTMO operational lead

---

<a name="section-12"></a>
## 12. Strategic Perspective: Balancing TMU Need, Staffing, and Empowerment

### 12.1 The Core Insight

The problem isn't JUST staffing — it's that TMU work is too complex and manual. Instead of "get more people to do complex work," the strategy should be "make the work simpler so existing people can do more."

### 12.2 Technology-Enabled Empowerment

| Manual Process (Current) | Technology-Enabled (Future) | Impact |
|--------------------------|----------------------------|--------|
| NTMO calculates GDP parameters | System recommends based on AAR + demand | 90% faster |
| NTMO writes NTML entries in 5 min | Template auto-generated in 30 sec | 90% faster |
| USA5 trains each NTMO personally | Self-paced video modules + mentorship | 70% less USA5 time |
| Complex TMI decision-making | Recommendations from historical data | Reduced expertise barrier |
| Facilities miss TMI notifications | Automated alerts + vNAS plugin | Higher compliance |

**Result:** Same or FEWER NTMOs can handle MORE events with BETTER quality.

### 12.3 What NTMOs Need

1. **Clear authority boundaries** — What they can decide independently vs. what needs USA5 approval
2. **Decision support tools** — System does the math, NTMO reviews and approves
3. **Safety nets** — Real-time compliance alerts, undo capability, escalation notifications
4. **Knowledge base** — Self-paced learning, playbooks, peer mentorship
5. **Community** — NTMO Coordinator manages pool, Discord channel for questions, knowledge sharing

### 12.4 Measuring Success

| Metric | Baseline (Now) | Target (6 months) |
|--------|----------------|-------------------|
| TMI Compliance Average | 66.5% | 75%+ |
| USA5 Training Hours per NTMO | 10-15 hours | 3-5 hours |
| NTMO Pool Size | Insufficient | +30% |
| USA5 Time on Strategic Work | ~10% | ~30% |

---

# Part V — Conclusion

<a name="section-13"></a>
## 13. Conclusion

### What This Document Showed

**Section 2** took you inside the planning threads — the real conversations where facilities negotiate MIT packages, debate fix routing, analyze weather, coordinate ground stops, and review compliance data. If there was any doubt about whether the Command Center does meaningful work, the evidence is there: 13 events, hundreds of messages, thousands of decisions.

**Section 3** showed the numbers: 66.5% average TMI compliance across 1,963 measured flight pairs. Not good enough by any standard — but measurably better than zero coordination, and critically, only measurable because the tools to measure it exist.

**Sections 5-7** showed the organizational trajectory: from dedicated DCC leadership (2020) to consolidation under VATUSA5 (2023) to personnel removal and access suspension (February 2026). The trend is toward less investment in the function, even as the evidence for its value grows.

### The Fundamental Contradiction

The data showed 66.5% TMI compliance — a serious gap requiring better tools AND better coordination.

The response was to remove the tools and reduce the coordination personnel.

This does not improve compliance. It makes compliance unmeasurable.

### What Remains

The 14 background daemons continue tracking flights. The databases continue storing TMI entries, flight data, and trajectory records. The SWIM API continues serving external consumers. 233 PERTI plans, 5,288 TMI entries, 1,019 advisories, and 3 months of compliance data are preserved.

The platform is functional. The capability exists. It awaits the decision to restore access.

### What the Planning Threads Tell Us

The Discord threads documented in Section 2 reveal something that org charts and policy documents cannot: **the Command Center works because real people with real expertise choose to coordinate.** Evan learns TMI creation from Cameron. Justin shares mathematical analysis with Dean. Ken validates compliance data against replay footage. Blake provides detailed TMR feedback after retirement. Kevin teaches "NO COMP" to the group and everyone learns.

This competence exists independently of VATUSA organizational decisions. But it needs a framework — the PERTI platform, the planning threads, the NTML, the compliance data — to be effective. Remove the framework, and the competence disperses. The people are still there, but they lack the common tools and common data to coordinate.

### For Every Audience

**If you're a pilot** who has ever flown a VATSIM event: the coordination described in this document directly affected your experience. Better coordination = shorter delays, fewer vectors, smoother arrivals. The question is whether VATSIM will invest in the infrastructure that makes that coordination possible.

**If you're a controller** who has ever worked a VATSIM event: the TMIs you implement come from the process described in this document. When that process works well, your workload is manageable and traffic is sequenced. When it breaks down, you get sector saturation and holding patterns. The question is whether the tools and authority structure support you.

**If you're in TMU** or considering it: the planning threads show what this work actually looks like — technically demanding, collaborative, and genuinely impactful. The question is whether the organizational structure will support and develop you, or burn you out.

**If you're in VATSIM leadership:** the data, the evidence, and the operational record all point the same direction. The Command Center function is vital. The platform investment is justified. The organizational structure needs attention. The February 2026 degradation moves in the wrong direction. The evidence is in this document. The decision is yours.

---

## Appendix: Data Sources

**TMI Compliance Data:**
- Plans 223, 225, 226, 227, 228, 229, 233, 234 (January-February 2026)
- Source: `data/tmi_compliance/tmi_compliance_results_{plan_id}.json` on production server
- Flight data: VATSIM_ADL `adl_flight_core` table (780,967 flights, Jan-Feb 2026)
- TMI data: VATSIM_TMI `tmi_entries` (5,288 total), `tmi_programs` (152 total), `tmi_advisories` (1,019 total)
- TMI by facility: 25 requesting facilities, 344 entries in Jan-Feb 2026 (12 ARTCCs, 11 TRACONs)

**Database-Verified Operational Statistics (queried February 13, 2026):**
- 232 PERTI plans in database, 16 with active event data in query period
- 14 Ground Stop programs logged (airports: KJFK, KBOS, CYYZ, EDDF, EHAM, LFBO)
- 9 formal reroutes (RDU→DCA, Florida→NE Corridor, PHL→BOS, JFK/ZBW→DCA, FEA multi-route)
- Staffing ranged from 3-18 controllers across 2-9 facilities per event
- Airport configs: up to 15 airports per plan (Plan 223 Nashville covered ATL, BNA, CLT, CVG, DFW, HOU, IAH, MCI, MDW, MEM, MSY, ORD, SDF, STL, DAL)

**Discord Planning Threads (13 events):**
- ZDV Live 2025 SNO (Plan 175) | Florida Night Ops (Plan 209) | Rock Around the Clock (Plan 213)
- Nashville Nights Charlotte Lights FNO (Plan 214) | Stuff the Albu-Turkey FNO (Plan 215)
- Home for the Holidays FNO (Plan 220) | Ski Country Sunday (Plan 221)
- New Year, New York (Plan 222) | New Year, Nashville (Plan 223)
- Honoring the Dream FNO (Plan 225) | Escape to the Desert SNO (Plan 226)
- Northeast Corridor FNO (Plan 228) | Hail Mary in the Bay (Plan 233)

**Infrastructure Costs:**
- Source: https://perti.vatcscc.org/transparency (February 2026)
- Azure Cost Management billing data (Oct 2025-Jan 2026 actuals)

**Policy Documents:**
- VATUSA DP001 - General Division Policy (November 29, 2025)
- VATUSA 7210.35C - General SOP (January 22, 2022)
- VATUSA DP003 - Events Policy (October 15, 2024)
- JO 7110.10A - vATCSCC General SOP (September 2020) [Historical]

**Technical Documentation:**
- ADL_Normalization_Transition_Summary.md (January 6, 2026)
- NTML_Quick_Entry_Transition.md (January 22, 2025)
- GDT_Unified_Design_Document_v1.md (January 9, 2026)

**Platform:**
- PERTI: https://perti.vatcscc.org
- Transparency Page: https://perti.vatcscc.org/transparency

---

*Document prepared by vATCSCC Development Team*
*All data verified from official sources, operational systems, and DCC planning thread records*
*Contact: Via vATCSCC Discord or perti@vatcscc.org*
