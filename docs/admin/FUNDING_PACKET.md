# PERTI / vATCSCC Azure Funding Packet (Application Copy + Templates)

**Version:** 1.0  
**Date:** 2026-02-17  
**Prepared for:** Funding partners, sponsors, and grantmakers

**Organization:** Virtual Air Traffic Control System Command Center (vATCSCC)  
**EIN:** 82-3089665  
**Legal status (per org records):** U.S. 501(c)(3) nonprofit; private foundation (operating foundation).  
**Primary contact:** `dev@vatcscc.org`

> Notes
> - This packet is designed to be paste-ready. Replace anything marked `[CUSTOMIZE]`.
> - Some programs require a public charity (not a private foundation) or a Canadian registered charity; eligibility gates are called out explicitly.
> - Because vATCSCC is a private foundation (operating foundation) per org records, confirm eligibility for each program; some corporate nonprofit programs explicitly exclude private foundations.
> - Cost figures below are from Azure Cost Management queries as of 2026-02-17.

## Contents
- 1) One-Page Summary
- 2) Current Azure Cost Profile
- 3) Project Narrative
- 4) Governance, Independence, and Acceptable Terms
- 5) Funder-Specific Templates
- 6) Attachments and Evidence Checklist
- 7) Application Tracking Table
- 8) Appendix

Disclaimer: This packet is not legal/tax advice. Confirm eligibility and terms with each funder and, if needed, your counsel.

---

## 1) One-Page Summary (Paste-Ready)

### 1.1 Elevator Pitch (2 sentences)
vATCSCC operates the Virtual Air Traffic Control System Command Center: a 24/7, data-driven air traffic flow coordination capability for the global VATSIM simulation network. We are seeking support to fund and stabilize our Azure cloud infrastructure (primarily Azure SQL) and to complete a focused reliability and cost-optimization initiative that reduces outage risk and controls cloud spend during peak events.

### 1.2 The Problem
- Our platform ingests and processes a high-volume, real-time aviation data feed; computational load and data volume are high and expected to grow with network flight counts.
- We already use flight-awareness and tiered processing to prioritize compute, but tiering forces trade-offs in temporal/spatial resolution; without sustainable funding we must downscale fidelity as demand grows.
- We want to push and pull from multiple sources (including VATSWIM integrations) to improve data quality for ourselves and other airspace users across the network; multi-source ingestion, reconciliation, and distribution requires additional compute, storage, and monitoring.
- In December 2025, migrating VATSIM_ADL to Hyperscale Serverless increased monthly infrastructure cost from roughly ~$670/month to ~$3,500-$4,300/month, dominated by SQL compute.
- The 2026-02-17 ADL ingest outage (Azure SQL transient availability event + reconnect-handling crash) is an example of how transient cloud events can compound into downtime without additional resiliency investment. The immediate defect is patched, and we are implementing supervision/alerting to prevent recurrence.

### 1.3 The Project (What You're Funding)
**Project name:** PERTI Cloud Reliability & Cost Optimization Initiative  
**Duration:** 90 days (initial), with ongoing reporting.

Deliverables:
- Reliability hardening: process supervision/auto-restart for the ingest daemon; defensive reconnect handling; staged rollout and validation.
- Observability & alerting: "no-ingest-write" alarms (e.g., >2 minutes), health dashboards, and incident runbooks.
- Database cost optimization: archive legacy tables, apply compression, implement retention for growth-heavy tables, and optimize top stored procedures to reduce vCore consumption at peak.
- Resolution improvements: maintain and increase temporal/spatial resolution (reduce downsampling) for key operational and research analytics, enabled by optimization and adequate capacity.
- Multi-source data quality (VATSWIM): expand push/pull integrations, reconcile multiple authorities, improve completeness/latency, and publish higher-quality outputs for downstream consumers.
- Cost governance: monthly cost review, forecast/run-rate reporting, and proactive scaling policies for predictable event spikes.

### 1.4 Funding Ask (Choose One Tier)
- $10,000: Reliability + monitoring milestones (supervision, alerting, runbooks, validation).
- $25,000: Reliability + cost-optimization Phase 1-2 (compression + changelog retention + archive plan + one major stored-proc optimization).
- $50,000: Covers the current annualized Azure run-rate (~$50k/year) and buffers growth while we complete the above optimizations.

### 1.5 Why It Matters (Impact)
The Command Center coordinates traffic management initiatives across multiple facilities during peak events, reducing congestion and improving predictability. Stable and resilient infrastructure prevents "silent failures" (like ingest stalls) and ensures operators and downstream systems have timely, accurate data for decision-making.

### 1.6 Independence & Ethics (Non-Negotiables)
We can accept restricted funds for clearly defined deliverables and transparency reporting, but we cannot accept any funding terms that grant operational control, governance rights, or decision authority over vATCSCC's DCC or platform direction. Any relationship must preserve independent ownership and mission control by vATCSCC.

---

## 2) Current Azure Cost Profile (For Budget Sections)

### 2.1 31-Day Snapshot (as of 2026-02-17)
- Last 31 days total: $4,263.39
- Annualized run-rate (last 31-day average): ~$50,198/year
- Service breakdown (last 31 days):
  - SQL Database: $4,041.99
  - Azure Database for MySQL: $89.51
  - Azure App Service: $81.70
  - Azure Database for PostgreSQL: $37.98
  - Storage: $9.73
  - Other: minimal

