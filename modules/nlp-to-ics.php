<?php
// Add the shortcode to convert natural language to ICS format

// Register the shortcode
add_shortcode('nlp_to_ics', 'nlp_to_ics_shortcode');

// Shortcode callback function
function nlp_to_ics_shortcode($atts) {
    ob_start(); // Start output buffering
    ?>

    <form method="post" action="<?php echo plugins_url('/modules/generate_ics.php', __FILE__); ?>">
        <textarea name="nlp_text" rows="5" cols="50" placeholder="Enter natural language event description"></textarea>
        <br>
        <input type="submit" name="generate_ics" value="Generate ICS">
    </form>

    <script>
        jQuery(document).ready(function($) {
            $('#my-form').submit(function(e) {
                e.preventDefault();
                var nlp_text = $('#my-textarea').val();
                $.post(my_ajax_object.ajax_url, {
                    action: 'my_ajax_action',
                    nlp_text: nlp_text
                }, function(response) {
                    // Handle the response here...
                });
            });
        });
    </script>
    <?php
    return ob_get_clean(); // Return the shortcode output
}


// Handle the AJAX request
function handle_my_ajax_request() {
    // Check if the form was submitted
    if (isset($_POST['nlp_text'])) {
        // Process the form submission
        $nlp_text = sanitize_textarea_field($_POST['nlp_text']);
        $ics_content = convert_nlp_to_ics($nlp_text);

        // Generate a unique filename for the ICS file
        $filename = 'event-' . time() . '.ics';

        // Set the appropriate headers for file download
        header('Content-Type: text/calendar');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        // Output the ICS content
        echo $ics_content;
    }
    wp_die(); // All AJAX handlers should die when finished
}
add_action('wp_ajax_my_ajax_action', 'handle_my_ajax_request');
add_action('wp_ajax_nopriv_my_ajax_action', 'handle_my_ajax_request');

// Function to convert natural language to ICS format
function convert_nlp_to_ics($nlp_text) {
    return "BEGIN:VCALENDAR
    VERSION:2.0
    PRODID:-
    BEGIN:VEVENT
    SUMMARY:FROM ZERO TO HERO: BUILD POWERFUL APPS WITH APPSHEET (NO CODING REQUIRED)
    DTSTART;TZID=Asia/Kuala_Lumpur:20240404T100000
    DTEND;TZID=Asia/Kuala_Lumpur:20240404T120000
    DESCRIPTION:📅 Tarikh : 4 April 2024 (KHAMIS)\\n⏰ Masa : 10:00 PAGI - 12:00 TENGAH HARI\\n👤 Penceramah/ Penyampai : MS. NOOR FARHANI FARHAT\nWorkspace Product Specialist\n\nSila tonton:\nCisco_Webex_logo_-_Brandlogos.net.svg (1).png\nframe (1).png\n\natau\nJoin webex\nhttps://utm.webex.com/utm/j.php?MTID=m36ca96b9517e7432024f1867d3d94338
    END:VEVENT
    END:VCALENDAR";

    // Use Open AI or any other NLP library to convert the natural language to ICS format
    $OPENAI_API_KEY = get_option('openai_api_key');
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $OPENAI_API_KEY
    ));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
        'model' => 'gpt-3.5-turbo',
        'messages' => array(
            array(
                'role' => 'system',
                'content' => 'You are an ics file generator. Your input will in text, image and url and then output only in icalendar format that are compatible with Google Calendar'
            ),
            array(
                'role' => 'user',
                'content' => $nlp_text
            )
        ),
        'temperature' => 1,
        'max_tokens' => 256,
        'top_p' => 1,
        'frequency_penalty' => 0,
        'presence_penalty' => 0
    )));

    $response = curl_exec($ch);
    curl_close($ch);

    $ics_content = json_decode($response, true);

    // Fix the PRODID field with a new value
    $new_prodid = "-//UTM//NLTOICAL//EN";
    $ics_content = preg_replace('/PRODID:-/', 'PRODID:' . $new_prodid, $ics_content);

    // Fix \n to \\n
    $ics_content = str_replace('\n', '\\n', $ics_content);

    return $ics_content;
}