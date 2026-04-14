# Ray Foundation - Inquiry and Proposal Copy

Date: 2026-02-17
Org: Virtual Air Traffic Control System Command Center (vATCSCC)
EIN: 82-3089665
Primary contact: dev@vatcscc.org
Website: https://perti.vatcscc.org

Link:
- https://rayfoundation.us/grant-requests/
Contact:
- info@rayfoundation.us

Status (as of 2026-02-17):
- Email inquiry sent to `info@rayfoundation.us`; awaiting response on fit and preferred submission format.

Public reference links:
- Platform status: https://perti.vatcscc.org/status
- Transparency: https://perti.vatcscc.org/transparency
- FMDS comparison: https://perti.vatcscc.org/fmds-comparison
- VATSWIM overview: https://perti.vatcscc.org/swim
- VATSWIM technical docs: https://perti.vatcscc.org/swim-docs
- GitHub repo: https://github.com/vATCSCC/PERTI
- GitHub wiki: https://github.com/vATCSCC/PERTI/wiki
- GitHub issues: https://github.com/vATCSCC/PERTI/issues

## Paste-Ready Initial Inquiry Email
Subject: Funding request inquiry: platform resiliency + multi-source aviation data quality (vATCSCC)

Hello Ray Foundation team,

I'm reaching out on behalf of the Virtual Air Traffic Control System Command Center (vATCSCC), a U.S. 501(c)(3) nonprofit (EIN 82-3089665). We operate a 24/7, data-driven command center platform that coordinates air traffic flow during large-scale aviation simulation events and generates post-event analytics to improve operational performance.

We are seeking support for a defined project to improve platform resilience and reduce long-run cloud infrastructure costs (primarily Azure SQL). Computational and data load are high and expected to grow with network flight counts; we already tier processing to manage cost, but we want to avoid forced downscaling and increase temporal/spatial resolution where it improves outcomes.

We also operate VATSWIM (a SWIM/FIXM-aligned data exchange API for the VATSIM ecosystem) and are expanding multi-source push/pull integrations to improve data quality across the network. Multi-source ingestion, validation, reconciliation, and distribution increases infrastructure requirements beyond a single-source architecture.

If the Ray Foundation is open to proposals in this area, we would welcome guidance on the best submission format and any priority areas you would like us to address. We can provide our IRS determination letter and recent 990, along with a concise project plan and budget.

Respectfully,
Jeremy Peterson
vATCSCC / DCC
`dev@vatcscc.org`

## Paste-Ready Proposal Narrative (250-450 words)
vATCSCC operates the Virtual Air Traffic Control System Command Center: a 24/7 platform that ingests real-time operational data from a large-scale aviation simulation network and supports planning, execution, and post-event review of traffic management initiatives. During major events, thousands of flights converge on constrained airspace and airports. Without system-level coordination, restrictions become fragmented, delays become unpredictable, and workload spikes across multiple facilities.

Our platform provides centralized, data-driven coordination and produces measurable performance analytics so the community can improve over time. This capability depends on reliable and scalable cloud infrastructure, dominated by Azure SQL for our operational database workload. After migrating to Azure SQL Hyperscale Serverless to meet performance requirements, our cloud run-rate increased substantially and now requires sustainable funding and deliberate cost governance.

The underlying driver is growth: as network flight counts increase, computational and data load increases, and without adequate capacity we are forced to downscale fidelity. We already tier processing based on flight-awareness and other helpers, but tiering is ultimately a trade-off. Sustainable funding allows us to improve efficiency and increase temporal/spatial resolution where it improves operational outcomes.

We are requesting support for the PERTI Cloud Reliability and Cost Optimization Initiative. Over 90 days, we will deliver (1) supervision/auto-restart and defensive reconnect handling to prevent prolonged ingest outages, (2) monitoring and alerting on data freshness, (3) database archiving/compression/retention to control growth, (4) targeted SQL performance tuning to reduce vCore-hours at peak, and (5) multi-source data quality improvements via VATSWIM integrations so we can enhance completeness and consistency for downstream airspace users across the network.

We will report quarterly on reliability, cost, and data quality metrics and provide a technical summary of delivered optimizations. Governance note: vATCSCC can accept restricted funding and reporting requirements, but cannot accept terms that grant operational control or decision authority over vATCSCC's DCC or platform direction.
