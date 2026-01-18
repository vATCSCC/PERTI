# VATSWIM Production Setup Checklist

This checklist covers all steps required to deploy VATSWIM (Virtual Air Traffic Service Wide Information Management) to production. Complete items in order, as some steps depend on previous ones.

---

## Critical (Required Before Production)

### 1. Database Credentials

Configure database connections in `load/config.php`:

- [ ] Set `ADL_SQL_HOST`, `ADL_SQL_DATABASE`, `ADL_SQL_USERNAME`, `ADL_SQL_PASSWORD`
- [ ] Set `SWIM_SQL_HOST`, `SWIM_SQL_DATABASE`, `SWIM_SQL_USERNAME`, `SWIM_SQL_PASSWORD`
- [ ] Set `TMI_SQL_HOST`, `TMI_SQL_DATABASE`, `TMI_SQL_USERNAME`, `TMI_SQL_PASSWORD`

> **Note:** Default server is `vatsim.database.windows.net`

---

### 2. Azure SQL Database Setup

- [ ] Verify `SWIM_API` database exists on Azure SQL
- [ ] Run migration scripts in order:
  - `database/migrations/swim/001_swim_tables.sql`
  - `database/migrations/swim/002_swim_api_database.sql`
  - `database/migrations/swim/003_swim_api_database_fixed.sql`
  - `database/migrations/swim/004_swim_api_keys_owner_cid.sql`
  - `database/migrations/swim/004_swim_bulk_upsert_sp.sql`
  - `database/migrations/swim/005_swim_add_telemetry_columns.sql`
  - `database/migrations/swim/005_swim_metering_fields.sql`
- [ ] Verify `swim_flights` table created (75 columns)
- [ ] Verify stored procedures created:
  - `sp_swim_bulk_upsert`
  - `sp_swim_flight_upsert`

---

### 3. API Key Generation

- [ ] Generate production **system tier** key for internal use
- [ ] Generate **partner tier** keys as needed for external integrations
- [ ] Update any internal services with new keys
- [ ] Document key assignments and distribute securely

> **Key Tiers:**
> - `system` - Full access, internal services only
> - `partner` - Write access for approved integrations
> - `subscriber` - Read-only access for data consumers
> - `trial` - Limited access for evaluation

---

### 4. WebSocket Configuration

- [ ] Configure Apache proxy for port 8090
- [ ] Verify WSS access at `wss://perti.vatcscc.org/api/swim/v1/ws`
- [ ] Test WebSocket connections with sample client
- [ ] Verify subscription messages are processed correctly

Example Apache proxy configuration:
```apache
ProxyPass /api/swim/v1/ws ws://localhost:8090/
ProxyPassReverse /api/swim/v1/ws ws://localhost:8090/
```

---

### 5. Sync Daemon

- [ ] Configure cron job/scheduled task for `scripts/swim_sync.php`
- [ ] Run every **2 minutes**
- [ ] Verify MySQL and `VATSIM_ADL` connections available
- [ ] Test sync daemon execution manually first

Example cron entry:
```bash
*/2 * * * * php /var/www/perti/scripts/swim_sync.php >> /var/log/swim_sync.log 2>&1
```

---

## Important (Recommended Before Full Production)

### 6. Monitoring

- [ ] Set up API health check monitoring (`api/system/health.php`)
- [ ] Configure alerting for error rates
- [ ] Set up uptime monitoring for WebSocket endpoint
- [ ] Monitor database connection pool health

---

### 7. Data Retention

Implement cleanup jobs for old data:

| Data Type | Retention Period |
|-----------|------------------|
| Active flights | 1 day |
| Position data | 7 days |
| Audit log | 90 days |
| API request logs | 30 days |

- [ ] Create scheduled cleanup job
- [ ] Test cleanup job in staging environment
- [ ] Verify cleanup doesn't impact active operations

---

### 8. Load Testing

- [ ] Test with realistic flight volumes (2,000-6,000 concurrent flights)
- [ ] Verify rate limits working correctly
- [ ] Test WebSocket broadcast under load
- [ ] Validate bulk upsert performance with 500+ flight batches

---

## Environment Variables (Optional)

Set these environment variables for client applications:

```bash
# API Configuration
SWIM_API_KEY=swim_par_your_key
SWIM_API_URL=https://perti.vatcscc.org/api/swim/v1
SWIM_WS_URL=wss://perti.vatcscc.org/api/swim/v1/ws

# Optional Settings
SWIM_TIMEOUT=30
SWIM_RETRY_COUNT=3
SWIM_LOG_LEVEL=info
```

---

## Verification Steps

After setup, verify the following endpoints are working:

### 1. API Root
```bash
GET /api/swim/v1/
```
Should return API info with version and available endpoints.

### 2. Flights Endpoint
```bash
GET /api/swim/v1/flights
```
Should return flight data (may require authentication header).

### 3. WebSocket Connection
```javascript
const ws = new WebSocket('wss://perti.vatcscc.org/api/swim/v1/ws');
ws.onopen = () => {
  ws.send(JSON.stringify({
    type: 'subscribe',
    topics: ['flights']
  }));
};
```
Should accept subscriptions and receive flight updates.

### 4. Ingest Endpoints (System/Partner Key Required)
```bash
POST /api/swim/v1/ingest/flight
Authorization: Bearer swim_sys_your_key
Content-Type: application/json

{
  "callsign": "TEST123",
  "departure": "KJFK",
  "arrival": "KLAX"
}
```
Should accept and process flight data.

---

## Monthly Costs

| Service | Cost |
|---------|------|
| Azure SQL Basic | $5/month |
| **Total estimated** | **$5/month** |

> **Note:** Costs may vary based on DTU usage and data transfer. Monitor Azure Cost Management for actual usage.

---

## Troubleshooting

### Common Issues

1. **Database Connection Failures**
   - Verify firewall rules allow your IP
   - Check connection string format
   - Ensure database exists

2. **WebSocket Not Connecting**
   - Verify Apache proxy configuration
   - Check SSL certificate is valid
   - Ensure port 8090 is not blocked

3. **Sync Daemon Not Running**
   - Check cron job is configured correctly
   - Verify PHP CLI is available
   - Check log files for errors

4. **Rate Limit Errors**
   - Verify API key tier has sufficient quota
   - Implement exponential backoff in clients
   - Contact admin to upgrade tier if needed

---

## Support

For assistance with VATSWIM setup:
- Technical Documentation: `docs/swim/VATSWIM_API_Documentation.md`
- API Reference: `docs/swim/openapi.yaml`
- Design Document: `docs/swim/VATSWIM_Design_Document_v1.md`

---

*Last Updated: 2026-01-18*
