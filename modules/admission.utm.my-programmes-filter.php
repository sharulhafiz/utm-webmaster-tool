<?php
/**
 * Admission Programmes Filter Shortcode (basic scaffold)
 *
 * Shortcode: [utm_admission_programme_filter]
 *
 * Runs only on admission.utm.my/new2024.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Restrict this module to admission.utm.my/new2024.
 *
 * @return bool
 */
function utm_admission_is_allowed_context() {
    $home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
    $home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

    $host = '';
    if ( isset( $_SERVER['HTTP_HOST'] ) ) {
        $host = strtolower( (string) $_SERVER['HTTP_HOST'] );
    } elseif ( is_string( $home_host ) ) {
        $host = strtolower( $home_host );
    }

    if ( 'admission.utm.my' !== $host ) {
        return false;
    }

    $normalized_path = '/' . trim( (string) $home_path, '/' ) . '/';
    return 0 === strpos( $normalized_path, '/new2024/' );
}

if ( ! utm_admission_is_allowed_context() ) {
    return;
}

/**
 * Enable scoped debug mode with query param: ?utm_adm_debug=1
 *
 * @return bool
 */
function utm_admission_debug_enabled() {
    return isset( $_GET['utm_adm_debug'] ) && '1' === (string) $_GET['utm_adm_debug'];
}

if ( utm_admission_debug_enabled() ) {
    @ini_set( 'display_errors', '1' );
    @ini_set( 'display_startup_errors', '1' );
    error_reporting( E_ALL );

    register_shutdown_function(
        function() {
            $error = error_get_last();
            if ( empty( $error ) ) {
                return;
            }

            $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
            if ( ! in_array( (int) $error['type'], $fatal_types, true ) ) {
                return;
            }

            echo '<pre style="background:#fff1f1;color:#900;border:1px solid #e5bcbc;padding:12px;margin:12px 0;white-space:pre-wrap">';
            echo 'UTM Admission Module Fatal Error\n';
            echo 'Message: ' . esc_html( (string) $error['message'] ) . "\n";
            echo 'File: ' . esc_html( (string) $error['file'] ) . "\n";
            echo 'Line: ' . esc_html( (string) $error['line'] ) . "\n";
            echo '</pre>';
        }
    );
}

/**
 * Get distinct meta values for a key on a post type.
 *
 * @param string $post_type Post type.
 * @param string $meta_key  Meta key.
 * @return array
 */
function utm_admission_get_distinct_meta_values( $post_type, $meta_key ) {
    global $wpdb;

    $cache_key = 'utm_adm_meta_opts_' . md5( get_current_blog_id() . '|' . $post_type . '|' . $meta_key );
    $cached    = get_transient( $cache_key );

    if ( false !== $cached && is_array( $cached ) ) {
        return $cached;
    }

    $sql = $wpdb->prepare(
        "SELECT DISTINCT pm.meta_value
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE p.post_type = %s
           AND p.post_status = 'publish'
           AND pm.meta_key = %s
           AND pm.meta_value <> ''
         ORDER BY pm.meta_value ASC",
        $post_type,
        $meta_key
    );

    $rows = $wpdb->get_col( $sql );
    if ( ! is_array( $rows ) ) {
        $rows = array();
    }

    $values = array_values( array_filter( array_map( 'trim', $rows ) ) );
    set_transient( $cache_key, $values, 15 * MINUTE_IN_SECONDS );

    return $values;
}

/**
 * Build filter configuration map from shortcode attributes.
 *
 * @param array $atts Shortcode attributes.
 * @return array
 */