### 2.2 Primary Cost Driver
- Top resource by cost (last 31 days): Azure SQL DB `vatsim_adl` at $3,839.17.

### 2.3 Narrative (Paste-Ready)
Our Azure costs are dominated by a single mission-critical workload: the VATSIM_ADL Hyperscale Serverless database that supports real-time ingest and analytics during peak events. This architecture enables performance and concurrency headroom during major network events, but it creates a step-change in monthly costs relative to the pre-Hyperscale footprint. Demand and data volume are expected to increase as the network grows; we already tier processing to manage compute, but tiering forces resolution trade-offs. Our funding request is designed to (1) ensure continuity of operations and (2) accelerate targeted optimizations that reduce long-run costs while improving reliability and preserving data fidelity.

---

## 3) Project Narrative (Long Form, Paste-Ready)

### 3.1 Organization Description (100 words)
The Virtual Air Traffic Control System Command Center (vATCSCC) coordinates air traffic flow across multiple facilities during VATSIM events and high-traffic periods. We operate a 24/7 platform that ingests live network flight data, supports planning and operational execution, and produces post-event review metrics so the community can continuously improve. Our work focuses on system-level coordination, data-driven decision support, and operational resilience for large-scale, real-time aviation simulation environments.

### 3.2 Need Statement (200-300 words)
vATCSCC's platform depends on reliable, scalable cloud infrastructure to ingest and process a high-volume, real-time aviation data feed and to support command-center operations during peak events. In late 2025, we migrated our primary database workload (VATSIM_ADL) to Azure SQL Hyperscale Serverless to address performance limits and concurrency requirements. This change improved scalability but increased monthly infrastructure costs from approximately ~$670/month to a sustained ~$3,500-$4,300/month, with SQL compute accounting for the overwhelming majority of spend.

The underlying driver is growth. As network flight counts and event scale increase, both compute demand (routing, GIS/boundary detection, analytics) and data volume (changelog, trajectory, history) increase. We already use flight-awareness and other helpers to tier data processing, but tiering is ultimately a trade-off: to control costs, data must be processed and stored at different resolutions. Sustainable funding enables us to avoid forced downscaling and to selectively increase temporal and spatial resolution where it improves operational decision-making and research outcomes.

We also aim to improve data quality by pushing and pulling from multiple sources across the network. vATCSCC operates VATSWIM (a SWIM/FIXM-aligned exchange API for the VATSIM ecosystem), and expanding multi-source ingestion and reconciliation improves completeness, reduces inconsistencies, and enables higher-quality outputs for other airspace users and partners. This capability increases infrastructure needs (ingest pipelines, validation, storage, and observability) beyond a single-source architecture.

Reliability remains a core risk, especially under transient cloud events and increasing load. On 2026-02-17, a transient Azure SQL availability event (error 40613) caused our ingest daemon to enter a reconnect path that crashed due to a PHP error-handling defect. The immediate bug is patched; however, the incident highlights a broader need for robust process supervision, defensive reconnection strategies, and "no-ingest-write" monitoring to prevent a single fault from causing prolonged data gaps.

This request funds a focused reliability and cost-optimization initiative that directly protects operational continuity and reduces long-term cloud spend. The result is a more resilient platform with transparent cost governance and measurable reliability improvements.

### 3.3 Project Description (500-900 words)
Project name: PERTI Cloud Reliability and Cost Optimization Initiative

Goal: Maintain mission continuity during peak events while controlling Azure cost growth and reducing outage risk.

Workstream A: Reliability hardening (Weeks 1-4)
- Add process supervision/auto-restart for the ingest daemon so a crash cannot cause a multi-hour ingest outage.
- Standardize defensive reconnect handling (including catching PHP Throwable failure modes) so transient SQL failures cannot trigger a fatal termination.
- Add ingest freshness monitoring (e.g., MAX(last_seen_utc) recency) and alert if no new writes are observed beyond a defined threshold (recommended: alert at 2 minutes).
- Create incident runbooks and post-deploy validation checks (process running, recency advancing, error rate).

Workstream B: Observability and incident response (Weeks 1-6)
- Create a single health view that operators can use during events (ingest freshness, SQL error rate, key job status).
- Implement alert routing (email + [CUSTOMIZE: Discord/Teams]) with a clear on-call escalation path.
- Define and report SLOs (ingest freshness, MTTR, incident count).

Workstream C: Database cost optimization (Weeks 2-8)
- Archive or drop legacy tables that are no longer written to (after export to low-cost storage if historical access is required).
- Apply page compression where it reduces I/O without harming peak workloads.
- Implement retention/archival for growth-heavy changelog-style tables to prevent runaway storage costs.

Workstream D: Peak performance tuning (Weeks 4-12)
- Identify top stored procedures and query paths that spike CPU or time out during major events.
- Deliver targeted optimizations (indexing, query rewrite, batching, caching, GIS/boundary calculation tuning) to reduce vCore-hours at peak while preserving headroom.
- Reduce the need for downscaling by improving pipeline efficiency and increasing coverage of higher-resolution processing where it improves outcomes.

