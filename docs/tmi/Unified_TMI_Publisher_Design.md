# Unified TMI Publisher Design Document
## One-Stop NTML & Advisory Publishing System

**Version:** 1.0  
**Date:** January 27, 2026  
**Status:** Design & Implementation  
**Location:** `docs/tmi/Unified_TMI_Publisher_Design.md`

---

## 1. Executive Summary

This document defines the architecture for a unified TMI (Traffic Management Initiative) publishing system that consolidates NTML entries and Advisories from multiple sources into a single interface, with bidirectional Discord synchronization and multi-Discord support.

### 1.1 Design Goals

| Goal | Description |
|------|-------------|
| **Unified UI** | Single page (`tmi-publish.php`) for both NTML Quick Entry and Advisory Builder |
| **Single Database** | All data flows to Azure SQL `VATSIM_TMI` database |
| **Bidirectional Discord** | Post to Discord AND parse incoming Discord messages |
| **Multi-Discord** | User chooses which Discord(s) to post to (vATCSCC, VATCAN, ECFMP, etc.) |
| **TypeForm Replacement** | Built-in form replaces external TypeForm+Zapier dependency |
| **SWIM Accessible** | All data exposed via existing SWIM TMI API endpoints |
| **Conference/Approval** | Real-world style coordination and approval workflow |

### 1.2 Key Decisions (Confirmed)

| Question | Decision |
|----------|----------|
| **Bot Strategy** | Single bot invited to all servers (vATCSCC, VATCAN, ECFMP, etc.) |
| **VATCAN Credentials** | VATCAN will provide channel IDs and invite bot |
| **TypeForm Migration** | Migrate existing data if possible |
| **Approval Workflow** | Both manual button and Discord reaction; conference mechanism later |
| **Permissions** | Privileged users → all Discords; otherwise org-specific unless cross-border TMI |

---

