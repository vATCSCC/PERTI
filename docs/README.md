# PERTI Documentation

## Directory Structure

| Directory | Contents | Files |
|-----------|----------|-------|
| `admin/` | Funding packets, use cases, organizational documents | 12 |
| `analysis/` | Strategic analyses, CTP scenarios, route expansion verification | 16 |
| `audits/` | Code quality audits, dependency maps, UI audits, reorganization catalog | 13 |
| `infra/` | Azure cost optimization, MySQL 8 upgrade, PowerBI, VATSIM_STATS | 4 |
| `operations/` | Deployment guide, hibernation runbook, status, incidents, i18n tracking | 7 |
| `plans/` | Feature design and implementation plans (active) | 11 |
| `plans/archive/` | Shipped feature plans (preserved for reference) | 24 |
| `reference/` | Technical references — computational algorithms, API quick-ref, vNAS ecosystem | 3 |
| `refs/` | Codebase globalization and migration references | 2 |
| `scaling/` | Scaling and performance analysis | 1 |
| `simulator/` | ATFM training simulator design and deployment docs | 4 |
| `standards/` | Coding standards, naming conventions, patterns, migration tracker | 7 |
| `superpowers/specs/` | AI-assisted design specifications (active) | -- |
| `superpowers/specs/archive/` | Shipped AI-assisted specs (preserved for reference) | 15 |
| `superpowers/plans/` | AI-assisted implementation plans | -- |
| `swim/` | VATSWIM API documentation, integration guides, data standards | 23 |
| `tmi/` | TMI system docs — GDT, Discord, publisher, coordination | 23 |
| `stats/` | Statistics documentation (deployed with API) | -- |
| `discord-threads/` | Archived Discord thread exports | -- |

## Key Entry Points

- **New to PERTI?** Start with the [wiki](../wiki/Home.md)
- **Deploying?** See [Deployment Guide](operations/DEPLOYMENT_GUIDE.md)
- **Algorithm details?** See [Computational Reference](reference/COMPUTATIONAL_REFERENCE.md)
- **API consumers?** See [SWIM README](swim/README.md) or [API Quick Reference](reference/QUICK_REFERENCE.md)
- **TMI system?** See [TMI README](tmi/README.md)

## Archive Policy

`archive/` subdirectories contain shipped specs, plans, and session transition files.
These are preserved for historical reference but are no longer actively maintained.
A spec/plan is archived when its feature is deployed to production on `main`.
