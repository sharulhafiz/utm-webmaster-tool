<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Restrict this module to admission.utm.my/new2024 context.
 *
 * @return bool
 */
function utm_admission_programmes_import_is_allowed_context() {
    $home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
    $home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

    $host = is_string( $home_host ) ? strtolower( $home_host ) : '';
    if ( isset( $_SERVER['HTTP_HOST'] ) ) {
        $host = strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) );
    }

    if ( 'admission.utm.my' !== $host ) {
        return false;
    }

    $normalized_path = '/' . trim( (string) $home_path, '/' ) . '/';

    return 0 === strpos( $normalized_path, '/new2024/' );
}

if ( ! utm_admission_programmes_import_is_allowed_context() ) {
    return;
}

define( 'UTM_ADM_PROGRAMMES_POST_TYPE', 'programmes' );
define( 'UTM_ADM_PROGRAMMES_CRON_HOOK', 'utm_admission_programmes_import_daily' );
define( 'UTM_ADM_PROGRAMMES_LOCK_TRANSIENT', 'utm_adm_programmes_import_lock' );
define( 'UTM_ADM_PROGRAMMES_LAST_RUN_OPTION', 'utm_adm_programmes_import_last_run' );
define( 'UTM_ADM_PROGRAMMES_LOGS_OPTION', 'utm_adm_programmes_import_logs' );
define( 'UTM_ADM_PROGRAMMES_API_TOKEN_OPTION', 'utm_adm_programmes_import_api_token' );
define( 'UTM_ADM_PROGRAMMES_REST_NAMESPACE', 'utm-webmaster/v1' );
define( 'UTM_ADM_PROGRAMMES_MAX_LOGS', 25 );

/**
 * Import source configuration.
 *
 * @return array
 */
function utm_admission_programmes_import_sources() {
    return array(
        'pg_research' => array(
            'label'      => 'PG Research',
            'url'        => 'https://docs.google.com/spreadsheets/d/1qFbTLt5OyMwJxEPiMeqSitI64s5vvb2xazW9WlUaHbg/export?format=csv',
            'level_name' => 'Postgraduate Research',
        ),
        'pg_coursework' => array(
            'label'      => 'PG Coursework',
            'url'        => 'https://docs.google.com/spreadsheets/d/1IX0pMBAd0fMZFqhjTLTNG2fAoAqmDOXPs2SlG6J3XMs/export?format=csv',
            'level_name' => 'Postgraduate Coursework',
        ),
        'ug' => array(
            'label'      => 'UG',
            'url'        => 'https://docs.google.com/spreadsheets/d/11_abb_j7WqnW36eApFYnNo_NbhFEtPYOcxzg0v5Fx1k/export?format=csv',
            'level_name' => 'Undergraduate',
        ),
    );
}

/**
 * Normalize CSV header/meta key.
 *
 * @param string $value Raw header.
 * @return string
 */
function utm_admission_programmes_import_normalize_key( $value ) {
    $value = strtolower( trim( (string) $value ) );
    $value = preg_replace( '/[^a-z0-9]+/', '_', $value );
    $value = trim( (string) $value, '_' );

    return (string) $value;
}

/**
 * Return known alias map for ACF/meta field keys.
 *
 * @return array
 */
function utm_admission_programmes_import_alias_map() {
    return array(
        'programme_name'               => 'program_name',
        'program_title'                => 'program_name',
        'programme_title'              => 'program_name',
        'scheme_of_study'              => 'study_scheme',
        'mode_of_study'                => 'study_mode',
        'delivery_mode_of_study'       => 'delivery_mode',
        'program_director_coordinator' => 'program_directorcoordinator',
        'programme_director_coordinator' => 'program_directorcoordinator',
        'program_code'                 => 'program_code_utm',
        'programme_code'               => 'program_code_utm',
        'fees_local'                   => 'fees_-_local',
        'fees_international'           => 'fees_-_international',
    );
}

