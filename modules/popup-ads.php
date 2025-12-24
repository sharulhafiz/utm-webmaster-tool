<?php
function people_enqueue_popup_script() {
    // If domain is not "people.utm.my", return early
    if ($_SERVER['HTTP_HOST'] !== 'people.utm.my') {
        return;
    }
    // Generate JavaScript dynamically to avoid caching
    echo <<<'JS'
    <script type="text/javascript">
        (function() {
            var userIp = "' . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '') . '";
            var utmIpRanges = ["10.0.0.0/8", "161.139.0.0/16"];
            var popupCookieName = "utm_popup";
            var popupUrls = [
                "https://plex.it/referrals/U1K7KCVS",
                // 14 Dec 2025
                "https://vt.tiktok.com/ZSHwb9S5cCos5-HkdGf/",
                "https://vt.tiktok.com/ZSHwbQhWYuxa1-vCjjU/"

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

            if (isIpInRanges(userIp, utmIpRanges)) return;  // Skip if IP is in UTM ranges
            if (document.cookie.includes("wordpress_logged_in")) return;    // Skip if user is logged in
            if (currentHour >= 18 || currentHour < 6) { // 6 PM to 6 AM
                document.cookie = popupCookieName + "=1; path=/; max-age=86400";
                scriptTag.parentNode.removeChild(scriptTag);
                return;
            }
            if (randomNumber > 7) { // 30% chance to not show popup
                document.cookie = popupCookieName + "=1; path=/; max-age=86400";
                scriptTag.parentNode.removeChild(scriptTag);
                return;
            }
            if (!document.cookie.includes(popupCookieName)) {   // Show popup if cookie not set
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
add_action('wp_footer', 'people_enqueue_popup_script');

// Modal popup for Open Day promotion
function utm_openday_modal_popup() {
    ?>
    <style>
        #utm-openday-modal {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 200px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 9999;
            overflow: hidden;
            display: none;
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(100px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        #utm-openday-modal.show {
            display: block;
        }
        
        #utm-openday-modal .modal-close {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(0,0,0,0.7);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            font-size: 18px;
            line-height: 1;
            cursor: pointer;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }
        
        #utm-openday-modal .modal-close:hover {
            background: rgba(0,0,0,0.9);
        }
        
        #utm-openday-modal a {
            display: block;
            cursor: pointer;
        }
        
        #utm-openday-modal img {
            width: 100%;
            height: auto;
            display: block;
        }
    </style>
    
    <div id="utm-openday-modal">
        <button class="modal-close" aria-label="Close">&times;</button>
        <a href="https://digital.utm.my/openday" target="_blank" rel="noopener noreferrer">
            <img src="https://digital.utm.my/wp-content/uploads/2025/10/eBunting-openDay2025.gif" alt="UTM Open Day">
        </a>
    </div>
    
    <script>
        (function() {
            var modal = document.getElementById('utm-openday-modal');
            var closeBtn = modal.querySelector('.modal-close');
            var cookieName = 'utm_openday_modal';
            var endDate = new Date('2025-10-29T17:00:00+08:00'); // 29 Oct 2025 5pm MYT
            var now = new Date();
            
            // Check if campaign has ended
            if (now >= endDate) {
                return;
            }
            
            // Check if logged in
            if (document.cookie.includes('wordpress_logged_in')) {
                return;
            }
            
            // Check cookie
            function getCookie(name) {
                var value = '; ' + document.cookie;
                var parts = value.split('; ' + name + '=');
                if (parts.length === 2) return parts.pop().split(';').shift();
                return null;
            }
            
            function setCookie(name, value, hours) {
                var expires = '';
                if (hours) {
                    var date = new Date();
                    date.setTime(date.getTime() + (hours * 60 * 60 * 1000));
                    expires = '; expires=' + date.toUTCString();
                }
                document.cookie = name + '=' + value + expires + '; path=/';
            }
            
            // Show modal if cookie not set
            if (!getCookie(cookieName)) {
                setTimeout(function() {
                    modal.classList.add('show');
                    
                    // Track event
                    if (window.dataLayer) {
                        window.dataLayer.push({
                            event: 'openday_modal_shown'
                        });
                    }
                    
                    // Auto-close after 10 seconds
                    setTimeout(function() {
                        if (modal.classList.contains('show')) {
                            modal.classList.remove('show');
                            setCookie(cookieName, '1', 4);
                            
                            // Track event
                            if (window.dataLayer) {
                                window.dataLayer.push({
                                    event: 'openday_modal_auto_closed'
                                });
                            }
                        }
                    }, 10000); // 10 seconds
                }, 1000); // Show after 1 second
            }
            
            // Close button handler
            closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                modal.classList.remove('show');
                setCookie(cookieName, '1', 4); // Set cookie for 4 hours
                
                // Track event
                if (window.dataLayer) {
                    window.dataLayer.push({
                        event: 'openday_modal_closed'
                    });
                }
            });
            
            // Track click on modal
            modal.querySelector('a').addEventListener('click', function() {
                setCookie(cookieName, '1', 4); // Set cookie for 4 hours
                
                // Track event
                if (window.dataLayer) {
                    window.dataLayer.push({
                        event: 'openday_modal_clicked'
                    });
                }
            });
        })();
    </script>
    <?php
}
add_action('wp_footer', 'utm_openday_modal_popup');