function utm_admission_get_filter_map( $atts ) {
    return array(
        'level' => array(
            'type'     => 'taxonomy',
            'title'    => 'Level',
            'icon'     => 'dashicons-welcome-learn-more',
            'taxonomy' => $atts['level_taxonomy'],
            'arg'      => 'uapf_level',
        ),
        'offered_to' => array(
            'type'  => 'meta',
            'title' => 'Offered To',
            'icon'  => 'dashicons-admin-users',
            'key'   => $atts['offered_to_key'],
            'arg'   => 'uapf_offered_to',
        ),
        'study_scheme' => array(
            'type'  => 'meta',
            'title' => 'Study Scheme',
            'icon'  => 'dashicons-welcome-write-blog',
            'key'   => $atts['study_scheme_key'],
            'arg'   => 'uapf_study_scheme',
        ),
        'study_mode' => array(
            'type'  => 'meta',
            'title' => 'Study Mode',
            'icon'  => 'dashicons-controls-repeat',
            'key'   => $atts['study_mode_key'],
            'arg'   => 'uapf_study_mode',
        ),
        'delivery_mode' => array(
            'type'  => 'meta',
            'title' => 'Delivery Mode',
            'icon'  => 'dashicons-admin-site-alt3',
            'key'   => $atts['delivery_mode_key'],
            'arg'   => 'uapf_delivery_mode',
        ),
        'study_location' => array(
            'type'  => 'meta',
            'title' => 'Study Location',
            'icon'  => 'dashicons-location-alt',
            'key'   => $atts['study_location_key'],
            'arg'   => 'uapf_study_location',
        ),
        'faculty' => array(
            'type'  => 'meta',
            'title' => 'Faculty',
            'icon'  => 'dashicons-building',
            'key'   => $atts['faculty_key'],
            'arg'   => 'uapf_faculty',
        ),
    );
}

/**
 * Resolve a URL value (slug/text) to an exact filter option value.
 *
 * Supports friendly URLs such as ?level=undergraduate and maps them
 * back to the exact stored label used by the form/query.
 *
 * @param string $value  Raw URL value.
 * @param array  $config Filter config.
 * @param array  $atts   Shortcode attributes.
 * @return string
 */
function utm_admission_resolve_filter_value( $value, $config, $atts ) {
    $value = trim( (string) $value );
    if ( '' === $value ) {
        return '';
    }

    // Exact value still wins.
    if ( 'taxonomy' === $config['type'] ) {
        $terms = get_terms(
            array(
                'taxonomy'   => $config['taxonomy'],
                'hide_empty' => true,
            )
        );

        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                if ( $value === (string) $term->name || $value === (string) $term->slug ) {
                    return (string) $term->name;
                }
                if ( sanitize_title( $value ) === sanitize_title( (string) $term->name ) ) {
                    return (string) $term->name;
                }
            }
        }

        return $value;
    }

    $options = utm_admission_get_distinct_meta_values( $atts['post_type'], $config['key'] );
    foreach ( $options as $option ) {
        $option = (string) $option;
        if ( '' === $option ) {
            continue;
        }
        if ( $value === $option || sanitize_title( $value ) === sanitize_title( $option ) ) {
            return $option;
        }
    }

    return $value;
}

/**
 * Read selected filter values from GET or POST.
 *
 * @param array  $filters Filter map.
 * @param string $source  get|post.
 * @param array  $atts    Shortcode attributes.
 * @return array
 */
function utm_admission_get_selected_filters( $filters, $source = 'get', $atts = array() ) {
    $selected = array();

    foreach ( $filters as $key => $config ) {
        $value = '';
        if ( 'post' === $source ) {
            $value = isset( $_POST[ $config['arg'] ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $config['arg'] ] ) ) : '';
        } else {
            $value = isset( $_GET[ $config['arg'] ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $config['arg'] ] ) ) : '';

            // Support clean aliases, e.g. ?level=undergraduate.
            if ( '' === $value ) {
                $aliases = array_unique(
                    array_filter(
                        array(
                            $key,
                            preg_replace( '/^uapf_/', '', (string) $config['arg'] ),
                        )
                    )
                );

                foreach ( $aliases as $alias ) {
                    if ( isset( $_GET[ $alias ] ) ) {
                        $value = sanitize_text_field( wp_unslash( (string) $_GET[ $alias ] ) );
                        break;
                    }
                }
            }

            // Normalize alias/slugs to exact option values for checked/query matching.
            if ( '' !== $value && ! empty( $atts ) ) {
                $value = utm_admission_resolve_filter_value( $value, $config, $atts );
            }
        }
        $selected[ $key ] = $value;
    }

    return $selected;
}

