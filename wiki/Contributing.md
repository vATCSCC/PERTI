# Contributing

Thank you for your interest in contributing to PERTI. This guide outlines the process for contributing code, documentation, and other improvements.

---

## Code of Conduct

All contributors are expected to:

- Be respectful and professional in all interactions
- Focus on constructive feedback
- Support an inclusive community
- Follow VATSIM's Code of Conduct

---

## Getting Started

### Prerequisites

Before contributing, ensure you have:

1. A GitHub account
2. Git installed locally
3. Development environment set up (see [[Getting Started]])
4. Familiarity with the [[Architecture]]

### Fork and Clone

1. Fork the repository on GitHub
2. Clone your fork:
   ```bash
   git clone https://github.com/your-username/PERTI.git
   cd PERTI
   ```
3. Add upstream remote:
   ```bash
   git remote add upstream https://github.com/vATCSCC/PERTI.git
   ```

---

## Development Workflow

### Branch Naming

Use descriptive branch names:

| Prefix | Purpose | Example |
|--------|---------|---------|
| `feature/` | New functionality | `feature/demand-charts` |
| `fix/` | Bug fixes | `fix/gs-activation-error` |
| `docs/` | Documentation | `docs/api-reference` |
| `refactor/` | Code improvements | `refactor/adl-queries` |

### Creating a Branch

```bash
git checkout main
git pull upstream main
git checkout -b feature/your-feature-name
```

### Making Changes

1. Make focused, incremental changes
2. Test thoroughly before committing
3. Write clear commit messages

### Commit Messages

Follow conventional commit format:

```
type(scope): brief description

Longer explanation if needed. Explain the what and why,
not the how (code explains how).

Fixes #123
```

**Types:**
- `feat` - New feature
- `fix` - Bug fix
- `docs` - Documentation
- `refactor` - Code refactoring
- `test` - Adding tests
- `chore` - Maintenance tasks

**Examples:**
```
feat(gdt): add demand visualization chart

fix(jatoc): correct incident status transition

docs(api): update ground stop endpoint documentation
```

---

## Code Standards

### PHP Guidelines

- Follow PSR-12 coding standard
- Use meaningful variable and function names
- Document public functions with PHPDoc
- Use parameterized queries for all database operations
- Handle errors appropriately

```php
/**
 * Retrieves active ground stops for an airport.
 *
 * @param string $airport ICAO airport code
 * @return array Active ground stop records
 */
function getActiveGroundStops(string $airport): array
{
    // Implementation
}
```

### JavaScript Guidelines

- Use ES6+ syntax
- Prefer `const` and `let` over `var`
- Use meaningful names
- Comment complex logic

```javascript
/**
 * Updates the demand chart with new data.
 * @param {Object} demandData - Hourly demand data
 */
function updateDemandChart(demandData) {
    // Implementation
}
```

### SQL Guidelines

- Use uppercase for SQL keywords
- Use meaningful aliases
- Include comments for complex queries
- Parameterize all inputs

```sql
-- Get active ground stops with affected flight counts
SELECT
    gs.id,
    gs.airport,
    COUNT(f.id) AS affected_flights
FROM ground_stops gs
LEFT JOIN flights f ON f.destination = gs.airport
WHERE gs.status = 'active'
GROUP BY gs.id, gs.airport;
```

---

## Testing

### Before Submitting

Ensure your changes:

1. Work correctly in local environment
2. Do not break existing functionality
3. Handle edge cases appropriately
4. Include appropriate error handling

### Test Checklist

- [ ] Feature works as intended
- [ ] No console errors in browser
- [ ] API responses are correct
- [ ] Database operations succeed
- [ ] Authentication/authorization enforced
- [ ] Works across supported browsers
- [ ] i18n keys added for all new user-facing strings
- [ ] `PERTI_MYSQL_ONLY` flag not applied to files using Azure SQL connections
- [ ] New API endpoints documented in wiki

---

## Pull Requests

### Creating a Pull Request

1. Push your branch:
   ```bash
   git push origin feature/your-feature-name
   ```

2. Open a Pull Request on GitHub

3. Fill out the PR template:
   ```markdown
   ## Summary
   Brief description of changes.

   ## Changes
   - Added X
   - Fixed Y
   - Updated Z

   ## Testing
   How was this tested?

   ## Screenshots
   (If applicable)

   ## Related Issues
   Fixes #123
   ```

### PR Requirements

