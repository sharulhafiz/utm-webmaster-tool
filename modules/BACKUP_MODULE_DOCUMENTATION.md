# UTM Backup Module - Enhanced Documentation

## Overview

The UTM Backup Module provides comprehensive backup and restore functionality for WordPress installations, including:

1. **Database Backups** - Full database dumps with compression
2. **Menu Backups** - Incremental versioning of navigation menus
3. **Automated Scheduling** - Daily automatic backups
4. **Email Notifications** - Alert administrators of backup status
5. **Restore Functionality** - Easy menu restoration interface

## Features

### 🗄️ Database Backup

#### Automatic Backups
- Scheduled daily at midnight via WordPress cron
- Full database dumps using mysqldump
- Automatic compression (gzip) to save storage space
- Configurable retention period (default: 7 days)
- Integrity verification after backup creation

#### Manual Backups
- On-demand backup creation through admin interface
- Progress indicators and status messages
- Email notifications on completion or failure

#### Storage Location
- Backups stored in: `wp-content/uploads/utm-backups/database/`
- Protected with `.htaccess` to prevent direct access
- Organized by timestamp for easy identification

### 📋 Menu Backup (NEW)

#### Automatic Protection
- Menus automatically backed up on every save
- Backup created before menu deletion (safety net)
- Keeps last 20 versions per menu by default
- JSON format for easy inspection and portability

#### Incremental Versioning
- Each menu change creates a new backup version
- Timestamp-based version tracking
- Trigger information (manual, auto-save, before-delete)
- No duplicate backups if content unchanged

#### Restore Capabilities
- Preview menu structure before restoring
- One-click restore to any previous version
- Preserves menu hierarchy and relationships
- Maintains menu item properties (classes, targets, etc.)

#### Storage Location
- Menu backups stored in: `wp-content/uploads/utm-backups/menus/`
- Protected directory structure
- JSON format with full menu metadata

### ⚙️ Configuration Options

Access settings via: **Tools → UTM Backup → Settings**

| Setting | Default | Description |
|---------|---------|-------------|
| Database Backup Retention | 7 days | How long to keep database backups |
| Email Notifications | Disabled | Receive backup status emails |
| Notification Email | admin email | Email address for notifications |

### 📧 Email Notifications

When enabled, you'll receive emails for:

**Success Notifications:**
- Backup filename
- File size
- Timestamp
- Automated confirmation

**Failure Notifications:**
- Error details
- Timestamp
- Troubleshooting guidance

## Usage Guide

### Database Backups

#### Creating Manual Backup
1. Navigate to **Tools → UTM Backup**
2. Click **Database Backup** tab
3. Click **Create Backup Now** button
4. Wait for confirmation message
5. Download backup from the list below

#### Downloading Backup
1. Find the backup in the list
2. Click **Download** button
3. Save the `.sql.gz` file to your computer

#### Restoring Database (Manual Process)
For safety, database restoration is done manually:

**Using phpMyAdmin:**
1. Extract the `.sql.gz` file
2. Log into phpMyAdmin
3. Select your database
4. Go to Import tab
5. Choose the `.sql` file
6. Click Go

**Using MySQL Command Line:**
```bash
# Extract backup
gunzip database-backup-20241021-123456.sql.gz

# Restore database
mysql -u username -p database_name < database-backup-20241021-123456.sql
```

### Menu Backups

#### Automatic Backups
No action needed! Menus are automatically backed up when you:
- Save a menu in **Appearance → Menus**
- Delete a menu

#### Creating Manual Backup
1. Navigate to **Tools → UTM Backup**
2. Click **Menu Backup** tab
3. Select a menu from dropdown
4. Click **Backup Selected Menu** button

#### Previewing Menu Backup
1. Find the backup in Menu Backup History
2. Click **Preview** button
3. Review menu structure in modal
4. Close preview when done

#### Restoring Menu
1. Find the backup version you want to restore
2. Click **Preview** to verify it's the correct version
3. Click **Restore** button
4. Confirm the action
5. Menu will be restored immediately

**⚠️ Important:** Restoring a menu will replace the current menu configuration. Make sure you have the right version!

