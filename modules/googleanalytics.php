<?php
add_action('wp_head', 'wpb_add_googleanalytics');
function wpb_add_googleanalytics(){ 
  if (is_user_logged_in()) {
    return;
  }
  ?>

  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-N3HJW8G3P7"></script>
  <script>
    window.dataLayer = window.dataLayer || [];

    function gtag() {
      dataLayer.push(arguments);
    }
    gtag('js', new Date());

    gtag('config', 'G-N3HJW8G3P7');
  </script>

<?php } ?>