/**
 * Parse CSV body into normalized row arrays.
 *
 * @param string $csv_body CSV content.
 * @return array
 */
function utm_admission_programmes_import_parse_csv( $csv_body ) {
    $rows = preg_split( '/\r\n|\n|\r/', (string) $csv_body );
    if ( empty( $rows ) ) {
        return array();
    }

    $header_row = str_getcsv( (string) array_shift( $rows ) );
    if ( empty( $header_row ) ) {
        return array();
    }

    if ( isset( $header_row[0] ) ) {
        $header_row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header_row[0] );
    }

    $normalized_headers = array();
    foreach ( $header_row as $header ) {
        $key = utm_admission_programmes_import_normalize_key( $header );
        $normalized_headers[] = '' === $key ? 'column_' . count( $normalized_headers ) : $key;
    }

    $parsed = array();
    foreach ( $rows as $line ) {
        if ( '' === trim( (string) $line ) ) {
            continue;
        }

        $values = str_getcsv( (string) $line );
        if ( empty( $values ) ) {
            continue;
        }

        if ( count( $values ) < count( $normalized_headers ) ) {
            $values = array_pad( $values, count( $normalized_headers ), '' );
        }

        $item = array();
        foreach ( $normalized_headers as $index => $header_key ) {
            $item[ $header_key ] = isset( $values[ $index ] ) ? trim( (string) $values[ $index ] ) : '';
        }

        $is_empty = true;
        foreach ( $item as $field_value ) {
            if ( '' !== (string) $field_value ) {
                $is_empty = false;
                break;
            }
        }

        if ( ! $is_empty ) {
            $parsed[] = $item;
        }
    }

    return $parsed;
}

/**
 * Fetch one source CSV and return rows.
 *
 * @param array $source Source config.
 * @return array|WP_Error
 */
function utm_admission_programmes_import_fetch_source_rows( $source ) {
    $response = wp_remote_get(
        $source['url'],
        array(
            'timeout'   => 30,
            'sslverify' => true,
        )
    );

    if ( is_wp_error( $response ) ) {
        return new WP_Error(
            'utm_adm_programmes_fetch_failed',
            sprintf( 'Failed to fetch %s: %s', (string) $source['label'], $response->get_error_message() )
        );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( 200 !== $code ) {
        return new WP_Error(
            'utm_adm_programmes_http_error',
            sprintf( 'Failed to fetch %s: HTTP %d', (string) $source['label'], $code )
        );
    }

    $body = (string) wp_remote_retrieve_body( $response );
    if ( '' === trim( $body ) ) {
        return new WP_Error(
            'utm_adm_programmes_empty_body',
            sprintf( 'Empty CSV body for source %s', (string) $source['label'] )
        );
    }

    return utm_admission_programmes_import_parse_csv( $body );
}

/**
 * Resolve ACF/meta key from source header key.
 *
 * @param string $raw_key Raw key.
 * @return string
 */
function utm_admission_programmes_import_resolve_meta_key( $raw_key ) {
    $normalized = utm_admission_programmes_import_normalize_key( $raw_key );
    if ( '' === $normalized ) {
        return '';
    }

    $aliases = utm_admission_programmes_import_alias_map();
    if ( isset( $aliases[ $normalized ] ) ) {
        return (string) $aliases[ $normalized ];
    }

    return $normalized;
}

/**
 * Determine import-safe title from row.
 *
 * @param array $row Row fields.
 * @return string
 */
function utm_admission_programmes_import_get_title( $row ) {
    $candidates = array(
        'program_name',
        'programme_name',
        'program_title',
        'programme_title',
        'name',
        'title',
    );

    foreach ( $candidates as $candidate ) {
        if ( ! empty( $row[ $candidate ] ) ) {
            return trim( (string) $row[ $candidate ] );
        }
    }

    return '';
}

/**
 * Build deterministic import unique key.
 *
 * @param array  $row    Row fields.
 * @param string $source Source key.
 * @return string
 */
function utm_admission_programmes_import_get_unique_key( $row, $source ) {
    $key_candidates = array(
        'program_code_utm',
        'programme_code_utm',
        'program_code_upu',
        'programme_code_upu',
    );

    foreach ( $key_candidates as $candidate ) {
        if ( ! empty( $row[ $candidate ] ) ) {
            $code = strtoupper( preg_replace( '/\s+/', '', (string) $row[ $candidate ] ) );
            if ( '' !== $code ) {
                return 'code:' . $code;
            }
        }
    }

    $title = utm_admission_programmes_import_get_title( $row );
    if ( '' === $title ) {
        return '';
    }

    return 'hash:' . md5( strtolower( $title ) . '|' . strtolower( (string) $source ) );
}

/**
 * Find existing programme by import key.
 *
 * @param string $import_key Unique import key.
 * @return int
 */
function utm_admission_programmes_import_find_existing_post_id( $import_key ) {
    $posts = get_posts(
        array(
            'post_type'      => UTM_ADM_PROGRAMMES_POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => '_utm_adm_programmes_import_key',
                    'value'   => (string) $import_key,
                    'compare' => '=',
                ),
            ),
        )
    );

    if ( empty( $posts ) ) {
        return 0;
    }

    return (int) $posts[0];
}