## 2. System Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      UNIFIED TMI PUBLISHER SYSTEM                           │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                    USER INPUT SOURCES                               │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│       │                        │                        │                   │
│       ▼                        ▼                        ▼                   │
│  ┌─────────────┐      ┌─────────────────┐      ┌─────────────────┐        │
│  │  PERTI Web  │      │  Discord Bot/   │      │  TypeForm       │        │
│  │  Unified    │      │  Webhook        │      │  (Legacy →      │        │
│  │  Publisher  │      │  (Inbound Parse)│      │   Phase Out)    │        │
│  └──────┬──────┘      └────────┬────────┘      └────────┬────────┘        │
│         │                      │                        │                   │
│         │         ┌────────────┴────────────┐           │                   │
│         │         │ Discord Message Parser  │◄──────────┘                   │
│         │         │ (Contingency Mode)      │                               │
│         │         └────────────┬────────────┘                               │
│         │                      │                                            │
│         ▼                      ▼                                            │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                    UNIFIED TMI API                                   │   │
│  │                    api/mgt/tmi/publish.php                          │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                │                                            │
│         ┌──────────────────────┼──────────────────────┐                    │
│         ▼                      ▼                      ▼                    │
│  ┌─────────────┐      ┌─────────────────┐      ┌─────────────────┐        │
│  │ VATSIM_TMI  │      │  Discord        │      │  Audit/Events   │        │
│  │ Database    │      │  Outbound Post  │      │  Logging        │        │
│  │ (Azure SQL) │      │  (Multi-Discord)│      │  (tmi_events)   │        │
│  └─────────────┘      └─────────────────┘      └─────────────────┘        │
│         │                      │                                            │
│         │         ┌────────────┴────────────┐                               │
│         │         │   Discord Targets       │                               │
│         │         │   ┌─────────────────┐   │                               │
│         │         │   │ vATCSCC Discord │   │                               │
│         │         │   │ - #ntml         │   │                               │
│         │         │   │ - #advisories   │   │                               │
│         │         │   │ - #staging      │   │                               │
│         │         │   └─────────────────┘   │                               │
│         │         │   ┌─────────────────┐   │                               │
│         │         │   │ VATCAN Discord  │   │                               │
│         │         │   │ - #ntml         │   │                               │
│         │         │   │ - #advisories   │   │                               │
│         │         │   └─────────────────┘   │                               │
│         │         │   ┌─────────────────┐   │                               │
│         │         │   │ ECFMP Discord   │   │                               │
│         │         │   │ - TBD           │   │                               │
│         │         │   └─────────────────┘   │                               │
│         │         │   ┌─────────────────┐   │                               │
│         │         │   │ Future Orgs...  │   │                               │
│         │         │   └─────────────────┘   │                               │
│         │         └─────────────────────────┘                               │
│         │                                                                   │
│         ▼                                                                   │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                    SWIM TMI API (Public)                             │   │
│  │                    /api/swim/v1/tmi/*                                │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Database Schema (Existing - VATSIM_TMI)

The `VATSIM_TMI` database already has all required tables. Key fields for this integration:

### 3.1 tmi_entries (NTML Log)

| Column | Purpose |
|--------|---------|
| `source_type` | 'PERTI', 'DISCORD', 'TYPEFORM', 'API' |
| `source_id` | External reference (TypeForm response ID, etc.) |
| `source_channel` | Discord channel name if from Discord |
| `discord_message_id` | For bidirectional sync (JSON for multi-Discord) |
| `discord_channel_id` | Target channel(s) - JSON for multi-Discord |
| `content_hash` | Deduplication for Discord parsing |

### 3.2 tmi_advisories (Formal Advisories)

Same source tracking fields plus:

| Column | Purpose |
|--------|---------|
| `program_id` | Link to GDT program if GS/GDP |
| `reroute_id` | Link to reroute if REROUTE type |

### 3.3 New: tmi_discord_posts (Multi-Discord Tracking)

```sql
CREATE TABLE dbo.tmi_discord_posts (
    post_id             INT IDENTITY(1,1) PRIMARY KEY,
    entity_type         NVARCHAR(16) NOT NULL,      -- 'ENTRY', 'ADVISORY', 'PROGRAM'
    entity_id           INT NOT NULL,
    
    -- Discord Target
    org_code            NVARCHAR(16) NOT NULL,      -- 'vatcscc', 'vatcan', 'ecfmp'
    channel_purpose     NVARCHAR(32) NOT NULL,      -- 'ntml', 'advisories', 'ntml_staging'
    channel_id          NVARCHAR(64) NOT NULL,
    
    -- Message Info
    message_id          NVARCHAR(64) NULL,
    message_url         NVARCHAR(256) NULL,
    
    -- Status
    status              NVARCHAR(16) NOT NULL DEFAULT 'PENDING',
                        -- PENDING, POSTED, FAILED, DELETED, PROMOTED
    error_message       NVARCHAR(512) NULL,
    
    -- Timestamps
    requested_at        DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    posted_at           DATETIME2(0) NULL,
    promoted_at         DATETIME2(0) NULL,
    
    INDEX IX_discord_posts_entity (entity_type, entity_id),
    INDEX IX_discord_posts_org (org_code, channel_purpose),
    INDEX IX_discord_posts_message (message_id) WHERE message_id IS NOT NULL
);
```

---

## 4. Multi-Discord Configuration

### 4.1 DISCORD_ORGANIZATIONS Constant

```php
// In config.php
define('DISCORD_ORGANIZATIONS', json_encode([
    'vatcscc' => [
        'name' => 'vATCSCC',
        'region' => 'US',
        'guild_id' => '358294607974539265',
        'channels' => [
            'ntml' => '358295136398082048',
            'advisories' => '358300240236773376',
            'ntml_staging' => '912499730335010886',
            'advzy_staging' => '1008478301251194951'
        ],
        'default' => true,
        'enabled' => true
    ],
    'vatcscc_backup' => [
        'name' => 'vATCSCC Backup',
        'region' => 'US',
        'guild_id' => 'BACKUP_GUILD_ID',
        'channels' => [
            'ntml' => '1350319537526014062',
            'advisories' => '1447715453425418251',
            'ntml_staging' => '1039586515115839621',
            'advzy_staging' => '1039586515115839622'
        ],
        'default' => false,
        'enabled' => true,
        'testing_only' => true
    ],
    'vatcan' => [
        'name' => 'VATCAN',
        'region' => 'CA',
        'guild_id' => null,                         // VATCAN to provide
        'channels' => [
            'ntml' => null,                         // VATCAN to provide
            'advisories' => null                    // VATCAN to provide
        ],
        'default' => false,
        'enabled' => false                          // Enable when credentials received
    ],
    'ecfmp' => [
        'name' => 'ECFMP',
        'region' => 'EU',
        'guild_id' => null,                         // Future
        'channels' => [],
        'default' => false,
        'enabled' => false
    ]
]));

// Single bot token used for all servers
define('DISCORD_BOT_TOKEN', 'your-bot-token-here');
define('DISCORD_APPLICATION_ID', '1447711207703183370');
```

### 4.2 Cross-Border TMI Logic

```php
/**
 * Determine which Discord orgs should receive a TMI based on scope
 */