- [ ] Descriptive title and summary
- [ ] Linked to related issue(s)
- [ ] No merge conflicts with main
- [ ] Passes all checks
- [ ] Code reviewed

### Review Process

1. Maintainers review within 48-72 hours
2. Address feedback promptly
3. Request re-review after changes
4. Maintainer merges when approved

---

## Types of Contributions

### Code Contributions

- New features
- Bug fixes
- Performance improvements
- Security patches

### Documentation

- Wiki pages
- Code comments
- API documentation
- User guides

### Other Contributions

- Bug reports (with reproduction steps)
- Feature suggestions
- Usability feedback
- Translation assistance

---

## Reporting Issues

### Bug Reports

Include:
- Clear description of the issue
- Steps to reproduce
- Expected vs. actual behavior
- Browser/environment details
- Screenshots if applicable

### Feature Requests

Include:
- Description of the feature
- Use case / problem it solves
- Proposed implementation (optional)
- Mockups if applicable

---

## Areas Seeking Contributions

We particularly welcome contributions in:

| Area | Description |
|------|-------------|
| Documentation | Wiki improvements, user guides |
| Accessibility | WCAG compliance improvements |
| Testing | Automated test coverage |
| Performance | Query optimization, caching |
| Internationalization | i18n dialog migration (~267 remaining Swal.fire calls), locale file expansion, PHP-side i18n layer |

---

## Development Tips

### Database Changes

1. Create migration file in the appropriate directory:
   - **MySQL (perti_site)**: `database/migrations/` organized by feature (e.g., `tmi/`, `initiatives/`, `jatoc/`)
   - **Azure SQL (ADL)**: `adl/migrations/` organized by feature area (`core/`, `boundaries/`, `crossings/`, `eta/`, `navdata/`, `changelog/`, `cifp/`, `demand/`)
   - **PostGIS (GIS)**: `database/migrations/postgis/`
   - **TMI**: `database/migrations/tmi/`
2. Use sequential numbering
3. Include rollback SQL if possible
4. Document in PR

### API Changes

1. Maintain backward compatibility when possible
2. Document new endpoints in [[API Reference]]
3. Update any affected client code

### UI Changes

1. Follow existing Bootstrap patterns
2. Test responsive behavior
3. Ensure accessibility
4. Match existing visual style

### i18n Requirements

All new user-facing strings in JavaScript must use the `PERTII18n.t()` translation system rather than hardcoded English strings.

1. Add translation keys to `assets/locales/en-US.json` using the nested key structure
2. Reference keys in code via `PERTII18n.t('section.key')` with interpolation support for dynamic values
3. Use the `PERTIDialog` wrapper for all modal dialogs instead of calling `Swal.fire()` directly -- `PERTIDialog` resolves i18n keys automatically
4. Test locale detection by verifying behavior with the `?locale=` URL parameter, `localStorage` (`PERTI_LOCALE`), and `navigator.language` fallback

See the Internationalization section in `CLAUDE.md` for the full API reference and current coverage status.

### Performance Considerations

1. **`PERTI_MYSQL_ONLY` flag** -- For PHP endpoints that only need MySQL, add `define('PERTI_MYSQL_ONLY', true);` before `include connect.php` to skip the 5 eager Azure SQL connections (~500-1000ms saved per request):
   ```php
   include("../../../load/config.php");
   define('PERTI_MYSQL_ONLY', true);
   include("../../../load/connect.php");
   ```
   **Always grep for** `$conn_adl`, `$conn_tmi`, `$conn_swim`, `$conn_ref`, and `$conn_gis` in the file before applying this flag. Applying it to a file that uses any Azure SQL connection will cause silent failures or HTTP 500 errors.

2. **Parallel API calls** -- Use `Promise.all()` for independent API calls in JavaScript to avoid sequential waterfalls:
   ```javascript
   const [configs, staffing, forecasts] = await Promise.all([
       fetch('/api/data/plans/configs.php?p_id=' + planId),
       fetch('/api/data/plans/term_staffing.php?p_id=' + planId),
       fetch('/api/data/plans/forecast.php?p_id=' + planId)
   ]);
   ```

---

## Recognition

Contributors are recognized in:
- Git commit history
- Release notes (for significant contributions)
- Contributors section in README (for major contributions)

---

## Questions?

- Open a GitHub Discussion for questions
- Tag maintainers if urgent
- Check existing issues/discussions first

---

## See Also

- [[Getting Started]] - Development setup
- [[Architecture]] - System overview
- [[Code Style]] - Detailed style guide