Workstream E: Cost governance and transparency (Ongoing)
- Publish a monthly run-rate view (total Azure + SQL share) and a peak-event cost view.
- Maintain a prioritized optimization backlog with before/after measurements.
- Establish guardrails for scaling decisions (documented thresholds and rollback criteria).

Workstream F: Multi-source data quality and exchange (VATSWIM) (Weeks 4-12)
- Expand push/pull integrations with other airspace systems and partners via VATSWIM.
- Implement reconciliation and data authority rules so multiple sources improve quality rather than create conflicts.
- Add data quality metrics (completeness, timeliness, consistency) and publish a quality report for partner stakeholders.

This request funds both (1) continuity of the current production run-rate and (2) the engineering work that reduces the long-run run-rate and improves reliability.

### 3.4 Milestones and Acceptance Criteria (Paste-Ready)
Within 30 days:
- Ingest daemon supervised (auto-restart) and validated in production.
- Alerting for ingest freshness live (alerts if no new writes >2 minutes).
- Incident runbook published and exercised.

Within 60 days:
- Legacy/unused data lifecycle plan executed (archive/export as needed).
- Compression and retention policies deployed for high-growth tables.
- Monthly cost/run-rate reporting established.

Within 90 days:
- At least 1-2 high-impact stored procedures optimized with measured reduction in peak duration and/or CPU.
- Measurable reduction in baseline SQL consumption and improved peak stability.

### 3.5 Outcomes and Metrics (Paste-Ready)
Reliability outcomes:
- Reduced probability of prolonged ingest outages from single-process failures.
- Faster detection of ingest stalls (minutes, not hours).

Cost outcomes:
- Controlled long-run SQL storage growth via archival/compression/retention.
- Reduced vCore-hours during non-peak windows; improved efficiency at peak.

Data fidelity outcomes:
- Maintain current operational cadence and increase temporal/spatial resolution for high-impact analyses without forced downscaling.

Data quality outcomes:
- Improve completeness and consistency through multi-source ingestion and reconciliation; provide higher-quality outputs to downstream consumers via VATSWIM.

KPIs:
- Ingest freshness SLO: [CUSTOMIZE] (recommended: alert if no writes >2 minutes; report maximum stall per month).
- MTTR for ingest failures: [CUSTOMIZE] (recommended target: < 15 minutes).
- SQL monthly cost vs Jan-Feb 2026 baseline: [CUSTOMIZE] (recommended target: 10-20% reduction from tuning + governance).
- Resolution KPI: [CUSTOMIZE] (e.g., expand high-resolution processing coverage and/or reduce downsampling while keeping costs governed).
- Data quality KPI: [CUSTOMIZE] (e.g., improve field completeness/latency; reduce conflicts through reconciliation; increase multi-source correlation coverage).

### 3.6 Traction and Proof Points (Copy/Paste)
- 24/7 platform operations with large event spikes.
- 780,967 flights tracked in Jan-Feb 2026 (platform operations data).
- 233 PERTI plans created since platform inception.
- 5,288 TMI entries logged; 152 programs; 1,019 advisories issued (to date).
- Cloud infrastructure cost: ~$3,640/month in Jan 2026 actual; current run-rate ~$3,500-$4,300/month driven primarily by Azure SQL.
- Measurable operational analytics exist (e.g., TMI compliance reporting) and are used for continuous improvement.

### 3.7 Risk Management (Copy/Paste)
Key risks and mitigations:
- Risk: transient cloud database events trigger outages. Mitigation: defensive reconnect handling + supervision/auto-restart + freshness alerting.
- Risk: cost increases during peak events. Mitigation: performance tuning + scaling guardrails + monthly governance.
- Risk: data growth causes storage cost creep. Mitigation: archival/retention + compression.

---

## 4) Governance, Independence, and Acceptable Terms

### 4.1 Independence Clause (Paste-Ready)
vATCSCC welcomes philanthropic support and is committed to transparency and measurable outcomes. However, vATCSCC cannot accept funding terms that grant the funder operational control, governance rights, editorial control, or decision authority over the vATCSCC DCC, its operational programs, or platform direction. Funding may be restricted to a defined scope of work and may include reporting requirements, but all ownership and operational direction remain with vATCSCC.

### 4.2 Acceptable Reporting
- Quarterly cost/run-rate reporting (total + SQL).
- Quarterly reliability reporting (ingest freshness, incidents, MTTR).
- Post-project technical summary of optimizations delivered.

### 4.3 Unacceptable Terms (Non-Negotiable)
- Board seats, veto rights, operational decision authority, or roadmap control.
- Requirements that external parties direct DCC operations.
- Public statements or branding requirements that misrepresent control/ownership.

---

## 5) Funder-Specific Templates (Paste-Ready)

Each section below includes:
- Best-fit framing
- Where/how to apply
- Paste-ready outreach email or LOI text

Private foundation gating (quick summary):
- Known exclusions (per published rules): Microsoft nonprofit offers; Cisco Global Impact Grants (public charity requirement).
- Confirm eligibility before investing time: Fast Forward; GTIA Gives; Ray Foundation (policies vary by funder).
- Not inherently blocked by charity class (subject to NOFO rules): FAA NOFO 20-01; Microsoft partner funding programs.

