<?php
add_action('wp_enqueue_scripts', 'wpb_add_googleanalytics');
function wpb_add_googleanalytics(){ 
  // Google Analytics
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

    console.log('Google Analytics is loaded');
  </script>

<?php } ?>