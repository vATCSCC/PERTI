# Microsoft Tech for Social Impact (Nonprofits) - Submission Copy

Date: 2026-02-17
Org: Virtual Air Traffic Control System Command Center (vATCSCC)
EIN: 82-3089665
Primary contact: dev@vatcscc.org
Website: https://perti.vatcscc.org

Important eligibility note:
- vATCSCC is a 501(c)(3) but classified as a private foundation (operating foundation) per org records. Microsoft nonprofit offers may exclude private foundations.
- This outreach explicitly asks for the correct pathway (nonprofit offer if eligible, or alternative support/sponsorship/technical assistance if not).

Links:
- Contact form: https://nonprofit.microsoft.com/en-us/contactus
- Eligibility details: https://learn.microsoft.com/en-us/nonprofits/eligibility
- Support overview: https://learn.microsoft.com/en-us/nonprofits/support-troubleshoot

Status (as of 2026-02-18):
- 2026-02-17: Support request submitted to Microsoft Support.
  - Support request number: 2602170040010605
  - Incident title: NONPROFIT: Virtual Air Traffic Control System Command Center, ROLE: current-nonprofit-customer, CATEGORY: nonprofit-offers
  - Product: Microsoft Elevate/Nonprofit/Offers
  - Severity rating: C (Microsoft indicated expected response within "8"; contact preference: email).
  - Note: microsoftsupport.com and microsoft.com are both valid Microsoft email domains for communications related to this request.
- 2026-02-18: Response received from Microsoft Elevate Nonprofit Customer Support (Romelyn Calumpag P) acknowledging delays due to high inquiry volume; a Support Ambassador will contact us shortly (awaiting that contact). Provided self-help resource: Nonprofit FAQ | Microsoft Nonprofits.
- 2026-02-18: Response received from Microsoft Elevate Nonprofit Customer Support (Eurico Ivan Fetisanan) with program constraints:
  - Azure nonprofit credit benefit is capped at USD $2,000/year.
  - Credits are valid for 12 months and must be renewed manually each year (renewal can be requested up to 30 days before end date).
  - Once the annual allocation is consumed, additional credits cannot be added until the next renewal cycle.
  - Recommended next step: open an Azure support request with the Azure Billing team for cost-management/forecasting options, and explore partner/technical optimization pathways for alternatives beyond the standard nonprofit program.

Public reference links:
- Platform status: https://perti.vatcscc.org/status
- Transparency: https://perti.vatcscc.org/transparency
- FMDS comparison: https://perti.vatcscc.org/fmds-comparison
- VATSWIM overview: https://perti.vatcscc.org/swim
- VATSWIM technical docs: https://perti.vatcscc.org/swim-docs
- GitHub repo: https://github.com/vATCSCC/PERTI
- GitHub wiki: https://github.com/vATCSCC/PERTI/wiki
- GitHub issues: https://github.com/vATCSCC/PERTI/issues

## Paste-Ready Follow-Up Reply (Support Ticket)
Subject: Re: Support request 2602170040010605 (NONPROFIT offers - vATCSCC)

Hello Romelyn / Support Ambassador,

Thank you for the update. For reference, this is regarding support request #2602170040010605 (NONPROFIT offers for vATCSCC, EIN 82-3089665).

We are seeking a sustainable support pathway for our ongoing Azure production run-rate (approx. $3.5k-$4.3k/month, annualized ~ $50k/year; Azure SQL ~95% of spend) plus technical guidance/partner-program alignment to reduce long-run SQL consumption while preserving peak headroom. Load is high and growing with network flight counts; we already tier processing but that forces downscaling, and we are expanding VATSWIM multi-source integrations which increases infrastructure needs.

Two questions to help us proceed:
1) Given our classification as a private operating foundation (per org records), are we eligible for any Microsoft nonprofit offers? If not, what alternative pathways are available (TSI sponsorship, partner funding, etc.)?
2) Is there any option for recurring Azure credits/sponsorship (12+ months) or in-kind engineering support beyond the standard nonprofit grant?

We can share architecture, cost breakdown, and a milestones-based plan with acceptance criteria.

Thank you,
Jeremy Peterson
vATCSCC / DCC
jeremy.peterson@vatcscc.org

## Paste-Ready Reply To Eurico (Acknowledgement + Next Steps)
Subject: Re: Ticket 2602170040010605 (Azure nonprofit offers + alternatives)

Hi Eurico,

Thank you for the detailed clarification and for acknowledging the scale/constraints we are operating under.

Understood on the $2,000/year Azure credit cap and the annual manual renewal process. Given our production run-rate and growth, we will treat the standard nonprofit credit as helpful but not sufficient, and focus on (1) cost optimization and (2) alternative support pathways.

Next steps we plan to take:
1) Open an Azure support request with the Azure Billing team to review Cost Management guardrails, forecasting, and SQL-specific optimization options.
2) Pursue partner engagement/nomination (Azure Accelerate or current successor program) for modernization/optimization support.

