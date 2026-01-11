# NOD Dashboard

The NAS Operations Dashboard provides consolidated monitoring of active TMIs and system status.

**URL:** `/nod.php`
**Access:** Public (view), Authenticated (edit)

---

## Features

- Active TMI display (Ground Stops, GDPs, Reroutes)
- DCC advisory management
- Operations level indicator
- Flight track visualization
- Weather integration

---

## Dashboard Panels

### Active TMIs

Displays all currently active Traffic Management Initiatives:
- Ground Stops with affected airports
- Ground Delay Programs with rates
- Active reroutes

### Advisories

DCC advisories for coordination and awareness:
- Create, edit, and expire advisories
- Category filtering
- Search functionality

### Operations Status

Current NAS operations level with active incident count.

---

## Data Refresh

| Data Type | Refresh Interval |
|-----------|------------------|
| Active TMIs | 30 seconds |
| Advisories | 60 seconds |
| Ops level | 30 seconds |

---

## See Also

- [[JATOC]] - Incident monitoring
- [[GDT Ground Delay Tool]] - TMI management
- [[API Reference]] - NOD APIs
