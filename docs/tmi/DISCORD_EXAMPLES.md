# TMI Discord Notification Examples

This document shows what each notification type will look like when posted to Discord.

---

## 1. NTML Entry - MIT (Miles-In-Trail)

```
┌─────────────────────────────────────────────────────────────────┐
│ 🔴 MIT - KJFK                                          [RED]    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ JFK arrivals via LENDY                                          │
│ **Qualifiers:** HEAVY, PER_FIX                                  │
│ **Exclusions:** LIFEGUARD, MEDEVAC                              │
│                                                                 │
│ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐              │
│ │ Restriction  │ │ Requesting   │ │ Providing    │              │
│ │ 15 MIT       │ │ N90          │ │ ZNY          │              │
│ └──────────────┘ └──────────────┘ └──────────────┘              │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Valid                                                       │ │
│ │ January 17, 2026 6:30 PM → January 17, 2026 8:30 PM        │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Reason                                                      │ │
│ │ VOLUME: High arrival demand                                 │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ─────────────────────────────────────────────────────────────── │
│ NTML Entry #999 • ACTIVE                          Jan 17, 2026  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 2. NTML Entry - MINIT (Minutes-In-Trail)

```
┌─────────────────────────────────────────────────────────────────┐
│ 🟠 MINIT - KEWR                                     [ORANGE]    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ EWR departures to ZDC                                           │
│                                                                 │
│ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐              │
│ │ Restriction  │ │ Requesting   │ │ Providing    │              │
│ │ 5 MINIT      │ │ ZDC          │ │ N90          │              │
│ └──────────────┘ └──────────────┘ └──────────────┘              │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Reason                                                      │ │
│ │ WEATHER                                                     │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ─────────────────────────────────────────────────────────────── │
│ NTML Entry #1000 • ACTIVE                         Jan 17, 2026  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 3. Ground Stop Advisory

```
┌─────────────────────────────────────────────────────────────────┐
│ 🛑 ADVZY 001 - KLGA GS                                 [RED]    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ **GROUND STOP - KLGA**                                          │
│                                                                 │
│ Due to weather, a ground stop is in effect for all arrivals     │
│ to LaGuardia Airport. All departures destined KLGA are held     │
│ at their origin.                                                │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Effective                                                   │ │
│ │ January 17, 2026 6:30 PM → January 17, 2026 8:00 PM        │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Reason                                                      │ │
│ │ WEATHER: Thunderstorms in terminal area                     │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ─────────────────────────────────────────────────────────────── │
│ Advisory #501 • Rev 1                             Jan 17, 2026  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 4. GDP Advisory

```
┌─────────────────────────────────────────────────────────────────┐
│ ⏱️ ADVZY 002 - KJFK GDP                             [PURPLE]    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ **GROUND DELAY PROGRAM - KJFK**                                 │
│                                                                 │
│ A Ground Delay Program is in effect for JFK International       │
│ Airport. Expect delays averaging 45-60 minutes.                 │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Effective                                                   │ │
│ │ January 17, 2026 6:30 PM → January 17, 2026 10:30 PM       │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ┌──────────────────┐ ┌──────────────────┐                       │
│ │ Program Rate     │ │ Delay Cap        │                       │
│ │ 30/hr            │ │ 90 min           │                       │
│ └──────────────────┘ └──────────────────┘                       │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Reason                                                      │ │
│ │ VOLUME: Reduced capacity due to runway construction         │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ─────────────────────────────────────────────────────────────── │
│ Advisory #502 • Rev 1                             Jan 17, 2026  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 5. GDT Program Activation

```
┌─────────────────────────────────────────────────────────────────┐
│ ⏱️ GDP-DAS ACTIVATED - KATL                         [PURPLE]    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ A Ground Delay Program has been activated for **KATL**.         │
│                                                                 │
│ **Cause:** Weather and volume                                   │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Program Window                                              │ │
│ │ January 17, 2026 6:30 PM → January 17, 2026 11:30 PM       │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ┌──────────────────┐ ┌──────────────────┐                       │
│ │ Arrival Rate     │ │ Delay Limit      │                       │
│ │ 45/hr            │ │ 120 min          │                       │
│ └──────────────────┘ └──────────────────┘                       │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Scope                                                       │ │
│ │ Tier 2                                                      │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Affected Flights                                            │ │
│ │ Total: 245 | Controlled: 198 | Exempt: 47                   │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ─────────────────────────────────────────────────────────────── │
│ Program #101 • ADVZY 003                          Jan 17, 2026  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 6. GDT Program Extension

```
┌─────────────────────────────────────────────────────────────────┐
│ ⏰ GDP-DAS EXTENDED - KATL                          [ORANGE]    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ The GDP has been extended.                                      │
│                                                                 │
│ ┌──────────────────────┐ ┌──────────────────────┐               │
│ │ Previous End         │ │ New End              │               │
│ │ Jan 17, 2026 11:30PM │ │ Jan 18, 2026 1:30AM  │               │
│ └──────────────────────┘ └──────────────────────┘               │
│                                                                 │
│ ─────────────────────────────────────────────────────────────── │
│ Program #101 • ADVZY 003                          Jan 17, 2026  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 7. GDT Program Purge (Completion)

