<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Manual Divi Onboarding Loop Bypass
// Set cookie via browser console: document.cookie = 'debug=true; path=/';
// Then visit any admin page. This will bypass the onboarding loop.

// If cookie not present, do nothing.
if ( ! isset( $_COOKIE['debug'] ) || $_COOKIE['debug'] !== 'true' ) {
    return;
}

// IMMEDIATE BYPASS - runs before any redirects
$is_onboarding = isset( $_GET['page'] ) && $_GET['page'] === 'et_onboarding';
$bypass_messages = array();

// Set bypass options immediately
if ( ! get_option( 'et_onboarding_skipped' ) ) {
    update_option( 'et_onboarding_skipped', true, false );
    $bypass_messages[] = '✓ Set et_onboarding_skipped = true';
}

if ( ! get_option( 'et_onboarding_wizard_completed' ) ) {
    update_option( 'et_onboarding_wizard_completed', true, false );
    $bypass_messages[] = '✓ Set et_onboarding_wizard_completed = true';
}

// Also try disabling the onboarding entirely
if ( ! get_option( 'et_pb_onboarding_disabled' ) ) {
    update_option( 'et_pb_onboarding_disabled', true, false );
    $bypass_messages[] = '✓ Set et_pb_onboarding_disabled = true';
}

// CRITICAL: DELETE et_onboarding option entirely
// From debug.txt: All completion flags are set but redirect persists
// The presence of et_onboarding option itself might be triggering the redirect
// Most aggressive approach: delete it entirely
$et_onboarding = get_option( 'et_onboarding' );
if ( $et_onboarding !== false ) {
    delete_option( 'et_onboarding' );
    $bypass_messages[] = '✓ DELETED et_onboarding option entirely (was: ' . esc_html( print_r( $et_onboarding, true ) ) . ')';
} else {
    $bypass_messages[] = '✓ et_onboarding option already deleted';
}

// Also ensure et_onboarding_completed is set
if ( ! get_option( 'et_onboarding_completed' ) ) {
    update_option( 'et_onboarding_completed', 1, false );
    $bypass_messages[] = '✓ Set et_onboarding_completed = 1';
}

// Clear any opcache if possible
if ( function_exists( 'opcache_reset' ) ) {
    @opcache_reset();
    $bypass_messages[] = '✓ OPcache cleared';
}

// CRITICAL DIAGNOSTIC: Capture what's loaded so far
// If redirect still happens, it's likely JavaScript or server-level
ob_start();
wp_head(); // This might trigger Divi's onboarding redirect scripts
$head_content = ob_get_clean();

// Check for meta refresh
if ( preg_match( '/<meta[^>]*http-equiv=["\']refresh["\'][^>]*>/i', $head_content, $matches ) ) {
    $bypass_messages[] = '⚠ META REFRESH DETECTED: ' . esc_html( $matches[0] );
}

// Check for window.location redirects
if ( preg_match( '/window\.location[^;]*=\s*["\'][^"\']*onboarding[^"\']*["\']/i', $head_content, $matches ) ) {
    $bypass_messages[] = '⚠ JS REDIRECT DETECTED: ' . esc_html( $matches[0] );
}

// Show immediate feedback
echo '<div style="position:fixed;top:10px;left:10px;right:10px;background:#efe;border:3px solid #0a0;padding:20px;z-index:999999;font-family:monospace;">';
echo '<h2 style="margin:0 0 10px 0;color:#060;">Divi Onboarding Bypass Applied</h2>';
foreach ( $bypass_messages as $msg ) {
    echo '<div style="padding:4px 0;">' . esc_html( $msg ) . '</div>';
}
echo '<hr style="margin:15px 0;border:1px solid #0a0;">';
echo '<strong>Next Steps:</strong><br/>';
echo '1. Clear debug cookie: <code>document.cookie = "debug=; Max-Age=0; path=/";</code> (paste in browser console)<br/>';
echo '2. Navigate to: <a href="' . esc_url( admin_url() ) . '" style="color:#009;font-weight:bold;">' . esc_html( admin_url() ) . '</a><br/>';
echo '3. If loop persists, check Step 2 below for remaining Divi options.<br/>';
echo '<hr style="margin:15px 0;border:1px solid #0a0;">';
echo '<strong>Current URL:</strong> <code style="font-size:11px;">' . esc_html( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : 'N/A' ) . '</code><br/>';
echo '<strong>On et_onboarding page?</strong> ' . ( $is_onboarding ? '<span style="color:#900;font-weight:bold;">YES - bypass applied</span>' : '<span style="color:#666;">No</span>' ) . '<br/>';