### 5.1 Microsoft Tech for Social Impact (Nonprofits)

Best-fit framing:
- Request a nonprofit offer review + technical assistance focused on Azure SQL cost governance and reliability.
- Ask for escalation beyond the standard nonprofit Azure credit (credits are limited), including architectural support and partner-funding pathways.

Eligibility gate (important):
- Microsoft states private foundations are not eligible for nonprofit offers. Confirm eligibility here: https://learn.microsoft.com/en-us/nonprofits/eligibility

Where/how to apply:
- Nonprofit contact form: https://nonprofit.microsoft.com/en-us/contactus
- Azure for Nonprofits overview: https://www.microsoft.com/en-us/nonprofits/azure
- Nonprofit support overview (has links to Contact Us and Success Center): https://learn.microsoft.com/en-us/nonprofits/support-troubleshoot

Paste-ready outreach (email or contact form):
Subject: Request: sustained Azure support + reliability/cost optimization (vATCSCC, EIN 82-3089665)

Hello Microsoft Tech for Social Impact team,

I'm reaching out on behalf of the Virtual Air Traffic Control System Command Center (vATCSCC), a U.S. 501(c)(3) nonprofit (EIN 82-3089665). We operate a 24/7, data-driven command center platform for the global VATSIM aviation simulation network.

Our platform runs primarily on Azure. In December 2025 we migrated our core operational database (VATSIM_ADL) to Azure SQL Hyperscale Serverless to meet performance and concurrency requirements. This improved scaling but increased our Azure run-rate to approximately ~$3,500-$4,300/month, with SQL responsible for ~95% of spend (most recently ~$4,042 in the last 31 days).

We understand Microsoft nonprofit offers may exclude private foundations. If our classification prevents access to standard offers, we would appreciate guidance on any alternative support pathways (technical assistance, sponsorship, partner funding) that do not require the standard nonprofit offer.

We need sustained, predictable support for our Azure production run-rate (current annualized run-rate is approximately ~$50k/year based on recent usage), not just short-term credits, because computational and data load are high and expected to grow with network flight counts.

We have an initial 90-day Phase 1 reliability + cost-optimization plan (supervision/auto-restart for ingest, monitoring for data freshness, archival/compression, and stored procedure optimization). We're requesting Microsoft's help to:
1) identify a sustainable support pathway (e.g., 12+ months recurring Azure credits/sponsorship, partner funding, or an equivalent commitment) appropriate for our org classification, and
2) connect us with Azure SQL / cost optimization resources to reduce our long-run run-rate while preserving headroom for peak events.

In addition, our compute and data volume are expected to grow with network flight counts. We already tier processing to manage costs, but we want to avoid forced downscaling and instead increase temporal/spatial resolution where it improves outcomes. We also operate VATSWIM (a SWIM/FIXM-aligned data exchange API for the VATSIM ecosystem) and want to expand multi-source push/pull integrations to improve data quality across the network, which increases infrastructure needs.

If helpful, we can share a cost breakdown, architectural overview, and a concise milestones-based plan with acceptance criteria.

Thank you,
[CUSTOMIZE: Name, Title]
vATCSCC / DCC
`dev@vatcscc.org`

### 5.2 Microsoft Partner Funding (Azure Accelerate / Modernization Funding)

Best-fit framing:
- Position as a scoped modernization/optimization project to reduce Azure SQL run-rate and improve resiliency.
- This is typically accessed via a Microsoft partner nomination.

Where/how to apply:
- Azure Accelerate overview (partner): https://partner.microsoft.com/en-us/partnership/azure-offerings

Paste-ready email to a Microsoft partner:
Subject: Request partner nomination: Azure Accelerate for vATCSCC (Azure SQL optimization + resiliency)

Hi [Partner name],

We operate vATCSCC's PERTI platform on Azure. Our main cost driver is Azure SQL Hyperscale Serverless (VATSIM_ADL), and our current run-rate is ~$3.5k-$4.3k/month with SQL representing ~95% of spend. We need sustained, predictable support for this ongoing production run-rate (annualized ~ $50k/year based on recent usage), not just a short-term optimization window.

We have an initial Phase 1 plan (first 90 days) to improve reliability (supervision/auto-restart + ingest freshness alerting) and reduce long-run SQL run-rate through targeted stored procedure optimization plus archival/compression/retention.

As demand grows with network flight counts, we want to avoid forced downscaling and instead improve pipeline efficiency so we can increase temporal/spatial resolution where it improves outcomes. We also operate VATSWIM (a SWIM/FIXM-aligned exchange API for the VATSIM ecosystem) and are expanding multi-source push/pull integrations to improve data quality across the network, which adds additional engineering and infrastructure needs.

Would your team be willing to evaluate and, if appropriate, nominate vATCSCC for Azure Accelerate (or the current Microsoft partner funding pathway) to support this modernization effort? We can provide:
- architecture overview and current SKU configuration
- cost/run-rate snapshot and peak event patterns
- prioritized optimization backlog and success metrics

Thanks,
[CUSTOMIZE: Name]
vATCSCC / DCC
`dev@vatcscc.org`

