# PERTI TMI System Documentation Index
**Last Updated:** January 27, 2026

---

## Quick Reference

| Document | Purpose | Location |
|----------|---------|----------|
| **NTML_Advisory_Formatting_Spec.md** | Official NTML & Advisory formats | `PERTI/` |
| **GDT_Unified_Design_Document_v1.md** | GS/GDP/AFP system design | `PERTI/docs/` |
| **NTML_Discord_Parser_Alignment_20260117.md** | Parser & message format alignment | `PERTI/docs/tmi/` |
| **NTML_Advisory_Formatting_Transition.md** | Jan 17 format compliance session | `PERTI/` |
| **TMI_Publisher_v1.8.0_Transition.md** | TMI Publisher v1.8.0 changes | `PERTI/docs/tmi/` |
| **assistant_codebase_index_v18.md** | Full codebase reference | `PERTI/` |

---

## Implementation Status

### Discord Integration (TMIDiscord.php)

| Feature | Status | Notes |
|---------|--------|-------|
| NTML Entry Formatting | ✅ Complete | Per TMIs.pdf spec |
| Delay Entry Formatting | ✅ Complete | D/D, E/D, A/D types |
| Config Entry Formatting | ✅ Complete | VMC/IMC, AAR/ADR |
| GS Advisory | ✅ Complete | FAA TFMS compliant |
| GDP Advisory | ✅ Complete | FAA TFMS compliant |
| Reroute Advisory | ✅ Complete | Protected segments (><) |
| 68-char Line Wrapping | ✅ Complete | IATA Type B format |

### NTML Quick Entry System (ntml.php / ntml.js)

| Feature | Status | Notes |
|---------|--------|-------|
| Natural Language Parser | ✅ Complete | Multiple input formats |
| Batch Entry Mode | ✅ Complete | Multi-line processing |
| Autocomplete | ✅ Complete | Facilities, fixes |
| Validation | ✅ Complete | Required field checks |
| Discord Posting | ✅ Complete | Format aligned with spec |
| Parser/Message Alignment | ✅ Complete | Matches NTML_2020.txt format |

### TMI Publisher (tmi-publish.php / tmi-publish.js)

| Feature | Status | Notes |
|---------|--------|-------|
| NTML Entry Forms | ✅ Complete | MIT, MINIT, STOP, APREQ, TBM, DELAY, CONFIG, CANCEL |
| Advisory Forms | ✅ Complete | Ops Plan, Free Form, Hotline, SWAP |
| Queue Management | ✅ Complete | Add, preview, remove, clear |
| Staging/Production Modes | ✅ Complete | Mode toggle with confirmation |
| User Profile System | ✅ Complete | v1.8.0 - OI, facility storage |
| Hotline Advisory | ✅ Complete | v1.8.0 - PERTI Plan alignment |
| Facility Selectors | ✅ Complete | v1.8.0 - Dropdown + text input |
| Source Filter | ✅ Complete | v1.8.0 - Production/Staging/All |
| Active TMI Display | ✅ Complete | v1.7.0 - FAA-style table |
| Category:Cause | ✅ Complete | v1.6.0 - OPSNET reasons |
| Airport CONFIG Presets | 📋 Planned | Database integration needed |

### GDT System (gdt.php)