// Show all Divi-related options for inspection
global $wpdb;
if ( isset( $wpdb ) ) {
    echo '<hr style="margin:15px 0;border:1px solid #0a0;">';
    echo '<strong>All Divi-related Options (for troubleshooting):</strong><br/>';
    $options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%divi%' OR option_name LIKE '%et_%' ORDER BY option_name" );
    echo '<div style="max-height:300px;overflow:auto;background:#fff;padding:8px;margin-top:8px;border:1px solid #ccc;"><pre style="margin:0;font-size:10px;">';
    foreach ( $options as $option ) {
        $value = maybe_unserialize( $option->option_value );
        if ( is_array( $value ) || is_object( $value ) ) {
            $value = print_r( $value, true );
        }
        if ( strlen( $value ) > 200 ) {
            $value = substr( $value, 0, 200 ) . '... (truncated)';
        }
        echo esc_html( $option->option_name ) . ' => ' . esc_html( (string) $value ) . "\n";
    }
    echo '</pre></div>';
}

echo '</div>';

// Stop here so user can read the message - don't let WordPress continue processing
exit;

// Option to debug
$options_to_debug = array(
    'divi'
);

// DIVI ONBOARDING BYPASS FIX
// The redirect loop happens because Divi's onboarding page (page=et_onboarding&content=disabled)
// keeps redirecting. We bypass it by setting the option that marks onboarding as complete/skipped.
$apply_divi_bypass = true;

if ( $apply_divi_bypass ) {
    // Check if we're on the et_onboarding page
    $is_onboarding_page = isset( $_GET['page'] ) && $_GET['page'] === 'et_onboarding';
    
    if ( $is_onboarding_page ) {
        // Set the option that tells Divi onboarding was skipped/completed
        // Common Divi onboarding completion options:
        $bypass_applied = false;
        
        // Option 1: Mark onboarding as skipped
        if ( ! get_option( 'et_onboarding_skipped' ) ) {
            update_option( 'et_onboarding_skipped', true );
            $bypass_applied = true;
            echo '<div style="background-color: #efe; border: 2px solid #0a0; padding: 10px; margin: 10px;">';
            echo '<strong>Divi Onboarding Bypass Applied:</strong> Set et_onboarding_skipped = true';
            echo '</div>';
        }
        
        // Option 2: Mark wizard as completed (some Divi versions use this)
        if ( ! get_option( 'et_onboarding_wizard_completed' ) ) {
            update_option( 'et_onboarding_wizard_completed', true );
            $bypass_applied = true;
            echo '<div style="background-color: #efe; border: 2px solid #0a0; padding: 10px; margin: 10px;">';
            echo '<strong>Divi Onboarding Bypass Applied:</strong> Set et_onboarding_wizard_completed = true';
            echo '</div>';
        }
        
        if ( $bypass_applied ) {
            echo '<div style="background-color: #eef; border: 2px solid #009; padding: 10px; margin: 10px;">';
            echo '<strong>Next Step:</strong> Click "Reset debug (clear cookie)" above, then navigate to WP Admin dashboard manually. The onboarding loop should be resolved.';
            echo '</div>';
        } else {
            echo '<div style="background-color: #ffd; border: 2px solid #aa0; padding: 10px; margin: 10px;">';
            echo '<strong>Divi Onboarding Bypass Status:</strong> Options already set (et_onboarding_skipped and et_onboarding_wizard_completed exist). If loop persists, check Step 2 for additional Divi onboarding-related options to clear.';
            echo '</div>';
        }
    }
}

// Handle reset request (clear cookie)
if ( isset( $_GET['utm_debug_reset'] ) ) {
    setcookie( 'debug', '', time() - 3600, '/' );
    // also unset in the current request so subsequent checks in this request treat it as cleared
    unset( $_COOKIE['debug'] );
}

// Determine current step
$current_step = isset( $_GET['utm_debug_step'] ) ? intval( $_GET['utm_debug_step'] ) : 1;
$continue_flag = isset( $_GET['utm_debug_continue'] ) && $_GET['utm_debug_continue'] == '1';