### 5.3 FAA Aviation Research Grants Program (NOFO 20-01)

Best-fit framing:
- Frame as research (capacity/flow management, operations research, decision support), not training/education.
- Use PERTI as an event-scale testbed and dataset for reproducible evaluation.

Where/how to apply:
- Grants.gov opportunity listing: https://www.grants.gov/search-results-detail/300620
- FAA Aviation Research Grants program page: https://www.faa.gov/research_grants
- FAA guidance page (including white paper requirement): https://www.faa.gov/about/office_org/headquarters_offices/ang/offices/tc/grants
- NOFO PDF: https://www.faa.gov/sites/faa.gov/files/2022-05/NOFO-20-01-2020-2027.pdf

Key contact (per NOFO and Grants.gov listing):
- Monica Butler: monica.y.butler@faa.gov
- Program mailbox: 9-ANG-ARG-Grants@faa.gov

Submission schedule (FY 2027 per NOFO):
White Paper / Pre-Application Due:
- June 3, 2026
- August 2, 2026
- November 1, 2026
- January 2, 2027

New Grant Application Due:
- July 3, 2026
- September 2, 2026
- December 1, 2026
- January 2, 2027

Paste-ready white paper cover email:
Subject: White Paper Submission (NOFO 20-01): [CUSTOMIZE: Title]

Hello Ms. Butler,

Please find attached our White Paper/Pre-Application for consideration under FAA Notice of Funding Opportunity 20-01.

Title: [CUSTOMIZE]
Principal Investigator: [CUSTOMIZE: Name, Title]
Institution: Virtual Air Traffic Control System Command Center (vATCSCC), EIN 82-3089665
Program Area: [CUSTOMIZE: e.g., Systems Science / Operations Research]
NOFO: FAA-20-01

We appreciate your review and welcome any guidance on fit with FAA research priorities.

Respectfully,
[CUSTOMIZE: Name]
[CUSTOMIZE: Title]
vATCSCC
`dev@vatcscc.org`

Paste-ready 3-page white paper draft (outline text):
1) Technical Summary
Title: PERTI: A High-Resolution, Event-Scale Testbed for Flow Management Research Using Real-Time Network Operations Data

Summary:
This proposal develops and evaluates methods for air traffic flow management and operations research using a high-resolution, event-scale dataset derived from a large, real-time aviation simulation network. vATCSCC operates a 24/7 command center platform that ingests live aircraft state and flight plan data and coordinates traffic management initiatives across multiple facilities during peak events. The resulting data captures demand, constraints, and compliance behaviors at scale, enabling research into predictive constraint management, intervention design, and decision support.

To improve data quality and create research-grade outputs, we will leverage VATSWIM (a SWIM/FIXM-aligned exchange API operated by vATCSCC) to support multi-source push/pull ingestion and reconciliation. This enables evaluation of how multi-authority data fusion impacts completeness, timeliness, and decision-support effectiveness at scale.

We propose to (1) curate a research-grade dataset and instrumentation layer, (2) design and evaluate candidate decision-support and flow management methods, and (3) quantify performance across representative high-demand scenarios. Outputs include reproducible evaluation methodology, aggregate/anonymized data products as permissible, and a technical report mapping findings to FAA research areas.

2) Collaborators/Co-Investigators/Consultants
- [CUSTOMIZE]

3) PI Biographical Summary
- [CUSTOMIZE]

4) Estimated Total Project Cost
- Total request: $[CUSTOMIZE]
- Period of performance: [CUSTOMIZE]

### 5.4 Fast Forward (ffwd) Accelerator

Best-fit framing:
- Position as high-impact tech nonprofit infrastructure with measurable operational outcomes and clear 90-day deliverables.

Where/how to apply:
- Accelerator page: https://www.ffwd.org/accelerator
- Accelerator FAQ (dates/requirements): https://www.ffwd.org/accelerator-faq
- Contact: accelerator@ffwd.org

Paste-ready application answers (core prompts):
What problem are you solving?
During peak aviation simulation events, multiple facilities must coordinate traffic flow to prevent cascading congestion, unpredictable delays, and controller overload. Without a system-level command center, restrictions are fragmented and reactive. vATCSCC provides centralized, data-driven coordination so the network can manage demand predictably.

What is your solution and why does tech matter?
We operate a 24/7 platform that ingests high-frequency operational data, supports planning and real-time execution, and produces post-event review analytics. The solution requires scalable data infrastructure, real-time analytics, and reliability engineering to maintain continuity during peak events.

Who do you serve and how do you measure impact?
We serve the global VATSIM community, including pilots and controllers participating in large-scale events. We measure impact via throughput/delay indicators, compliance with flow initiatives, and platform reliability metrics (ingest freshness, incident rate, MTTR).

What would $25k enable?
$25k provides immediate runway to complete reliability and cost-optimization deliverables that reduce outage risk and stabilize our Azure run-rate, including supervision/auto-restart, ingest freshness alerting, and targeted SQL performance tuning. It also helps fund multi-source data quality work via VATSWIM so we can improve data completeness and avoid downscaling resolution as demand grows.

