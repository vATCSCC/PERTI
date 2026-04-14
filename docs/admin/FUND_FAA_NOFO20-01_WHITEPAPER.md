# FAA NOFO 20-01 - White Paper / Pre-Application (Draft)

Date: 2026-02-17
NOFO: FAA-20-01 (Aviation Research Grants Program)
Org: Virtual Air Traffic Control System Command Center (vATCSCC)
EIN: 82-3089665
Primary contact: dev@vatcscc.org
Website: https://perti.vatcscc.org

Scope / Virtual Environment Disclaimer
- vATCSCC and PERTI operate entirely within the VATSIM flight simulation network (virtual aviation).
- All flights, air traffic control, and traffic management initiatives referenced here are simulated; this project does not interface with FAA operational systems and does not affect real-world aircraft operations.
- The intent is to use an event-scale, high-volume virtual environment as a safe research testbed for data fusion, analytics, and Collaborative Decision Making (CDM) decision support methods.

Reminder (fit):
- Frame as research (capacity/flow management, operations research, decision support). Avoid training/education framing.
- Use vATCSCC's platform and VATSWIM as an event-scale testbed and data exchange layer.

Public reference links:
- Platform status: https://perti.vatcscc.org/status
- Transparency: https://perti.vatcscc.org/transparency
- FMDS comparison: https://perti.vatcscc.org/fmds-comparison
- VATSWIM overview: https://perti.vatcscc.org/swim
- VATSWIM technical docs: https://perti.vatcscc.org/swim-docs
- GitHub repo: https://github.com/vATCSCC/PERTI
- GitHub wiki: https://github.com/vATCSCC/PERTI/wiki
- GitHub issues: https://github.com/vATCSCC/PERTI/issues
- GitHub docs: https://github.com/vATCSCC/PERTI/tree/main/docs

## Paste-Ready Cover Email
To: monica.y.butler@faa.gov
Cc: 9-ANG-ARG-Grants@faa.gov
Subject: White Paper Submission (NOFO 20-01): PERTI/VATSWIM Multi-Source Flow Management Research Testbed

Hello Ms. Butler,

Please find attached our White Paper/Pre-Application for consideration under FAA Notice of Funding Opportunity 20-01.

Title: PERTI/VATSWIM Multi-Source Flow Management Research Testbed
Principal Investigator: Jeremy Peterson (vATCSCC / DCC)
Institution: Virtual Air Traffic Control System Command Center (vATCSCC), EIN 82-3089665
Program Area: Systems Science / Operations Research (Capacity and Flow Management Decision Support)
NOFO: FAA-20-01

Scope note: vATCSCC's operational environment is entirely virtual (VATSIM flight simulation). No FAA operational systems are used, and this work does not affect real-world aircraft operations.

We appreciate your review and welcome any guidance on alignment with FAA research priorities and appropriate topic areas.

Respectfully,
Jeremy Peterson
vATCSCC / DCC
`dev@vatcscc.org`

---

## 1) Technical Summary

### Title
PERTI/VATSWIM Multi-Source Flow Management Research Testbed

### Problem
Flow management decision support in CDM-style environments depends on complete, timely, and consistent operational data. As traffic demand scales, latency and missing/discordant fields degrade decision support and evaluation fidelity. Research also needs reproducible, event-scale datasets to evaluate interventions under stress conditions.

### Approach
This project uses a high-volume, event-scale, simulation-only operational environment (VATSIM) to study and improve CDM-style flow management decision support and data quality. vATCSCC operates a 24/7 Command Center platform (PERTI) that ingests high-frequency virtual flight state and flight plan information, supports collaborative Traffic Management Initiatives (TMIs), and produces analytics before/during/after major events.

The PERTI process already includes CDM-style planning, issuance, and monitoring (e.g., Ground Stops, Ground Delay Programs, and advisory outputs where applicable), plus dashboards and metrics for demand, delay, and compliance. This proposal formalizes and extends those analytical capabilities into a reproducible research workflow.

