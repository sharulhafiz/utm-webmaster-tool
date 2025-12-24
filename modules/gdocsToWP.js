/**
 * Recursively finds all Google Docs in a folder and its sub-folders,
 * then pushes their content to a WordPress site as custom posts.
 */

// === 1. UPDATE THESE VALUES ===
const FOLDER_ID = '14npnQkpgt7AbfB6FdvtW1gPs7MoWQysQ';
const WORDPRESS_URL = 'https://sustainable.utm.my'; // NO trailing slash
const WP_USERNAME = 'adminsustainability'; // Your WordPress username
const WP_APPLICATION_PASSWORD = 'Gbj2 Vohg Ov77 lsXp OcNC itQ9'; // Your generated application password
const WP_POST_TYPE = 'the'; // The custom post type you created

/**
 * Main function to orchestrate the entire process.
 */
function pushDocsToWordPress() {
  Logger.log('--- Starting: Pushing Google Docs to WordPress ---');

  try {
    // 1. Get a flat list of all Google Docs in the folder tree
    const allFiles = getAllFilesRecursive(FOLDER_ID);
    Logger.log(`Found ${allFiles.length} file(s) to process.`);

    // 2. Loop through each file and push it to WordPress
    allFiles.forEach(file => {
      if (file.mimeType === MimeType.GOOGLE_DOCS) {
        Logger.log(`Processing: "${file.name}" (ID: ${file.id})`);

        // 3. Fetch the content of the Google Doc as HTML
        const htmlContent = getGoogleDocAsHTML(file.id);
        if (!htmlContent) {
          Logger.log(`...Skipped: Could not fetch content for "${file.name}".`);
          return; // continue to next file
        }

        // 3.5 Parse HTML Content
        const parsedContent = parseGoogleDocHtml(htmlContent);
        filea = DriveApp.getFileById(file.id);
        lastModifiedTime = filea.getLastUpdated();
        
        // 4. Send the data to WordPress to create or update the post
        createOrUpdateWordPressPost({
          google_id: file.id,
          title: file.name,
          content: parsedContent,
          status: 'publish',
          google_modified: lastModifiedTime
        });
      }
      Utilities.sleep(3000);
    });

    Logger.log('--- Finished: All files processed. ---');

  } catch (e) {
    Logger.log(`Error during execution: ${e.toString()}`);
    Logger.log(`Stack: ${e.stack}`);
  }
}

/**
 * Creates or updates a post in WordPress.
 * First, it checks if a post with the given Google Doc ID already exists.
 * If it exists, it updates only if Google Doc was modified after the last WordPress post update.
 * Otherwise, it creates a new post.
 * @param {object} postData The data for the post.
 *   Required fields: google_id, title, content, status, google_modified (ISO string)
 */