/**
 * Assign level taxonomy terms.
 *
 * @param int    $post_id           Post ID.
 * @param string $default_level     Default level by source.
 * @param string $row_level_raw     Optional row level value.
 * @return void
 */
function utm_admission_programmes_import_set_level_terms( $post_id, $default_level, $row_level_raw ) {
    $terms = array();

    if ( '' !== trim( (string) $row_level_raw ) ) {
        $raw_parts = preg_split( '/\r\n|\n|\r|,|;|\|/', (string) $row_level_raw );
        if ( is_array( $raw_parts ) ) {
            foreach ( $raw_parts as $part ) {
                $name = trim( (string) $part );
                if ( '' !== $name ) {
                    $terms[] = $name;
                }
            }
        }
    }

    if ( empty( $terms ) && '' !== trim( (string) $default_level ) ) {
        $terms[] = (string) $default_level;
    }

    if ( empty( $terms ) ) {
        return;
    }

    $terms = array_values( array_unique( $terms ) );
    wp_set_post_terms( (int) $post_id, $terms, 'level', false );
}

/**
 * Upsert one row into programmes CPT.
 *
 * @param array  $row    Parsed row.
 * @param string $source Source key.
 * @param array  $config Source config.
 * @return array
 */
function utm_admission_programmes_import_upsert_row( $row, $source, $config ) {
    $title = utm_admission_programmes_import_get_title( $row );
    if ( '' === $title ) {
        return array(
            'status'  => 'skipped',
            'message' => 'Missing programme title.',
        );
    }

    $import_key = utm_admission_programmes_import_get_unique_key( $row, $source );
    if ( '' === $import_key ) {
        return array(
            'status'  => 'skipped',
            'message' => sprintf( 'Unable to create import key for title "%s".', $title ),
        );
    }

    $post_id = utm_admission_programmes_import_find_existing_post_id( $import_key );
    $payload = array(
        'post_type'   => UTM_ADM_PROGRAMMES_POST_TYPE,
        'post_title'  => $title,
        'post_status' => 'publish',
    );

    $content_keys = array( 'about_the_program', 'program_structure', 'entry_requirements', 'program_objectives' );
    foreach ( $content_keys as $content_key ) {
        if ( ! empty( $row[ $content_key ] ) ) {
            $payload['post_content'] = wp_kses_post( (string) $row[ $content_key ] );
            break;
        }
    }

    if ( $post_id > 0 ) {
        $payload['ID'] = $post_id;
        $updated_id    = wp_update_post( $payload, true );
        if ( is_wp_error( $updated_id ) ) {
            return array(
                'status'  => 'error',
                'message' => $updated_id->get_error_message(),
            );
        }

        $post_id = (int) $updated_id;
        $status  = 'updated';
    } else {
        $inserted_id = wp_insert_post( $payload, true );
        if ( is_wp_error( $inserted_id ) ) {
            return array(
                'status'  => 'error',
                'message' => $inserted_id->get_error_message(),
            );
        }

        $post_id = (int) $inserted_id;
        $status  = 'created';
    }

    foreach ( $row as $raw_key => $value ) {
        $meta_key = utm_admission_programmes_import_resolve_meta_key( $raw_key );
        if ( '' === $meta_key ) {
            continue;
        }

        update_post_meta( $post_id, $meta_key, (string) $value );

        // Preserve raw origin mapping for unknown/extra columns.
        if ( $meta_key !== $raw_key ) {
            update_post_meta( $post_id, '_utm_adm_raw_' . $raw_key, (string) $value );
        }
    }

    $row_level_raw = '';
    if ( isset( $row['level'] ) ) {
        $row_level_raw = (string) $row['level'];
    } elseif ( isset( $row['level_of_study'] ) ) {
        $row_level_raw = (string) $row['level_of_study'];
    }

    utm_admission_programmes_import_set_level_terms( $post_id, (string) $config['level_name'], $row_level_raw );

    update_post_meta( $post_id, '_utm_adm_programmes_import_key', $import_key );
    update_post_meta( $post_id, '_utm_adm_programmes_source', (string) $source );
    update_post_meta( $post_id, '_utm_adm_programmes_source_label', (string) $config['label'] );
    update_post_meta( $post_id, '_utm_adm_programmes_last_imported_at', current_time( 'mysql' ) );

    return array(
        'status'  => $status,
        'message' => sprintf( '%s: %s', ucfirst( $status ), $title ),
        'post_id' => $post_id,
    );
}

