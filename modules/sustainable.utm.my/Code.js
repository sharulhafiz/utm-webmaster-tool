/**
 * Recursively finds all Google Docs in a folder and its sub-folders,
 * then pushes their content to a WordPress site through the
 * sustainable.utm.my sync endpoints.
 */

const DEFAULT_WORDPRESS_URL = 'https://sustainable.utm.my';
const DEFAULT_WP_POST_TYPE = 'page';
const SUSTAINABLE_SYNC_PATH = '/wp-json/utm-sustainable/v1/sync-page';
const SYNC_STATE_PROPERTY_KEY = 'UTM_GDOC_LAST_PUSH_MAP';

function loadSyncSettings() {
  const scriptProperties = PropertiesService.getScriptProperties().getProperties();
  const wordpressUrl = normalizeWordPressUrl(scriptProperties.WORDPRESS_URL || DEFAULT_WORDPRESS_URL);
  const folderId = (scriptProperties.FOLDER_ID || '').trim();
  const wpUsername = (scriptProperties.WP_USERNAME || '').trim();
  const wpApplicationPassword = (scriptProperties.WP_APP_PASSWORD || '').trim();
  const wpPostType = (scriptProperties.WP_POST_TYPE || DEFAULT_WP_POST_TYPE).trim();

  if (!folderId) {
    throw new Error('Missing Apps Script property: FOLDER_ID');
  }

  if (!wpUsername || !wpApplicationPassword) {
    throw new Error('Missing Apps Script properties: WP_USERNAME and/or WP_APP_PASSWORD');
  }

  return {
    folderId: folderId,
    wordpressUrl: wordpressUrl,
    wpUsername: wpUsername,
    wpApplicationPassword: wpApplicationPassword,
    wpPostType: wpPostType
  };
}

function normalizeWordPressUrl(url) {
  return String(url || '').replace(/\/+$/, '');
}

/**
 * Main function to orchestrate the entire process.
 *
 * @param {Object=} options Optional runtime options.
 * @param {boolean=} options.force When true, pushes all docs regardless of timestamp.
 */
function pushDocsToWordPress(options) {
  const runOptions = options || {};
  const forceSync = Boolean(runOptions.force);

  Logger.log('--- Starting: Pushing Google Docs to WordPress ---');

  try {
    const settings = loadSyncSettings();
    const syncState = loadLastPushState();
    const stats = {
      totalFiles: 0,
      nonDocsSkipped: 0,
      unchangedSkipped: 0,
      attempted: 0,
      success: 0,
      failed: 0
    };

    // 1. Get a flat list of all Google Docs in the folder tree
    const allFiles = getAllFilesRecursive(settings.folderId);
    stats.totalFiles = allFiles.length;
    Logger.log(`Found ${allFiles.length} file(s) to process.`);
    Logger.log(`Incremental mode: ${forceSync ? 'FORCE (all files)' : 'ON (skip unchanged)'}`);

    // 2. Loop through each file and push it to WordPress
    allFiles.forEach(file => {
      try {
        if (file.mimeType !== MimeType.GOOGLE_DOCS) {
          stats.nonDocsSkipped += 1;
          Logger.log(`Skipping non-Google-Doc file: "${file.name}" (${file.mimeType})`);
          return;
        }

        const lastModifiedTime = DriveApp.getFileById(file.id).getLastUpdated();
        const lastModifiedIso = lastModifiedTime.toISOString();
        const lastPushedIso = syncState[file.id] || '';

        if (!forceSync && lastPushedIso === lastModifiedIso) {
          stats.unchangedSkipped += 1;
          Logger.log(`Skipping unchanged file: "${file.name}" (ID: ${file.id})`);
          return;
        }

        Logger.log(`Processing: "${file.name}" (ID: ${file.id})`);
        stats.attempted += 1;

        // 3. Fetch the content of the Google Doc as HTML
        const htmlContent = getGoogleDocAsHTML(file.id);
        if (!htmlContent) {
          stats.failed += 1;
          Logger.log(`...Skipped: Could not fetch content for "${file.name}".`);
          return;
        }

        // 3.5 Parse HTML Content
        const parsedContent = parseGoogleDocHtml(htmlContent);

        // 4. Send the data to WordPress to create or update the post
        const result = createOrUpdateWordPressPost({
          google_id: file.id,
          title: file.name,
          content: parsedContent,
          status: 'publish',
          google_modified: lastModifiedIso,
          folder_path: file.folder_path || []
        });

        if (result && result.ok) {
          syncState[file.id] = lastModifiedIso;
          stats.success += 1;
        } else {
          stats.failed += 1;
        }
      } catch (fileError) {
        stats.failed += 1;
        Logger.log(`...Error while processing "${file.name}" (${file.id}): ${fileError.toString()}`);
      }

      Utilities.sleep(3000);
    });

    saveLastPushState(syncState);

    Logger.log('--- Sync summary ---');
    Logger.log(`Total discovered: ${stats.totalFiles}`);
    Logger.log(`Skipped non-doc: ${stats.nonDocsSkipped}`);
    Logger.log(`Skipped unchanged: ${stats.unchangedSkipped}`);
    Logger.log(`Attempted pushes: ${stats.attempted}`);
    Logger.log(`Successful pushes: ${stats.success}`);
    Logger.log(`Failed pushes: ${stats.failed}`);

    Logger.log('--- Finished: All files processed. ---');

  } catch (e) {
    Logger.log(`Error during execution: ${e.toString()}`);
    Logger.log(`Stack: ${e.stack}`);
  }
}