/**
 * Get selected result page from request.
 *
 * @param string $source get|post.
 * @return int
 */
function utm_admission_get_selected_page( $source = 'get' ) {
    if ( 'post' === $source ) {
        $page = isset( $_POST['uapf_page'] ) ? absint( wp_unslash( (string) $_POST['uapf_page'] ) ) : 1;
    } else {
        $page = isset( $_GET['uapf_page'] ) ? absint( wp_unslash( (string) $_GET['uapf_page'] ) ) : 1;
    }

    return max( 1, $page );
}

/**
 * Get keyword search term from request.
 *
 * @param string $source get|post.
 * @return string
 */
function utm_admission_get_search_term( $source = 'get' ) {
    if ( 'post' === $source ) {
        return isset( $_POST['uapf_q'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['uapf_q'] ) ) : '';
    }

    return isset( $_GET['uapf_q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['uapf_q'] ) ) : '';
}

/**
 * Get sort mode from request.
 *
 * @param string $source get|post.
 * @return string
 */
function utm_admission_get_sort_mode( $source = 'get' ) {
    $value = '';
    if ( 'post' === $source ) {
        $value = isset( $_POST['uapf_sort'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['uapf_sort'] ) ) : 'alpha';
    } else {
        $value = isset( $_GET['uapf_sort'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['uapf_sort'] ) ) : 'alpha';
    }

    return in_array( $value, array( 'alpha', 'latest' ), true ) ? $value : 'alpha';
}

/**
 * Render one radio-group section.
 *
 * @param string $title    Section title.
 * @param string $name     Input name.
 * @param array  $options  Option values.
 * @param string $selected Selected value.
 * @return string
 */
function utm_admission_render_radio_group( $title, $name, $options, $selected, $icon = 'dashicons-filter' ) {
    $html  = '<div class="utm-adm-filter-group">';
    $html .= '<h4><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span> ' . esc_html( $title ) . '</h4>';

    $html .= '<label><input type="radio" name="' . esc_attr( $name ) . '" value="" ' . checked( '', $selected, false ) . '> All</label>';

    foreach ( $options as $option ) {
        $value = (string) $option;
        if ( '' === $value ) {
            continue;
        }
        $html .= '<label><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . checked( $value, $selected, false ) . '> ' . esc_html( $value ) . '</label>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * Build WP_Query args for selected filters.
 *
 * @param array $atts     Shortcode attributes.
 * @param array $filters  Filter map.
 * @param array $selected Selected values.
 * @return array
 */
function utm_admission_build_query_args( $atts, $filters, $selected, $page = 1, $search_term = '' ) {
    $query_args = array(
        'post_type'      => $atts['post_type'],
        'post_status'    => 'publish',
        'posts_per_page' => (int) $atts['posts_per_page'],
        'paged'          => max( 1, (int) $page ),
        'orderby'        => 'title',
        'order'          => 'ASC',
    );

    if ( '' !== trim( $search_term ) ) {
        $query_args['s'] = $search_term;
    }

    $tax_query  = array();
    $meta_query = array( 'relation' => 'AND' );

    if ( '' !== $selected['level'] ) {
        $tax_query[] = array(
            'taxonomy' => $atts['level_taxonomy'],
            'field'    => 'name',
            'terms'    => $selected['level'],
        );
    }

    foreach ( $filters as $key => $config ) {
        if ( 'meta' !== $config['type'] || '' === $selected[ $key ] ) {
            continue;
        }

        $meta_query[] = array(
            'key'     => $config['key'],
            'value'   => $selected[ $key ],
            'compare' => '=',
        );
    }

    if ( ! empty( $tax_query ) ) {
        $query_args['tax_query'] = $tax_query;
    }
    if ( count( $meta_query ) > 1 ) {
        $query_args['meta_query'] = $meta_query;
    }

    return $query_args;
}

/**
 * Render one programme card.
 *
 * @param int   $post_id Post ID.
 * @param array $atts    Shortcode attributes.
 * @param array $filters Filter map.
 * @return string
 */
function utm_admission_render_programme_card( $post_id, $atts, $filters ) {
    $title   = get_the_title( $post_id );
    $link    = get_permalink( $post_id );
    $excerpt = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $post_id ) ), 28 );

    if ( '' === trim( $excerpt ) ) {
        $excerpt = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 28 );
    }

    // Level taxonomy — shown as a colored pill badge.
    $level_terms = get_the_terms( $post_id, $atts['level_taxonomy'] );
    $level_name  = '';
    if ( ! is_wp_error( $level_terms ) && ! empty( $level_terms ) ) {
        $level_name = $level_terms[0]->name;
    }
    $level_slug = preg_replace( '/[^a-z0-9]+/', '-', strtolower( $level_name ) );

    // Meta fields — each rendered with its icon.
    $meta_items = array();
    foreach ( $filters as $key => $config ) {
        if ( 'meta' !== $config['type'] ) {
            continue;
        }
        $value = get_post_meta( $post_id, $config['key'], true );
        if ( is_scalar( $value ) ) {
            $value = trim( (string) $value );
            if ( '' !== $value ) {
                $meta_items[ $key ] = array(
                    'icon'  => $config['icon'],
                    'title' => $config['title'],
                    'value' => $value,
                );
            }
        }
    }

    $html = '<article class="utm-adm-programme-card">';

    // Header: title + level badge.
    $html .= '<div class="utm-adm-card-header">';
    $html .= '<h5><a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a></h5>';
    if ( '' !== $level_name ) {
        $html .= '<span class="utm-adm-card-level utm-adm-level-' . esc_attr( $level_slug ) . '">' . esc_html( $level_name ) . '</span>';
    }
    $html .= '</div>';

    // Meta info row with icons.
    if ( ! empty( $meta_items ) ) {
        $html .= '<div class="utm-adm-card-meta">';
        foreach ( $meta_items as $key => $item ) {
            $item_class = ( 'faculty' === $key ) ? 'utm-adm-meta-item utm-adm-meta-full' : 'utm-adm-meta-item';
            $html      .= '<span class="' . esc_attr( $item_class ) . '">';
            $html      .= '<span class="dashicons ' . esc_attr( $item['icon'] ) . '" aria-hidden="true"></span>';
            $html      .= '<span>' . esc_html( $item['value'] ) . '</span>';
            $html      .= '</span>';
        }
        $html .= '</div>';
    }

    // Short description.
    if ( '' !== $excerpt ) {
        $html .= '<p class="utm-adm-card-excerpt">' . esc_html( $excerpt ) . '</p>';
    }

    // Footer CTA.
    $html .= '<div class="utm-adm-card-footer">';
    $html .= '<a href="' . esc_url( $link ) . '" class="utm-adm-card-cta">View Programme &#8594;</a>';
    $html .= '</div>';

    $html .= '</article>';

    return $html;
}

