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

### API Endpoints
- [ ] Responses return valid JSON
- [ ] Error handling works
- [ ] Authentication enforced where required

---

## Database Testing

Test migrations on a staging database before production:

```bash
# Backup first
mysqldump -u user -p database > backup.sql

# Apply migration
mysql -u user -p database < migration.sql

# Verify
mysql -u user -p database -e "DESCRIBE new_table;"
```

---

## Browser Testing

Test across supported browsers:
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

---

## See Also

- [[Contributing]] - Contribution process
- [[Code Style]] - Coding standards