function createOrUpdateWordPressPost(postData) {
  const restApiUrl = `${WORDPRESS_URL}/wp-json/wp/v2/${WP_POST_TYPE}`;
  const headers = {
    'Authorization': 'Basic ' + Utilities.base64Encode(`${WP_USERNAME}:${WP_APPLICATION_PASSWORD}`)
  };

  let targetUrl = restApiUrl;
  let method = 'post'; // Default to create new post
  let matchedPost = null;

  // --- 1) Preferred: Lookup by Google Doc ID via custom endpoint ---
  try {
    const lookupUrl = `${WORDPRESS_URL}/wp-json/utm/v1/post-by-google-id?google_id=${encodeURIComponent(postData.google_id)}`;
    const lookupResp = UrlFetchApp.fetch(lookupUrl, { headers: headers, muteHttpExceptions: true });
    const lookupCode = lookupResp.getResponseCode();
    if (lookupCode === 200) {
      const existing = JSON.parse(lookupResp.getContentText());
      const postId = existing.id;
      Logger.log(`...Found existing post by Google Doc ID (ID: ${postId}).`);

      // Fetch full post to get modified timestamps
      const postDetailResp = UrlFetchApp.fetch(`${restApiUrl}/${postId}`, { headers: headers, muteHttpExceptions: true });
      if (postDetailResp.getResponseCode() === 200) {
        matchedPost = JSON.parse(postDetailResp.getContentText());
      } else {
        Logger.log('...Warning: could not fetch post details for ID ' + postId + '. Falling back to other lookups.');
      }
    }
  } catch (e) {
    Logger.log('...Lookup by Google ID failed: ' + e.toString());
  }

  // --- 2) Fallback: try slug-based exact lookup (more reliable than free-text search) ---
  if (!matchedPost) {
    try {
      const slug = wpSlugify(postData.title);
      if (slug) {
        const slugUrl = `${restApiUrl}?slug=${encodeURIComponent(slug)}&per_page=1`;
        const slugResp = UrlFetchApp.fetch(slugUrl, { headers: headers, muteHttpExceptions: true });
        if (slugResp.getResponseCode() === 200) {
          const posts = JSON.parse(slugResp.getContentText());
          if (Array.isArray(posts) && posts.length > 0) {
            matchedPost = posts[0];
            Logger.log('...Found existing post by slug (ID: ' + matchedPost.id + ', slug: ' + slug + ').');
          }
        }
      }
    } catch (e) {
      Logger.log('...Slug lookup failed: ' + e.toString());
    }
  }

  // --- 3) Final fallback: title free-text search (existing behavior) ---
  if (!matchedPost) {
    try {
      const queryUrl = `${restApiUrl}?search=${encodeURIComponent(postData.title)}&per_page=5`;
      const existingPostsResponse = UrlFetchApp.fetch(queryUrl, { headers: headers, muteHttpExceptions: true });
      if (existingPostsResponse.getResponseCode() === 200) {
        const existingPosts = JSON.parse(existingPostsResponse.getContentText());
        matchedPost = existingPosts.find(post => post.title && post.title.rendered === postData.title);
        if (matchedPost) Logger.log('...Found existing post by title (ID: ' + matchedPost.id + ').');
      }
    } catch (e) {
      Logger.log('...Title search failed: ' + e.toString());
    }
  }

  // If we have a matchedPost, decide whether to update (compare timestamps or force)
  if (matchedPost) {
    const postId = matchedPost.id;
    const wpModifiedRaw = matchedPost.modified_gmt || matchedPost.modified;
    const googleModifiedRaw = postData.google_modified;

    const wpModified = wpModifiedRaw ? new Date(wpModifiedRaw) : null;
    const googleModified = googleModifiedRaw ? new Date(googleModifiedRaw) : null;

    if (!isValidDate(wpModified) || !isValidDate(googleModified)) {
      Logger.log('Invalid date(s) detected:');
      Logger.log('  google_modified: ' + googleModifiedRaw);
      Logger.log('  wpModified: ' + wpModifiedRaw);
      // Still allow update via force if dates invalid
    }

    Logger.log('Google Doc modified: ' + (isValidDate(googleModified) ? googleModified.toISOString() : 'Invalid date: ' + postData.google_modified));
    Logger.log('WP Post modified: ' + (isValidDate(wpModified) ? wpModified.toISOString() : 'Invalid date: ' + (matchedPost.modified_gmt || matchedPost.modified)));

    const forceUpdate = true; // keep existing behavior — can be toggled
    if (isValidDate(googleModified) && isValidDate(wpModified) && googleModified <= wpModified && forceUpdate !== true) {
      Logger.log(`--- Google Doc NOT modified since last update. Skipping post ID ${postId}.`);
      return; // Skip updating
    }

    Logger.log(`+++ Updating existing post ID ${postId}.`);
    targetUrl = `${restApiUrl}/${postId}`;
    method = 'post';
  } else {
    Logger.log('...No existing post found. Creating new one.');
  }

  let postPayload = {
    title: postData.title,
    content: postData.content,
    status: postData.status,
    meta: {
      _google_doc_id: postData.google_id
    }
  };

  const options = {
    method: method,
    headers: headers,
    contentType: 'application/json',
    payload: JSON.stringify(postPayload),
    muteHttpExceptions: true
  };

  const response = UrlFetchApp.fetch(targetUrl, options);
  const responseCode = response.getResponseCode();
  const responseBody = response.getContentText();

  if (responseCode === 200 || responseCode === 201) {
    // Determine whether this was a create or update
    let action = 'updated';
    if (responseCode === 201) action = 'created';
    else if (responseCode === 200 && targetUrl === restApiUrl) action = 'created';
    const respJson = (() => { try { return JSON.parse(responseBody); } catch (e) { return null; }})();
    const returnedId = respJson && respJson.id ? respJson.id : '(unknown)';
    Logger.log(`✅ Success! Post ${action}. WordPress Post ID: ${returnedId}`);
  } else {
    Logger.log(`...Error communicating with WordPress. Response Code: ${responseCode}`);
    Logger.log(`...Response Body: ${responseBody}`);
  }
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
  recursiveFileLister(rootFolder, fileList, 0);
  return fileList;
}