/**
 * Render programme results HTML.
 *
 * @param array $atts     Shortcode attributes.
 * @param array $filters  Filter map.
 * @param array $selected Selected values.
 * @return string
 */
function utm_admission_render_programme_results_html( $atts, $filters, $selected, $page = 1, $search_term = '' ) {
    $query_args       = utm_admission_build_query_args( $atts, $filters, $selected, $page, $search_term );
    $programmes_query = new WP_Query( $query_args );

    $active_filters = 0;
    foreach ( $selected as $value ) {
        if ( '' !== (string) $value ) {
            $active_filters++;
        }
    }
    if ( '' !== trim( $search_term ) ) {
        $active_filters++;
    }

    $html  = '<div class="utm-adm-filter-results">';
    $html .= '<h4>Programmes Found: ' . esc_html( (string) $programmes_query->found_posts ) . '</h4>';
    $html .= '<p class="utm-adm-refined-by">Results refined by <strong>' . esc_html( (string) $active_filters ) . '</strong> filter(s)</p>';

    if ( $programmes_query->have_posts() ) {
        $html .= '<div class="utm-adm-programme-cards">';
        while ( $programmes_query->have_posts() ) {
            $programmes_query->the_post();
            $html .= utm_admission_render_programme_card( get_the_ID(), $atts, $filters );
        }
        $html .= '</div>';
        wp_reset_postdata();
    } else {
        $html .= '<p>No programmes matched your filter.</p>';
    }

    if ( $programmes_query->max_num_pages > 1 ) {
        $base_url = strtok( (string) $_SERVER['REQUEST_URI'], '?' );
        $html    .= '<nav class="utm-adm-pagination" aria-label="Programmes pagination">';

        for ( $i = 1; $i <= (int) $programmes_query->max_num_pages; $i++ ) {
            $query_data = array( 'uapf_page' => $i );
            foreach ( $filters as $k => $config ) {
                if ( '' !== $selected[ $k ] ) {
                    $query_data[ $config['arg'] ] = $selected[ $k ];
                }
            }
            if ( '' !== $search_term ) {
                $query_data['uapf_q'] = $search_term;
            }

            $url       = add_query_arg( $query_data, $base_url );
            $is_active = ( $i === (int) $page );
            $class     = $is_active ? 'utm-adm-page-link is-active' : 'utm-adm-page-link';

            $html .= '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '" data-page="' . esc_attr( (string) $i ) . '">' . esc_html( (string) $i ) . '</a>';
        }

        $html .= '</nav>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * AJAX callback for live filter updates.
 *
 * @return void
 */
function utm_admission_ajax_filter_programmes() {
    if ( ! utm_admission_is_allowed_context() ) {
        wp_send_json_error( array( 'message' => 'Invalid context.' ), 403 );
    }

    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'utm_adm_filter_nonce' ) ) {
        wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
    }

    $raw_atts = isset( $_POST['uapf_atts'] ) ? wp_unslash( (string) $_POST['uapf_atts'] ) : '';
    $atts     = json_decode( $raw_atts, true );

    if ( ! is_array( $atts ) ) {
        $atts = array();
    }

    $atts = shortcode_atts(
        array(
            'post_type'          => 'programmes',
            'posts_per_page'     => 20,
            'show_results'       => 'yes',
            'level_taxonomy'     => 'level',
            'offered_to_key'     => 'offered_to',
            'study_scheme_key'   => 'study_scheme',
            'study_mode_key'     => 'study_mode',
            'delivery_mode_key'  => 'delivery_mode',
            'study_location_key' => 'study_location',
            'faculty_key'        => 'faculty',
        ),
        $atts,
        'utm_admission_programme_filter'
    );

    $filters  = utm_admission_get_filter_map( $atts );
    $selected = utm_admission_get_selected_filters( $filters, 'post', $atts );
    $page     = utm_admission_get_selected_page( 'post' );
    $search   = utm_admission_get_search_term( 'post' );

    $html = utm_admission_render_programme_results_html( $atts, $filters, $selected, $page, $search );

    wp_send_json_success(
        array(
            'html' => $html,
        )
    );
}
add_action( 'wp_ajax_utm_admission_filter_programmes', 'utm_admission_ajax_filter_programmes' );
add_action( 'wp_ajax_nopriv_utm_admission_filter_programmes', 'utm_admission_ajax_filter_programmes' );

