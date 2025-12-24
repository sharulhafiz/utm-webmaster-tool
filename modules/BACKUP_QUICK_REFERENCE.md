# UTM Backup Module - Quick Reference Guide

## 🚀 Quick Start

### Access the Backup Module
**WordPress Admin → Tools → UTM Backup**

---

## 📊 Dashboard Overview

### Three Main Tabs
1. **Database Backup** - Full database backups
2. **Menu Backup** - Navigation menu versioning
3. **Settings** - Configuration options

---

## 🗄️ Database Backups

### Create Backup
1. Go to **Database Backup** tab
2. Click **Create Backup Now**
3. Wait for completion (15-120 seconds)
4. Backup appears in list below

### Download Backup
- Click **Download** button next to any backup
- File format: `.sql.gz` (compressed)

### Restore Database
⚠️ **Manual process for safety:**

**Via phpMyAdmin:**
1. Extract `.sql.gz` file
2. Open phpMyAdmin
3. Select database
4. Import → Choose `.sql` file

**Via Command Line:**
```bash
gunzip database-backup-YYYYMMDD-HHMMSS.sql.gz
mysql -u username -p database_name < database-backup-YYYYMMDD-HHMMSS.sql
```

---

## 📋 Menu Backups

### Automatic Protection ✨
Your menus are **automatically backed up** when you:
- Save a menu in **Appearance → Menus**
- Delete a menu

**No action required!**

### Manual Menu Backup
1. Go to **Menu Backup** tab
2. Select menu from dropdown
3. Click **Backup Selected Menu**

### Preview Menu Backup
1. Find backup in history table
2. Click **Preview**
3. Review menu structure
4. Click **Close**

### Restore Menu
1. Click **Preview** to verify correct version
2. Click **Restore** button
3. Confirm action
4. Done! Menu restored

---

## ⚙️ Settings

### Backup Retention
**Default:** 7 days for database backups

**Adjust:**
1. Go to **Settings** tab
2. Change "Database Backup Retention" value
3. Click **Save Changes**

### Email Notifications
**Enable/Disable:**
1. Go to **Settings** tab
2. Check/uncheck "Send email notifications"
3. Enter custom email (optional)
4. Click **Save Changes**

**What You'll Receive:**
- ✅ Success: Backup filename, size, timestamp
- ❌ Failure: Error details, troubleshooting info

---

## 🎯 Common Tasks

### Scenario: Accidentally Deleted Menu Items

**Solution:**
1. Go to **Tools → UTM Backup → Menu Backup**
2. Find recent backup (check timestamp)
3. Click **Preview** to verify
4. Click **Restore**
5. ✅ Menu items restored!

### Scenario: Before Major Changes

**Best Practice:**
1. Create manual database backup
2. Create manual menu backup
3. Make changes
4. If something breaks → restore backup

### Scenario: Weekly Maintenance

**Checklist:**
- [ ] Check backup history for successful runs
- [ ] Download important backups to computer
- [ ] Review error log (if any)
- [ ] Verify disk space available

---

## 📍 Backup Locations

### Database Backups
```
wp-content/uploads/utm-backups/database/
├── database-backup-20241021-123456.sql.gz
├── database-backup-20241020-120000.sql.gz
└── backup-errors.log
```

### Menu Backups
```
wp-content/uploads/utm-backups/menus/
├── menu-123-main-menu-20241021-123456.json
├── menu-123-main-menu-20241021-120000.json
└── menu-456-footer-menu-20241021-123456.json
```

---

## 🔍 Understanding Backup Info

### Database Backup Filename
`database-backup-20241021-123456.sql.gz`
- **20241021** = Date (October 21, 2024)
- **123456** = Time (12:34:56)
- **.gz** = Compressed file

### Menu Backup Filename
`menu-123-main-menu-20241021-123456.json`
- **123** = Menu ID
- **main-menu** = Menu name
- **20241021-123456** = Date and time
- **.json** = JSON format

### Menu Backup Triggers
- **👤 Manual** = You created it manually
- **💾 Auto Save** = Created when menu was saved
- **🗑️ Before Delete** = Safety backup before deletion

---

## ⚡ Pro Tips

### Tip 1: Before Plugin Updates
Always create manual backup before updating plugins or WordPress core.

### Tip 2: Keep Off-site Copies
Download important backups to your computer or cloud storage (Dropbox, Google Drive).

### Tip 3: Test Restores
Periodically test restoring backups to ensure they work.

### Tip 4: Menu Experimentation
Make a manual menu backup before experimenting with menu changes.

### Tip 5: Monitor Email Notifications
Enable email notifications to stay informed of backup status.

---

## ⚠️ Important Notes

### Database Restore
- **Always test on staging site first**
- **Creates a complete database replacement**
- **Current data will be overwritten**
- **Use with caution!**

### Menu Restore
- **Replaces current menu configuration**
- **Preview before restoring**
- **Can be done multiple times**
- **Safe to experiment**

### Automatic Cleanup
- Database backups older than retention period are **automatically deleted**
- Menu backups keep last **20 versions per menu**
- Adjust settings if you need more history

---

## 🆘 Troubleshooting

### "No backup files found"
**Solution:** Check if backup directory exists and has proper permissions.

### "mysqldump not available"
**Solution:** Contact your hosting provider to install MySQL client tools.

### "Backup failed"
**Check:**
1. Disk space available
2. Database connection
3. Error log in backup directory

### Email not received
**Check:**
1. Email notifications enabled in Settings
2. Correct email address
3. Spam/junk folder
4. WordPress can send emails (test password reset)

---

## 📞 Need Help?

1. **Check Error Log:** Settings tab shows recent errors
2. **Review Documentation:** Read BACKUP_MODULE_DOCUMENTATION.md
3. **Contact Administrator:** Provide error details
4. **Check WordPress Debug Log:** wp-content/debug.log

---

## ✅ Quick Checklist

### Daily
- [ ] Automatic backup runs (check email if enabled)

### Weekly
- [ ] Review backup history
- [ ] Download important backups

### Monthly
- [ ] Test backup restore on staging
- [ ] Review retention settings
- [ ] Check disk space usage

### Before Changes
- [ ] Create manual database backup
- [ ] Create manual menu backup
- [ ] Document current state

---

## 🎓 Learn More

**Full Documentation:** `BACKUP_MODULE_DOCUMENTATION.md`
**Improvement Details:** `BACKUP_IMPROVEMENTS_SUMMARY.md`

---

**Version:** 2.0  
**Last Updated:** October 21, 2024  
**Module Location:** `modules/backup.php`