/**
 * Helper function that traverses folders recursively to build a file list.
 * @param {GoogleAppsScript.Drive.Folder} folder The folder to process.
 * @param {Array} fileList The array to accumulate file objects.
 * @param {number} depth The current recursion depth.
 */
function recursiveFileLister(folder, fileList, depth) {
  const files = folder.getFiles();
  while (files.hasNext()) {
    const file = files.next();
    fileList.push({
      id: file.getId(),
      name: file.getName(),
      url: file.getUrl(),
      mimeType: file.getMimeType()
    });
  }

  const subFolders = folder.getFolders();
  while (subFolders.hasNext()) {
    recursiveFileLister(subFolders.next(), fileList, depth + 1);
  }
}

/**
 * Parse and clean Google Docs HTML content
 * Preserves text, images, videos, and formatting including styles
 * @param {string} html Raw HTML string exported from Google Docs
 * @return {string} Cleaned and sanitized HTML string with preserved styling
 */
function parseGoogleDocHtml(html) {
  if (!html) return '';

  // Extract the <style> tag content BEFORE removing head
  let styleContent = '';
  const styleMatch = html.match(/<style[^>]*>([\s\S]*?)<\/style>/i);
  if (styleMatch) {
    styleContent = styleMatch[1];
  }

  // Remove <head> section (after extracting styles)
  html = html.replace(/<head>[\s\S]*?<\/head>/gi, '');

  // Remove <html> and <body> tags, preserve content
  html = html.replace(/<\/?html[^>]*>/gi, '');
  html = html.replace(/<body([^>]*)>/gi, ''); // Remove opening body tag
  html = html.replace(/<\/body>/gi, ''); // Remove closing body tag

  // CRITICAL: DO NOT strip class attributes - they're needed for styling
  // The previous version removed these, breaking the styles

  // Add lazy loading to images
  html = html.replace(/<img([^>]*?)>/gi, '<img$1 loading="lazy">');

  // Remove empty <span> tags
  html = html.replace(/<span[^>]*>\s*<\/span>/gi, '');

  // Wrap content in a scoped container for styling isolation
  let scopedHtml = '<div class="google-doc-content">';
  
  // Add scoped styles if we have them
  // Note: WordPress may strip <style> tags from post content for security
  // So we use a data attribute to store styles and load them via PHP
  if (styleContent) {
    // Minify: remove newlines and extra spaces from CSS
    let minifiedStyles = styleContent
      .replace(/\s+/g, ' ')
      .replace(/\s*{\s*/g, '{')
      .replace(/\s*}\s*/g, '}')
      .replace(/\s*:\s*/g, ':')
      .replace(/\s*;\s*/g, ';')
      .trim();
    
    // Store styles in a data attribute (WordPress won't strip this)
    scopedHtml = '<div class="google-doc-content" data-gdoc-styles="' + 
                 minifiedStyles.replace(/"/g, '&quot;') + '">';
    
  }
  
  // Add the HTML content
  scopedHtml += html;
  scopedHtml += '</div>';

  // Add optional classes to tables/lists for additional styling hooks
  scopedHtml = scopedHtml.replace(/<table/gi, '<table class="google-doc-table"');
  scopedHtml = scopedHtml.replace(/<ul/gi, '<ul class="google-doc-list"');
  scopedHtml = scopedHtml.replace(/<ol/gi, '<ol class="google-doc-list"');

  return scopedHtml;
}

function wpSlugify(title) {
  if (!title) return '';
  // Basic normalization: remove diacritics, convert to lower-case, replace non-alphanum with hyphen
  try {
    // Normalize and strip combining accents
    let normalized = title.normalize ? title.normalize('NFKD') : title;
    normalized = normalized.replace(/\p{Diacritic}/gu, '');
    let slug = normalized.toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')   // non-alphanum -> hyphen
      .replace(/^-+|-+$/g, '')       // trim hyphens
      .replace(/-+/g, '-');          // collapse
    return slug;
  } catch (e) {
    // Fallback simple slugify
    return title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').replace(/-+/g, '-');
  }
}

function isValidDate(d) {
  return d instanceof Date && !isNaN(d);
}