// Intercept wp_redirect attempts while debugging so we can inspect them and avoid real redirects
global $utm_debug_redirects, $utm_debug_loop;
$utm_debug_redirects = array();
$utm_debug_loop = false;
if ( ! function_exists( 'utm_debug_wp_redirect_intercept' ) ) {
    function utm_debug_wp_redirect_intercept( $location, $status ) {
        global $utm_debug_redirects, $continue_flag, $current_step;
        $entry = array(
            'time' => date( 'c' ),
            'location' => $location,
            'status' => $status,
            'backtrace' => array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 0, 6 ),
        );
        // detect simple loops: same target already attempted
        foreach ( $utm_debug_redirects as $r ) {
            if ( isset( $r['location'] ) && $r['location'] === $location ) {
                $entry['note'] = 'loop_detected';
                $utm_debug_redirects[] = $entry;
                $GLOBALS['utm_debug_loop'] = true;
                // suppress redirect to break the loop
                return false;
            }
        }
        $utm_debug_redirects[] = $entry;

        // If user clicked Continue, allow the redirect to proceed but preserve debug params
        if ( isset( $continue_flag ) && $continue_flag ) {
            // Try to append debug query params to the redirect location so the debugger
            // remains active and resumes at the same step on the next request.
            // Only modify same-host or relative URLs to avoid leaking params to other domains.
            $url = $location;
            $parsed = parse_url( $url );
            $modify = false;
            if ( empty( $parsed['host'] ) ) {
                // relative URL
                $modify = true;
            } elseif ( isset( $_SERVER['HTTP_HOST'] ) && $parsed['host'] === $_SERVER['HTTP_HOST'] ) {
                $modify = true;
            }
            if ( $modify ) {
                $qs = array();
                if ( isset( $parsed['query'] ) ) {
                    parse_str( $parsed['query'], $qs );
                }
                // Preserve the current step across the redirect so the debugger
                // resumes at the same step on the next request, but do NOT
                // propagate the "continue" flag. This prevents automatic
                // immediate continuation loops.
                unset( $qs['utm_debug_continue'] );
                if ( isset( $current_step ) ) {
                    $qs['utm_debug_step'] = intval( $current_step );
                }
                $new_query = http_build_query( $qs );
                $new_url = ( isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '' )
                    . ( isset( $parsed['host'] ) ? $parsed['host'] : '' )
                    . ( isset( $parsed['path'] ) ? $parsed['path'] : '' )
                    . ( $new_query ? '?' . $new_query : '' )
                    . ( isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '' );
                return $new_url;
            }

            return $location;
        }

        // Otherwise suppress the redirect so we can inspect state.
        return false;
    }
}
add_filter( 'wp_redirect', 'utm_debug_wp_redirect_intercept', 10, 2 );

// Also capture headers at shutdown (in case non-wp_redirect header() calls were used)
register_shutdown_function( function() {
    global $utm_debug_redirects;
    if ( function_exists( 'headers_list' ) ) {
        $utm_debug_redirects[] = array( 'shutdown_headers' => headers_list(), 'shutdown_time' => date( 'c' ) );
    }
} );

// Helper to build a URL that preserves other query args but sets our debug params
function utm_debug_build_url( $step = null, $continue = false, $extra = array() ) {
    $qs = $_GET;
    if ( $step !== null ) {
        $qs['utm_debug_step'] = $step;
    }
    if ( $continue ) {
        $qs['utm_debug_continue'] = '1';
    } else {
        unset( $qs['utm_debug_continue'] );
    }
    foreach ( $extra as $k => $v ) {
        $qs[ $k ] = $v;
    }
    $path = isset( $_SERVER['REQUEST_URI'] ) ? preg_replace( '/\?.*/', '', $_SERVER['REQUEST_URI'] ) : '/';
    $q = http_build_query( $qs );
    return $path . ( $q ? '?' . $q : '' );
}