| Feature | Status | Notes |
|---------|--------|-------|
| Schema Design | ✅ Complete | See GDT design doc |
| Database Migration | ✅ Complete | Jan 21, 2026 - 010/011/012 deployed |
| GDT Tables | ✅ Complete | tmi_flight_control, tmi_popup_queue |
| GDT Views | ✅ Complete | 6 views deployed + gs_flag |
| GDT Procedures | ✅ Complete | 12 procedures deployed |
| GS Eligibility Flag | ✅ Complete | Migration 009 - phase-based gs_flag |
| Ground Stop UI | 🔄 In Progress | Basic implementation |
| GDP UI | 📋 Planned | After GS complete |
| API Layer | 🔄 In Progress | /api/gdt/* endpoints |
| Daemon Integration | 📋 Planned | Pop-up detection |

---

## Key Format References

### NTML Entry Format
```
DD/HHMM [APT] [direction] via [FIX] ##MIT [QUALIFIERS] TYPE:x SPD:x ALT:x VOLUME:x WEATHER:x EXCL:x HHMM-HHMM REQ:PROV
```

### Advisory Header Format
```
vATCSCC ADVZY ### APT/CTR MM/DD/YYYY [TYPE]
```

### Advisory Footer Format
```
ddhhmm-ddhhmm
YY/MM/DD HH:MM
```

---

## Source Documents

| Document | Description | Location |
|----------|-------------|----------|
| TMIs.pdf | NTML Guide (vATCSCC internal) | User provided |
| Advisories_and_General_Messages_v1_3.pdf | FAA Advisory spec | Project knowledge |
| FSM_9_0_Training_Guide.pdf | FSM operations | Project knowledge |
| R10_ADL_File_Specification_v14_1.pdf | ADL format spec | Project knowledge |
| TFMDI_ICD.pdf | TFMS interface spec | Project knowledge |

---

## Related Code Files

### Discord Module
- `load/discord/DiscordAPI.php` - Base Discord API class
- `load/discord/TMIDiscord.php` - TMI-specific formatting & posting

### NTML System
- `ntml.php` - Quick entry UI
- `assets/js/ntml.js` - Client-side parser & validation
- `api/mgt/ntml/post.php` - API endpoint

### Advisory System
- `advisory-builder.php` - Advisory builder UI
- `assets/js/advisory-builder.js` - Client-side builder logic
- `api/nod/advisories.php` - Advisory API endpoint
- `api/nod/discord-post.php` - Discord posting endpoint

### TMI Publisher System
- `tmi-publish.php` - Unified TMI publisher UI (v1.8.0)
- `assets/js/tmi-publish.js` - Client-side publisher logic
- `assets/js/tmi-active-display.js` - Active TMI display component
- `assets/css/tmi-publish.css` - Publisher styling
- `api/mgt/tmi/publish.php` - TMI posting endpoint
- `api/mgt/tmi/active.php` - Active TMI retrieval
- `api/mgt/tmi/staged.php` - Staged entries retrieval

### GDT System
- `gdt.php` - GDT management UI
- `api/mgt/gdt/*.php` - GDT API endpoints
- `database/migrations/tmi_*.sql` - Schema migrations

### SWIM TMI API
- `api/swim/v1/tmi/index.php` - TMI API overview
- `api/swim/v1/tmi/entries.php` - NTML log entries
- `api/swim/v1/tmi/advisories.php` - Formal advisories
- `api/swim/v1/tmi/reroutes.php` - Reroute definitions
- `api/swim/v1/tmi/routes.php` - Public route display (GeoJSON)
- `api/swim/v1/tmi/programs.php` - GS/GDP programs
- `api/swim/v1/tmi/controlled.php` - Controlled flights

---

## Session History

| Date | Summary | Transition Doc |
|------|---------|----------------|
| Jan 22, 2025 | NTML Quick Entry redesign | (legacy, no doc) |
| Jan 9, 2026 | GDT system design | docs/GDT_Unified_Design_Document_v1.md |
| Jan 17, 2026 | TMI API infrastructure | docs/tmi/SESSION_TRANSITION_20260117.md |
| Jan 17, 2026 | Format compliance | NTML_Advisory_Formatting_Transition.md |
| Jan 17, 2026 | Parser & Discord alignment | docs/tmi/NTML_Discord_Parser_Alignment_20260117.md |
| Jan 21, 2026 | GDT Database Migration | GDT_Incremental_Migration.md |
| Jan 21, 2026 | GS Eligibility Fix | docs/tmi/GS_Eligibility_Fix_Transition.md |
| Jan 27, 2026 | TMI Publisher v1.8.0 | docs/tmi/TMI_Publisher_v1.8.0_Transition.md |

---

## Next Steps

1. **Test NTML Discord posting** - Deploy and verify output matches NTML_2020.txt examples (test script: `scripts/tmi/test_ntml_format.php`)
2. **Test Advisory format** - Validate against ADVZY_2020.txt (test script: `scripts/tmi/test_advisory_format.php`)
3. **Complete GDT UI** - Ground Stop implementation
4. **Edge case testing** - Multiple airports, complex qualifiers, holding patterns
5. **Discord bot integration** - Update bot to call PHP API directly

---

## Test Scripts

| Script | Purpose | Location |
|--------|---------|----------|
| `test_ntml_format.php` | NTML message format validation | `scripts/tmi/` |
| `test_advisory_format.php` | Advisory TFMS format validation | `scripts/tmi/` |
| `test_ntml_edge_cases.php` | Complex parsing scenarios | `scripts/tmi/` |
| `test_crud.php` | TMI API CRUD operations | `scripts/tmi/` |

---

## Notes

- All times in documentation and code are UTC (Zulu)
- 68-character line limit applies to all advisory text
- Protected route segments use >< markers
- VATSIM adaptations noted where they differ from FAA spec