Two questions to help us move quickly:
1) Is there a preferred support category/route in the Azure Portal to ensure we reach the correct Azure Billing team for this scenario?
2) For partner/technical optimization pathways, is there a Microsoft-recommended route (or internal referral) you can point us to, given our org structure?

Thanks again,
Jeremy Peterson
vATCSCC / DCC
jeremy.peterson@vatcscc.org

## Contact Form Field Mapping (Suggested)
- First/Last: Jeremy Peterson
- Email: dev@vatcscc.org (or jeremy.peterson@vatcscc.org if preferred)
- Org: Virtual Air Traffic Control System Command Center (vATCSCC)
- Website: https://perti.vatcscc.org
- Message: use the copy below

## Short Message (<=2268 chars)
Subject: Request: sustained Azure support + reliability/cost optimization (vATCSCC, EIN 82-3089665)

Hello Microsoft Tech for Social Impact team,

I'm writing for the Virtual Air Traffic Control System Command Center (vATCSCC), a U.S. 501(c)(3) nonprofit (EIN 82-3089665). We operate a 24/7, data-driven air traffic flow coordination platform for the global VATSIM network (https://perti.vatcscc.org).

We run primarily on Azure; our dominant cost driver is Azure SQL Hyperscale Serverless (VATSIM_ADL). Recent Azure cost (last 31 days): $4,263 total; Azure SQL ~$4,042 (~95%) -> ~$3.5k-$4.3k/month (~$50k/year run-rate). Load is high and grows with network flight counts. We already tier processing, but that forces temporal/spatial downscaling. We also operate VATSWIM (SWIM/FIXM-aligned data exchange) and want to expand multi-source push/pull integrations, which increases compute/storage/observability needs.

We need sustained, predictable support for ongoing Azure production (e.g., 12+ months recurring credits/sponsorship or an equivalent pathway), plus technical help/partner-program alignment to reduce long-run SQL vCore-hours while preserving peak headroom. Phase 1 deliverables (first 90 days): supervise/auto-restart ingest; alert if no writes >2 min; archival/retention/compression; targeted stored-proc/query tuning.

Eligibility note: our org records indicate we are a private operating foundation; if that blocks standard nonprofit offers, please advise the correct alternative support pathway.

Governance: we can accept restricted funding and reporting, but not terms granting operational control over DCC/platform direction.

Thanks,
Jeremy Peterson, vATCSCC / DCC
dev@vatcscc.org

## Paste-Ready Message (Email or Contact Form)
Subject: Request: sustained Azure support + reliability/cost optimization (vATCSCC, EIN 82-3089665)

Hello Microsoft Tech for Social Impact team,

I'm reaching out on behalf of the Virtual Air Traffic Control System Command Center (vATCSCC), a U.S. 501(c)(3) nonprofit (EIN 82-3089665). We operate a 24/7, data-driven command center platform for the global VATSIM aviation simulation network.

Our platform runs primarily on Azure. In December 2025 we migrated our core operational database (VATSIM_ADL) to Azure SQL Hyperscale Serverless to meet performance and concurrency requirements. This improved scaling but increased our Azure run-rate to approximately $3,500-$4,300/month. The last 31 days totaled $4,263, with Azure SQL representing about $4,042.

We understand Microsoft nonprofit offers may exclude private foundations. If our classification prevents access to standard offers, we would appreciate guidance on any alternative pathways for support that preserve independent ownership and direction (technical assistance, sponsorship, partner funding, or other programs).

We need sustained, predictable support for our Azure production run-rate (current annualized run-rate is approximately ~$50k/year based on recent usage), not just short-term credits, because computational and data load are high and expected to grow with network flight counts.

We have an initial 90-day Phase 1 Reliability and Cost Optimization initiative with measurable deliverables:
- supervision/auto-restart for ingest daemons
- alerting if no new ingest writes > 2 minutes
- database archival/retention/compression to control growth
- targeted Azure SQL performance optimization to reduce vCore-hours while preserving peak headroom

Our request is for (1) a sustainable support pathway (e.g., 12+ months recurring Azure credits/sponsorship or an equivalent commitment), and (2) technical assistance and/or partner-program alignment to complete the optimization work while keeping production stable.

The core driver is sustained and growing computational/data load as network flight counts increase. We already tier processing based on flight-awareness and other helpers, but tiering forces trade-offs in temporal/spatial resolution. With adequate funding and optimization, we can avoid forced downscaling and selectively increase resolution where it improves operational outcomes.

We also operate VATSWIM (a SWIM/FIXM-aligned data exchange API for the VATSIM ecosystem) and want to expand multi-source push/pull integrations to improve data quality across the network. Multi-source ingestion, reconciliation, and distribution increases infrastructure and engineering needs beyond a single-source architecture.

If helpful, we can share a cost breakdown, architecture overview, and a milestones-based plan with acceptance criteria.

Thank you,
Jeremy Peterson
vATCSCC / DCC
`dev@vatcscc.org`

## Attachments (If Allowed)
- Cost snapshot + service breakdown
- 1-page architecture overview
- Incident case study: 2026-02-17 ingest outage summary + remediation plan
- Project milestones + acceptance criteria