// Steps: array of labels and render callbacks
$steps = array(
    1 => array(
        'title' => 'Request & Server Info',
        'render' => function() {
            echo '<h3>Request ($_SERVER)</h3>';
            echo '<pre>' . esc_html( print_r( $_SERVER, true ) ) . '</pre>';
            if ( function_exists( 'getallheaders' ) ) {
                echo '<h3>Request Headers</h3>';
                echo '<pre>' . esc_html( print_r( getallheaders(), true ) ) . '</pre>';
            }
        },
    ),
    2 => array(
        'title' => 'Divi-related Options',
        'render' => function() use ( $options_to_debug ) {
            global $wpdb;
            echo '<h3>Options containing ' . esc_html( $options_to_debug ) . '</h3>';
            if ( ! isset( $wpdb ) ) {
                echo '<p>$wpdb not available in this context.</p>';
                return;
            }

            // Build a safe query depending on the type of $options_to_debug.
            if ( empty( $options_to_debug ) ) {
                // default to searching for "divi"
                $like = '%' . $wpdb->esc_like( 'divi' ) . '%';
                $sql = $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like );
                $options = $wpdb->get_results( $sql );
            } elseif ( is_string( $options_to_debug ) ) {
                $like = '%' . $wpdb->esc_like( $options_to_debug ) . '%';
                $sql = $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like );
                $options = $wpdb->get_results( $sql );
            } elseif ( is_array( $options_to_debug ) ) {
                // build multiple LIKE clauses joined by OR
                $likes = array();
                $params = array();
                foreach ( $options_to_debug as $opt ) {
                    $likes[] = "option_name LIKE %s";
                    $params[] = '%' . $wpdb->esc_like( $opt ) . '%';
                }
                $where = implode( ' OR ', $likes );
                $sql = "SELECT option_name, option_value FROM {$wpdb->options} WHERE $where";
                $prepared = $wpdb->prepare( $sql, ...$params );
                $options = $wpdb->get_results( $prepared );
            } else {
                $options = array();
            }

            if ( empty( $options ) ) {
                echo '<p>No options matched.</p>';
                return;
            }
            echo '<pre>';
            foreach ( $options as $option ) {
                $value = maybe_unserialize( $option->option_value );
                if ( is_array( $value ) || is_object( $value ) ) {
                    $value = print_r( $value, true );
                }
                echo esc_html( $option->option_name ) . ' => ' . esc_html( (string) $value ) . "\n";
            }
            echo '</pre>';
        },
    ),
    3 => array(
        'title' => 'Site and Active Components',
        'render' => function() {
            echo '<h3>Site URL / Home</h3>';
            echo '<pre>' . esc_html( get_option( 'siteurl' ) ) . ' / ' . esc_html( get_option( 'home' ) ) . '</pre>';
            echo '<h3>Active Theme</h3>';
            if ( function_exists( 'wp_get_theme' ) ) {
                $t = wp_get_theme();
                echo '<pre>' . esc_html( $t->get( 'Name' ) . ' ' . $t->get( 'Version' ) ) . '</pre>';
            }
            echo '<h3>Active Plugins (subset)</h3>';
            $active = get_option( 'active_plugins', array() );
            echo '<pre>' . esc_html( print_r( array_slice( $active, 0, 30 ), true ) ) . '</pre>';
        },
    ),
    4 => array(
        'title' => 'Redirect Attempts & Headers',
        'render' => function() {
            global $utm_debug_redirects, $wp_scripts;
            
            // Show current URL for easy copy-paste reporting
            $current_url = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : 'N/A';
            $full_url = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://' )
                . ( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost' )
                . $current_url;
            echo '<div style="background:#eef;border:1px solid #009;padding:8px;margin-bottom:12px;">';
            echo '<strong>Current URL:</strong><br/>';
            echo '<code style="font-size:11px;">' . esc_html( $full_url ) . '</code>';
            echo '</div>';
            
            // Show enqueued scripts that might contain redirect logic
            echo '<h3>Enqueued Scripts (potential JS redirect sources)</h3>';
            if ( isset( $wp_scripts ) && ! empty( $wp_scripts->queue ) ) {
                echo '<ul style="font-size:12px;">';
                foreach ( $wp_scripts->queue as $handle ) {
                    if ( isset( $wp_scripts->registered[ $handle ] ) ) {
                        $src = $wp_scripts->registered[ $handle ]->src;
                        // Highlight Divi/ET scripts
                        $highlight = ( stripos( $handle, 'divi' ) !== false || stripos( $handle, 'et_' ) !== false || stripos( $src, 'divi' ) !== false || stripos( $src, 'elegant' ) !== false );
                        $style = $highlight ? 'background:#ffe;font-weight:bold;' : '';
                        echo '<li style="' . $style . '">' . esc_html( $handle ) . ' => ' . esc_html( $src ) . '</li>';
                    }
                }
                echo '</ul>';
            } else {
                echo '<p>No scripts enqueued yet (or wp_scripts not available).</p>';
            }
            
            // Inline localized script data (often used for config/redirects)
            echo '<h3>Inline Script Data (localized)</h3>';
            if ( isset( $wp_scripts ) && ! empty( $wp_scripts->registered ) ) {
                $found = false;
                foreach ( $wp_scripts->registered as $handle => $script ) {
                    if ( ! empty( $script->extra['data'] ) ) {
                        $found = true;
                        $highlight = ( stripos( $handle, 'divi' ) !== false || stripos( $handle, 'et_' ) !== false );
                        $style = $highlight ? 'background:#ffe;font-weight:bold;' : '';
                        echo '<div style="border:1px solid #ddd;padding:6px;margin-bottom:6px;' . $style . '">';
                        echo '<strong>' . esc_html( $handle ) . ':</strong><br/>';
                        echo '<pre style="font-size:10px;max-height:150px;overflow:auto;">' . esc_html( $script->extra['data'] ) . '</pre>';
                        echo '</div>';
                    }
                }
                if ( ! $found ) {
                    echo '<p>No inline script data found.</p>';
                }
            } else {
                echo '<p>wp_scripts not available.</p>';
            }
            
            echo '<h3>Captured wp_redirect calls (PHP-level)</h3>';
                if ( empty( $utm_debug_redirects ) ) {
                    echo '<p style="background:#ffe;border:1px solid #aa0;padding:8px;">No PHP redirect attempts captured. Since you see a redirect loop, it is likely:<br/>
                    <strong>1. JavaScript-based redirect</strong> - Check the "Enqueued Scripts" and "Inline Script Data" above for Divi/ET scripts.<br/>
                    <strong>2. Client-side meta refresh or window.location</strong> - Inspect page source for &lt;meta http-equiv="refresh"&gt; or inline &lt;script&gt; tags.<br/>
                    <strong>3. Server-level redirect</strong> - Check nginx.conf or .htaccess for rewrite rules matching "et_onboarding".<br/><br/>
                    <strong>Recommended action:</strong> Open browser DevTools (F12) &rarr; Network tab, then reload this page. Look for a 3xx redirect response or JS file execution that triggers window.location changes.</p>';
                } else {
                    // Show each captured redirect with a short backtrace summary
                    foreach ( $utm_debug_redirects as $i => $r ) {
                        echo '<div style="border:1px solid #ddd;padding:8px;margin-bottom:8px;">';
                        echo '<strong>Attempt ' . intval( $i + 1 ) . ':</strong> ' . esc_html( isset( $r['location'] ) ? $r['location'] : 'N/A' );
                        echo ' <em>(' . esc_html( isset( $r['status'] ) ? $r['status'] : 'N/A' ) . ')</em>';
                        if ( isset( $r['note'] ) && $r['note'] === 'loop_detected' ) {
                            echo ' <span style="color:#900;font-weight:bold">LOOP DETECTED</span>';
                        }
                        echo '<br/>';
                        if ( isset( $r['backtrace'] ) && is_array( $r['backtrace'] ) ) {
                            echo '<small>Backtrace (top):</small>';
                            echo '<pre style="font-size:11px;margin:6px 0;">';
                            $bt = $r['backtrace'];
                            foreach ( $bt as $frame ) {
                                $file = isset( $frame['file'] ) ? $frame['file'] : '(unknown)';
                                $line = isset( $frame['line'] ) ? $frame['line'] : 0;
                                $fn = isset( $frame['function'] ) ? $frame['function'] : '(closure)';
                                echo esc_html( "$file:$line => $fn\n" );
                            }
                            echo '</pre>';
                        }
                        echo '</div>';
                    }
                }
            if ( function_exists( 'headers_list' ) ) {
                echo '<h3>Current headers_list()</h3>';
                $headers = headers_list();
                if ( empty( $headers ) ) {
                    echo '<p style="background:#ffe;padding:6px;">No headers set yet (or headers already sent).</p>';
                } else {
                    echo '<pre>' . esc_html( print_r( $headers, true ) ) . '</pre>';
                }
            }
                global $utm_debug_loop;
                if ( isset( $utm_debug_loop ) && $utm_debug_loop ) {
                    echo '<div style="background:#fee;border:1px solid #900;padding:8px;margin-top:8px;font-weight:bold;color:#900;">Redirect loop detected - the debugger suppressed a redirect to break the loop. Inspect the backtrace above to find the source.</div>';
                }
                
            // Add diagnosis helper
            echo '<div style="background:#f9f9f9;border:1px solid #ccc;padding:8px;margin-top:12px;font-size:12px;">';
            echo '<strong>Diagnosis Helper:</strong><br/>';
            echo '• If you see "No redirect attempts" above, the redirect may be happening via:<br/>';
            echo '&nbsp;&nbsp;- JavaScript (check browser DevTools Network tab for 3xx responses or JS redirects)<br/>';
            echo '&nbsp;&nbsp;- Server rules (.htaccess, nginx.conf)<br/>';
            echo '&nbsp;&nbsp;- Direct header("Location:...") calls (not using wp_redirect)<br/>';
            echo '• Click Continue to let the request proceed and observe browser behavior.<br/>';
            echo '• Check browser Network tab for redirects that happen after Continue.<br/>';
            echo '</div>';
        },
    ),
    5 => array(
        'title' => 'End / Continue Execution',
        'render' => function() {
            echo '<p>This is the final debugging step. Choose Continue to let the request proceed normally, or Next to loop here again.</p>';

            // Attempt to clear PHP opcode cache if available so updated PHP
            // code is used during debugging. Show result visibly.
            $opcache_note = '';
            if ( function_exists( 'opcache_reset' ) ) {
                try {
                    $ok = @opcache_reset();
                    if ( $ok ) {
                        $opcache_note = '<div style="background:#efe;border:1px solid #0a0;padding:8px;margin-top:8px;">OPcache reset successfully.</div>';
                    } else {
                        $opcache_note = '<div style="background:#fee;border:1px solid #900;padding:8px;margin-top:8px;">OPcache reset attempted but returned false.</div>';
                    }
                } catch ( Throwable $t ) {
                    $opcache_note = '<div style="background:#fee;border:1px solid #900;padding:8px;margin-top:8px;">OPcache reset error: ' . esc_html( $t->getMessage() ) . '</div>';
                }
            } else {
                $opcache_note = '<div style="background:#ffd;border:1px solid #aa0;padding:8px;margin-top:8px;">OPcache functions not available on this PHP build.</div>';
            }

            echo $opcache_note;
            echo '<div style="margin-top:8px;color:#666;font-size:12px;">Debugger step: ' . intval( isset( $GLOBALS['current_step'] ) ? $GLOBALS['current_step'] : 5 ) . '</div>';
        },
    ),
);

