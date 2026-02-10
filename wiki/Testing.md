# Testing

Testing guidelines for PERTI development.

---

## Manual Testing Checklist

Before submitting changes, verify:

### Authentication
- [ ] Login via VATSIM Connect works
- [ ] Session persists across pages
- [ ] Logout clears session

### Public Pages
- [ ] JATOC loads without login
- [ ] NOD loads without login
- [ ] Data displays correctly

### Authenticated Features
- [ ] GDT loads and displays data
- [ ] Route Plotter renders map
- [ ] Splits configuration works
- [ ] TMI Publish page loads and Discord integration works
- [ ] TMR (review reports) CRUD operations function
- [ ] Plan page loads all 16 parallel API calls
- [ ] Demand charts render with correct data

### NOD & Public Features
- [ ] NOD facility flow layers render on map
- [ ] NOD TMI cards display active programs
- [ ] TMI compliance report generates correctly
- [ ] Transparency page shows accurate infrastructure data

### i18n (Internationalization)
- [ ] `i18n.js`, `index.js` (locale loader), and `en-US.json` load in order
- [ ] `PERTII18n.t()` resolves keys correctly
- [ ] `PERTIDialog` modals show translated text
- [ ] Locale auto-detection works (URL param → localStorage → navigator.language)
- [ ] No `[missing: key.name]` strings visible in UI

### API Endpoints
- [ ] Responses return valid JSON
- [ ] Error handling works
- [ ] Authentication enforced where required
- [ ] `PERTI_MYSQL_ONLY` endpoints don't use Azure SQL connections

---

## Browser Testing

Test across supported browsers:
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

**Key features requiring WebGL:**
- Route Plotter (MapLibre GL)
- NOD Dashboard (MapLibre GL with facility flow layers)
- Demand Analysis (MapLibre GL overlays)

---

## Database Testing

Test migrations on a staging database before production.

### MySQL (perti_site)

```bash
mysqldump -u user -p perti_site > backup.sql
mysql -u user -p perti_site < database/migrations/schema/NNN_migration.sql
```

### Azure SQL (ADL/TMI)

```bash
sqlcmd -S server.database.windows.net -d VATSIM_ADL -U admin -P 'pass' \
       -i adl/migrations/core/NNN_migration.sql
```

### PostgreSQL/PostGIS (GIS)

```bash
psql -h server.postgres.database.azure.com -d vatcscc_gis -U admin \
     -f database/migrations/postgis/NNN_migration.sql
```

---

## Performance Testing

### PERTI_MYSQL_ONLY Verification

Before applying the `PERTI_MYSQL_ONLY` flag to an endpoint:

```bash
# Grep for Azure SQL connection usage
grep -n "conn_adl\|conn_tmi\|conn_swim\|conn_ref\|conn_gis" api/path/to/endpoint.php
```

If any matches found, do NOT apply the flag.

### API Response Times

Key endpoints to benchmark:
- Plan page APIs (16 parallel calls) — target < 500ms total
- ADL current flights — target < 2s
- TMI active programs — target < 1s

---

## See Also

- [[Contributing]] - Contribution process
- [[Code Style]] - Coding standards
