<?php
// Include the WordPress environment
require( dirname(__FILE__) . '/../../../../wp-load.php' );

// Check if the form was submitted
if (isset($_POST['generate_ics'])) {
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

    try {
        // Fetch OPENAI_API_KEY from the options table
        $OPENAI_API_KEY = get_option('openai_api_key');
        
        if (!$OPENAI_API_KEY) {
            throw new Exception('OpenAI API key is not set.');
        }
    } catch (Exception $e) {
        // Handle the exception (e.g., log the error, display a message to the user)
        error_log($e->getMessage());
        wp_die('An error occurred: ' . $e->getMessage());
    }


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