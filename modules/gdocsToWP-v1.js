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

  // Query existing post by title using the 'search' parameter
  const queryUrl = `${restApiUrl}?search=${encodeURIComponent(postData.title)}&per_page=5`; 
  // Limit results to 5 to reduce response size; adjust if needed

  const existingPostsResponse = UrlFetchApp.fetch(queryUrl, { headers: headers, muteHttpExceptions: true });
  const existingPosts = JSON.parse(existingPostsResponse.getContentText());

  // Find exact title match among returned posts (search is partial match)
  const matchedPost = existingPosts.find(post => post.title && post.title.rendered === postData.title);


  let targetUrl = restApiUrl;
  let method = 'post'; // Default to create new post

  if (matchedPost) {
    const postId = matchedPost.id;
    Logger.log(`...Found existing post by title (ID: ${postId}). Updating.`);

    // Compare Google Doc last modified time vs WordPress post modified time
    // WordPress dates are ISO 8601, e.g., "2025-11-06T08:00:00"
    const wpModifiedRaw = matchedPost.modified_gmt || matchedPost.modified;
    const googleModifiedRaw = postData.google_modified;

    const wpModified = wpModifiedRaw ? new Date(wpModifiedRaw) : null;
    const googleModified = googleModifiedRaw ? new Date(googleModifiedRaw) : null;

    if (!isValidDate(wpModified) || !isValidDate(googleModified)) {
      Logger.log('Invalid date(s) detected:');
      Logger.log('  google_modified: ' + googleModifiedRaw);
      Logger.log('  wpModified: ' + wpModifiedRaw);
      // Optionally, skip or handle as needed
      return;
    }

    Logger.log('Google Doc modified: ' + (isValidDate(googleModified) ? googleModified.toISOString() : 'Invalid date: ' + postData.google_modified));
    Logger.log('WP Post modified: ' + (isValidDate(wpModified) ? wpModified.toISOString() : 'Invalid date: ' + (matchedPost.modified_gmt || matchedPost.modified)));

    if (googleModified > wpModified) {
      Logger.log(`...Google Doc modified after last update. Updating post ID ${postId}.`);
      targetUrl = `${restApiUrl}/${postId}`; // Update existing post
      method = 'post'; // POST for update works too for WP REST API
    } else {
      Logger.log(`...Google Doc NOT modified since last update. Skipping post ID ${postId}.`);
      return; // Skip updating
    }
  } else {
    Logger.log('...No existing post found by title. Creating new one.');
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
    Logger.log(`...Success! Post ${existingPosts.length > 0 ? 'updated' : 'created'}. WordPress Post ID: ${JSON.parse(responseBody).id}`);
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
 * Preserves text, images, videos, and formatting
 * @param {string} html Raw HTML string exported from Google Docs
 * @return {string} Cleaned and sanitized HTML string
 */
function parseGoogleDocHtml(html) {
  if (!html) return '';

  // Remove <head> section
  html = html.replace(/<head>[\s\S]*?<\/head>/gi, '');

  // Remove <html> and <body> tags, preserve content
  html = html.replace(/<\/?html[^>]*>/gi, '');
  html = html.replace(/<\/?body[^>]*>/gi, '');

  // Remove only Google's tracking id/class attributes
  html = html.replace(/ (id|class)="c\d+"/gi, '');

  // Add lazy loading and class to images
  html = html.replace(/<img([^>]*?)>/gi, '<img$1 loading="lazy" class="google-doc-image">');

  // Remove empty <span> tags
  html = html.replace(/<span[^>]*>\s*<\/span>/gi, '');

  // Optionally add your own classes to tables/lists
  html = html.replace(/<table/gi, '<table class="google-doc-table"');
  html = html.replace(/<ul/gi, '<ul class="google-doc-list"');
  html = html.replace(/<ol/gi, '<ol class="google-doc-list"');

  // DO NOT strip or filter style attributes—preserve all for maximum fidelity
  return html;
}

function isValidDate(d) {
  return d instanceof Date && !isNaN(d);
}