function getTargetOrgs($entry, $userOrg, $isPrivileged) {
    // Privileged users can post anywhere
    if ($isPrivileged) {
        return getEnabledOrgs();
    }
    
    // Check if cross-border
    $isCrossBorder = isCrossBorderTMI($entry);
    
    if ($isCrossBorder) {
        // Cross-border TMIs go to all relevant regions
        return getOrgsForRegions($entry['affected_regions']);
    }
    
    // Otherwise, user's org only
    return [$userOrg];
}

function isCrossBorderTMI($entry) {
    // Check if TMI affects facilities in multiple regions
    $facilities = array_merge(
        [$entry['requesting_facility'] ?? ''],
        [$entry['providing_facility'] ?? ''],
        explode(',', $entry['scope_facilities'] ?? '')
    );
    
    $regions = [];
    foreach ($facilities as $fac) {
        $region = getFacilityRegion(trim($fac));
        if ($region) $regions[$region] = true;
    }
    
    return count($regions) > 1;
}
```

---

## 5. Unified Publisher Page (tmi-publish.php)

### 5.1 UI Layout

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ TMI Publisher                                           [UTC Clock] [User] │
├─────────────────────────────────────────────────────────────────────────────┤
│ ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐                │
│ │ [x] NTML Entry  │ │ [ ] Advisory    │ │ [ ] Program     │  Entry Type   │
│ └─────────────────┘ └─────────────────┘ └─────────────────┘                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │  NTML Quick Entry Panel (when NTML selected)                         │  │
│  │  ┌────────────────────────────────────────────────────────────────┐  │  │
│  │  │ [Quick Entry Input - Natural Language]                         │  │  │
│  │  │ 20MIT ZBW→ZNY JFK LENDY VOLUME 1400-1800                      │  │  │
│  │  └────────────────────────────────────────────────────────────────┘  │  │
│  │  [MIT Arrival] [MIT Dep] [MINIT] [STOP] [CFR] [TBM] [Config]        │  │
│  │                                                                      │  │
│  │  Entry Queue: [3 entries]                         [Clear] [Preview]  │  │
│  │  ┌────────────────────────────────────────────────────────────────┐  │  │
│  │  │ [05B01] 20MIT ZBW→ZNY JFK LENDY... [Edit] [Remove]            │  │  │
│  │  │ [06A02] 10MINIT ZOB→ZNY CLE...     [Edit] [Remove]            │  │  │
│  │  └────────────────────────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
│                                                                             │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │  Advisory Builder Panel (when Advisory selected)                     │  │
│  │  [GDP] [GS] [AFP] [CTOP] [Reroute] [Free-Form] [MIT] [Cancel]       │  │
│  │  ┌──────────────────────────────────┬─────────────────────────────┐  │  │
│  │  │ Advisory Configuration Form          │ Live Preview            │  │  │
│  │  │ - Type-specific fields               │ ┌─────────────────────┐ │  │  │
│  │  │ - Timing                             │ │vATCSCC ADVZY 001... │ │  │  │
│  │  │ - Scope                              │ │...                  │ │  │  │
│  │  │ - Reason                             │ └─────────────────────┘ │  │  │
│  │  └──────────────────────────────────┴─────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
│                                                                             │
├─────────────────────────────────────────────────────────────────────────────┤
│  Target Discord(s):                                                        │
│  [x] vATCSCC   [ ] VATCAN   [ ] ECFMP   [x] Post to Staging First         │
│                                                                             │
│  [ ] Production Mode                    [Submit to NTML] [Publish Advisory]│
└─────────────────────────────────────────────────────────────────────────────┘
```

### 5.2 Features

1. **Tab-Based Entry Type Selection**: NTML Entry | Advisory | GDT Program
2. **NTML Quick Entry**: Natural language parser (existing ntml.js)
3. **Advisory Builder**: Form-based builder (existing advisory-builder.js)
4. **Multi-Discord Target Selection**: Checkboxes for enabled organizations
5. **Staging Mode**: Post to staging channels first, then promote to production
6. **Entry Queue**: Batch multiple NTML entries before publishing
7. **Live Preview**: Real-time preview of formatted output
8. **Unified API**: Single backend endpoint for all publishing
9. **Cross-Border Detection**: Auto-suggest additional orgs for cross-border TMIs