#### Downloading Menu Backup
1. Click **Download** button next to the backup
2. Save the `.json` file
3. Can be used for manual inspection or migration

### Understanding Backup Triggers

Menu backups show a trigger indicator:

- **👤 Manual** - Created via manual backup button
- **💾 Auto Save** - Created when menu was saved
- **🗑️ Before Delete** - Created before menu deletion

## Technical Details

### Database Backup Process

```
1. Initialize backup file path
2. Build mysqldump command with proper escaping
3. Execute mysqldump
4. Verify backup integrity
   - Check for MySQL dump header
   - Verify presence of CREATE TABLE or INSERT statements
5. Compress backup with gzip (if available)
6. Delete uncompressed file
7. Clean up old backups based on retention policy
8. Send email notification (if enabled)
```

### Menu Backup Structure

Menu backups are stored as JSON with the following structure:

```json
{
  "menu_id": 123,
  "menu_name": "Main Menu",
  "menu_slug": "main-menu",
  "menu_locations": {
    "primary": 123,
    "footer": 456
  },
  "timestamp": "2024-10-21 12:34:56",
  "trigger": "auto_save",
  "items": [
    {
      "ID": 789,
      "title": "Home",
      "url": "https://example.com",
      "menu_item_parent": 0,
      "menu_order": 1,
      "object": "page",
      "object_id": 2,
      "type": "post_type",
      "type_label": "Page",
      "target": "",
      "attr_title": "",
      "description": "",
      "classes": ["menu-item", "home"],
      "xfn": ""
    }
  ]
}
```

### Security Measures

1. **Directory Protection**
   - `.htaccess` files prevent direct web access
   - Backups stored outside web root when possible

2. **Nonce Verification**
   - All AJAX requests verified with WordPress nonces
   - Prevents CSRF attacks

3. **Capability Checks**
   - Database operations require `manage_options` capability
   - Menu operations require `edit_theme_options` capability

4. **File Validation**
   - Strict filename validation
   - File type checking before download/restore
   - Path traversal prevention

### Performance Considerations

**Database Backups:**
- Large databases may take several minutes
- Compressed backups save 70-90% storage space
- Single-transaction mode ensures consistency

**Menu Backups:**
- Minimal performance impact (< 100ms)
- JSON format is lightweight
- No database queries during backup creation

## Troubleshooting

### Database Backup Issues

#### "mysqldump not available on this system"
**Solution:** Install MySQL client tools on your server.

**For Ubuntu/Debian:**
```bash
sudo apt-get install mysql-client
```

**For CentOS/RHEL:**
```bash
sudo yum install mysql
```

#### "Backup file was not created or is empty"
**Possible causes:**
- Insufficient disk space
- Permission issues on backup directory
- Database connection problems

**Solution:**
1. Check available disk space
2. Verify wp-content/uploads permissions (should be 755)
3. Check error log in backup directory

#### "Backup integrity check failed"
**Solution:**
- Check database server is running
- Verify database credentials in wp-config.php
- Check error log for specific MySQL errors

### Menu Backup Issues

#### Menu not appearing in backup list
**Solution:**
- Ensure menu has been saved at least once
- Check menu backup directory permissions
- Verify menu still exists in WordPress

#### Restore creates duplicate menu
**Explanation:** If the original menu was deleted, restore creates a new menu with the same name.

**Solution:** This is expected behavior. You can delete duplicate menus manually.

#### Menu structure incorrect after restore
**Solution:**
1. Check the backup file is not corrupted
2. Verify all menu item relationships were preserved
3. Try restoring from a different backup version

### General Issues

#### "No backup files found"
**Possible causes:**
- Backups stored in old location (pre-enhancement)
- Migration from old backup location needed

**Solution:**
Old backups are in `wp-content/uploads/`. New backups are in `wp-content/uploads/utm-backups/`. You can manually move old backups to the new location:

```bash
# Move old database backups
mv wp-content/uploads/database-backup-*.sql* wp-content/uploads/utm-backups/database/

# Verify files are present
ls wp-content/uploads/utm-backups/database/
```