/**
 * Persist one run log.
 *
 * @param array $run Run report.
 * @return void
 */
function utm_admission_programmes_import_save_log( $run ) {
    $logs = get_option( UTM_ADM_PROGRAMMES_LOGS_OPTION, array() );
    if ( ! is_array( $logs ) ) {
        $logs = array();
    }

    array_unshift( $logs, $run );
    $logs = array_slice( $logs, 0, UTM_ADM_PROGRAMMES_MAX_LOGS );

    update_option( UTM_ADM_PROGRAMMES_LOGS_OPTION, $logs, false );
    update_option( UTM_ADM_PROGRAMMES_LAST_RUN_OPTION, $run, false );
}

/**
 * Run full import process.
 *
 * @param string $trigger Trigger source (cron/manual).
 * @return array
 */
function utm_admission_programmes_import_run( $trigger = 'manual' ) {
    $lock_key = UTM_ADM_PROGRAMMES_LOCK_TRANSIENT;
    if ( get_transient( $lock_key ) ) {
        return array(
            'ok'      => false,
            'message' => 'Import is already running.',
        );
    }

    set_transient( $lock_key, 1, 20 * MINUTE_IN_SECONDS );

    $report = array(
        'timestamp' => current_time( 'mysql' ),
        'trigger'   => (string) $trigger,
        'created'   => 0,
        'updated'   => 0,
        'skipped'   => 0,
        'errors'    => 0,
        'details'   => array(),
        'sources'   => array(),
    );

    $sources = utm_admission_programmes_import_sources();
    foreach ( $sources as $source_key => $source ) {
        $source_summary = array(
            'label'   => (string) $source['label'],
            'rows'    => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors'  => 0,
        );

        $rows = utm_admission_programmes_import_fetch_source_rows( $source );
        if ( is_wp_error( $rows ) ) {
            $message = $rows->get_error_message();
            $report['errors']++;
            $source_summary['errors']++;
            $report['details'][] = sprintf( '[%s] %s', (string) $source['label'], $message );
            $report['sources'][] = $source_summary;
            continue;
        }

        $source_summary['rows'] = count( $rows );

        foreach ( $rows as $row ) {
            $result = utm_admission_programmes_import_upsert_row( $row, (string) $source_key, $source );

            if ( 'created' === $result['status'] ) {
                $report['created']++;
                $source_summary['created']++;
            } elseif ( 'updated' === $result['status'] ) {
                $report['updated']++;
                $source_summary['updated']++;
            } elseif ( 'skipped' === $result['status'] ) {
                $report['skipped']++;
                $source_summary['skipped']++;
            } else {
                $report['errors']++;
                $source_summary['errors']++;
            }

            if ( ! empty( $result['message'] ) && ( 'error' === $result['status'] || 'skipped' === $result['status'] ) ) {
                $report['details'][] = sprintf( '[%s] %s', (string) $source['label'], (string) $result['message'] );
            }
        }

        $report['sources'][] = $source_summary;
    }

    if ( count( $report['details'] ) > 50 ) {
        $report['details'] = array_slice( $report['details'], 0, 50 );
    }

    utm_admission_programmes_import_save_log( $report );
    delete_transient( $lock_key );

    return array(
        'ok'     => true,
        'report' => $report,
    );
}

