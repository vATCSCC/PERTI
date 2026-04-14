# Fast Forward (ffwd) Accelerator - Application Copy

Date: 2026-02-17
Org: Virtual Air Traffic Control System Command Center (vATCSCC)
EIN: 82-3089665
Primary contact: dev@vatcscc.org
Website: https://perti.vatcscc.org

Links:
- Accelerator page: https://www.ffwd.org/accelerator
- Accelerator FAQ: https://www.ffwd.org/accelerator-faq
- Contact: accelerator@ffwd.org

Status (as of 2026-02-17):
- FFWD indicates the Accelerator application is closed for the 2026 cycle and they will email when the 2027 cycle opens.

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
- Confirm whether ffwd requires public charity status. vATCSCC is a 501(c)(3) private operating foundation per org records.

## Paste-Ready Core Answers

What problem are you solving?
During peak aviation simulation events, multiple facilities must coordinate traffic flow to prevent cascading congestion, unpredictable delays, and controller overload. Without a system-level command center, restrictions are fragmented and reactive. vATCSCC provides centralized, data-driven coordination so the network can manage demand predictably.

What is your solution and why does tech matter?
We operate a 24/7 platform that ingests high-frequency operational data, supports planning and real-time execution, and produces post-event review analytics. The solution requires scalable data infrastructure, real-time analytics, and reliability engineering to maintain continuity during peak events. As network flight counts grow, computational and data load increases; we already tier processing for cost control, but want to avoid forced downscaling and instead increase temporal/spatial resolution where it improves outcomes.

Who do you serve and how do you measure impact?
We serve the global VATSIM community, including pilots and controllers participating in large-scale events. We measure impact via throughput/delay indicators, compliance with flow initiatives, and platform reliability metrics (ingest freshness, incident rate, MTTR). We also measure data quality for downstream consumers (completeness, latency, consistency) as we expand multi-source integrations.

What traction do you have?
- 24/7 platform operations with large event spikes.
- 780,967 flights tracked in Jan-Feb 2026.
- 233 PERTI plans created since platform inception.
- 5,288 TMI entries logged; 152 programs; 1,019 advisories issued (to date).
- VATSWIM (SWIM/FIXM-aligned exchange API) is deployed with REST + WebSocket for real-time consumers.

What would $25k enable?
$25k provides immediate runway to complete reliability and cost-optimization deliverables that reduce outage risk and stabilize our Azure run-rate, including supervision/auto-restart, ingest freshness alerting, and targeted SQL performance tuning. It also helps fund multi-source data quality work via VATSWIM so we can improve completeness and avoid downscaling resolution as demand grows.

## Paste-Ready 2-Minute Video Script
Hi, I'm [Name] with vATCSCC. We run the Virtual Air Traffic Control System Command Center for the VATSIM network, a global real-time aviation simulation environment. When major events happen, thousands of flights converge on a few airports and cross multiple facilities. Without coordination, restrictions stack, delays become unpredictable, and workload spikes for volunteers.

Our platform provides the missing system-level layer: we ingest live operational data, coordinate flow initiatives across facilities, and measure compliance so the community can improve. This is real-time infrastructure work, and it depends on reliable, scalable cloud systems.

As network flight counts grow, computational and data load increases. We already tier processing to prioritize compute, but tiering forces trade-offs in temporal and spatial resolution. We want to avoid forced downscaling and instead improve efficiency so we can increase resolution where it improves operational outcomes.

We also operate VATSWIM, a SWIM/FIXM-aligned exchange API for the VATSIM ecosystem. Expanding multi-source push/pull integrations improves data quality across the network, but it requires additional engineering and infrastructure.

Fast Forward's accelerator and $25k unrestricted funding would let us deliver a clear 90-day plan: prevent ingest outages through supervision and alerting, reduce SQL load through targeted optimization, improve multi-source data quality via VATSWIM integrations, and publish transparent reliability and cost metrics. Thank you for considering us.