/**
 * Creates or updates a page in WordPress through the sustainable sync API.
 * @param {object} postData The data for the post.
 *   Required fields: google_id, title, content, status, google_modified (ISO string)
 */
function createOrUpdateWordPressPost(postData) {
  const settings = loadSyncSettings();
  const restApiUrl = `${settings.wordpressUrl}${SUSTAINABLE_SYNC_PATH}`;
  const headers = {
    Authorization: 'Basic ' + Utilities.base64Encode(`${settings.wpUsername}:${settings.wpApplicationPassword}`)
  };

  const postPayload = {
    google_id: postData.google_id,
    title: postData.title,
    content: postData.content,
    status: postData.status || 'publish',
    google_modified: postData.google_modified,
    folder_path: Array.isArray(postData.folder_path) ? postData.folder_path : [],
    post_type: settings.wpPostType
  };

  Logger.log(`...Syncing Google Doc ${postData.google_id} to ${restApiUrl}`);

  const response = UrlFetchApp.fetch(restApiUrl, {
    method: 'post',
    headers: headers,
    contentType: 'application/json',
    payload: JSON.stringify(postPayload),
    muteHttpExceptions: true
  });

  const responseCode = response.getResponseCode();
  const responseBody = response.getContentText();

  if (responseCode === 200 || responseCode === 201) {
    try {
      const result = JSON.parse(responseBody);
      Logger.log(`...Success! WordPress page ID: ${result.post_id || result.id || 'unknown'}`);
      return {
        ok: true,
        postId: result.post_id || result.id || null
      };
    } catch (parseError) {
      Logger.log('...Success, but response body could not be parsed as JSON.');
      return {
        ok: true,
        postId: null
      };
    }
  } else {
    Logger.log(`...Error communicating with WordPress. Response Code: ${responseCode}`);
    Logger.log(`...Response Body: ${responseBody}`);
    throw new Error(`WordPress sync failed (${responseCode}) for doc ${postData.google_id}`);
  }
}

/**
 * Load persisted sync state map from Script Properties.
 *
 * @return {Object<string, string>}
 */
function loadLastPushState() {
  const raw = PropertiesService.getScriptProperties().getProperty(SYNC_STATE_PROPERTY_KEY);

  if (!raw) {
    return {};
  }

  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch (error) {
    Logger.log(`Invalid sync state JSON. Resetting state. Error: ${error.toString()}`);
    return {};
  }
}

/**
 * Persist sync state map into Script Properties.
 *
 * @param {Object<string, string>} syncState Sync state map.
 */
function saveLastPushState(syncState) {
  const safeState = syncState && typeof syncState === 'object' ? syncState : {};
  PropertiesService.getScriptProperties().setProperty(SYNC_STATE_PROPERTY_KEY, JSON.stringify(safeState));
}



/**
 * Fetches a Google Doc's content as HTML using the direct export URL.
 * This requires the Apps Script OAuth token to access private docs.
 * @param {string} docId The ID of the Google Doc.
 * @return {string} The HTML content, or null if fetching failed.
 */