Paste-ready 2-minute video script:
Hi, I'm [Name] with vATCSCC. We run the Virtual Air Traffic Control System Command Center for the VATSIM network, a global real-time aviation simulation environment. When major events happen, thousands of flights converge on a few airports and cross multiple facilities. Without coordination, restrictions stack, delays become unpredictable, and workload spikes for volunteers.

Our platform provides the missing system-level layer: we ingest live operational data, coordinate flow initiatives across facilities, and measure compliance so the community can improve. This is real-time infrastructure work, and it depends on reliable, scalable cloud systems.

In late 2025 we moved our primary database to Azure SQL Hyperscale Serverless to meet performance requirements, and our costs jumped to roughly $3,500-$4,300 per month, dominated by SQL compute. We're doing active cost optimization, but we need a stability buffer while we harden reliability and reduce our long-run run-rate.

Fast Forward's accelerator and $25k unrestricted funding would let us deliver a clear 90-day plan: prevent ingest outages through supervision and alerting, reduce SQL load through targeted optimization, improve multi-source data quality via VATSWIM integrations, and publish transparent reliability and cost metrics. Thank you for considering us.

### 5.5 GTIA (formerly CompTIA) Gives Grants

Best-fit framing:
- Request project support for cloud reliability and cost governance as a defined infrastructure initiative with measurable outcomes.

Where/how to apply:
- GTIA Gives grant program page: https://gtia.org/giving/grants
- Email: giving@gtia.org

Status note (as of 2026-02-19):
- Reply received from Emily Gaines (`egaines@gtia.org`): GTIA will announce the 2026 cycle soon and advised monitoring `https://gtia.org/giving/grants`.

Paste-ready outreach email:
Subject: Inquiry: next GTIA Gives grant cycle (vATCSCC cloud reliability + cost governance)

Hello GTIA Gives team,

I'm writing to ask about the next GTIA Gives grant cycle and whether a U.S. 501(c)(3) nonprofit private operating foundation is eligible to apply. vATCSCC operates a 24/7, data-driven command center platform that supports large-scale aviation simulation events on the global VATSIM network.

Our core operational dependency is Azure cloud infrastructure (primarily Azure SQL). We are seeking support for a defined reliability + cost-optimization initiative to stabilize and reduce long-run cloud spend while improving uptime and monitoring.

As demand grows, we also want to avoid downscaling data fidelity. We already tier processing based on flight-awareness, but increased resolution (temporal/spatial) and multi-source data quality improvements (push/pull integrations via VATSWIM) require additional resources.

If you can share the next application window, eligibility requirements, and any guidance on fit, we would appreciate it.

Thank you,
[CUSTOMIZE: Name]
vATCSCC
`dev@vatcscc.org`

Paste-ready short proposal paragraph (150-200 words):
vATCSCC operates the Virtual Air Traffic Control System Command Center, a 24/7 platform that supports high-traffic aviation simulation events through real-time data ingest, coordinated flow initiatives, and post-event analytics. Our infrastructure is Azure-based and dominated by a mission-critical Azure SQL workload that must scale during peak events. After migrating to Azure SQL Hyperscale Serverless to meet performance requirements, our cloud run-rate increased substantially and is now a sustainability constraint.

Requested funds will support the PERTI Cloud Reliability and Cost Optimization Initiative, delivering measurable improvements in platform uptime, real-time data freshness monitoring, and cost governance. Over 90 days, we will implement supervision/auto-restart for ingest, alerting when no new data writes occur, data lifecycle policies (archival/retention/compression) to control growth, targeted SQL performance tuning to reduce vCore-hours, and multi-source data quality work (push/pull integrations via VATSWIM) to improve completeness and avoid downscaling resolution. We will report on cost run-rate, reliability, and data quality metrics quarterly and provide a technical summary of delivered optimizations.

### 5.6 Ray Foundation (Aviation / Education / Community)

Best-fit framing:
- Request project support for platform resiliency and operations-research enabling infrastructure.

Where/how to apply:
- Ray Foundation grants page: https://rayfoundation.us/grant-requests/
- Submissions accepted year-round; reviewed quarterly (per Ray Foundation site).
- Contact: info@rayfoundation.us

Paste-ready initial inquiry email:
Subject: Funding request inquiry: aviation operations research + platform resiliency (vATCSCC)

Hello Ray Foundation team,

I'm reaching out on behalf of the Virtual Air Traffic Control System Command Center (vATCSCC), a U.S. 501(c)(3) nonprofit (EIN 82-3089665). We operate a 24/7, data-driven command center platform that coordinates air traffic flow during large-scale aviation simulation events and generates post-event analytics to improve operational performance.

We are seeking support for a defined project to improve platform resilience and reduce long-run cloud infrastructure costs (primarily Azure SQL). This initiative directly supports our ability to maintain reliable, real-time aviation operational tooling and to produce research-grade analysis of flow management effectiveness.

We also operate VATSWIM (a SWIM/FIXM-aligned exchange API for the VATSIM ecosystem) and are expanding multi-source push/pull integrations to improve data quality across the network. That work increases infrastructure requirements (ingest pipelines, reconciliation, and observability) beyond a single-source architecture.

If the Ray Foundation is open to proposals in this area, we would welcome guidance on the best submission format and any priority areas you would like us to address. We can provide our IRS determination letter and recent 990, along with a concise project plan and budget.

