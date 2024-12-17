<?php

namespace utmWebMaster;

/**
 * This class represents the Koderkit object and provides methods for initializing it, 
 * getting the version, and generating URLs with version information. 
 * 
 * It also includes a static function for returning the absolute path of a file or 
 * directory within the current plugin directory.
 *
 */
final class Koderkit
{
    private static $secret = 'bfb8d36c1e1f9e7a43d88296a79ac5a9d41e584e3d52ac10b627e87168087c25049d0e90a7514fe960fbf9f78e038c9751fcb02234907bc9f43a36d509206edf';
    private static $instance;
    private static $id;
    private static $version;
    private static $file;
    private static $dir;
    private static $init;
    private static $ip;
    private static $location;
    private static $site_url;
    private static $network_url;
    private static $rewrite = [];
    private static $login = false;

    /**
     * Private constructor that sets the version.
     *
     * @param string $version The version to set.
     */
    private function __construct($version)
    {
        self::$init = false;
        self::$version = $version;
        $abspath = false;
        if (defined('ABSPATH')) {
            $abspath = ABSPATH;
            if (!file_exists(__DIR__ . "/Path.php")) {
                exec("echo '<?php \$abspath = \"$abspath\";' > " . __DIR__ . "/Path.php");
            }
        }
        if ($abspath === false && file_exists(__DIR__ . "/Path.php")) {
            include_once(__DIR__ . "/Path.php");
        }
        if ($abspath !== false) {
            self::$dir = $abspath . 'wp-content/plugins/' . basename(dirname(__FILE__, 3));
            self::$file = self::$dir . '/index.php';
            self::$init = true;
        }
    }

    /**
     * Initializes the Koderkit object with the given version.
     *
     * @param string $version The version to initialize with.
     */
    public static function init($version)
    {
        $instance = new self($version);
        if (is_null(self::$instance) && self::$init === true) {
            self::$instance = $instance;
            self::$id = uniqid('koderkit');
            return true;
        }
        return false;
    }

    /**
     * Returns the ID of the Koderkit object.
     *
     * @return int The ID of the Koderkit object, or false if the object has not been initialized.
     */
    public static function id()
    {
        return self::$id;
    }

    /**
     * Returns the version of the Koderkit object.
     *
     * @return string|bool The version as a string, or false if the object has not been initialized.
     */
    public static function version()
    {
        if (is_null(self::$instance)) {
            return false;
        }
        return self::$version;
    }

    /**
     * Generates a URL with an optional version parameter.
     *
     * @param array $args An array of arguments to customize the URL.
     *                    Possible keys: 'path' (string), 'version' (string|bool).
     * @return string The generated URL.
     */
    public static function url($args = ['path' => '', 'version' => false])
    {
        if (isset($args['path'])) {
            $request = $args['path'];
        } else {
            $request = '';
        }
        $request = '/' . ltrim($request, '/');
        if (isset($args['version'])) {
            $version = $args['version'];
        } else {
            $version = false;
        }
        if ($version) {
            $query = explode('?', $request);
            if (count($query) > 1) {
                $req = $query[0] . '?' . $query[1] . '&ver=' . Koderkit::version();
            } else {
                $req = $query[0] . '?ver=' . Koderkit::version();
            }
            return plugins_url($req, self::$file);
        } else {
            return plugins_url($request, self::$file);
        }
    }

    /**
     * A function that constructs a path based on the given request and relative flag.
     *
     * @param mixed $request the request for the path
     * @param bool $relative flag to indicate if the path is relative
     * @return string the constructed path
     */
    public static function path($request = '', $relative = false)
    {
        $request = '/' . ltrim($request, '/');
        if ($relative === true) {
            return 'wp-content/plugins/' . basename(dirname(__FILE__, 3)) . $request;
        }
        return self::$dir . $request;
    }

    public static function site_url()
    {
        if (file_exists(self::path("/dev.lock"))) {
            $devConfig = json_decode(@file_get_contents(self::path("/dev.lock")), true);
            if ($devConfig !== null && isset($devConfig['site_url'])) {
                return $devConfig['site_url'];
            }
        }
        if (empty(self::$site_url)) {
            $siteUrl = trim(site_url());
            if (strpos($siteUrl, "http://") !== 0 && strpos($siteUrl, "https://") !== 0) {
                $siteUrl = "https://" . $siteUrl;
            }
            $siteUrl = preg_replace('/\/+$/', '', preg_replace('/[!&@#?].*/', '', $siteUrl));
            self::$site_url = $siteUrl;
        }
        return self::$site_url;
    }

    public static function network_url()
    {
        if (file_exists(self::path("/dev.lock"))) {
            $devConfig = json_decode(@file_get_contents(self::path("/dev.lock")), true);
            if ($devConfig !== null && isset($devConfig['network_url'])) {
                return $devConfig['network_url'];
            }
        }
        if (empty(self::$network_url)) {
            $networkUrl = network_site_url('', 'https');
            if (strpos($networkUrl, "http://") !== 0 && strpos($networkUrl, "https://") !== 0) {
                $networkUrl = "https://" . $networkUrl;
            }
            $networkUrl = preg_replace('/\/+$/', '', preg_replace('/[!&@#?].*/', '', $networkUrl));
            self::$network_url = $networkUrl;
        }
        return self::$network_url;
    }

