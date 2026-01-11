# Troubleshooting

Common issues and solutions for PERTI.

---

## Database Issues

### Cannot connect to MySQL

**Symptoms:** Connection refused, access denied

**Solutions:**
- Verify MySQL is running
- Check credentials in `load/config.php`
- Confirm database exists
- Check firewall rules

---

### Cannot connect to Azure SQL

**Symptoms:** Connection timeout, login failed

**Solutions:**
- Verify `ADL_*` constants in config
- Check Azure SQL firewall allows your IP
- Confirm `pdo_sqlsrv` extension is loaded
- Test connection string separately

---

## Authentication Issues

### OAuth redirect error

**Symptoms:** Redirect loop, invalid state

**Solutions:**
- Verify `VATSIM_REDIRECT_URI` matches exactly
- Check VATSIM Connect app configuration
- Clear cookies and try again

---

### Session expired unexpectedly

**Symptoms:** Logged out mid-session

**Solutions:**
- Check session storage permissions
- Verify `SESSION_PATH` is writable
- Check PHP session settings

---

## Daemon Issues

### Another instance is already running

**Symptoms:** Lock file error

**Solutions:**
```bash
# Check for running process
ps aux | grep vatsim_adl

# Remove stale lock if process not running
rm scripts/vatsim_adl.lock
```

---

### Daemon taking too long

**Symptoms:** SP execution > 10 seconds

**Solutions:**
- Check index health
- Review query execution plans
- Consider history table cleanup

---

## Display Issues

### Map not loading

**Symptoms:** Blank map area

**Solutions:**
- Verify JavaScript enabled
- Check browser console for errors
- Confirm WebGL support
- Try different browser

---

### Flight data not updating

**Symptoms:** Stale positions

**Solutions:**
- Verify ADL daemon is running
- Check VATSIM API status
- Review daemon logs for errors

---

## Performance Issues

### Slow page loads

**Solutions:**
- Check database query times
- Review network latency
- Consider caching
- Check server resources

---

## See Also

- [[Maintenance]] - Routine tasks
- [[Configuration]] - Setup options
- [[Daemons and Scripts]] - Background processes
