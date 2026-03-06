# VATSWIM External Connectors

Client libraries and integration stubs for connecting external data sources to the VATSWIM API.

## Available Connectors

### New Client Stubs (this directory)

| Source | Language | Directory | Type | Endpoint |
|--------|----------|-----------|------|----------|
| **vNAS** | C# | [`vnas/`](vnas/) | Push | `/ingest/vnas/track`, `/tags`, `/handoff` |
| **SimTraffic** | Python | [`simtraffic/`](simtraffic/) | Bidirectional | `/ingest/simtraffic` |
| **vACDM** | JavaScript | [`vacdm/`](vacdm/) | Bidirectional | `/ingest/cdm` |
| **ECFMP** | Python | [`ecfmp/`](ecfmp/) | Poll (read-only) | `/tmi/flow/events`, `/measures` |

### Existing Full Client Implementations

These sources already have complete client libraries elsewhere in the codebase:

| Source | Language | Directory | Description |
|--------|----------|-----------|-------------|
| **vATIS** | PHP | [`../vatis/`](../vatis/) | ATIS monitoring, runway correlation, weather extraction |
| **Hoppie ACARS** | PHP | [`../hoppie-cpdlc/`](../hoppie-cpdlc/) | ACARS/CPDLC bridge (Hoppie network) |
| **phpVMS 7** | PHP (Laravel) | [`../virtual-airlines/phpvms7/`](../virtual-airlines/phpvms7/) | Virtual airline PIREP/schedule integration |
| **smartCARS** | PHP | [`../virtual-airlines/smartcars/`](../virtual-airlines/smartcars/) | Webhook-based PIREP integration |
| **VAM** | PHP | [`../virtual-airlines/vam/`](../virtual-airlines/vam/) | REST-based flight sync integration |

## Authentication

All VATSWIM integrations use API keys. Four tiers are available:

| Tier | Prefix | Rate Limit | Use Case |
|------|--------|------------|----------|
| System | `swim_sys_` | 30,000/min | Trusted systems (vNAS, SimTraffic) |
| Partner | `swim_par_` | 3,000/min | Integration partners (VAs, vACDM) |
| Developer | `swim_dev_` | 300/min | Development and testing |
| Public | `swim_pub_` | 100/min | Dashboards and widgets |

Request an API key at: https://perti.vatcscc.org/swim-keys.php

## Health Monitoring

Check connector status:
- **Aggregate**: `GET /api/swim/v1/connectors/health.php` (any valid key)
- **Detailed**: `GET /api/swim/v1/connectors/status.php` (system/partner key)

## Documentation

- [VATSWIM Connector Guide](../../docs/swim/VATSWIM_Connector_Guide.md) - Comprehensive integration guide
- [vACDM Integration](../../docs/swim/vACDM_VATSWIM_Integration.md) - CDM milestone guide
- [ECFMP Integration](../../docs/swim/ECFMP_VATSWIM_Integration.md) - Flow management guide
- [SWIM API Documentation](../../swim-docs.php) - Full API reference