---

## 6. Approval & Conference Workflow

### 6.1 Workflow States

```
DRAFT → STAGED → COORDINATING → APPROVED → PUBLISHED → EXPIRED/CANCELLED
```

| State | Description |
|-------|-------------|
| `DRAFT` | Entry created but not posted anywhere |
| `STAGED` | Posted to staging channel(s) for review |
| `COORDINATING` | Conference in progress (multi-facility TMIs) |
| `APPROVED` | Approved by authorized user(s) |
| `PUBLISHED` | Posted to production channel(s) |
| `EXPIRED` | Past valid_until time |
| `CANCELLED` | Manually cancelled |

### 6.2 Approval Methods

1. **Manual Button**: User clicks "Promote to Production" in PERTI UI
2. **Discord Reaction**: Authorized user adds ✅ reaction to staging message
3. **Conference Approval**: All required facilities approve via reactions or UI

### 6.3 Conference Mechanism (Future Enhancement)

For TMIs requiring multi-facility coordination:

```
┌─────────────────────────────────────────────────────────────────┐
│  TMI Conference: 20MIT ZBW→ZNY JFK via LENDY                   │
├─────────────────────────────────────────────────────────────────┤
│  Requesting: ZNY                                                │
│  Providing:  ZBW                                                │
│                                                                 │
│  Approvals Required:                                            │
│  ┌─────────────┬──────────────┬─────────────────────────────┐  │
│  │ Facility    │ Status       │ Approved By                 │  │
│  ├─────────────┼──────────────┼─────────────────────────────┤  │
│  │ ZNY (Req)   │ ✅ Approved  │ John D. (TMC) @ 1423Z       │  │
│  │ ZBW (Prov)  │ ⏳ Pending   │ -                           │  │
│  └─────────────┴──────────────┴─────────────────────────────┘  │
│                                                                 │
│  [Cancel Conference]                    [Remind Pending]        │
└─────────────────────────────────────────────────────────────────┘
```

---

## 7. API Endpoints

### 7.1 New Unified Publish Endpoint

**POST /api/mgt/tmi/publish.php**

```json
{
    "entry_type": "NTML",
    "data": {
        "type": "MIT",
        "determinant": "05B01",
        "distance": 20,
        "from_facility": "ZBW",
        "to_facility": "ZNY",
        "condition": "JFK",
        "via_route": "LENDY",
        "reason": "VOLUME",
        "valid_from": "1400",
        "valid_until": "1800"
    },
    "targets": {
        "orgs": ["vatcscc", "vatcan"],
        "staging": true,
        "production": false
    },
    "source": "PERTI",
    "require_conference": false
}
```

**Response:**
```json
{
    "success": true,
    "entry_id": 12345,
    "entry_guid": "abc-123-def",
    "status": "STAGED",
    "discord_results": {
        "vatcscc": {
            "ntml_staging": {
                "message_id": "123456789",
                "message_url": "https://discord.com/channels/.../...",
                "success": true
            }
        },
        "vatcan": {
            "ntml_staging": {
                "message_id": "987654321",
                "message_url": "https://discord.com/channels/.../...",
                "success": true
            }
        }
    }
}
```

### 7.2 Promote Endpoint

**POST /api/mgt/tmi/promote.php**

```json
{
    "entry_id": 12345,
    "entry_type": "NTML",
    "orgs": ["vatcscc"]
}
```

### 7.3 Discord Inbound Webhook

**POST /api/webhook/discord.php**

Receives Discord webhook events for:
- New messages in #ntml or #advisories channels
- Reactions (for approval workflow)
- Message edits/deletes (for sync)

### 7.4 Existing SWIM Endpoints (No Changes Needed)

- GET /api/swim/v1/tmi/entries - Active NTML entries
- GET /api/swim/v1/tmi/advisories - Active advisories
- GET /api/swim/v1/tmi/programs - Active GS/GDP programs

---

## 8. Bidirectional Discord Flow

### 8.1 Outbound (PERTI → Discord)

```
User submits via Unified Publisher
        │
        ▼
API validates and saves to VATSIM_TMI
        │
        ▼
Create tmi_discord_posts records for each target
        │
        ▼
Format message using TMIDiscord.php
        │
        ▼
Post to each selected Discord org via MultiDiscordAPI
        │
        ▼
Update tmi_discord_posts with message_id
```

### 8.2 Inbound (Discord → PERTI)