```
┌─────────────────────────────────────────────────────────────────┐
│ ✅ GDP-DAS PURGED - KATL                            [GREEN]     │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ The GDP has ended and been purged.                              │
│                                                                 │
│ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐              │
│ │ Average      │ │ Max Delay    │ │ Total        │              │
│ │ Delay        │ │              │ │ Flights      │              │
│ │ 43 min       │ │ 87 min       │ │ 245          │              │
│ └──────────────┘ └──────────────┘ └──────────────┘              │
│                                                                 │
│ ─────────────────────────────────────────────────────────────── │
│ Program #101 • COMPLETED                          Jan 17, 2026  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 8. Reroute Activation

```
┌─────────────────────────────────────────────────────────────────┐
│ ↩️ REROUTE ACTIVATED - ZNY EAST REROUTE              [BLUE]     │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ **ADVZY 004**                                                   │
│                                                                 │
│ **From:** KORD, KMDW, KMKE                                      │
│ **To:** KJFK, KLGA, KEWR                                        │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Valid                                                       │ │
│ │ January 17, 2026 6:30 PM → January 18, 2026 12:30 AM       │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Reason                                                      │ │
│ │ Convective weather over western Pennsylvania                │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ ─────────────────────────────────────────────────────────────── │
│ Reroute #201 • ACTIVE                             Jan 17, 2026  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 9. NTML Cancellation

```
┌─────────────────────────────────────────────────────────────────┐
│ ❌ MIT CANCELLED - KJFK                              [GRAY]     │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ The MIT has been cancelled.                                     │
│                                                                 │
│ **Reason:** Weather improving, demand reduced                   │
│                                                                 │
│ ─────────────────────────────────────────────────────────────── │
│ NTML Entry #999 • CANCELLED                       Jan 17, 2026  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 10. Advisory Cancellation

```
┌─────────────────────────────────────────────────────────────────┐
│ ❌ ADVZY 001 CANCELLED - KLGA GS                     [GRAY]     │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ The advisory has been cancelled.                                │
│                                                                 │
│ **Reason:** Weather has cleared                                 │
│                                                                 │
│ ─────────────────────────────────────────────────────────────── │
│ Advisory #501 • CANCELLED                         Jan 17, 2026  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Color Reference

| Color | Hex Code | Usage |
|-------|----------|-------|
| 🔴 Red | `#e74c3c` | Critical: GS, MIT, Contingency |
| 🟠 Orange | `#f39c12` | Warning: MINIT, Extensions, CTOP |
| 🟣 Purple | `#9b59b6` | GDP, Reroutes |
| 🔵 Blue | `#3498db` | Info: APREQ, AFP, General |
| 🟢 Green | `#2ecc71` | Success: Purge/Completion |
| ⚫ Gray | `#95a5a6` | Cancelled/Expired |

---

## Emoji Reference

| Type | Emoji | Description |
|------|-------|-------------|
| MIT | 🔴 | Miles-In-Trail restriction |
| MINIT | 🟠 | Minutes-In-Trail restriction |
| DELAY | 🟡 | General delay |
| APREQ | 🔵 | Approval required |
| CONFIG | ⚙️ | Configuration/Runway change |
| CONTINGENCY | ⚠️ | Contingency plan |
| REROUTE | ↩️ | Route change |
| MISC | 📋 | Miscellaneous |
| GS | 🛑 | Ground Stop |
| GDP | ⏱️ | Ground Delay Program |
| AFP | 🌐 | Airspace Flow Program |
| CTOP | 🔷 | Collaborative Trajectory Options Program |
| OPS_PLAN | 📝 | Operations Plan |
| GENERAL | 📢 | General Advisory |

---

## Discord Timestamp Formats

The embeds use Discord's native timestamp formatting which automatically converts to the viewer's local timezone:

| Style | Code | Example Output |
|-------|------|----------------|
| Short Time | `t` | 6:30 PM |
| Long Time | `T` | 6:30:00 PM |
| Short Date | `d` | 01/17/2026 |
| Long Date | `D` | January 17, 2026 |
| Short DateTime | `f` | January 17, 2026 6:30 PM |
| Long DateTime | `F` | Friday, January 17, 2026 6:30 PM |
| Relative | `R` | in 2 hours / 3 hours ago |

The integration uses `f` (Short DateTime) format by default.