/**
 * Main shortcode.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function utm_admission_programme_filter_shortcode( $atts ) {
    wp_enqueue_style( 'dashicons' );

    $atts = shortcode_atts(
        array(
            'post_type'          => 'programmes',
            'posts_per_page'     => 20,
            'show_results'       => 'yes',
            'level_taxonomy'     => 'level',
            'offered_to_key'     => 'offered_to',
            'study_scheme_key'   => 'study_scheme',
            'study_mode_key'     => 'study_mode',
            'delivery_mode_key'  => 'delivery_mode',
            'study_location_key' => 'study_location',
            'faculty_key'        => 'faculty',
        ),
        $atts,
        'utm_admission_programme_filter'
    );

    $filters  = utm_admission_get_filter_map( $atts );
    $selected = utm_admission_get_selected_filters( $filters, 'get', $atts );
    $page     = utm_admission_get_selected_page( 'get' );
    $search   = utm_admission_get_search_term( 'get' );

    $instance_id = 'utm-adm-filter-' . wp_rand( 1000, 999999 );
    $nonce       = wp_create_nonce( 'utm_adm_filter_nonce' );

    $wrap_class = 'utm-adm-programme-filter-wrap';
    if ( 'yes' !== strtolower( (string) $atts['show_results'] ) ) {
        $wrap_class .= ' utm-adm-filter-only';
    }

    $output  = '<div id="' . esc_attr( $instance_id ) . '" class="' . esc_attr( $wrap_class ) . '" data-ajax-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( $nonce ) . '">';
    $output .= '<style>
    .utm-adm-programme-filter-wrap{display:block}
    .utm-adm-programme-filter-wrap-inner{display:grid;grid-template-columns:minmax(260px,320px) minmax(0,1fr);gap:20px;align-items:start}
    .utm-adm-programme-filter-wrap.utm-adm-filter-only .utm-adm-programme-filter-wrap-inner{grid-template-columns:1fr}
    .utm-adm-top-search{background:#dbeaf3;border-radius:18px;padding:18px 20px;margin:0 0 18px}
    .utm-adm-top-search .utm-adm-top-search-input{display:flex;align-items:center;background:#fff;border:1px solid #cbd8e3;border-radius:999px;padding:6px 8px 6px 14px}
    .utm-adm-top-search .utm-adm-top-search-input input[type=text]{width:100%;border:none;outline:none;font-size:18px;background:transparent}
    .utm-adm-top-search .utm-adm-top-search-input .dashicons{width:40px;height:40px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#20262d;color:#fff}
    .utm-adm-programme-filter{background:#f7f8fb;padding:16px;border:1px solid #e2e5ec;border-radius:12px;box-shadow:0 8px 24px rgba(18,38,63,.06);position:sticky;top:16px}
    .utm-adm-programme-filter h3{margin:-16px -16px 14px;padding:12px 16px;background:linear-gradient(135deg,#8e0028,#6d001f);color:#fff;font-size:22px;font-weight:700}
    .utm-adm-filter-group{margin:0 0 18px;padding-bottom:10px;border-bottom:1px dashed #ddd}
    .utm-adm-filter-group:last-of-type{border-bottom:none}
    .utm-adm-filter-group h4{margin:0 0 8px;font-size:18px;color:#4b4b4b;font-weight:700}
    .utm-adm-filter-group h4 .dashicons{font-size:18px;line-height:1.2;color:#8e0028;vertical-align:text-bottom}
    .utm-adm-filter-group label{display:flex;align-items:flex-start;gap:6px;margin:5px 0;font-size:15px;color:#8e0028;transition:color .2s ease;cursor:pointer}
    .utm-adm-filter-group label:hover{color:#5f001b}
    .utm-adm-filter-group label:has(input:checked){color:#4b0016;font-weight:700}
    .utm-adm-filter-group input[type=radio]{flex-shrink:0;margin-top:3px;transform:scale(1.05)}
    .utm-adm-filter-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .utm-adm-filter-actions button{background:#8e0028;border:1px solid #8e0028;color:#fff;padding:7px 12px;cursor:pointer;border-radius:8px;font-weight:600}
    .utm-adm-filter-actions button:hover{background:#6d001f;border-color:#6d001f}
    .utm-adm-filter-actions a{display:inline-block;padding:7px 12px;border:1px solid #bbb;background:#fff;color:#333;text-decoration:none;border-radius:8px}
    .utm-adm-filter-actions a:hover{border-color:#8e0028;color:#8e0028}
    .utm-adm-results-container{min-width:0}
    .utm-adm-filter-results{margin-top:0}
    .utm-adm-filter-results h4{display:flex;align-items:center;gap:8px;margin:0 0 12px}
    .utm-adm-filter-results h4:before{content:"\f109";font-family:dashicons;color:#8e0028}
    .utm-adm-refined-by{margin:-4px 0 14px;color:#5c6470;font-size:13px}
    .utm-adm-programme-cards{display:grid;grid-template-columns:1fr;gap:12px}
    .utm-adm-programme-card{border:1px solid #e2e8f0;border-left:4px solid #8e0028;background:#fff;padding:16px 18px;border-radius:0 12px 12px 0;box-shadow:0 2px 8px rgba(15,23,42,.05);transition:box-shadow .2s,transform .2s}
    .utm-adm-programme-card:hover{box-shadow:0 8px 24px rgba(142,0,40,.12);transform:translateY(-2px)}
    .utm-adm-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
    .utm-adm-card-header h5{margin:0;font-size:17px;font-weight:700;line-height:1.35;flex:1}
    .utm-adm-card-header h5 a{color:#1a2030;text-decoration:none}
    .utm-adm-card-header h5 a:hover{color:#8e0028;text-decoration:underline}
    .utm-adm-card-level{flex-shrink:0;display:inline-block;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:4px 10px;border-radius:999px;border:1px solid;line-height:1.5;white-space:nowrap;background:#f1f5f9;color:#475569;border-color:#cbd5e1}
    .utm-adm-level-undergraduate{background:#fde8ed;color:#8e0028;border-color:#f5bfcb}
    .utm-adm-level-postgraduate-coursework{background:#eef2ff;color:#3730a3;border-color:#c7d2fe}
    .utm-adm-level-postgraduate-research{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}
    .utm-adm-card-meta{display:flex;flex-wrap:wrap;gap:5px 18px;margin:0 0 10px}
    .utm-adm-meta-item{display:inline-flex;align-items:center;gap:5px;font-size:13px;color:#4b5563}
    .utm-adm-meta-item .dashicons{font-size:13px;width:13px;height:13px;color:#8e0028;flex-shrink:0;line-height:1}
    .utm-adm-meta-full{flex-basis:100%}
    .utm-adm-card-excerpt{margin:0 0 12px;color:#4a5568;font-size:13.5px;line-height:1.6}
    .utm-adm-card-footer{display:flex;justify-content:flex-end;border-top:1px solid #f0f4f8;padding-top:10px;margin-top:6px}
    .utm-adm-card-cta{display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:#8e0028;text-decoration:none;border:1.5px solid #8e0028;padding:5px 14px;border-radius:999px;transition:background .18s,color .18s}
    .utm-adm-card-cta:hover{background:#8e0028;color:#fff}
    .utm-adm-pagination{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
    .utm-adm-page-link{display:inline-block;min-width:32px;text-align:center;padding:6px 8px;border:1px solid #ccc;background:#fff;color:#333;text-decoration:none}
    .utm-adm-page-link.is-active{background:#8e0028;border-color:#8e0028;color:#fff}
    .utm-adm-loading{opacity:.6;pointer-events:none}
    @media (max-width: 980px){
        .utm-adm-programme-filter-wrap-inner{grid-template-columns:1fr}
        .utm-adm-programme-filter{position:static}
    }
    </style>';

    $output .= '<form class="utm-adm-programme-filter-form" method="get">';
    $output .= '<div class="utm-adm-top-search">';
    $output .= '<div class="utm-adm-top-search-input">';
    $output .= '<input type="text" name="uapf_q" value="' . esc_attr( $search ) . '" placeholder="Search for a course" aria-label="Search programmes">';
    $output .= '<span class="dashicons dashicons-search" aria-hidden="true"></span>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '<div class="utm-adm-programme-filter-wrap-inner">';
    $output .= '<aside class="utm-adm-programme-filter">';
    $output .= '<h3>Filter by:</h3>';

    foreach ( $filters as $key => $config ) {
        $options = array();

        if ( 'taxonomy' === $config['type'] ) {
            $terms = get_terms(
                array(
                    'taxonomy'   => $config['taxonomy'],
                    'hide_empty' => true,
                )
            );
            if ( ! is_wp_error( $terms ) ) {
                $options = wp_list_pluck( $terms, 'name' );
            }
        } else {
            $options = utm_admission_get_distinct_meta_values( $atts['post_type'], $config['key'] );
        }

        $output .= utm_admission_render_radio_group( $config['title'], $config['arg'], $options, $selected[ $key ], $config['icon'] );
    }

    $output .= '<div class="utm-adm-filter-actions">';
    $output .= '<button type="submit">Apply Filter</button>';
    $output .= '<a href="' . esc_url( strtok( (string) $_SERVER['REQUEST_URI'], '?' ) ) . '">Reset</a>';
    $output .= '</div>';
    $output .= '<input type="hidden" name="uapf_page" value="' . esc_attr( (string) $page ) . '">';
    $output .= '<input type="hidden" class="uapf-atts" value="' . esc_attr( wp_json_encode( $atts ) ) . '">';
    $output .= '</aside>';

    if ( 'yes' === strtolower( (string) $atts['show_results'] ) ) {
        $output .= '<div class="utm-adm-results-container">';
        $output .= utm_admission_render_programme_results_html( $atts, $filters, $selected, $page, $search );
        $output .= '</div>';

        $output .= '<script>
        (function(){
            var wrap = document.getElementById(' . wp_json_encode( $instance_id ) . ');
            if(!wrap){ return; }
            var form = wrap.querySelector(".utm-adm-programme-filter-form");
            var sidebar = wrap.querySelector(".utm-adm-programme-filter");
            var results = wrap.querySelector(".utm-adm-results-container");
            if(!form || !results){ return; }
            var pageInput = form.querySelector("input[name=\"uapf_page\"]");
            var searchInput = form.querySelector("input[name=\"uapf_q\"]");
            var timer = null;
            var runAjax = function(){
                wrap.classList.add("utm-adm-loading");
                var fd = new FormData(form);
                fd.append("action", "utm_admission_filter_programmes");
                fd.append("nonce", wrap.getAttribute("data-nonce") || "");
                var attsInput = form.querySelector(".uapf-atts");
                if(attsInput && attsInput.value){
                    fd.append("uapf_atts", attsInput.value);
                }

                fetch(wrap.getAttribute("data-ajax-url"), {
                    method: "POST",
                    credentials: "same-origin",
                    body: fd
                })
                .then(function(r){ return r.json(); })
                .then(function(json){
                    if(json && json.success && json.data && json.data.html){
                        results.innerHTML = json.data.html;
                    }
                })
                .catch(function(){})
                .finally(function(){
                    wrap.classList.remove("utm-adm-loading");
                });
            };

            form.addEventListener("change", function(e){
                if(!e.target){ return; }
                if(e.target.type !== "radio"){ return; }
                if(pageInput){ pageInput.value = "1"; }
                if(timer){ window.clearTimeout(timer); }
                timer = window.setTimeout(runAjax, 120);
            });

            if(searchInput){
                searchInput.addEventListener("input", function(){
                    if(pageInput){ pageInput.value = "1"; }
                    if(timer){ window.clearTimeout(timer); }
                    timer = window.setTimeout(runAjax, 220);
                });
            }

            results.addEventListener("click", function(e){
                var link = e.target && e.target.closest ? e.target.closest(".utm-adm-page-link") : null;
                if(!link){ return; }
                e.preventDefault();
                if(pageInput){
                    pageInput.value = link.getAttribute("data-page") || "1";
                }
                runAjax();
            });
        })();
        </script>';
    }

    $output .= '</div>';
    $output .= '</form>';
    $output .= '</div>';

    return $output;
}
add_shortcode( 'utm_admission_programme_filter', 'utm_admission_programme_filter_shortcode' );