```
Message posted to #ntml or #advisories
        │
        ▼
Discord Webhook triggers
        │
        ▼
/api/webhook/discord.php receives event
        │
        ▼
Identify org from guild_id
        │
        ▼
Parse message using TMIDiscordParser
        │
        ▼
Check content_hash for deduplication
        │
        ▼
If new: Create tmi_entry with source_type='DISCORD'
If existing: Update discord tracking (we sent this)
```

### 8.3 Reaction Handling (Approval)

```
User adds ✅ reaction to staging message
        │
        ▼
Discord Webhook receives REACTION_ADD event
        │
        ▼
Lookup entry by message_id in tmi_discord_posts
        │
        ▼
Check if user is authorized to approve
        │
        ▼
If conference: Record facility approval
If single-approval: Promote to production
        │
        ▼
Post to production channel(s)
        │
        ▼
Update tmi_discord_posts status to PROMOTED
```

### 8.4 Deduplication Logic

```php
function shouldCreateEntry($parsed, $channelId, $orgCode) {
    // Calculate content hash
    $hash = md5(json_encode([
        $parsed['type'],
        $parsed['determinant'],
        $parsed['condition'],
        $parsed['valid_from'],
        $parsed['valid_until']
    ]));
    
    // Check if we created this message (outbound)
    $existing = $db->query("
        SELECT e.entry_id 
        FROM tmi_entries e
        JOIN tmi_discord_posts p ON p.entity_type = 'ENTRY' AND p.entity_id = e.entry_id
        WHERE p.channel_id = ? AND e.content_hash = ?
    ", [$channelId, $hash]);
    
    if ($existing) {
        // We sent this - don't duplicate
        return false;
    }
    
    // New entry from Discord - create it
    return true;
}
```

---

## 9. TypeForm Migration

### 9.1 TypeForm API Integration

```php
// Fetch existing TypeForm responses
$typeformToken = 'YOUR_TYPEFORM_API_TOKEN';
$formId = 'YOUR_FORM_ID';

$responses = fetchTypeFormResponses($typeformToken, $formId);

foreach ($responses as $response) {
    $entry = mapTypeFormToTmiEntry($response);
    $entry['source_type'] = 'TYPEFORM';
    $entry['source_id'] = $response['response_id'];
    
    // Check for duplicates
    if (!entryExists($entry)) {
        insertTmiEntry($entry);
    }
}
```

### 9.2 Field Mapping

| TypeForm Field | TMI Entry Field |
|----------------|-----------------|
| Facility | requesting_facility |
| Subject | condition_text |
| Message Type | entry_type |
| Valid From | valid_from |
| Valid Until | valid_until |
| Submitted At | created_at |
| Response ID | source_id |

---

## 10. Implementation Plan

### Phase 1: Multi-Discord Infrastructure (Day 1) ✅ COMPLETE
- [x] Design document created
- [x] Create MultiDiscordAPI.php wrapper class (`load/discord/MultiDiscordAPI.php`)
- [x] Update config.example.php with DISCORD_ORGANIZATIONS
- [x] Create tmi_discord_posts table migration (`database/migrations/tmi/016_tmi_discord_posts.sql`)
- [x] Update TMIDiscord v3.5.0 to support multi-target posting
- [x] Create test script (`scripts/test_multi_discord.php`)
- [ ] Run migration on VATSIM_TMI database
- [ ] Test posting to vATCSCC backup/staging channels

### Phase 2: Unified Publisher Page (Day 2) ✅ COMPLETE
- [x] Create tmi-publish.php combining ntml.php + advisory-builder.php
- [x] Implement tab-based entry type selection
- [x] Add Discord target checkboxes with org detection
- [x] Create tmi-publish.js unified controller
- [x] Create tmi-publish.css styles
- [x] Add cross-border TMI detection (basic)
- [ ] Wire up existing ntml.js parser (full integration)

### Phase 3: Unified API Endpoint (Day 2-3) ✅ COMPLETE
- [x] Create api/mgt/tmi/publish.php
- [x] Migrate NTML message formatting logic
- [x] Add advisory publishing logic
- [x] Implement multi-Discord posting with tmi_discord_posts tracking
- [x] Create api/mgt/tmi/promote.php for staging→production
- [x] Create api/mgt/tmi/staged.php for listing staged entries
- [x] Add promotion UI to tmi-publish.js

