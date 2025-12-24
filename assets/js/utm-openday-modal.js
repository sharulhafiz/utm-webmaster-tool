/**
 * UTM Open Day Modal Popup
 * Displays a promotional modal in the bottom right corner
 * @version 1.0.0
 */
(function() {
    'use strict';
    
    // Configuration
    var config = {
        cookieName: 'utm_openday_modal',
        endDate: new Date('2025-10-29T17:00:00+08:00'), // 29 Oct 2025 5pm MYT
        imageUrl: 'https://digital.utm.my/wp-content/uploads/2025/10/eBunting-openDay2025.gif',
        targetUrl: 'https://digital.utm.my/openday',
        cookieDurationHours: 4,
        delayMs: 1000
    };
    
    // Inject CSS styles
    function injectStyles() {
        var style = document.createElement('style');
        style.textContent = `
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
                animation: utm-slideIn 0.5s ease-out;
            }
            
            @keyframes utm-slideIn {
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
        `;
        document.head.appendChild(style);
    }
    
    // Create modal HTML
    function createModal() {
        var modal = document.createElement('div');
        modal.id = 'utm-openday-modal';
        modal.innerHTML = `
            <button class="modal-close" aria-label="Close">&times;</button>
            <a href="${config.targetUrl}" target="_blank" rel="noopener noreferrer">
                <img src="${config.imageUrl}" alt="UTM Open Day">
            </a>
        `;
        document.body.appendChild(modal);
        return modal;
    }
    
    // Cookie helpers
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
    
    // Analytics tracking
    function trackEvent(eventName, data) {
        if (window.dataLayer) {
            window.dataLayer.push(Object.assign({ event: eventName }, data || {}));
        }
    }
    
    // Initialize modal
    function init() {
        var now = new Date();
        
        // Check if campaign has ended
        if (now >= config.endDate) {
            return;
        }
        
        // Check if logged in
        if (document.cookie.includes('wordpress_logged_in')) {
            return;
        }
        
        // Check cookie
        if (getCookie(config.cookieName)) {
            return;
        }
        
        // Inject styles and create modal
        injectStyles();
        var modal = createModal();
        var closeBtn = modal.querySelector('.modal-close');
        var link = modal.querySelector('a');
        
        // Show modal after delay
        setTimeout(function() {
            modal.classList.add('show');
            trackEvent('openday_modal_shown');
            
            // Auto-close after 10 seconds
            setTimeout(function() {
                if (modal.classList.contains('show')) {
                    modal.classList.remove('show');
                    setCookie(config.cookieName, '1', config.cookieDurationHours);
                    trackEvent('openday_modal_auto_closed');
                }
            }, 10000); // 10 seconds
        }, config.delayMs);
        
        // Close button handler
        closeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            modal.classList.remove('show');
            setCookie(config.cookieName, '1', config.cookieDurationHours);
            trackEvent('openday_modal_closed');
        });
        
        // Track click on modal
        link.addEventListener('click', function() {
            setCookie(config.cookieName, '1', config.cookieDurationHours);
            trackEvent('openday_modal_clicked', { target_url: config.targetUrl });
        });
    }
    
    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // Export init function for manual initialization
    window.UTMOpenDayModal = {
        init: init,
        config: config
    };
})();
