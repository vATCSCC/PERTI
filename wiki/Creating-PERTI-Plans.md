# Creating PERTI Plans

This guide walks through creating and managing PERTI event plans for both privileged (editing) and non-privileged (read-only) users.

---

## Overview

PERTI Plans are comprehensive event planning documents used to coordinate traffic flow management during major VATSIM events. Plans include operational goals, staffing assignments, runway configurations, terminal and en-route initiatives, and advisories.

**Access URL:** https://perti.vatcscc.org/plan.php?p={plan_id}

---

## User Roles

| Role | Capabilities |
|------|--------------|
| **Non-Privileged** | View all plan sections, read-only access |
| **Privileged (DCC)** | Full editing, create goals, manage staffing, publish advisories |

---

## Non-Privileged User Walkthrough

### Step 1: Access the Plan

1. Navigate to the plan URL provided (e.g., `https://perti.vatcscc.org/plan.php?p=42`)
2. Login with your VATSIM account if prompted
3. The plan interface loads with the Overview tab active

### Step 2: Navigate Plan Sections

Use the **tabbed navigation bar** to explore different sections:

| Tab | Content |
|-----|---------|
| **Overview** | Event banner, operational goals, key objectives |
| **DCC Staffing** | Command Center staffing assignments |
| **Historical** | Past event data for reference |
| **Weather** | Weather forecast and expected conditions |
| **Terminal Initiatives** | Ground stops, GDPs, arrival/departure programs |
| **Terminal Staffing** | TRACON and tower position assignments |
| **Field Configs** | Runway configurations by airport |
| **Terminal Planning** | Detailed terminal procedures and notes |
| **En-Route Initiatives** | Miles-in-trail, reroutes, flow constraints |
| **En-Route Staffing** | ARTCC sector staffing |
| **En-Route Planning** | High-altitude coordination procedures |
| **Group Flights** | Coordinated formation or charter flights |
| **Extended Outlook** | Long-term event outlook and contingencies |
| **Advisory Builder** | Published advisories (view only) |
| **Ops Plan** | Structured FAA-format operational plan output (v18) |
| **Splits** | Sector split configurations with map visualization (v18) |

### Step 3: View Operational Goals

On the **Overview** tab:

1. The event banner displays at the top
2. Below, **Operational Goals** are listed as cards
3. Each goal shows:
   - Goal title and description
   - Priority level
   - Responsible party
   - Status indicator

### Step 4: Review Staffing

On **DCC Staffing**, **Terminal Staffing**, or **En-Route Staffing** tabs:

1. View position assignments by time block
2. See assigned controller CIDs and names
3. Note any gaps or conflicts highlighted

### Step 5: Check Field Configurations

On the **Field Configs** tab:

1. Select an airport from the dropdown
2. View expected runway configuration
3. See associated AAR (Arrival Rate) and ADR (Departure Rate)
4. Note weather category assumptions (VMC, IMC, etc.)

### Step 6: Review Initiatives

On **Terminal Initiatives** or **En-Route Initiatives**:

1. View planned TMIs (Traffic Management Initiatives)
2. Each initiative shows:
   - Type (Ground Stop, GDP, MIT, Reroute)
   - Affected airports/airspace
   - Start/end times (UTC)
   - Scope and reason

---

## Privileged User Walkthrough

Privileged users have full editing capabilities. All non-privileged features apply, plus the following.

### Creating a New Plan

1. Navigate to `https://perti.vatcscc.org/plan.php` (no plan ID)
2. Click **Create New Plan**
3. Fill in the **Plan Details** form:

| Field | Description | Example |
|-------|-------------|---------|
| **Event Name** | Official event title | "Cross the Pond Eastbound 2026" |
| **Event Date** | Primary event date | 2026-03-15 |
| **Start Time** | Event start (UTC) | 18:00Z |
| **End Date** | Event end date | 2026-03-15 |
| **End Time** | Event end (UTC) | 04:00Z |
| **Op Level** | Operational level (1-3) | 2 |

4. Click **Create Plan**
5. A Plan ID is auto-generated and you're redirected to the new plan's editing interface

### Adding Operational Goals

1. Go to the **Overview** tab
2. Click **+ Add Goal** button
3. In the modal form, enter:
   - **Goal Title** - Concise objective
   - **Description** - Detailed explanation
   - **Priority** - High, Medium, Low
   - **Owner** - Responsible party/facility
4. Click **Save Goal**
5. The goal appears in the goals list

### Editing Goals

1. Click the **Edit** (pencil) icon on any goal card
2. Modify fields as needed
3. Click **Update Goal**

### Managing Staffing

#### DCC Staffing

1. Go to **DCC Staffing** tab
2. Click **+ Add Position**
3. Enter:
   - Position name
   - Time block (start/end UTC)
   - Assigned CID or "Open"
4. Click **Save**

#### Terminal/En-Route Staffing

