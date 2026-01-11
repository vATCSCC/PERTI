# JATOC - Joint Air Traffic Operations Command

JATOC provides real-time monitoring and management of Air Traffic Control incidents across the virtual NAS.

---

## Overview

JATOC (Joint Air Traffic Operations Command) is the AWO Incident Monitor, providing:
- Real-time incident tracking and visualization
- Operations level monitoring
- Historical incident search
- Special operations calendar (POTUS, Space)
- Personnel roster management

**Access:** Public viewing, authenticated editing
**URL:** `/jatoc.php`

---

## Incident Types

JATOC tracks four categories of ATC incidents:

### ATC Zero

Complete suspension of ATC services at a facility.

| Aspect | Description |
|--------|-------------|
| **Impact** | No traffic handled |
| **Causes** | Staffing emergency, equipment failure, security |
| **Response** | Traffic rerouted, holding patterns |
| **Duration** | Until resolved |

### ATC Alert

Significant degradation of ATC services.

| Aspect | Description |
|--------|-------------|
| **Impact** | Reduced capacity, delays |
| **Causes** | Partial staffing, equipment issues |
| **Response** | Reduced acceptance rates |
| **Duration** | Variable |

### ATC Limited

Reduced but operational ATC services.

| Aspect | Description |
|--------|-------------|
| **Impact** | Limited services available |
| **Causes** | Staffing, training, weather |
| **Response** | Expect delays |
| **Duration** | Often planned |

### Non-Responsive

Communication difficulties with facility.

| Aspect | Description |
|--------|-------------|
| **Impact** | Coordination challenges |
| **Causes** | Technical issues, staffing |
| **Response** | Alternative coordination |
| **Duration** | Until contact restored |

---

## Operations Levels

JATOC displays current operations level:

| Level | Status | Description |
|-------|--------|-------------|
| **1** | Normal | Standard operations |
| **2** | Degraded | Reduced capacity, expect delays |
| **3** | Critical | Severely impacted, major delays |

The operations level is displayed prominently with color coding:
- Level 1: Green
- Level 2: Yellow
- Level 3: Red

---

## User Interface

### Map View

Interactive map displaying:
- ARTCC boundaries
- TRACON boundaries
- Active incidents (color-coded markers)
- Incident radius/impact area

Click incidents on map for details.

### Incident List

Tabular view showing:

| Column | Description |
|--------|-------------|
| **Facility** | Affected ARTCC/TRACON |
| **Type** | Incident category |
| **Level** | Operations level |
| **Started** | Incident start time (Zulu) |
| **Duration** | Time active |
| **Status** | Active/Resolved |

### Incident Details Panel

When an incident is selected:
- Full description
- Timeline of updates
- Affected areas
- Resolution notes
- Related TMIs

---

## Creating an Incident

*Requires DCC role*

1. Click "New Incident"
2. Complete the form:

| Field | Description |
|-------|-------------|
| **Facility** | Select affected facility |
| **Type** | ATC Zero/Alert/Limited/Non-Responsive |
| **Operations Level** | 1, 2, or 3 |
| **Description** | Detailed explanation |
| **Expected Duration** | Estimated time to resolution |
| **Affected Area** | Geographic scope |

3. Click "Create"
4. Incident appears on map and list

---

## Updating an Incident

*Requires DCC role*

1. Select the incident
2. Click "Update"
3. Add update information:
   - Status changes
   - Duration adjustments
   - Resolution notes
4. Click "Save Update"

Updates are timestamped and form a timeline.

---

## Resolving an Incident

*Requires DCC role*

1. Select the active incident
2. Click "Resolve"
3. Add resolution notes
4. Confirm resolution
5. Incident moves to historical records

---

## Historical Search

Search past incidents with multiple criteria:

| Filter | Options |
|--------|---------|
| **Date Range** | Start and end dates |
| **Facility** | Specific ARTCC/TRACON |
| **Type** | Incident category |
| **Duration** | Minimum duration |
| **Operations Level** | Level 2 or 3 only |

Results can be exported for analysis.

---

## Special Operations Calendar

JATOC tracks special operations that may affect ATC:

### POTUS Movements

Presidential movements affecting airspace:
- TFRs
- Reroutes
- Capacity impacts

### Space Operations

Launch and reentry activities:
- Warning areas
- Temporary restrictions
- Duration windows

### Military Exercises

Large-scale military operations:
- SUA activations
- Increased traffic
- Coordination requirements

---

## Personnel Roster

Track JATOC position assignments:

| Position | Responsibility |
|----------|----------------|
| **JATOC Director** | Overall coordination |
| **Operations Officer** | Current operations |
| **Planning Officer** | Upcoming activities |
| **Liaison Officers** | External coordination |

---

## Notifications

When enabled, JATOC sends notifications for:
- New incidents
- Status changes (level changes)
- Resolutions
- Special operations updates

Notification channels:
- Discord webhooks
- In-app notifications

---

## Integration with Other Tools

| Tool | Integration |
|------|-------------|
| **NOD** | Incidents displayed on dashboard |
| **GDT** | Related TMIs linked |
| **Route Plotter** | Affected areas shown on map |

---

## For Different Audiences

### National TMU Personnel

Use JATOC to:
- Monitor nationwide incident status
- Coordinate response to major events
- Track operations level trends
- Plan staffing responses

### Facility TMU

Use JATOC to:
- Report facility incidents
- Monitor neighboring facility status
- Coordinate during events
- Review historical patterns

### ATC Personnel

Use JATOC to:
- Stay aware of system status
- Understand current operations level
- Anticipate traffic impacts
- Coordinate with affected facilities

### Virtual Airline Operators

Use JATOC to:
- Plan flights around incidents
- Anticipate delays
- Monitor operations status
- Make informed dispatch decisions

---

## Best Practices

### When Creating Incidents

- Be specific about the impact
- Provide realistic duration estimates
- Update promptly as conditions change
- Resolve as soon as conditions normalize

### When Monitoring

- Refresh periodically for latest status
- Check before major operations
- Note patterns for planning
- Coordinate with affected parties

---

## See Also

- [[NOD Dashboard]] - Operations overview
- [[GDT Ground Delay Tool]] - TMI management
- [[API Reference]] - JATOC API endpoints
