# TMI Publisher v2.0 - Multi-Facility Coordination

**Release Date:** January 2026
**System:** PERTI Traffic Management Initiative (TMI) Publisher

---

## Overview

The TMI Publisher now supports Discord-based multi-facility coordination for Traffic Management Initiatives that affect multiple ARTCCs. This workflow ensures affected facilities can review and approve TMIs before activation.

---

## Key Features

### Discord Coordination Workflow

When a TMI requires coordination across facilities (e.g., a MIT from ZNY affecting ZDC traffic):

1. **Proposal Submission** - TMI is submitted via the Publisher with coordination enabled
2. **Discord Thread** - A coordination thread is created in the `#coordination` channel
3. **Facility Approval** - Affected facilities react with their facility emoji to approve
4. **Deadline Enforcement** - Proposals expire if not approved by the specified deadline
5. **Publication Queue** - Approved proposals appear in the Publisher queue for final review
6. **Manual Activation** - User clicks "Publish" to activate the TMI and post to Discord

### Approval Methods

Facilities can approve proposals using:

- **Custom Facility Emoji** - e.g., :ZNY:, :ZDC:, :ZOB:
- **Regional Indicator Letters** - Fallback method using letter emoji

### DCC Override

DCC Staff and NTMO personnel can override the approval process when operational requirements dictate immediate action.

### Internal TMI Auto-Approval

TMIs that only affect facilities within the same responsible ARTCC are automatically approved without coordination.

---

## User Interface Changes

### Proposals Tab

- **Pending Proposals** - Yellow highlighting, shows approval progress (e.g., "1/2 approved")
- **Approved Proposals** - Green highlighting, shows "Ready" badge with Publish button
- **Edit Capability** - Approved proposals can be edited, which restarts coordination

### Coordination Controls

- **Approval Deadline** - Defaults to TMI start time minus 1 minute
- **Facilities Selection** - Auto-populated based on TMI scope
- **Extend Deadline** - Available for pending proposals approaching deadline

---

## Technical Details

### API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/mgt/tmi/coordinate.php` | GET | List pending/approved proposals |
| `/api/mgt/tmi/coordinate.php` | POST | Submit new proposal |
| `/api/mgt/tmi/coordinate.php` | PUT | Process Discord reaction |
| `/api/mgt/tmi/coordinate.php` | DELETE | Cancel proposal |

### Database Tables

- `tmi_proposals` - Proposal metadata and status
- `tmi_proposal_facilities` - Required facility approvals
- `tmi_proposal_reactions` - Discord reaction audit log

### Discord Bot

A Node.js Gateway bot provides real-time reaction processing. The bot listens for reactions on coordination threads and immediately calls the PHP API to update approval status.

---

## Testing Environment

TMI coordination is currently deployed to the backup Discord server for testing:

**Join:** https://discord.gg/P5ZtKNzd

Production deployment to the main VATUSA Discord will require DCC staff coordination for server credentials and bot permissions.

---

## Documentation

- [TMI System README](README.md) - System overview and API reference
- [Coordination Session Notes](TMI_Coordination_Session_20260128.md) - Detailed workflow documentation
- [Discord Bot README](../../discord-bot/README.md) - Bot deployment guide

---

## Support

For questions or issues, contact DCC staff or submit feedback via the PERTI issue tracker.