1. Navigate to the appropriate staffing tab
2. Select facility from dropdown
3. Click **+ Add Assignment**
4. Fill in position, time, and assignee
5. Save the assignment

### Configuring Field Configurations

1. Go to **Field Configs** tab
2. Select airport
3. Click **+ Add Configuration**
4. Enter:
   - **Config Name** - e.g., "South Flow VMC"
   - **Arrival Runways** - e.g., "13L, 13R"
   - **Departure Runways** - e.g., "13L, 13R, 4L"
   - **AAR** - Arrival rate per hour
   - **ADR** - Departure rate per hour
   - **Weather Category** - VMC, LVMC, IMC, LIMC, VLIMC
5. Save configuration

### Creating Initiatives

#### Terminal Initiative (Ground Stop/GDP)

1. Go to **Terminal Initiatives** tab
2. Click **+ Add Initiative**
3. Select initiative type:
   - **Ground Stop** - All departures held
   - **GDP** - Controlled departure times (EDCTs)
   - **Arrival Delay Program**
   - **Departure Delay Program**
4. Fill in details:
   - Affected airport(s)
   - Start/end times (UTC)
   - Scope tier (1, 2, 3, or all)
   - Reason/remarks
5. Save initiative

#### En-Route Initiative (MIT/Reroute)

1. Go to **En-Route Initiatives** tab
2. Click **+ Add Initiative**
3. Select type:
   - **Miles-in-Trail (MIT)** - Spacing requirement
   - **Minutes-in-Trail (MINIT)**
   - **Reroute** - Alternative routing
   - **Ground Delay** - En-route induced
4. Enter:
   - Affected fix/airspace
   - Spacing value (for MIT/MINIT)
   - Route string (for reroutes)
   - Effective times
5. Save initiative

### Adding Planning Notes

On **Terminal Planning** or **En-Route Planning** tabs:

1. Use the **Summernote rich text editor**
2. Add detailed procedures, coordination notes, and contingencies
3. Format with headers, lists, and tables as needed
4. Click **Save** to persist changes

### Building Advisories

1. Go to **Advisory Builder** tab
2. Click **+ Create Advisory**
3. Select advisory type:
   - DCC Advisory
   - Facility Advisory
   - Special Advisory
4. Enter:
   - **Title** - Brief summary
   - **Body** - Full advisory text
   - **Effective Time** - Start/end UTC
   - **Facilities** - Affected facilities
5. Preview the advisory
6. Click **Publish** to make it live on NOD

### Using the Ops Plan Tab (v18)

The **Ops Plan** tab generates a structured FAA-format operational plan output:

1. Go to the **Ops Plan** tab
2. The plan auto-generates formatted sections from your plan data:
   - Operational goals
   - Staffing summary (with ARTCC grouping)
   - Airport configurations
   - Initiative timeline
   - Weather/constraints summary
3. Copy the formatted output for Discord or document distribution
4. Facility entries are grouped by ARTCC for multi-facility events

### Using the Splits Tab (v18)

The **Splits** tab shows sector configuration and map visualization:

1. Go to the **Splits** tab
2. View active and scheduled sector splits for the event's ARTCC(s)
3. The interactive map shows sector boundaries with strata filtering (low/high/superhigh)
4. Personnel tables show assigned controller positions
5. Configurations can be modified or scheduled from this tab

See [[Splits]] for detailed splits documentation.

### Sortable Columns (v18)

Plan page tables (staffing, configs, initiatives) support column sorting:

- Click a column header to sort ascending
- Click again to sort descending
- A sort indicator shows the active sort column and direction

### Publishing the Plan

1. Review all sections for completeness
2. Verify staffing assignments
3. Check initiative times are correct
4. Click **Publish Plan** on the Overview tab
5. The plan becomes visible to all users
6. Discord notifications are sent automatically

---

## Best Practices

### For Non-Privileged Users

- Bookmark frequently accessed plans
- Check the plan before each event shift
- Note any changes from previous versions
- Contact DCC if you spot errors

### For Privileged Users

- Create plans at least 48 hours before events
- Use clear, concise goal descriptions
- Double-check all times are in UTC
- Coordinate with facilities before publishing
- Update the plan as conditions change
- Archive completed plans for historical reference

---

## Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `Tab` | Navigate between sections |
| `Ctrl+S` | Save current section (when editing) |
| `Esc` | Close modal dialogs |

---

## Troubleshooting

### "Access Denied" on Edit

You don't have DCC privileges. Contact administration if you believe you should have editing access.

### Changes Not Saving

- Check your internet connection
- Ensure all required fields are filled
- Look for validation errors highlighted in red
- Try refreshing the page and re-entering

### Plan Not Appearing on NOD

- Verify the plan is published (not draft)
- Check the event date is current or future
- Ensure at least one initiative is active

---

## See Also

- [[GDT Ground Delay Tool]] - Managing ground stops and GDPs
- [[NOD Dashboard]] - Viewing published plans and advisories
- [[FAQ]] - Common questions