/**
 * Ensure daily cron event exists.
 *
 * @return void
 */
function utm_admission_programmes_import_maybe_schedule() {
    if ( ! wp_next_scheduled( UTM_ADM_PROGRAMMES_CRON_HOOK ) ) {
        wp_schedule_event( time() + 600, 'daily', UTM_ADM_PROGRAMMES_CRON_HOOK );
    }
}
add_action( 'init', 'utm_admission_programmes_import_maybe_schedule' );

/**
 * Ensure API token exists for remote trigger use.
 *
 * @return void
 */
function utm_admission_programmes_import_maybe_seed_api_token() {
    $token = get_option( UTM_ADM_PROGRAMMES_API_TOKEN_OPTION, '' );
    if ( is_string( $token ) && '' !== trim( $token ) ) {
        return;
    }

    update_option( UTM_ADM_PROGRAMMES_API_TOKEN_OPTION, wp_generate_password( 48, false, false ), false );
}
add_action( 'init', 'utm_admission_programmes_import_maybe_seed_api_token' );

/**
 * Cron callback.
 *
 * @return void
 */
function utm_admission_programmes_import_run_cron() {
    utm_admission_programmes_import_run( 'cron' );
}
add_action( UTM_ADM_PROGRAMMES_CRON_HOOK, 'utm_admission_programmes_import_run_cron' );

/**
 * Get configured API token.
 *
 * @return string
 */
function utm_admission_programmes_import_get_api_token() {
    if ( defined( 'UTM_ADM_PROGRAMMES_IMPORT_API_TOKEN' ) && is_string( UTM_ADM_PROGRAMMES_IMPORT_API_TOKEN ) ) {
        return trim( UTM_ADM_PROGRAMMES_IMPORT_API_TOKEN );
    }

    $token = get_option( UTM_ADM_PROGRAMMES_API_TOKEN_OPTION, '' );
    return is_string( $token ) ? trim( $token ) : '';
}

/**
 * Check REST request permission for remote import endpoints.
 *
 * Allows either:
 * - authenticated admin user, or
 * - matching X-UTM-Import-Token header / token parameter.
 *
 * @param WP_REST_Request $request Request object.
 * @return true|WP_Error
 */
function utm_admission_programmes_import_rest_permission( $request ) {
    if ( current_user_can( 'manage_options' ) ) {
        return true;
    }

    $configured = utm_admission_programmes_import_get_api_token();
    if ( '' === $configured ) {
        return new WP_Error( 'forbidden', 'Import API token is not configured.', array( 'status' => 403 ) );
    }

    $provided = '';
    $header_token = $request->get_header( 'x-utm-import-token' );
    if ( is_string( $header_token ) && '' !== trim( $header_token ) ) {
        $provided = trim( $header_token );
    }

    if ( '' === $provided ) {
        $param_token = $request->get_param( 'token' );
        if ( is_string( $param_token ) ) {
            $provided = trim( $param_token );
        }
    }

    if ( '' === $provided || ! hash_equals( $configured, $provided ) ) {
        return new WP_Error( 'forbidden', 'Invalid import API token.', array( 'status' => 403 ) );
    }

    return true;
}

