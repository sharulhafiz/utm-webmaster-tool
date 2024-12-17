<?php
// add an api endpoint to check this plugin is active or not, with the version
add_action('rest_api_init', function () {
	register_rest_route('utm-webmaster-tool/v1', '/status', array(
		'methods' => 'GET',
		'callback' => 'utm_webmaster_tool_status',
	));
});

function utm_webmaster_tool_status($data)
{
	return array(
		'active' => true,
		'version' => '5.22',
	);
}

// content of API Tester
function api_tester()
{
	?>
    <div class="wrap">
        <h1>API Tester</h1>
        <p>Check the status of UTM Webmaster Tool</p>
        <button id="check-status">Check Status</button>
        <div id="status"></div>
    </div>
    <script>
        document.getElementById('check-status').addEventListener('click', function () {
            fetch('<?php echo get_rest_url(null, 'utm-webmaster-tool/v1/status'); ?>')
                .then(response => response.json())
                .then(data => {
                    document.getElementById('status').innerText = JSON.stringify(data, null, 2);
                });
        });

        const fetch = require('node-fetch');

        const sftpConfig = {
            "profiles": {
                "people": {
                    "host": "161.139.21.66",
                    "username": "peoplevs",
                    "password": "in0%Ej459",
                    "remotePath": "/httpdocs/wp-content/plugins"
                },
                "utm": {
                    "host": "161.139.21.66",
                    "username": "wplogin",
                    "password": "Sjy863h6#",
                    "remotePath": "/httpdocs/wp-content/plugins"
                },
                // ... other profiles
            }
        };

        const profiles = sftpConfig.profiles;
        const apiRoute = '/wp-json/utm-webmaster-tool/v1/status';

        async function fetchPluginVersion(host) {
            try {
                const response = await fetch(`http://${host}${apiRoute}`);
                if (!response.ok) {
                    throw new Error(`Failed to fetch from ${host}: ${response.statusText}`);
                }
                const data = await response.json();
                console.log(`Host: ${host}, Plugin Version: ${data.version}`);
            } catch (error) {
                console.error(`Error fetching plugin version from ${host}:`, error);
            }
        }

        async function fetchAllPluginVersions() {
            for (const profileName in profiles) {
                if (profiles.hasOwnProperty(profileName)) {
                    const profile = profiles[profileName];
                    await fetchPluginVersion(profile.host);
                }
            }
        }

        fetchAllPluginVersions();
    </script>
    <?php
}