### Phase 4: Discord Inbound Webhook (Day 3-4)
- [ ] Create api/webhook/discord.php
- [ ] Implement message parsing (port ntml.js logic to PHP)
- [ ] Add deduplication logic
- [ ] Implement reaction handling for approvals
- [ ] Configure Discord webhook in Developer Portal

### Phase 5: Approval Workflow (Day 4)
- [ ] Add manual "Promote to Production" button
- [ ] Implement Discord reaction approval
- [ ] Create approval audit trail
- [ ] Add notification for pending approvals

### Phase 6: TypeForm Migration & Cleanup (Day 5)
- [ ] Fetch and migrate existing TypeForm responses
- [ ] Test full flow: PERTI → Discord → Database → SWIM API
- [ ] Test bidirectional sync (Discord → Database)
- [ ] Document TypeForm → PERTI migration for users
- [ ] Update user documentation

### Phase 7: Conference Mechanism (Future)
- [ ] Design conference workflow UI
- [ ] Implement multi-facility approval tracking
- [ ] Add conference reminder notifications
- [ ] Create conference audit trail

---

## 11. File Structure

```
PERTI/
├── tmi-publish.php                     # ✅ Phase 2: Unified publisher page
├── assets/
│   ├── js/
│   │   ├── tmi-publish.js              # ✅ Phase 2: Unified publisher JS
│   │   ├── ntml.js                     # Existing (refactor to module)
│   │   └── advisory-builder.js         # Existing (refactor to module)
│   └── css/
│       └── tmi-publish.css             # ✅ Phase 2: Unified styles
├── load/
│   └── discord/
│       ├── DiscordAPI.php              # Existing
│       ├── MultiDiscordAPI.php         # ✅ Phase 1: Multi-Discord wrapper
│       ├── TMIDiscord.php              # ✅ Phase 1: Updated v3.5.0 for multi-target
│       ├── DiscordMessageParser.php    # Existing: Inbound message parser
│       └── DiscordWebhookHandler.php   # Existing: Webhook handler
├── api/
│   ├── mgt/
│   │   └── tmi/
│   │       ├── publish.php             # ✅ Phase 3: Unified publish endpoint
│   │       ├── promote.php             # ✅ Phase 3: Staging→production promotion
│   │       └── staged.php              # ✅ Phase 3: List staged entries
│   └── webhook/
│       └── discord.php                 # Phase 4: Discord webhook handler
├── scripts/
│   └── test_multi_discord.php          # ✅ Phase 1: Multi-Discord test script
├── database/
│   └── migrations/
│       └── tmi/
│           └── 016_tmi_discord_posts.sql   # ✅ Phase 1: Multi-Discord tracking
└── docs/
    └── tmi/
        └── Unified_TMI_Publisher_Design.md  # THIS FILE
```

---

## 12. Configuration Requirements

### 12.1 Credentials Needed

| Credential | Source | Status |
|------------|--------|--------|
| `DISCORD_BOT_TOKEN` | Discord Dev Portal | ✅ Have (vATCSCC) |
| `DISCORD_APPLICATION_ID` | Discord Dev Portal | ✅ Have: 1447711207703183370 |
| VATCAN Guild ID | VATCAN to provide | ⏳ Pending |
| VATCAN Channel IDs | VATCAN to provide | ⏳ Pending |
| ECFMP Guild/Channels | Future | 📋 Planned |

### 12.2 vATCSCC Channel IDs (Confirmed)

| Channel | ID |
|---------|-----|
| #ntml | 358295136398082048 |
| #advisories | 358300240236773376 |
| #ntml-staging | 912499730335010886 |
| #advzy-staging | 1008478301251194951 |

### 12.3 vATCSCC Backup Channel IDs (For Testing)

| Channel | ID |
|---------|-----|
| #ntml | 1350319537526014062 |
| #advisories | 1447715453425418251 |
| #⚠ntml-staging⚠ | 1039586515115839621 |
| #⚠advzy-staging⚠ | 1039586515115839622 |

---

## 13. Change Log

| Version | Date | Changes |
|---------|------|---------||
| 1.0 | 2026-01-27 | Initial design document |
| 1.1 | 2026-01-27 | v1.6.0: Hotline Activation boilerplate, Category:Cause reasons per OPSNET, NTML Zapier format alignment |

---

## 14. Related Documents

- `GDT_Unified_Design_Document_v1.1.md` - GS/GDP system design (root)
- `NTML_Quick_Entry_Transition.md` - NTML parser documentation
- `001_tmi_core_schema_azure_sql.sql` - Database schema
