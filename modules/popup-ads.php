<?php
function enqueue_popup_script() {
    // If current page is not people.utm.my, skip
    if (!preg_match('/people.utm.my/', $_SERVER['HTTP_HOST'])) {
        ?>
        <script>
        console.log('Popup ads not loaded');
        </script>
        <?php
        return;
    } else {
        // If current page is people.utm.my, show popup ads
    ?>
    <script>
        console.log('Popup ads loaded');
        jQuery(document).ready(function($) {
            // Load popup ads
            var popup_ads = '<div id="popup-ads" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 9999;">' +
                '<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background-color: white; padding: 20px;">' +
                '<h2>Get 50% discount on all products!</h2>' +
                '<p>Use code: <strong>DISCOUNT50</strong></p>' +
                '<button id="close-popup-ads" style="padding: 10px 20px; background-color: #0073aa; color: white; border: none; cursor: pointer;">Close</button>' +
                '</div>' +
                '</div>';
            $('body').append(popup_ads);

            // Show popup ads
            $('#popup-ads').fadeIn();

            // Close popup ads
            $('#close-popup-ads').click(function() {
                $('#popup-ads').fadeOut();
            });
        });
    </script>
    <?php
    }
}
add_action('wp_enqueue_scripts', 'enqueue_popup_script');