#### Email notifications not received
**Solution:**
1. Verify email notifications are enabled in Settings
2. Check WordPress can send emails (test with password reset)
3. Check spam folder
4. Verify correct email address in settings

## Maintenance

### Recommended Practices

1. **Regular Testing**
   - Test restore procedures quarterly
   - Verify backup integrity monthly
   - Keep off-site backup copies

2. **Monitoring**
   - Check backup logs weekly
   - Review error log regularly
   - Monitor disk space usage

3. **Retention Policy**
   - Adjust based on your needs
   - Consider weekly/monthly archives for long-term storage
   - Balance between storage costs and retention needs

### Disk Space Management

**Estimating Backup Size:**
- Database backup: ~10-30% of database size (compressed)
- Menu backup: < 50 KB per menu

**Example Storage Usage:**
- 100 MB database × 7 days = ~210 MB (compressed)
- 10 menus × 20 versions = ~10 MB
- Total: ~220 MB

### Migration to New Server

**Exporting Backups:**
1. Download all required backups through admin interface
2. Or copy entire backup directory via FTP/SSH

**Importing on New Server:**
1. Restore database backup using MySQL
2. Copy menu backup files to new server
3. Use admin interface to restore menus

## Advanced Usage

### Programmatic Access

#### Create Database Backup via Code
```php
// Get backup instance
$backup = new UTM_Webmaster_Tool_Backup();

// Create backup
$result = $backup->create_database_backup();

if ($result['success']) {
    echo "Backup created: " . $result['file'];
} else {
    echo "Backup failed: " . $result['error'];
}
```

#### Create Menu Backup via Code
```php
// Backup specific menu
$menu_id = 123;
$backup = new UTM_Webmaster_Tool_Backup();
$filename = $backup->backup_menu_on_save($menu_id);

if ($filename) {
    echo "Menu backed up: " . $filename;
}
```

### Customization

#### Change Menu Backup Retention
Edit the class property in `backup.php`:

```php
private $max_menu_versions = 50; // Keep 50 versions instead of 20
```

#### Custom Backup Schedule
Replace daily schedule with custom interval:

```php
// In constructor, replace the schedule_daily_backup hook
add_action('admin_init', function() {
    if (!wp_next_scheduled('utm_webmaster_custom_backup')) {
        // Schedule every 6 hours
        wp_schedule_event(time(), 'sixhourly', 'utm_webmaster_custom_backup');
    }
});

// Register custom interval
add_filter('cron_schedules', function($schedules) {
    $schedules['sixhourly'] = array(
        'interval' => 6 * 60 * 60,
        'display' => 'Every 6 Hours'
    );
    return $schedules;
});
```

## Future Enhancements

Potential features for future versions:

1. **Database Restore via UI** (with safety checks and confirmations)
2. **Incremental Database Backups** (only changed tables)
3. **Remote Storage Integration** (S3, Dropbox, Google Drive)
4. **Backup Encryption** (for sensitive data)
5. **Differential Backups** (save only changes)
6. **Multi-site Support** (per-site and network backups)
7. **Backup Comparison** (diff between versions)
8. **Scheduled Reports** (backup status summaries)
9. **Integration with Other Plugins** (WooCommerce data, etc.)
10. **API for Third-party Access** (REST endpoints)

## Support

For issues or questions:

1. Check error log in backup directory
2. Review this documentation
3. Contact your WordPress administrator
4. Check WordPress debug log (wp-content/debug.log)

## Changelog

### Version 2.0 (Current)
- ✅ Added incremental menu backup system
- ✅ Menu restore functionality with preview
- ✅ Improved backup file detection
- ✅ Enhanced UI with tabbed interface
- ✅ Email notifications for backup operations
- ✅ Backup integrity verification
- ✅ Configurable retention policies
- ✅ Better error handling and logging
- ✅ Security improvements (directory protection)

### Version 1.0 (Previous)
- Basic database backup functionality
- Daily scheduled backups
- Manual backup creation
- Simple backup download

---

**Last Updated:** October 21, 2024
**Module:** backup.php
**Location:** modules/backup.php
