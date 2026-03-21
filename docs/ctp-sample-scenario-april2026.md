# CTP Sample Scenario: April 1, 2026 Westbound Event

## Event Parameters
- **Event Period**: 2026-04-01 00:00Z to 2026-04-01 18:00Z
- **Departures**: 00:00Z - 09:00Z
- **Arrivals**: 09:00Z - 18:00Z
- **Total Flights**: 3,000
- **Direction**: EASTBOUND (Americas → Europe)

## Origins & Destinations

**Origins (6)**: KJFK, KBOS, CYYZ, KORD, KDFW, KIAH
**Destinations (8)**: EGLL, LFPG, EDDF, EHAM, EBBR, LIMC, LPPT, UUEE

**Excluded Pairs (9)**: KJFK-UUEE, KDFW-LIMC, KDFW-UUEE, KDFW-LPPT, KIAH-EDDF, KIAH-EHAM, KORD-EGLL, CYYZ-LFPG, CYYZ-LIMC

**Valid Pairs**: 39

## O/D Flight Distribution Matrix

|        | EGLL | LFPG | EDDF | EHAM | EBBR | LIMC | LPPT | UUEE | **Total** |
|--------|------|------|------|------|------|------|------|------|-----------|
| KJFK   | 204  | 184  | 163  | 163  | 102  | 102  | 82   | --   | **1,000** |
| KBOS   | 143  | 102  | 61   | 82   | 41   | 41   | 41   | 40   | **551**   |
| CYYZ   | 143  | --   | 82   | 82   | 41   | --   | 41   | 41   | **430**   |
| KORD   | --   | 82   | 82   | 82   | 41   | 41   | 41   | 41   | **410**   |
| KDFW   | 82   | 61   | 61   | 61   | 41   | --   | --   | --   | **306**   |
| KIAH   | 82   | 61   | --   | --   | 40   | 40   | 40   | 40   | **303**   |
| **Total** | **654** | **490** | **449** | **470** | **306** | **224** | **245** | **162** | **3,000** |

## NAT Track Assignments

|        | EGLL | LFPG | EDDF | EHAM | EBBR | LIMC | LPPT | UUEE |
|--------|------|------|------|------|------|------|------|------|
| KJFK   | A    | C    | E    | C    | E    | G    | U    | --   |
| KBOS   | A    | E    | G    | C    | G    | G    | T    | A    |
| CYYZ   | C    | --   | E    | A    | E    | --   | T    | A    |
| KORD   | --   | G    | G    | S    | S    | G    | U    | E    |
| KDFW   | T    | S    | U    | T    | U    | --   | --   | --   |
| KIAH   | T    | S    | --   | --   | U    | V    | V    | T    |

## Per-Track Volume & Constraints

| Track    | Flights | %     | Max ACPH | Ocean Entry Window |
|----------|---------|-------|----------|--------------------|
| **NATA** | 510     | 17.0% | 120      | 02:00-07:00Z       |
| **NATC** | 572     | 19.1% | 120      | 02:00-07:00Z       |
| **NATE** | 531     | 17.7% | 120      | 02:00-07:30Z       |
| **NATG** | 409     | 13.6% | 100      | 02:30-08:00Z       |
| **NATS** | 286     | 9.5%  | 80       | 03:00-08:00Z       |
| **NATT** | 347     | 11.6% | 80       | 03:00-08:30Z       |
| **NATU** | 265     | 8.8%  | 60       | 03:00-08:30Z       |
| **NATV** | 80      | 2.7%  | 30       | 03:30-08:30Z       |

## Departure Windows (3h each)

| Origin | Window Start | Window End | Flights | Avg Rate/h |
|--------|-------------|------------|---------|------------|
| KJFK   | 00:00Z      | 03:00Z     | 1,000   | 333        |
| KBOS   | 00:00Z      | 03:00Z     | 551     | 184        |
| CYYZ   | 00:30Z      | 03:30Z     | 430     | 143        |
| KORD   | 01:00Z      | 04:00Z     | 410     | 137        |
| KDFW   | 02:00Z      | 05:00Z     | 306     | 102        |
| KIAH   | 02:00Z      | 05:00Z     | 303     | 101        |

## Arrival Windows (3h each)

| Dest | Window Start | Window End | Flights | Avg Rate/h |
|------|-------------|------------|---------|------------|
| EGLL | 09:00Z      | 12:00Z     | 654     | 218        |
| LFPG | 09:30Z      | 12:30Z     | 490     | 163        |
| EHAM | 09:30Z      | 12:30Z     | 470     | 157        |
| EDDF | 10:00Z      | 13:00Z     | 449     | 150        |
| EBBR | 10:00Z      | 13:00Z     | 306     | 102        |
| LIMC | 11:00Z      | 14:00Z     | 224     | 75         |
| LPPT | 11:00Z      | 14:00Z     | 245     | 82         |
| UUEE | 12:00Z      | 15:00Z     | 162     | 54         |

