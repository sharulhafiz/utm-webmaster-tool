<?php

if (file_exists(dirname(__DIR__, 2) . '/update.lock')) {
    return;
}
require_once(dirname(__DIR__, 2) . '/lib/Composer/autoload.php');
include(__DIR__ . '/version.php');

use utmWebMaster\Koderkit as utmWebMasterKit;
use utmWebMaster\KoderZi\PhpGitHubUpdater\Updater as utmWebMasterUpdate;

utmWebMasterKit::init($wputmwebmasterVersion);

function webmasterUpdate()
{
    if (!file_exists(utmWebMasterKit::path('/update/timestamp.php'))) {
        @file_put_contents(utmWebMasterKit::path('/update/timestamp.php'), '<?php $wputmwebmasterTimestamp = 0;');
    }
    include(utmWebMasterKit::path('/update/timestamp.php'));
    $webmasterCurrentTime = time();
    if ($webmasterCurrentTime - $wputmwebmasterTimestamp > 86400 && !file_exists(utmWebMasterKit::path('/update.lock'))) {
        $webmasterUpdateAuth = base64_encode('webmasterUpdate' . $webmasterCurrentTime);
        @file_put_contents(utmWebMasterKit::path('/update/timestamp.php'), "<?php \$wputmwebmasterTimestamp = $webmasterCurrentTime; \$webmasterUpdateAuth = '$webmasterUpdateAuth';");
        wp_remote_get(utmWebMasterKit::network_url() . "/wp-json/utm-webmaster-update/v1/cron?auth=$webmasterUpdateAuth");
    }
}

add_action('shutdown', webmasterUpdate());

function webmaster_update_run()
{
    $path = dirname(__DIR__, 2);

    $releaseExclusion =  [
        'filename' => [
            'composer.json',
            'composer.lock'
        ]
    ];

    $token = base64_decode('Z2l0aHViX3BhdF8xMUFCV05aQ1kwS1Bya2FQT1hjSDcxXzgwczBKWnh4cTcwdkxGUEJhY1p1cFdhWndTVThIRGduT3dlRmRsSERUekxMRFFZUEdJSEZZbllJWWxV==');

    $additional_info =
        "This is an update for UTM Webmaster Tool Wordpress plugin in " . utmWebMasterKit::network_url() . "<br>"
        . "It seems that this automatic update have failed.";

    new utmWebMasterUpdate(
        'sharulhafiz',
        'utm-webmaster-tool',
        $token,
        utmWebMasterKit::version(),
        'sharulhafiz@utm.my',
        'noreply@utm.my',
        null,
        $releaseExclusion,
        false,
        $path,
        true,
        30,
        $additional_info
    );
}

function webmaster_update_cron($request)
{
    if (!file_exists(utmWebMasterKit::path('/update/timestamp.php'))) {
        return;
    }
    include_once(utmWebMasterKit::path('/update/timestamp.php'));

    $auth = $request->get_param('auth');
    if ($auth != $webmasterUpdateAuth) {
        return;
    }

    webmaster_update_run();

    @file_put_contents(utmWebMasterKit::path('/update/timestamp.php'), "<?php \$wputmwebmasterTimestamp = $wputmwebmasterTimestamp;");
    unset($webmasterUpdateAuth);
}

function webmaster_update_register_routes()
{
    register_rest_route("utm-webmaster-update/v1", "cron", array(
        array(
            "methods" => WP_REST_Server::READABLE,
            "callback" => "webmaster_update_cron",
            "permission_callback" => "__return_true"
        )
    ));
}
add_action("rest_api_init", "webmaster_update_register_routes");
