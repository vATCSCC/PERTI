# Discord Announcement - TMI Coordination v2.0

Copy each section below as a separate Discord message.

---

## MESSAGE 1 - Overview (~900 chars)

```
**TMI Publisher v2.0 - Multi-Facility Coordination**

**Release:** January 2026
**System:** PERTI Traffic Management Initiative Publisher

The TMI Publisher now supports Discord-based multi-facility coordination for Traffic Management Initiatives that affect multiple ARTCCs. This workflow ensures affected facilities can review and approve TMIs before activation.

**Discord Coordination Workflow:**

1. **Proposal Submission** - TMI is submitted via the Publisher with coordination enabled
2. **Discord Thread** - A coordination thread is created in #coordination
3. **Facility Approval** - Affected facilities react with their facility emoji to approve
4. **Deadline Enforcement** - Proposals expire if not approved by the specified deadline
5. **Publication Queue** - Approved proposals appear in the Publisher queue for final review
6. **Manual Activation** - User clicks "Publish" to activate the TMI and post to Discord
```

---

## MESSAGE 2 - Features (~950 chars)

```
**TMI Coordination Features**

**Approval Methods:**
- Custom Facility Emoji (e.g., :ZNY:, :ZDC:, :ZOB:)
- Regional Indicator Letters (fallback method)

**DCC Override:**
DCC Staff and NTMO personnel can override the approval process when operational requirements dictate immediate action.

**Internal TMI Auto-Approval:**
TMIs that only affect facilities within the same responsible ARTCC are automatically approved without coordination.

**User Interface - Proposals Tab:**
- Pending Proposals: Yellow highlighting, shows approval progress (e.g., "1/2 approved")
- Approved Proposals: Green highlighting, shows "Ready" badge with Publish button
- Edit Capability: Approved proposals can be edited, which restarts coordination

**Coordination Controls:**
- Approval Deadline: Defaults to TMI start time minus 1 minute
- Facilities Selection: Auto-populated based on TMI scope
- Extend Deadline: Available for pending proposals approaching deadline
```

---

## MESSAGE 3 - Testing & Support (~650 chars)

```
**Testing Environment**

TMI coordination is currently deployed to the backup Discord server for testing:
https://discord.gg/P5ZtKNzd

Production deployment to the main VATUSA Discord will require DCC staff coordination for server credentials and bot permissions.

**Technical Details:**
- API Endpoint: `/api/mgt/tmi/coordinate.php`
- Database: `tmi_proposals`, `tmi_proposal_facilities`, `tmi_proposal_reactions`
- Discord Bot: Node.js Gateway bot for real-time reaction processing

**Documentation:**
https://github.com/vATCSCC/PERTI/blob/main/docs/tmi/README.md

**Support:**
For questions or issues, contact DCC staff.
```

---

## Character Counts

- Message 1: ~900 characters
- Message 2: ~950 characters
- Message 3: ~650 characters

All messages are under Discord's 2000 character limit.