/**
 * REST callback: trigger importer.
 *
 * @return array
 */
function utm_admission_programmes_import_rest_run() {
    $result = utm_admission_programmes_import_run( 'rest' );

    if ( empty( $result['ok'] ) ) {
        return array(
            'ok'      => false,
            'message' => isset( $result['message'] ) ? (string) $result['message'] : 'Import failed.',
        );
    }

    return array(
        'ok'     => true,
        'report' => isset( $result['report'] ) ? $result['report'] : array(),
    );
}

/**
 * REST callback: import status.
 *
 * @return array
 */
function utm_admission_programmes_import_rest_status() {
    $last_run = get_option( UTM_ADM_PROGRAMMES_LAST_RUN_OPTION, array() );
    if ( ! is_array( $last_run ) ) {
        $last_run = array();
    }

    $next_run = wp_next_scheduled( UTM_ADM_PROGRAMMES_CRON_HOOK );

    return array(
        'ok'       => true,
        'last_run' => $last_run,
        'next_run' => $next_run ? date_i18n( 'Y-m-d H:i:s', $next_run ) : null,
    );
}

/**
 * Register REST routes for remote import operations.
 *
 * @return void
 */
function utm_admission_programmes_import_register_rest_routes() {
    register_rest_route(
        UTM_ADM_PROGRAMMES_REST_NAMESPACE,
        '/admission-programmes-import/run',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => 'utm_admission_programmes_import_rest_permission',
            'callback'            => 'utm_admission_programmes_import_rest_run',
        )
    );

    register_rest_route(
        UTM_ADM_PROGRAMMES_REST_NAMESPACE,
        '/admission-programmes-import/status',
        array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => 'utm_admission_programmes_import_rest_permission',
            'callback'            => 'utm_admission_programmes_import_rest_status',
        )
    );
}
add_action( 'rest_api_init', 'utm_admission_programmes_import_register_rest_routes' );

/**
 * Register admin submenu page.
 *
 * @return void
 */
function utm_admission_programmes_import_register_admin_page() {
    add_submenu_page(
        'utm-webmaster-dashboard',
        'Admission Programmes Import',
        'Admission Programmes Import',
        'manage_options',
        'utm-admission-programmes-import',
        'utm_admission_programmes_import_render_admin_page'
    );
}
add_action( 'admin_menu', 'utm_admission_programmes_import_register_admin_page' );

/**
 * Handle manual run action.
 *
 * @return void
 */
function utm_admission_programmes_import_handle_run_now() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }

    check_admin_referer( 'utm_adm_programmes_run_now' );

    $result = utm_admission_programmes_import_run( 'manual' );

    $args = array(
        'page' => 'utm-admission-programmes-import',
    );

    if ( ! empty( $result['ok'] ) ) {
        $args['utm_adm_import_ok'] = 1;
    } else {
        $args['utm_adm_import_ok'] = 0;
        $args['utm_adm_import_msg'] = isset( $result['message'] ) ? rawurlencode( (string) $result['message'] ) : rawurlencode( 'Import failed.' );
    }

    wp_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
    exit;
}
add_action( 'admin_post_utm_adm_programmes_run_now', 'utm_admission_programmes_import_handle_run_now' );

/**
 * Handle API token rotation.
 *
 * @return void
 */