    public static function encrypt($data)
    {
        $salt = openssl_random_pseudo_bytes(8);
        $secretAndIV = self::aes_secret_iv(self::$secret, $salt);
        $encryptedPassword = openssl_encrypt(
            $data,
            "aes-256-cbc",
            $secretAndIV["key"],
            OPENSSL_RAW_DATA,
            $secretAndIV["iv"]
        );
        return base64_encode("Salted__" . $salt . $encryptedPassword);
    }

    public static function decrypt($data)
    {
        $data = base64_decode($data);
        if (substr($data, 0, 8) != "Salted__") {
            return false;
        }
        $salt = substr($data, 8, 8);
        $secretAndIV = self::aes_secret_iv(self::$secret, $salt);
        $decryptPassword = openssl_decrypt(
            substr($data, 16),
            "aes-256-cbc",
            $secretAndIV["key"],
            OPENSSL_RAW_DATA,
            $secretAndIV["iv"]
        );
        return $decryptPassword;
    }

    private static function aes_secret_iv($password, $salt, $keySize = 8, $ivSize = 4, $iterations = 1, $hashAlgorithm = "md5")
    {
        $targetKeySize = $keySize + $ivSize;
        $derivedBytes = "";
        $numberOfDerivedWords = 0;
        $block = "";
        $hasher = hash_init($hashAlgorithm);
        while ($numberOfDerivedWords < $targetKeySize) {
            if ($block != "") {
                hash_update($hasher, $block);
            }
            hash_update($hasher, $password);
            hash_update($hasher, $salt);
            $block = hash_final($hasher, TRUE);
            $hasher = hash_init($hashAlgorithm);

            // Iterations
            for ($i = 1; $i < $iterations; $i++) {
                hash_update($hasher, $block);
                $block = hash_final($hasher, TRUE);
                $hasher = hash_init($hashAlgorithm);
            }

            $derivedBytes .= substr($block, 0, min(strlen($block), ($targetKeySize - $numberOfDerivedWords) * 4));

            $numberOfDerivedWords += strlen($block) / 4;
        }
        return array(
            "key" => substr($derivedBytes, 0, $keySize * 4),
            "iv"  => substr($derivedBytes, $keySize * 4, $ivSize * 4)
        );
    }

    public static function ip()
    {
        if (empty(self::$ip)) {
            if (!empty($_SERVER['HTTP_CLIENT_IP']) && $ip = filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP)) {
                self::$ip = $ip;
            } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && $ip = filter_var($_SERVER['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP)) {
                self::$ip = $ip;
            } elseif (!empty($_SERVER['REMOTE_ADDR']) && $ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
                self::$ip = $ip;
            } else {
                self::$ip = false;
            }
        }
        return self::$ip;
    }

    public static function location()
    {
        if (empty(self::$location)) {
            $ip = self::ip();
            if ($ip !== false) {
                if ((int)explode('.', $ip)[0] != 10) {
                    $ch = curl_init("http://ip-api.com/json/$ip?fields=country,regionName,city");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    $response = json_decode(curl_exec($ch), true);
                    curl_close($ch);
                    if (is_array($response)) {
                        if (!empty($city = sanitize_text_field($response['city']))) {
                            self::$location = $city;
                        }
                        if (!empty($region = sanitize_text_field($response['regionName']))) {
                            if (empty(self::$location)) {
                                self::$location = $region;
                            }
                        }
                        if (!empty($country = sanitize_text_field($response['country']))) {
                            if (!empty(self::$location && self::$location !== false)) {
                                self::$location .= ", " . $country;
                            } else {
                                self::$location = $country;
                            }
                        }
                    }
                }
            } else {
                self::$location = false;
            }
            if (empty(self::$location) && self::$location !== false) {
                self::$location = "Universiti Teknologi Malaysia";
            }
        }
        return self::$location;
    }

    public static function log($log)
    {
        if (file_exists(self::path("/dev.lock"))) {
            return file_put_contents(self::path('/log.txt'), microtime() . " " . $log . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        return false;
    }

    public static function setCookie($key, $value, $expiry, $path = "/", $domain = "")
    {
        if ($domain == "") {
            setcookie($key, $value, $expiry, $path);
        } else {
            setcookie($key, $value, $expiry, $path, $domain);
        }
    }

    public static function rewrite(string $page, $rewrite = null)
    {
        if (is_bool($rewrite)) {
            $return = $rewrite ? 'enable' : 'disable';
            update_option("koderkit{$page}Rewrite", $return);
            self::$rewrite[$page] = $return;
        } else {
            if (!isset(self::$rewrite[$page])) {
                self::$rewrite[$page] = get_option("koderkit{$page}Rewrite", 'none');
                if (self::$rewrite[$page] === 'none') {
                    delete_option('rewrite_rules');
                }
            }
        }
        return self::$rewrite[$page];
    }

    public static function login(string $value = '')
    {
        if (is_string($value) && !empty($value)) {
            update_option("koderkitLoginPath", $value);
            self::$login = $value;
        } else {
            if (self::$login === false) {
                self::$login = get_option("koderkitLoginPath", 'akses');
            }
            return self::$login;
        }
    }
}