function getGoogleDocAsHTML(docId) {
  try {
    const exportUrl = `https://docs.google.com/document/d/${docId}/export?format=html`;
    
    const response = UrlFetchApp.fetch(exportUrl, {
      headers: {
        Authorization: 'Bearer ' + ScriptApp.getOAuthToken()
      }
    });
    
    return response.getContentText();
  } catch (e) {
    Logger.log(`Could not fetch HTML export for Doc ID ${docId}. Error: ${e.toString()}`);
    return null;
  }
}


/**
 * Initializes the recursive file search.
 * @param {string} folderId The root folder ID to start from.
 * @return {Array} A flat array of file objects.
 */
function getAllFilesRecursive(folderId) {
  const rootFolder = DriveApp.getFolderById(folderId);
  let fileList = [];
  recursiveFileLister(rootFolder, fileList, [rootFolder.getName()]);
  return fileList;
}

/**
 * Helper function that traverses folders recursively to build a file list.
 * @param {GoogleAppsScript.Drive.Folder} folder The folder to process.
 * @param {Array} fileList The array to accumulate file objects.
 * @param {Array} pathSegments The current folder path relative to the root.
 */
function recursiveFileLister(folder, fileList, pathSegments) {
  const currentPath = Array.isArray(pathSegments) ? pathSegments.slice() : [];

  const files = folder.getFiles();
  while (files.hasNext()) {
    const file = files.next();
    fileList.push({
      id: file.getId(),
      name: file.getName(),
      url: file.getUrl(),
      mimeType: file.getMimeType(),
      folder_path: currentPath
    });
  }

  const subFolders = folder.getFolders();
  while (subFolders.hasNext()) {
    const subFolder = subFolders.next();
    recursiveFileLister(subFolder, fileList, currentPath.concat(subFolder.getName()));
  }
}

/**
 * Parse and clean Google Docs HTML content
 * Preserves text, images, videos, and formatting
 * @param {string} html Raw HTML string exported from Google Docs
 * @return {string} Cleaned and sanitized HTML string
 */
function parseGoogleDocHtml(html) {
  if (!html) return '';

  // Preserve Google Docs CSS so class-based styling remains intact.
  const styleBlocks = (html.match(/<style[\s\S]*?<\/style>/gi) || []).join('\n');

  // Keep only body HTML content if present.
  const bodyMatch = html.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
  let content = bodyMatch ? bodyMatch[1] : html;

  // Remove outer wrappers/doctype if still present.
  content = content.replace(/<!doctype[^>]*>/gi, '');
  content = content.replace(/<\/?html[^>]*>/gi, '');
  content = content.replace(/<\/?body[^>]*>/gi, '');

  // Normalize malformed inline base64 image src values.
  // Some payloads arrive as src="image/png;base64,..." instead of data:image/... .
  content = content.replace(/src=(['"])\s*(image\/[a-zA-Z0-9.+-]+;base64,[^'"\s>]+)\1/gi, 'src=$1data:$2$1');

  // Add lazy loading while preserving existing attributes and classes.
  content = content.replace(/<img\b([^>]*?)>/gi, function(match, attrs) {
    let updatedAttrs = attrs || '';

    if (!/\bloading\s*=\s*['"]/i.test(updatedAttrs)) {
      updatedAttrs += ' loading="lazy"';
    }

    if (/\bclass\s*=\s*['"]/i.test(updatedAttrs)) {
      updatedAttrs = updatedAttrs.replace(/\bclass\s*=\s*(['"])(.*?)\1/i, function(classMatch, quote, classValue) {
        if (/\bgoogle-doc-image\b/i.test(classValue)) {
          return classMatch;
        }

        return `class=${quote}${classValue} google-doc-image${quote}`;
      });
    } else {
      updatedAttrs += ' class="google-doc-image"';
    }

    return `<img${updatedAttrs}>`;
  });

  return styleBlocks ? `${styleBlocks}\n${content}` : content;
}

/**
 * Backward-compatible alias in case an Apps Script trigger still points
 * to the previous function name casing.
 */
function pushDocsToWordpress() {
  return pushDocsToWordPress();
}

/**
 * Force a full push regardless of last modified tracking.
 */
function pushDocsToWordPressForce() {
  return pushDocsToWordPress({ force: true });
}