We will leverage VATSWIM (a SWIM/FIXM-aligned exchange API operated by vATCSCC) as the integration layer for multi-source push/pull ingestion and reconciliation. The core research contribution is to evaluate how multi-source data fusion, authority rules, and quality scoring impact both (1) data quality (completeness, latency, consistency) and (2) downstream CDM decision-support analytics at event scale.

### Research Objectives
1. Define and implement a multi-source reconciliation framework for operational flight data (authority rules, conflict resolution, and quality scoring) using VATSWIM push/pull integrations.
2. Define an evaluation and analytics framework for CDM outcomes in an event-scale environment (demand/capacity, delay distributions, compliance/equity metrics, and timeliness of decision-support outputs).
3. Quantify impacts of improved data quality and analytics on CDM decision-support outputs during peak-demand scenarios, and publish reproducible methodology and findings mapped to FAA research priorities.

### Expected Outputs
- A research-grade data model, reconciliation approach, and quality scoring methodology for multi-source operational flight data.
- A CDM analytics package that demonstrates measurable results of the PERTI process during event-scale operations (metrics definitions, dashboards, and repeatable reporting).
- A curated dataset (aggregate/anonymized as permissible) and reproducible evaluation scripts.
- Technical report with results and recommendations for scalable data quality and decision-support architectures.

### Relevance to FAA Research Areas
- Systems Science / Operations Research
- Capacity and flow management decision support
- Data quality, interoperability, and SWIM/FIXM-aligned information exchange

---

## 2) Work Plan and Deliverables (High Level)

Phase 1 (Months 1-3): Baseline and instrumentation
- Establish baseline data quality metrics (completeness, latency, consistency).
- Establish baseline CDM outcome metrics and reporting (demand/capacity, delay distributions, and compliance/equity metrics).
- Define authority/reconciliation rules for multi-source ingestion.
- Implement quality scoring, analytics instrumentation, and telemetry.

Phase 2 (Months 4-9): Multi-source fusion and evaluation
- Integrate additional sources via VATSWIM push/pull pathways.
- Implement reconciliation and conflict resolution rules.
- Evaluate decision-support and CDM analytics impacts under peak-demand conditions.

Phase 3 (Months 10-12): Reporting and dissemination
- Publish technical report and evaluation methodology.
- Deliver dataset artifacts and reproducible analysis outputs (subject to policy).

Deliverables
- Multi-source reconciliation framework and quality scoring methodology.
- CDM analytics framework and reporting outputs (dashboards/metrics) for event operations.
- Monthly metrics report (data quality + CDM outcomes + platform performance) during the project.
- Final technical report and evaluation package.

---

## 3) Collaborators / Co-Investigators / Consultants
- [CUSTOMIZE: names, organizations, roles]

---

## 4) PI Biographical Summary (Short)
Jeremy Peterson leads vATCSCC's platform engineering and operations work supporting real-time data ingest, system resiliency, and analytics for event-scale flow management. [CUSTOMIZE: add 5-8 lines of relevant engineering/ops research experience, publications, or prior projects.]

---

## 5) Estimated Total Project Cost
Total request: $[CUSTOMIZE]
Period of performance: [CUSTOMIZE: 12-24 months]

High-level budget categories (example)
- Cloud infrastructure and data engineering: $[CUSTOMIZE]
- Research/analysis effort: $[CUSTOMIZE]
- Collaboration/consulting: $[CUSTOMIZE]
- Compliance/admin: $[CUSTOMIZE]

---

## 6) Notes for Full Application (If Invited)
- Confirm SAM.gov + Grants.gov registrations.
- Include a data management and dissemination plan, including what can be shared publicly.
- Include cybersecurity/PII posture (the dataset is from a flight simulation network; no real-world ATC or FAA systems are involved; still apply best practices).