Respectfully,
[CUSTOMIZE: Name]
vATCSCC / DCC
`dev@vatcscc.org`

Paste-ready proposal narrative (250-400 words):
vATCSCC operates the Virtual Air Traffic Control System Command Center: a 24/7 platform that ingests real-time operational data from a large-scale aviation simulation network and supports planning, execution, and post-event review of traffic management initiatives. During major events, thousands of flights converge on constrained airspace and airports. Without system-level coordination, restrictions become fragmented, delays become unpredictable, and workload spikes across multiple facilities.

Our platform provides centralized, data-driven coordination and produces measurable performance analytics so the community can improve over time. This capability depends on reliable and scalable cloud infrastructure, dominated by Azure SQL for our operational database workload. After migrating to Azure SQL Hyperscale Serverless to meet performance requirements, our cloud run-rate increased substantially and now requires sustainable funding and deliberate cost governance.

We are requesting support for the PERTI Cloud Reliability and Cost Optimization Initiative. Over 90 days, we will deliver (1) supervision/auto-restart and defensive reconnect handling to prevent prolonged ingest outages, (2) monitoring and alerting on data freshness, (3) database archiving/compression/retention to control growth, (4) targeted SQL performance tuning to reduce vCore-hours at peak, and (5) multi-source data quality improvements via VATSWIM integrations so we can enhance completeness and avoid forced downscaling of resolution. We will report quarterly on reliability, cost, and data quality metrics and provide a technical summary of delivered optimizations.

This investment preserves a unique aviation operations capability while improving resilience and reducing long-run cloud costs.

### 5.7 Cisco Global Impact Grants (Eligibility Gate + Alternative Outreach)

Important eligibility gate:
- Cisco's Global Impact Grants start with an online application (LOI-style) and may proceed by invitation.
- Cisco's published eligibility criteria include being recognized by the IRS as a 501(c)(3) and classified as a public charity. If vATCSCC is a private foundation, confirm eligibility with Cisco before investing significant effort.

Submission status note:
- Cisco indicates nonprofit grant proposals may be paused due to a platform transition. If submissions are paused, use the CSR sponsorship inquiry below to share the concept and request the correct pathway/contact, and check back when submissions reopen.

Where/how to apply:
- Cisco Global Impact Cash Grants page: https://www.cisco.com/site/us/en/about/purpose/social-impact/investments/global-impact-cash-grants.html

Paste-ready CSR sponsorship inquiry (if the grant path is not a fit):
Subject: Corporate sponsorship inquiry: critical aviation simulation infrastructure (vATCSCC)

Hello Cisco CSR team,

I'm reaching out on behalf of vATCSCC, a U.S. 501(c)(3) nonprofit (EIN 82-3089665). We operate a 24/7 platform that supports large-scale aviation simulation events by coordinating traffic flow and providing real-time operational tooling and analytics.

We are seeking a corporate sponsor to help fund a defined reliability and cost-optimization initiative for our Azure cloud infrastructure. Our goal is to improve resilience (supervision/auto-restart + alerting for data freshness) and reduce long-run cloud spend through targeted SQL optimization and data lifecycle policies.

As demand grows, we also aim to improve data quality across the network through multi-source push/pull integrations via VATSWIM, which increases infrastructure needs beyond a single-source architecture.

If Cisco has an appropriate pathway for sponsorship or in-kind support for critical community infrastructure, we would appreciate a referral to the correct contact or program.

Thank you,
[CUSTOMIZE: Name]
vATCSCC
`dev@vatcscc.org`

### 5.8 Air Canada Foundation (Canada Eligibility Gate)

Key gate:
- Air Canada Foundation grant applications are for Canadian registered charities.
- The Foundation's public application window is typically Nov 1 to Dec 31.

Where/how to apply:
- Air Canada Foundation support/apply page: https://www.aircanada.com/en/about/community/foundation/get-support.html

Recommended approach if you want Canada alignment:
- Identify a Canadian registered charity partner and propose a jointly delivered program that matches Air Canada Foundation priorities.

Paste-ready inquiry email:
Subject: Inquiry: eligibility via Canadian charity partnership (vATCSCC aviation program)

Hello Air Canada Foundation team,

I'm writing on behalf of vATCSCC, a U.S. 501(c)(3) nonprofit that operates a 24/7 aviation operations platform for the global VATSIM community. We interface globally, including with Canada, and we are exploring whether a partnership with a Canadian registered charity could be an eligible structure for a program aligned with your focus areas.

We also operate VATSWIM (a SWIM/FIXM-aligned exchange API) and are expanding multi-source integrations to improve data quality for other airspace users across the network. If a Canadian charity partner is required, we would propose a jointly delivered, Canada-inclusive program that aligns with your priorities while supporting measurable deliverables.

If you can share any guidance on whether collaborative projects with a Canadian registered charity partner are eligible (and what documentation you would require), we would appreciate it. We can provide a concise project overview and budget once we confirm the appropriate structure.

Respectfully,
[CUSTOMIZE: Name]
vATCSCC
`dev@vatcscc.org`

---

## 6) Attachments and Evidence Checklist

