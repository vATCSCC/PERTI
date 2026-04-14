# GTIA Gives Grants - Outreach and Proposal Copy

Date: 2026-02-17
Org: Virtual Air Traffic Control System Command Center (vATCSCC)
EIN: 82-3089665
Primary contact: dev@vatcscc.org
Website: https://perti.vatcscc.org

Link:
- https://gtia.org/giving/grants
Email:
- giving@gtia.org

Status (as of 2026-02-19):
- Reply received from Emily Gaines (`egaines@gtia.org`): GTIA will announce the 2026 cycle soon and advised monitoring `https://gtia.org/giving/grants` for updates.
- Current status: warm contact established; awaiting public opening/announcement of the 2026 cycle.

Public reference links:
- Platform status: https://perti.vatcscc.org/status
- Transparency: https://perti.vatcscc.org/transparency
- FMDS comparison: https://perti.vatcscc.org/fmds-comparison
- VATSWIM overview: https://perti.vatcscc.org/swim
- VATSWIM technical docs: https://perti.vatcscc.org/swim-docs
- GitHub repo: https://github.com/vATCSCC/PERTI
- GitHub wiki: https://github.com/vATCSCC/PERTI/wiki
- GitHub issues: https://github.com/vATCSCC/PERTI/issues

Eligibility note:
- Confirm whether GTIA Gives accepts private operating foundations. vATCSCC is a 501(c)(3) private foundation per org records.

## Paste-Ready Outreach Email
Subject: Inquiry: next GTIA Gives grant cycle (vATCSCC cloud reliability + data quality)

Hello GTIA Gives team,

I'm writing to ask about the next GTIA Gives grant cycle and whether a U.S. 501(c)(3) nonprofit private operating foundation is eligible to apply. vATCSCC operates a 24/7, data-driven command center platform that supports large-scale aviation simulation events on the global VATSIM network.

Our core operational dependency is Azure cloud infrastructure (primarily Azure SQL). Computational and data load are high and expected to grow with network flight counts. We already tier processing to manage costs, but we want to avoid forced downscaling and increase temporal/spatial resolution where it improves operational outcomes.

We also operate VATSWIM (a SWIM/FIXM-aligned data exchange API) and want to expand multi-source push/pull integrations to improve data quality across the network. That work requires additional infrastructure for ingestion, validation, reconciliation, and observability.

If you can share the next application window, eligibility requirements, and any guidance on fit, we would appreciate it.

Thank you,
Jeremy Peterson
vATCSCC / DCC
`dev@vatcscc.org`

## Paste-Ready Short Proposal Paragraph (150-250 words)
vATCSCC operates the Virtual Air Traffic Control System Command Center, a 24/7 platform that supports high-traffic aviation simulation events through real-time data ingest, coordinated flow initiatives, and post-event analytics. Our infrastructure is Azure-based and dominated by a mission-critical Azure SQL workload that must scale during peak events. As network flight counts grow, computational and data load increases, and without sustainable funding we are forced to downscale data fidelity to control costs.

Requested funds will support the PERTI Cloud Reliability and Cost Optimization Initiative, delivering measurable improvements in platform uptime, real-time data freshness monitoring, and cost governance. Over 90 days, we will implement supervision/auto-restart for ingest, alerting when no new data writes occur, data lifecycle policies (archival/retention/compression) to control growth, and targeted SQL performance tuning to reduce vCore-hours while preserving peak headroom.

We will also expand multi-source data quality work via VATSWIM (a SWIM/FIXM-aligned exchange API), enabling push/pull integrations and reconciliation rules that improve completeness and consistency for downstream airspace users across the network. We will report quarterly on cost run-rate, reliability, and data quality metrics and provide a technical summary of delivered optimizations.
