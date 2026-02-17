# Quick Start Guide: Auto-Update Module

## For Repository Administrators

### 1. To Enable Auto-Updates (2 minutes)

#### Step 1: Generate GitHub Token
1. Go to https://github.com/settings/tokens
2. Click "Generate new token (classic)"
3. Name: `UTM Plugin Auto-Update`
4. Select scope: ✅ `repo` (Full control of private repositories)
5. Click "Generate token"
6. **Copy the token immediately** (you won't see it again!)

#### Step 2: Add Token to Plugin
1. Open `modules/auto-update.php` in this repository
2. Find line ~90: `$this->access_token = '';`
3. Add your token between the quotes:
   ```php
   $this->access_token = 'ghp_YourTokenHere123456789';
   ```
4. Save the file and commit to the repository

#### Step 3: Deploy
Once you commit the token to this repository, it will automatically work on all WordPress installations that use this plugin. No need to configure each site individually!

### 2. To Create a New Release

#### Method 1: GitHub Web Interface
1. Go to repository: https://github.com/sharulhafiz/utm-webmaster-tool
2. Click "Releases" → "Create a new release"
3. Tag version: `v5.41` (increment from current version)
4. Release title: "Version 5.41"
5. Description: Add release notes
6. Click "Publish release"

#### Method 2: Command Line
```bash
git tag v5.41
git push origin v5.41
# Then create release on GitHub from the tag
```

### 3. Updates Will Appear In
- WordPress Admin → Dashboard → Updates
- WordPress Admin → Plugins (update badge)
- Automatic checks every 12 hours

---

## For Plugin Users

### Installation (Already Done)
The auto-update module is included in the plugin. No installation needed!

### Configuration
No configuration needed on your WordPress installation! The GitHub token is already configured in the plugin repository, so updates work automatically across all your sites.

### How to Update
1. Go to WordPress Admin → Plugins
2. Look for "UTM Webmaster Tool"
3. If update available, click "Update Now"
4. Done!

---

## Troubleshooting

### Updates Not Showing?
1. Verify the GitHub token is set in `modules/auto-update.php` (line ~90)
2. Check token hasn't expired at https://github.com/settings/tokens
3. Ensure releases are published (not draft) on GitHub
4. Check PHP error logs for specific errors
5. Try: Plugins → Check for updates (force refresh)

### Where Are Error Logs?
- Usually: `/wp-content/debug.log` (if WP_DEBUG_LOG enabled)
- Or: Server error logs (ask hosting provider)
- Look for: "UTM Plugin Auto-Updater:" prefix

### Token Not Working?
1. Verify token has `repo` scope
2. Check no extra spaces when pasting
3. Verify access to the repository
4. Try generating a new token

---

## Documentation Files

- **Full README**: `modules/AUTO_UPDATE_README.md`
- **Config Example**: `modules/wp-config-example.php`
- **Implementation**: `IMPLEMENTATION_SUMMARY.md`

---

## Support

For issues or questions, check the documentation or contact the UTM Webmaster team.

---

## Quick Facts

✓ Updates checked: Every 12 hours
✓ API calls: Minimal (cached)
✓ Security: Header-based authentication
✓ Compatible: WordPress 5.0+, PHP 7.2+
✓ Multisite: Yes, fully supported