## Session Configuration

- **Session Name**: CTP April 2026 Westbound
- **Direction**: EASTBOUND
- **Constraint Window**: 2026-04-01T00:00Z to 2026-04-01T18:00Z
- **Constrained FIRs**: CZQX, BIRD, EGGX, LPPO
- **Slot Interval**: 2 min
- **Max Slots/Hour**: 120

## Traffic Blocks (6 blocks, one per origin)

### Block 1: JFK Departures
- **Origins**: KJFK
- **Destinations**: EGLL, LFPG, EDDF, EHAM, EBBR, LIMC, LPPT
- **Flight Count**: 1000
- **Distribution**: FRONT_LOADED
- **Track Assignments**:
  - NAT A: 204 flights (EGLL) — FL340-FL400
  - NAT C: 347 flights (LFPG+EHAM) — FL340-FL400
  - NAT E: 265 flights (EDDF+EBBR) — FL340-FL400
  - NAT G: 102 flights (LIMC) — FL350-FL410
  - NAT U: 82 flights (LPPT) — FL340-FL380

### Block 2: BOS Departures
- **Origins**: KBOS
- **Destinations**: EGLL, LFPG, EDDF, EHAM, EBBR, LIMC, LPPT, UUEE
- **Flight Count**: 551
- **Distribution**: FRONT_LOADED
- **Track Assignments**:
  - NAT A: 183 flights (EGLL+UUEE) — FL340-FL400
  - NAT C: 82 flights (EHAM) — FL340-FL400
  - NAT E: 102 flights (LFPG) — FL350-FL410
  - NAT G: 143 flights (EDDF+EBBR+LIMC) — FL340-FL400
  - NAT T: 41 flights (LPPT) — FL340-FL380

### Block 3: YYZ Departures
- **Origins**: CYYZ
- **Destinations**: EGLL, EDDF, EHAM, EBBR, LPPT, UUEE
- **Flight Count**: 430
- **Distribution**: UNIFORM
- **Track Assignments**:
  - NAT A: 123 flights (EHAM+UUEE) — FL340-FL400
  - NAT C: 143 flights (EGLL) — FL350-FL410
  - NAT E: 123 flights (EDDF+EBBR) — FL340-FL400
  - NAT T: 41 flights (LPPT) — FL340-FL380

### Block 4: ORD Departures
- **Origins**: KORD
- **Destinations**: LFPG, EDDF, EHAM, EBBR, LIMC, LPPT, UUEE
- **Flight Count**: 410
- **Distribution**: UNIFORM
- **Track Assignments**:
  - NAT E: 41 flights (UUEE) — FL360-FL410
  - NAT G: 205 flights (LFPG+EDDF+LIMC) — FL340-FL400
  - NAT S: 123 flights (EHAM+EBBR) — FL340-FL400
  - NAT U: 41 flights (LPPT) — FL340-FL380

### Block 5: DFW Departures
- **Origins**: KDFW
- **Destinations**: EGLL, LFPG, EDDF, EHAM, EBBR
- **Flight Count**: 306
- **Distribution**: BACK_LOADED
- **Track Assignments**:
  - NAT S: 61 flights (LFPG) — FL340-FL400
  - NAT T: 143 flights (EGLL+EHAM) — FL340-FL400
  - NAT U: 102 flights (EDDF+EBBR) — FL340-FL380

### Block 6: IAH Departures
- **Origins**: KIAH
- **Destinations**: EGLL, LFPG, EBBR, LIMC, LPPT, UUEE
- **Flight Count**: 303
- **Distribution**: BACK_LOADED
- **Track Assignments**:
  - NAT S: 61 flights (LFPG) — FL340-FL400
  - NAT T: 122 flights (EGLL+UUEE) — FL340-FL400
  - NAT U: 40 flights (EBBR) — FL340-FL380
  - NAT V: 80 flights (LIMC+LPPT) — FL340-FL380

## Throughput Configs (auto-generated from Apply)

| Track | Max ACPH | Priority |
|-------|----------|----------|
| NATA  | 120      | 50       |
| NATC  | 120      | 50       |
| NATE  | 120      | 50       |
| NATG  | 100      | 50       |
| NATS  | 80       | 50       |
| NATT  | 80       | 50       |
| NATU  | 60       | 50       |
| NATV  | 30       | 50       |
