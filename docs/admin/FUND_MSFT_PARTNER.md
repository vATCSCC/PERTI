# Microsoft Partner Funding (Azure Accelerate / Modernization) - Submission Copy

Date: 2026-02-17
Org: Virtual Air Traffic Control System Command Center (vATCSCC)
EIN: 82-3089665
Primary contact: dev@vatcscc.org
Website: https://perti.vatcscc.org

Goal:
- Obtain partner nomination for Microsoft-funded modernization/optimization support (Azure Accelerate or current successor program).

Link (partner portal entry point):
- https://partner.microsoft.com/en-us/partnership/azure-offerings

Public reference links:
- Platform status: https://perti.vatcscc.org/status
- Transparency: https://perti.vatcscc.org/transparency
- FMDS comparison: https://perti.vatcscc.org/fmds-comparison
- VATSWIM overview: https://perti.vatcscc.org/swim
- VATSWIM technical docs: https://perti.vatcscc.org/swim-docs
- GitHub repo: https://github.com/vATCSCC/PERTI
- GitHub wiki: https://github.com/vATCSCC/PERTI/wiki
- GitHub issues: https://github.com/vATCSCC/PERTI/issues

## How To Find A Microsoft Partner (Practical Guide)

Microsoft-funded modernization/optimization support is typically accessed via a Microsoft partner. Use this process to find partners who can nominate you and can support Azure SQL optimization work.

Shortlist sources:
- Microsoft Marketplace Partner Directory: https://marketplace.microsoft.com/en-us/marketplace/partner-dir
- Microsoft Marketplace Consulting Services: https://marketplace.microsoft.com/en-us/marketplace/consulting-services

Quick-start search URLs (use "Contact me" on relevant listings):
- Azure + SQL: https://marketplace.microsoft.com/en-us/search/professional-services?product=azure%3Bdata-platform&search=sql&page=1&country=US
- Azure + FinOps: https://marketplace.microsoft.com/en-us/search/professional-services?product=azure&search=finops&page=1&country=US

Suggested filters / search terms:
- Keywords: Azure SQL, Azure Database, Hyperscale, serverless, performance tuning, query optimization, stored procedures, observability, FinOps, cost optimization.
- Look for partners with "Solutions Partner" designations (especially Data & AI (Azure) / Infrastructure (Azure)) and any database/analytics specializations.

Fast screening questions (15 minutes per partner):
- Do they explicitly mention Azure SQL / database performance or FinOps/cost optimization?
- Do they have nonprofit/community experience (or pro bono/in-kind programs)?
- Are they willing to do a light-touch engagement (partner nomination + limited advisory) without operational control?

Outreach sequence:
- Contact 10-15 partners using the email template below.
- Ask for a 20-minute call and confirm whether they can nominate you for Microsoft-funded support (Azure Accelerate or the current successor program).
- On the call, confirm they accept the independence clause (no operational control / governance rights).

## Paste-Ready Email To A Microsoft Partner
Subject: Request partner nomination: Azure Accelerate for vATCSCC (Azure SQL optimization + multi-source data quality)

Hi [Partner name],

I'm reaching out on behalf of vATCSCC (EIN 82-3089665). We operate a 24/7, data-driven command center platform for the global VATSIM aviation simulation network. Our platform is Azure-based and our dominant cost driver is Azure SQL Hyperscale Serverless (VATSIM_ADL).

Current spend context:
- Azure last 31 days: $4,263 total
- Azure SQL last 31 days: ~$4,042 (~95%)
- Annualized run-rate: ~ $50k/year (recent 31-day average)

Why we need funding:
- Computational and data load are high and expected to grow with network flight counts.
- We need sustained, predictable support for our ongoing Azure production run-rate (not just a short-term optimization window) so we can keep production stable while we reduce costs and scale to demand.
- We already tier processing using flight-awareness and other helpers, but tiering forces temporal/spatial resolution trade-offs. With adequate capacity and targeted optimization, we can avoid forced downscaling and increase resolution where it improves outcomes.
- We operate VATSWIM (a SWIM/FIXM-aligned exchange API) and want to expand multi-source push/pull integrations to improve data quality across the network. Multi-source ingestion, reconciliation, and distribution increases infrastructure and engineering needs.

Phase 1 deliverables (first 90 days):
- Reliability: supervision/auto-restart for ingest daemons; defensive reconnect handling; alert if no new ingest writes > 2 minutes.
- Cost optimization: archival/retention/compression; targeted Azure SQL stored procedure and query optimization to reduce vCore-hours while preserving peak headroom.
- Data quality: expand VATSWIM integrations; implement reconciliation/data authority rules; publish completeness/latency metrics.

Request:
Would your team be willing to evaluate and, if appropriate, nominate vATCSCC for Azure Accelerate (or the current Microsoft partner funding pathway) to support this modernization effort? We can provide architecture, cost snapshots, a prioritized optimization backlog, and measurable acceptance criteria.

Governance note:
We can accept restricted funding and reporting requirements, but we cannot accept terms that grant operational control, governance rights, or decision authority over vATCSCC's DCC or platform direction.

Thanks,
Jeremy Peterson
vATCSCC / DCC
`dev@vatcscc.org`

## Attachments (If Useful)
- Cost snapshot + service breakdown
- 1-page architecture overview
- Project milestones + acceptance criteria
- Incident case study: 2026-02-17 ingest outage summary + remediation plan
- VATSWIM overview: https://perti.vatcscc.org/swim
- VATSWIM technical docs: https://perti.vatcscc.org/swim-docs
