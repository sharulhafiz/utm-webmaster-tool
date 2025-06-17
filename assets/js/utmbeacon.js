// This script checks if the current page was served from cache and sends a beacon to the server if it was.
// It uses the Fetch API to make a HEAD request to the current URL and checks the 'hummingbird-cache' header.
// If the header indicates that the page was served from cache, it sends a beacon with the URL, cache status, and timestamp to the server's cache monitor endpoint.
// This is useful for monitoring cache hits and understanding how often cached pages are served to users.
(function() {
  fetch(window.location.href, { method: 'HEAD' })
    .then(response => {
      const cacheStatus = response.headers.get('hummingbird-cache');
      const payload = JSON.stringify({
        url: window.location.href,
        status: cacheStatus || 'Missed',
        timestamp: Date.now()
      });
      const sent = navigator.sendBeacon('/wp-json/cache-monitor/v1/hit', payload);
      if (sent) {
        console.log('[Cache Monitor] Beacon sent successfully:', payload);
      } else {
        console.warn('[Cache Monitor] Failed to send beacon:', payload);
      }
    })
    .catch(err => {
      console.error('[Cache Monitor] Error checking cache status:', err);
    });
})();
