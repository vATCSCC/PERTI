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

1. Navigate to https://vatcscc.azurewebsites.net
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

- Active Traffic Management Initiatives (Ground Stops, GDPs, Reroutes)
- DCC Advisories
- Current operations status
- Weather impacts

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

PERTI runs on Microsoft Azure App Service with databases on Azure SQL and Azure Database for MySQL.

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

Report bugs on [GitHub Issues](../../issues) with:
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

| Term | Definition |
|------|------------|
| **AAR** | Airport Acceptance Rate - arrivals per hour |
| **ADL** | Aeronautical Data Link - flight data system |
| **ADR** | Airport Departure Rate - departures per hour |
| **ARTCC** | Air Route Traffic Control Center |
| **DCC** | Data Communications Center |
| **EDCT** | Expect Departure Clearance Time |
| **GDP** | Ground Delay Program |
| **GS** | Ground Stop |
| **JATOC** | Joint Air Traffic Operations Command |
| **NOD** | NAS Operations Dashboard |
| **OOOI** | Out-Off-On-In (flight phase tracking) |
| **SUA** | Special Use Airspace |
| **TFR** | Temporary Flight Restriction |
| **TMI** | Traffic Management Initiative |
| **TMU** | Traffic Management Unit |
| **TSD** | Traffic Situation Display |

---

## See Also

- [[Getting Started]] - Setup guide
- [[API Reference]] - API documentation
- [[Troubleshooting]] - Detailed troubleshooting guide
