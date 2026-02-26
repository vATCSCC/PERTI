# Security Policy

## Overview

PERTI (Plan, Execute, Review, Train, and Improve) is a web-based traffic flow management platform for VATSIM (Virtual Air Traffic Control Simulation). We take the security of our platform and user data seriously.

**Production URL:** https://perti.vatcscc.org

---

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| v18.x   | :white_check_mark: |
| v17.x   | :white_check_mark: |
| < v17   | :x:                |

Only the current and previous major versions receive security updates.

---

## Reporting a Vulnerability

We appreciate responsible disclosure of security vulnerabilities. Please **do not** report security issues through public GitHub issues.

### How to Report

1. **GitHub Private Vulnerability Reporting (Preferred)**
   - Navigate to the repository's **Security** tab
   - Click **Report a vulnerability**
   - Provide detailed information about the issue

2. **Direct Contact**
   - Contact the vATCSCC development team directly through VATSIM channels
   - Include "[SECURITY]" in the subject line

### What to Include

Please provide as much of the following information as possible:

- **Description:** Clear explanation of the vulnerability
- **Impact:** What an attacker could accomplish by exploiting it
- **Steps to Reproduce:** Detailed steps to replicate the issue
- **Affected Components:** URLs, API endpoints, or file paths involved
- **Proof of Concept:** Screenshots, logs, or code snippets (if applicable)
- **Suggested Fix:** Any recommendations for remediation (optional)

### Response Timeline

| Action                        | Timeframe        |
|-------------------------------|------------------|
| Initial acknowledgment        | Within 48 hours  |
| Status update                 | Within 7 days    |
| Resolution target             | Within 30 days   |

Complex issues may require additional time. We will keep you informed of progress.

---

## Scope

### In Scope

- **Web Application:** All pages at `perti.vatcscc.org`
- **API Endpoints:** All `/api/*` endpoints
- **Authentication:** VATSIM OAuth integration
- **Database Security:** SQL injection, data exposure
- **Session Management:** Session hijacking, fixation
- **Access Control:** Authorization bypass, privilege escalation

### Out of Scope

- **Third-party services:** VATSIM API, FAA data sources, IEM weather services, VATUSA
- **Social engineering attacks** against team members
- **Denial of Service (DoS/DDoS)** attacks
- **Physical security** issues
- **Attacks requiring compromised VATSIM accounts**
- **Issues already reported or known**
- **Outdated browser vulnerabilities**

---

## Security Best Practices

### For Contributors

- Never commit credentials, API keys, or secrets to the repository
- Use `load/config.example.php` as a template; never commit `load/config.php`
- Validate and sanitize all user inputs
- Use parameterized queries for all database operations
- Follow the principle of least privilege for database access

### For Users

- Keep your VATSIM credentials secure
- Log out when using shared computers
- Report suspicious activity immediately

---

## Known Security Considerations

### Authentication

- PERTI uses VATSIM Connect (OAuth) for authentication
- Public pages (JATOC, NOD) do not require authentication
- Editing operations require DCC role assignment

### Data Classification

| Data Type            | Sensitivity | Notes                          |
|----------------------|-------------|--------------------------------|
| VATSIM CID           | Low         | Publicly visible on VATSIM     |
| Flight data          | Low         | Real-time simulation data      |
| User sessions        | Medium      | Temporary, server-side storage |
| Configuration        | High        | Database credentials, OAuth secrets |

---

## Safe Harbor

We support responsible security research. If you:

- Make a good faith effort to avoid privacy violations, data destruction, or service disruption
- Only interact with accounts you own or have explicit permission to test
- Report vulnerabilities promptly and provide sufficient detail
- Do not publicly disclose issues before they are resolved

We commit to:

- Not pursue legal action against good faith security researchers
- Work with you to understand and resolve the issue
- Credit you in any public disclosure (if desired)

---

## Acknowledgments

We thank the security research community for helping keep PERTI secure. Researchers who report valid vulnerabilities will be acknowledged here (with permission).

---

## Contact

For security concerns, use [GitHub Private Vulnerability Reporting](https://github.com/vATCSCC/PERTI/security/advisories/new) or contact the vATCSCC development team.

---

*Last updated: 2026-01-10*