// Clamp current step
if ( $current_step < 1 ) {
    $current_step = 1;
}
if ( $current_step > count( $steps ) ) {
    $current_step = count( $steps );
}

// Render the step UI immediately to the screen
echo '<div style="position:fixed;left:10px;top:10px;right:10px;bottom:10px;overflow:auto;background:#fff;border:3px solid #900;padding:12px;z-index:999999;">';
echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">';
echo '<strong style="font-size:16px">UTM Manual Debugger</strong>';
echo '<div>'; 
echo '<a href="' . esc_html( utm_debug_build_url( $current_step, false, array( 'utm_debug_reset' => '1' ) ) ) . '" style="margin-right:8px;color:#900;">Reset debug (clear cookie)</a>';
echo '<a href="' . esc_html( utm_debug_build_url( 1, false ) ) . '" style="margin-right:8px;">Restart</a>';
echo '</div>';
echo '</div>';

echo '<h2>Step ' . intval( $current_step ) . ': ' . esc_html( $steps[ $current_step ]['title'] ) . '</h2>';
// Render content
call_user_func( $steps[ $current_step ]['render'] );

// Controls
$next_step = $current_step + 1;
if ( $next_step > count( $steps ) ) {
    $next_step = count( $steps );
}
echo '<div style="margin-top:12px;">';
echo '<a href="' . esc_html( utm_debug_build_url( $next_step, false ) ) . '" style="padding:6px 10px;border:1px solid #ccc;margin-right:8px;">Next (pause)</a>';
echo '<a href="' . esc_html( utm_debug_build_url( $current_step, true ) ) . '" style="padding:6px 10px;border:1px solid #4a4;margin-right:8px;">Continue (let request proceed)</a>';
echo '<a href="' . esc_html( utm_debug_build_url( $current_step, false, array( 'utm_debug_pause' => time() ) ) ) . '" style="padding:6px 10px;border:1px solid #999;margin-right:8px;">Pause (stay)</a>';
echo '</div>';

echo '<div style="margin-top:10px;font-size:12px;color:#666;">Tip: Use the browser to navigate steps; each step will pause execution unless you click Continue.</div>';
echo '</div>';

// If not explicitly continuing, stop further processing so we capture state before redirects
if ( ! $continue_flag ) {
    // Prevent further output/redirects
    exit;
}

// Note: Do NOT automatically clear the debug cookie on Continue. The user must
// explicitly Reset debug (clear cookie) using the Reset link; this prevents
// unintended restarts or infinite loops during multi-step debugging.