Prepare these once and reuse:
- IRS determination letter (501(c)(3)) and EIN confirmation
- Most recent Form 990 (and schedules)
- Board roster / leadership list
- One-page project budget + budget narrative
- Azure cost snapshot (service breakdown + SQL dominance)
- Architecture overview (1-page diagram)
- Incident case study: 2026-02-17 ingest outage summary + remediation plan

Internal supporting docs in this repo:
- Outage analysis brief: `docs/adl-ingest-outage-2026-02-17-claude-brief.md`
- Cost optimization analysis: `docs/AZURE_COST_OPTIMIZATION_ANALYSIS.md`
- Use case narrative: `docs/vATCSCC_Use_Case_Digestible_v5.md`
- VATSWIM documentation: `docs/swim/VATSWIM_Release_Documentation.md` (and `docs/swim/` index)
- VATSWIM integration example: `docs/swim/vNAS_VATSWIM_Integration.md`

Public reference links (for reviewers):
- Platform status: https://perti.vatcscc.org/status
- Transparency: https://perti.vatcscc.org/transparency
- FMDS comparison: https://perti.vatcscc.org/fmds-comparison
- VATSWIM overview: https://perti.vatcscc.org/swim
- VATSWIM technical docs: https://perti.vatcscc.org/swim-docs
- GitHub repo: https://github.com/vATCSCC/PERTI
- GitHub wiki: https://github.com/vATCSCC/PERTI/wiki
- GitHub issues: https://github.com/vATCSCC/PERTI/issues
- GitHub docs: https://github.com/vATCSCC/PERTI/tree/main/docs

GitHub evidence (optional):
- Outage analysis brief (2026-02-17): https://github.com/vATCSCC/PERTI/blob/main/docs/adl-ingest-outage-2026-02-17-claude-brief.md
- Azure cost optimization analysis: https://github.com/vATCSCC/PERTI/blob/main/docs/AZURE_COST_OPTIMIZATION_ANALYSIS.md
- Use case narrative: https://github.com/vATCSCC/PERTI/blob/main/docs/vATCSCC_Use_Case_Digestible_v5.md
- VATSWIM release documentation: https://github.com/vATCSCC/PERTI/blob/main/docs/swim/VATSWIM_Release_Documentation.md
- VATSWIM integration example: https://github.com/vATCSCC/PERTI/blob/main/docs/swim/vNAS_VATSWIM_Integration.md

---

## 7) Application Tracking Table (Fill In)

| Funder | Link | Submitted (date) | Status | Next action | Owner |
|---|---|---|---|---|---|
| Microsoft TSI | https://nonprofit.microsoft.com/en-us/contactus | 2026-02-17 | Support request #2602170040010605 (Severity C); Microsoft confirmed $2,000/year Azure credit cap and recommended Azure Billing + partner optimization pathways | Open Azure Billing support request (cost mgmt/forecast) and pursue Microsoft partner nomination; if no progress by 2026-02-21, reply to the ticket requesting the best escalation/path | Jeremy Peterson |
| Microsoft partner funding | https://partner.microsoft.com/en-us/partnership/azure-offerings | [CUSTOMIZE] | [CUSTOMIZE] | [CUSTOMIZE] | [CUSTOMIZE] |
| FAA NOFO 20-01 | https://www.grants.gov/search-results-detail/300620 | [CUSTOMIZE] | [CUSTOMIZE] | [CUSTOMIZE] | [CUSTOMIZE] |
| Fast Forward | https://www.ffwd.org/accelerator | 2026-02-17 | Interest registered; 2026 cycle closed; awaiting 2027 open | Wait for FFWD 2027 open email; reassess when cycle opens | Jeremy Peterson |
| GTIA Gives | https://gtia.org/giving/grants | 2026-02-17 | Reply from Emily Gaines (`egaines@gtia.org`) on 2026-02-19: 2026 cycle announcement coming soon | Send thank-you reply and request to be notified when the cycle opens; monitor grants page weekly | Jeremy Peterson |
| Ray Foundation | https://rayfoundation.us/grant-requests/ | 2026-02-17 | Email inquiry sent (fit + submission format) | Follow up on 2026-03-03 if no response | Jeremy Peterson |
| Cisco | https://www.cisco.com/site/us/en/about/purpose/social-impact/investments/global-impact-cash-grants.html | [CUSTOMIZE] | [CUSTOMIZE] | [CUSTOMIZE] | [CUSTOMIZE] |
| Air Canada Foundation | https://www.aircanada.com/en/about/community/foundation/get-support.html | [CUSTOMIZE] | [CUSTOMIZE] | [CUSTOMIZE] | [CUSTOMIZE] |

---

## 8) Appendix: No Outside Control Language

Short version:
vATCSCC retains full operational and strategic control of its programs and platform. Funding may be restricted to defined deliverables and reporting, but it cannot include decision-making authority, governance rights, or operational direction by external parties.

Formal version:
All funded activities will be carried out under vATCSCC's independent governance and operational control. No grant, sponsorship, or partnership agreement shall be construed to grant the funder any ownership interest, board seat, veto power, or operational control over vATCSCC programs, personnel, platform roadmap, or decision processes. Reporting and transparency requirements are acceptable; operational direction and mission control remain exclusively with vATCSCC.