function utm_admission_programmes_import_handle_rotate_token() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }

    check_admin_referer( 'utm_adm_programmes_rotate_token' );

    update_option( UTM_ADM_PROGRAMMES_API_TOKEN_OPTION, wp_generate_password( 48, false, false ), false );

    wp_redirect(
        add_query_arg(
            array(
                'page'                     => 'utm-admission-programmes-import',
                'utm_adm_import_token_rotated' => 1,
            ),
            admin_url( 'admin.php' )
        )
    );
    exit;
}
add_action( 'admin_post_utm_adm_programmes_rotate_token', 'utm_admission_programmes_import_handle_rotate_token' );

/**
 * Render import admin screen.
 *
 * @return void
 */
function utm_admission_programmes_import_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $last_run = get_option( UTM_ADM_PROGRAMMES_LAST_RUN_OPTION, array() );
    $logs     = get_option( UTM_ADM_PROGRAMMES_LOGS_OPTION, array() );
    $api_token = utm_admission_programmes_import_get_api_token();
    $next_run = wp_next_scheduled( UTM_ADM_PROGRAMMES_CRON_HOOK );
    $run_endpoint = rest_url( UTM_ADM_PROGRAMMES_REST_NAMESPACE . '/admission-programmes-import/run' );
    $status_endpoint = rest_url( UTM_ADM_PROGRAMMES_REST_NAMESPACE . '/admission-programmes-import/status' );

    if ( ! is_array( $last_run ) ) {
        $last_run = array();
    }
    if ( ! is_array( $logs ) ) {
        $logs = array();
    }

    echo '<div class="wrap">';
    echo '<h1>Admission Programmes Import</h1>';

    if ( isset( $_GET['utm_adm_import_ok'] ) ) {
        if ( 1 === (int) $_GET['utm_adm_import_ok'] ) {
            echo '<div class="notice notice-success is-dismissible"><p>Import completed successfully.</p></div>';
        } else {
            $msg = isset( $_GET['utm_adm_import_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['utm_adm_import_msg'] ) ) : 'Import failed.';
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
        }
    }

    if ( isset( $_GET['utm_adm_import_token_rotated'] ) && 1 === (int) $_GET['utm_adm_import_token_rotated'] ) {
        echo '<div class="notice notice-success is-dismissible"><p>Import API token rotated successfully.</p></div>';
    }

    echo '<div class="card" style="max-width:1000px;">';
    echo '<h2>Import Controls</h2>';
    echo '<p><strong>Next Scheduled Run:</strong> ' . esc_html( $next_run ? date_i18n( 'Y-m-d H:i:s', $next_run ) : 'Not scheduled' ) . '</p>';
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
    wp_nonce_field( 'utm_adm_programmes_run_now' );
    echo '<input type="hidden" name="action" value="utm_adm_programmes_run_now" />';
    submit_button( 'Run Import Now', 'primary', 'submit', false );
    echo '</form>';
    echo '</div>';

    echo '<div class="card" style="max-width:1000px;">';
    echo '<h2>Remote Trigger API (for cross-server execution)</h2>';
    echo '<p>Use this when you need to trigger import on the actual admission server from another host.</p>';
    echo '<p><strong>Run Endpoint (POST):</strong><br><code>' . esc_html( $run_endpoint ) . '</code></p>';
    echo '<p><strong>Status Endpoint (GET):</strong><br><code>' . esc_html( $status_endpoint ) . '</code></p>';
    echo '<p><strong>Auth Header:</strong> <code>X-UTM-Import-Token: &lt;token&gt;</code></p>';
    echo '<p><strong>Current Token:</strong><br><input type="text" readonly value="' . esc_attr( $api_token ) . '" style="width:100%;max-width:700px;" /></p>';
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;">';
    wp_nonce_field( 'utm_adm_programmes_rotate_token' );
    echo '<input type="hidden" name="action" value="utm_adm_programmes_rotate_token" />';
    submit_button( 'Rotate API Token', 'secondary', 'submit', false );
    echo '</form>';
    echo '<p class="description">Keep this token secret. You may also define <code>UTM_ADM_PROGRAMMES_IMPORT_API_TOKEN</code> in wp-config.php to override option storage.</p>';
    echo '</div>';

    if ( ! empty( $last_run ) ) {
        echo '<div class="card" style="max-width:1000px;">';
        echo '<h2>Last Run Summary</h2>';
        echo '<p><strong>Timestamp:</strong> ' . esc_html( isset( $last_run['timestamp'] ) ? (string) $last_run['timestamp'] : 'N/A' ) . '</p>';
        echo '<p><strong>Trigger:</strong> ' . esc_html( isset( $last_run['trigger'] ) ? (string) $last_run['trigger'] : 'N/A' ) . '</p>';
        echo '<ul>';
        echo '<li>Created: <strong>' . esc_html( (string) ( isset( $last_run['created'] ) ? (int) $last_run['created'] : 0 ) ) . '</strong></li>';
        echo '<li>Updated: <strong>' . esc_html( (string) ( isset( $last_run['updated'] ) ? (int) $last_run['updated'] : 0 ) ) . '</strong></li>';
        echo '<li>Skipped: <strong>' . esc_html( (string) ( isset( $last_run['skipped'] ) ? (int) $last_run['skipped'] : 0 ) ) . '</strong></li>';
        echo '<li>Errors: <strong>' . esc_html( (string) ( isset( $last_run['errors'] ) ? (int) $last_run['errors'] : 0 ) ) . '</strong></li>';
        echo '</ul>';

        if ( ! empty( $last_run['sources'] ) && is_array( $last_run['sources'] ) ) {
            echo '<h3>Per Source</h3>';
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Source</th><th>Rows</th><th>Created</th><th>Updated</th><th>Skipped</th><th>Errors</th></tr></thead><tbody>';
            foreach ( $last_run['sources'] as $source_summary ) {
                echo '<tr>';
                echo '<td>' . esc_html( isset( $source_summary['label'] ) ? (string) $source_summary['label'] : '' ) . '</td>';
                echo '<td>' . esc_html( (string) ( isset( $source_summary['rows'] ) ? (int) $source_summary['rows'] : 0 ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( isset( $source_summary['created'] ) ? (int) $source_summary['created'] : 0 ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( isset( $source_summary['updated'] ) ? (int) $source_summary['updated'] : 0 ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( isset( $source_summary['skipped'] ) ? (int) $source_summary['skipped'] : 0 ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( isset( $source_summary['errors'] ) ? (int) $source_summary['errors'] : 0 ) ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        if ( ! empty( $last_run['details'] ) && is_array( $last_run['details'] ) ) {
            echo '<h3>Last Run Details (Warnings/Errors)</h3>';
            echo '<ul style="max-height:260px;overflow:auto;">';
            foreach ( $last_run['details'] as $detail ) {
                echo '<li>' . esc_html( (string) $detail ) . '</li>';
            }
            echo '</ul>';
        }

        echo '</div>';
    }

    echo '<div class="card" style="max-width:1000px;">';
    echo '<h2>Recent Import Logs</h2>';

    if ( empty( $logs ) ) {
        echo '<p>No import logs yet.</p>';
    } else {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Timestamp</th><th>Trigger</th><th>Created</th><th>Updated</th><th>Skipped</th><th>Errors</th></tr></thead><tbody>';
        foreach ( $logs as $log ) {
            echo '<tr>';
            echo '<td>' . esc_html( isset( $log['timestamp'] ) ? (string) $log['timestamp'] : '' ) . '</td>';
            echo '<td>' . esc_html( isset( $log['trigger'] ) ? (string) $log['trigger'] : '' ) . '</td>';
            echo '<td>' . esc_html( (string) ( isset( $log['created'] ) ? (int) $log['created'] : 0 ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( isset( $log['updated'] ) ? (int) $log['updated'] : 0 ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( isset( $log['skipped'] ) ? (int) $log['skipped'] : 0 ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( isset( $log['errors'] ) ? (int) $log['errors'] : 0 ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '</div>';
    echo '</div>';
}
