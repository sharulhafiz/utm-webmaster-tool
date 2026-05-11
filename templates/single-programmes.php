<?php
/**
 * Single Programmes Template
 *
 * Custom template for the 'programmes' custom post type.
 * Replaces the Divi Theme Builder body template while preserving
 * the Divi header and footer.
 *
 * Template Name: Single Programme (UTM Admission) v2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

$post_id = get_the_ID();

// ---------- Meta Field Mapping ----------
$fields = array(
    // Core
    'program_name'          => get_post_meta( $post_id, 'program_name', true ),
    'level_of_study'        => get_post_meta( $post_id, 'level_of_study', true ),
    'programme_field'       => get_post_meta( $post_id, 'programme_field', true ),

    // Key info
    'faculty'               => get_post_meta( $post_id, 'faculty', true ),
    'duration_of_study'     => get_post_meta( $post_id, 'duration_of_study', true ),
    'programme_total_credit'=> get_post_meta( $post_id, 'programme_total_credit', true ),
    'study_scheme'          => get_post_meta( $post_id, 'study_scheme', true ),
    'study_mode'            => get_post_meta( $post_id, 'study_mode', true ),
    'delivery_mode'         => get_post_meta( $post_id, 'delivery_mode', true ),
    'study_location'        => get_post_meta( $post_id, 'study_location', true ),

    // Fees
    'programme_fees_malaysian'      => get_post_meta( $post_id, 'programme_fees_malaysian', true ),
    'programme_fees_international'  => get_post_meta( $post_id, 'programme_fees_international', true ),

    // Codes
    'programme_code_utm'    => get_post_meta( $post_id, 'programme_code_utm', true ),
    'programme_code_upu'    => get_post_meta( $post_id, 'programme_code_upu', true ),
    'nec_code'              => get_post_meta( $post_id, 'nec_code', true ),
    'mqa_code'              => get_post_meta( $post_id, 'mqa_code', true ),

    // Content sections
    'about_the_programme'   => get_post_meta( $post_id, 'about_the_programme', true ),
    'programme_structure'   => get_post_meta( $post_id, 'programme_structure', true ),
    'programme_objectives'  => get_post_meta( $post_id, 'programme_objectives', true ),
    'programme_learning_outcomes_plo' => get_post_meta( $post_id, 'programme_learning_outcomes_plo', true ),
    'entry_requirements'    => get_post_meta( $post_id, 'entry_requirements', true ),
    'career_prospect'       => get_post_meta( $post_id, 'career_prospect', true ),
    'awards_and_recognition'=> get_post_meta( $post_id, 'awards_and_recognition', true ),
    'program_directorcoordinator' => get_post_meta( $post_id, 'program_directorcoordinator', true ),

    // Additional
    'area_of_research'      => get_post_meta( $post_id, 'area_of_research', true ),
    'interview_and_non_interview_programme' => get_post_meta( $post_id, 'interview_and_non_interview_programme', true ),
    'colour_blind_test_cbt' => get_post_meta( $post_id, 'colour_blind_test_cbt', true ),
    'category_malaysian'    => get_post_meta( $post_id, 'category_malaysian', true ),
    'sept_intake_malaysian_upu' => get_post_meta( $post_id, 'sept_intake_malaysian_upu', true ),
    'offered_to_intake_malaysian'    => get_post_meta( $post_id, 'offered_to_september_2026_intake_malaysian', true ),
    'offered_to_intake_international' => get_post_meta( $post_id, 'offered_to_september_2026_intake_international', true ),
);

// ---------- Helpers ----------
$has = function( $key ) use ( $fields ) {
    return isset( $fields[ $key ] ) && '' !== trim( (string) $fields[ $key ] ) && '-' !== trim( (string) $fields[ $key ] );
};

$display = function( $key ) use ( $fields ) {
    return nl2br( esc_html( (string) $fields[ $key ] ) );
};

// ---------- Level Badge ----------
$level_slug = sanitize_title( $fields['level_of_study'] );
$level_colors = array(
    'undergraduate'               => array( '#8e0028', '#fde8ed' ),
    'postgraduate-coursework'     => array( '#3730a3', '#eef2ff' ),
    'postgraduate-research'       => array( '#065f46', '#ecfdf5' ),
    'postgraduate'                => array( '#5b3e87', '#f0ecfa' ),
);
$level_color = isset( $level_colors[ $level_slug ] ) ? $level_colors[ $level_slug ] : array( '#475569', '#f1f5f9' );

// ---------- Apply Now Logic (refined) ----------
// Uses offered_to (audience eligibility) + intake meta (current availability)
// per Codex recommendation: show button only when BOTH are true.
// Falls back to showing button when intake data is missing (assume open).

$is_ug = false !== stripos( (string) $fields['level_of_study'], 'undergraduate' );
$is_pg = ! $is_ug;

// Audience eligibility from offered_to field
$offered_raw     = strtolower( trim( (string) $fields['offered_to'] ) );
$eligible_local  = (bool) preg_match( '/(local|malaysian)/', $offered_raw );
$eligible_intl   = (bool) preg_match( '/international/', $offered_raw );

// Intake availability from intake-specific meta
$raw_local_intake = strtolower( trim( (string) $fields['offered_to_intake_malaysian'] ) );
$raw_intl_intake  = strtolower( trim( (string) $fields['offered_to_intake_international'] ) );
$has_local_intake = '' !== $raw_local_intake;
$has_intl_intake  = '' !== $raw_intl_intake;
$intake_local_open = $has_local_intake && in_array( $raw_local_intake, array( 'yes', 'y', '1', 'true' ), true );
$intake_intl_open  = $has_intl_intake  && in_array( $raw_intl_intake,  array( 'yes', 'y', '1', 'true' ), true );

// Combined: eligible AND (intake open OR intake data missing → assume open)
$local_ok = $eligible_local && ( $has_local_intake ? $intake_local_open : true );
$intl_ok  = $eligible_intl  && ( $has_intl_intake  ? $intake_intl_open  : true );

// Explicitly closed states (eligible but intake data says "no")
$local_closed = $eligible_local && $has_local_intake && ! $intake_local_open;
$intl_closed  = $eligible_intl  && $has_intl_intake  && ! $intake_intl_open;

// Build apply URLs
$url_local = 'https://smart.utm.my/admission/';
$url_intl  = 'https://admission.utm.my/international/';

// ---------- Content sections for accordion ----------
$sections = array(
    'about_the_programme'   => array( 'About the Programme', 'about_the_programme' ),
    'programme_structure'   => array( 'Programme Structure', 'programme_structure' ),
    'programme_objectives'  => array( 'Programme Objectives', 'programme_objectives' ),
    'programme_learning_outcomes_plo' => array( 'Programme Learning Outcomes', 'programme_learning_outcomes_plo' ),
    'entry_requirements'    => array( 'Entry Requirements', 'entry_requirements' ),
    'career_prospect'       => array( 'Career Prospects', 'career_prospect' ),
    'awards_and_recognition'=> array( 'Awards & Recognition', 'awards_and_recognition' ),
);

$has_any_section = false;
foreach ( $sections as $sk => $sv ) {
    if ( $has( $sv[1] ) ) { $has_any_section = true; break; }
}
?>
<style>
:root {
    --utm-maroon: #8e0028;
    --utm-maroon-dark: #6d001f;
    --utm-maroon-light: #fde8ed;
    --purple: #5b3e87;
    --purple-light: #f0ecfa;
    --green-deep: #065f46;
    --green-light: #ecfdf5;
    --slate-50: #f8f9fc;
    --slate-100: #f1f5f9;
    --slate-200: #e2e5ec;
    --slate-400: #9ca3af;
    --slate-500: #6b7280;
    --slate-600: #4b5563;
    --slate-700: #374151;
    --text-main: #1a2030;
    --text-muted: #5c6470;
    --radius-sm: 12px;
    --radius-md: 16px;
    --radius-lg: 20px;
    --radius-xl: 30px;
    --shadow-sm: 0 2px 8px rgba(15,23,42,0.05);
    --shadow-md: 0 4px 12px rgba(142,0,40,0.08);
    --font-sans: 'Poppins', 'Open Sans', sans-serif;
}

.utm-single-programme {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px 60px;
    font-family: var(--font-sans);
    color: var(--text-main);
}

/* --- Hero --- */
.utm-single-hero {
    background: linear-gradient(135deg, var(--utm-maroon) 0%, var(--utm-maroon-dark) 100%);
    color: #fff;
    padding: 50px 40px;
    border-radius: 0 0 var(--radius-xl) var(--radius-xl);
    margin: 0 -20px 40px;
    position: relative;
    overflow: hidden;
}
.utm-single-hero::after {
    content: '';
    position: absolute;
    top: -50%;
    right: -15%;
    width: 400px;
    height: 400px;
    background: rgba(255,255,255,0.04);
    border-radius: 50%;
    pointer-events: none;
}
.utm-hero-top {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    margin-bottom: 14px;
    position: relative;
    z-index: 1;
}
.utm-hero-back {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    padding: 4px 12px;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.25);
    transition: all 0.2s;
}
.utm-hero-back:hover {
    color: #fff;
    border-color: rgba(255,255,255,0.5);
    background: rgba(255,255,255,0.1);
}
.utm-single-hero h1 {
    font-size: 32px;
    font-weight: 800;
    margin: 0 0 12px;
    line-height: 1.25;
    max-width: 800px;
    position: relative;
    z-index: 1;
}
.utm-hero-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    position: relative;
    z-index: 1;
}
.utm-hero-badge {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    padding: 5px 14px;
    border-radius: 999px;
    background: var(--utm-maroon-light);
    color: var(--utm-maroon);
    border: 1px solid currentColor;
}
.utm-hero-badge.is-pg {
    background: var(--purple-light);
    color: var(--purple);
}
.utm-hero-field {
    font-size: 14px;
    color: rgba(255,255,255,0.8);
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.utm-hero-field::before {
    content: '\2022';
    opacity: 0.5;
}

/* --- Section Title --- */
.utm-section-title {
    font-size: 20px;
    font-weight: 700;
    margin: 0 0 16px;
    padding-bottom: 8px;
    border-bottom: 3px solid var(--utm-maroon);
    color: var(--text-main);
}

/* --- Key Info Grid --- */
.utm-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 12px;
    margin-bottom: 36px;
}
.utm-info-card {
    background: var(--slate-50);
    border: 1px solid var(--slate-200);
    border-radius: var(--radius-sm);
    padding: 16px 18px;
    transition: box-shadow 0.2s;
}
.utm-info-card:hover {
    box-shadow: var(--shadow-md);
}
.utm-info-label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--utm-maroon);
    margin-bottom: 4px;
}
.utm-info-value {
    font-size: 15px;
    font-weight: 500;
    color: var(--text-main);
    line-height: 1.4;
}

/* --- Fees Row --- */
.utm-fees-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 36px;
}
.utm-fee-card {
    background: #fff;
    border: 2px solid var(--slate-200);
    border-radius: var(--radius-md);
    padding: 24px;
    text-align: center;
}
.utm-fee-card.is-local {
    border-color: var(--utm-maroon);
    background: linear-gradient(135deg, var(--utm-maroon-light), #fff);
}
.utm-fee-card.is-international {
    border-color: #8B6914;
    background: linear-gradient(135deg, #fffdf0, #fff);
}
.utm-fee-label {
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    margin-bottom: 6px;
}
.utm-fee-amount {
    font-size: 28px;
    font-weight: 800;
    color: var(--text-main);
}
.utm-fee-type {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 4px;
}

/* --- Codes Bar --- */
.utm-codes-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 36px;
    padding: 16px 20px;
    background: var(--slate-50);
    border-radius: var(--radius-sm);
    border: 1px solid var(--slate-200);
}
.utm-code-item {
    display: flex;
    flex-direction: column;
}
.utm-code-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--utm-maroon);
}
.utm-code-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-main);
}

/* --- Content Accordion --- */
.utm-accordion {
    margin-bottom: 36px;
}
.utm-accordion-item {
    border: 1px solid var(--slate-200);
    border-radius: var(--radius-sm);
    margin-bottom: 8px;
    overflow: hidden;
    transition: box-shadow 0.2s;
}
.utm-accordion-item:hover {
    box-shadow: 0 2px 8px rgba(142,0,40,0.06);
}
.utm-accordion-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 16px 20px;
    background: var(--slate-50);
    border: none;
    cursor: pointer;
    font-size: 15px;
    font-weight: 700;
    color: var(--text-main);
    text-align: left;
    transition: background 0.2s;
    gap: 12px;
}
.utm-accordion-header:hover {
    background: #f0f2f7;
}
.utm-accordion-header:focus-visible {
    outline: 2px solid var(--utm-maroon);
    outline-offset: -2px;
}
.utm-accordion-icon {
    flex-shrink: 0;
    width: 20px;
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.25s ease;
    color: var(--utm-maroon);
    font-size: 18px;
    font-weight: 300;
}
.utm-accordion-item.is-open .utm-accordion-icon {
    transform: rotate(45deg);
}
.utm-accordion-body {
    padding: 0 20px 20px;
    display: none;
    font-size: 14.5px;
    line-height: 1.75;
    color: var(--slate-700);
}
.utm-accordion-item.is-open .utm-accordion-body {
    display: block;
}
.utm-accordion-body p {
    margin: 0 0 10px;
}
.utm-accordion-body ul, .utm-accordion-body ol {
    margin: 6px 0;
    padding-left: 22px;
}
.utm-accordion-body li {
    margin-bottom: 4px;
}

/* --- Apply Section --- */
.utm-apply-section {
    text-align: center;
    padding: 40px 20px;
    background: linear-gradient(135deg, var(--utm-maroon) 0%, var(--utm-maroon-dark) 100%);
    border-radius: var(--radius-lg);
    margin-bottom: 0;
}
.utm-apply-section h2 {
    color: #fff;
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 8px;
}
.utm-apply-section p {
    color: rgba(255,255,255,0.8);
    font-size: 15px;
    margin: 0 0 24px;
}
.utm-apply-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;
}
.utm-apply-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 14px 32px;
    background: #fff;
    color: var(--utm-maroon);
    font-size: 15px;
    font-weight: 700;
    text-decoration: none;
    border-radius: 999px;
    border: 2px solid #fff;
    transition: all 0.2s;
}
.utm-apply-btn:hover {
    background: transparent;
    color: #fff;
}
.utm-apply-btn.is-outline {
    background: transparent;
    color: #fff;
    border-color: rgba(255,255,255,0.5);
}
.utm-apply-btn.is-outline:hover {
    background: rgba(255,255,255,0.1);
    border-color: #fff;
}
.utm-apply-tbc {
    color: rgba(255,255,255,0.7);
    font-size: 14px;
    margin: 0;
    padding: 8px 0;
}

/* --- Director Card --- */
.utm-director-card {
    background: var(--slate-50);
    border-radius: var(--radius-sm);
    padding: 16px 20px;
    border: 1px solid var(--slate-200);
    line-height: 1.6;
    font-size: 14px;
    margin-bottom: 36px;
}

/* --- Extra Info --- */
.utm-extra-info {
    margin-top: 36px;
    padding: 20px;
    background: var(--slate-50);
    border-radius: var(--radius-sm);
    border: 1px solid var(--slate-200);
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px;
}
.utm-extra-item {
    font-size: 13px;
    color: var(--slate-600);
}
.utm-extra-item strong {
    color: var(--text-main);
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--utm-maroon);
    margin-bottom: 2px;
}

/* --- Intake Status --- */
.utm-intake-status {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 28px;
}
.utm-intake-tag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 600;
    padding: 6px 14px;
    border-radius: 999px;
}
.utm-intake-tag.is-offered {
    background: #dcfce7;
    color: #166534;
}
.utm-intake-tag.is-not-offered {
    background: #f1f5f9;
    color: #64748b;
}

/* --- Sticky Mobile Apply Bar --- */
.utm-sticky-apply {
    display: none;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 999;
    background: #fff;
    border-top: 1px solid var(--slate-200);
    padding: 10px 16px;
    box-shadow: 0 -4px 12px rgba(0,0,0,0.08);
}
.utm-sticky-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    max-width: 1200px;
    margin: 0 auto;
    gap: 12px;
}
.utm-sticky-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-main);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
    min-width: 0;
}
.utm-sticky-apply-btn {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 24px;
    background: var(--utm-maroon);
    color: #fff;
    font-size: 14px;
    font-weight: 700;
    text-decoration: none;
    border-radius: 999px;
    border: none;
    transition: background 0.2s;
}
.utm-sticky-apply-btn:hover {
    background: var(--utm-maroon-dark);
}

/* ============================================
   Responsive
   ============================================ */
@media screen and (max-width: 768px) {
    .utm-single-hero {
        padding: 30px 20px;
        margin: 0 -20px 30px;
    }
    .utm-single-hero h1 {
        font-size: 24px;
    }
    .utm-info-grid {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    .utm-fees-row {
        grid-template-columns: 1fr;
    }
    .utm-codes-bar {
        flex-direction: column;
        gap: 10px;
    }
    .utm-accordion-header {
        font-size: 14px;
        padding: 14px 16px;
    }
    .utm-accordion-body {
        padding: 0 16px 16px;
        font-size: 14px;
    }
    .utm-apply-section {
        padding: 30px 16px;
        border-radius: var(--radius-md);
    }
    .utm-apply-section h2 {
        font-size: 20px;
    }
    .utm-apply-btn {
        padding: 12px 28px;
        font-size: 14px;
    }
    .utm-apply-row {
        flex-direction: column;
        align-items: stretch;
    }
    .utm-apply-btn {
        justify-content: center;
    }
    .utm-sticky-apply {
        display: block;
    }
}

@media screen and (max-width: 480px) {
    .utm-info-grid {
        grid-template-columns: 1fr;
    }
    .utm-single-hero h1 {
        font-size: 20px;
    }
    .utm-fee-amount {
        font-size: 22px;
    }
    .utm-single-programme {
        padding: 0 16px 80px;
    }
}
</style>

<main id="main-content" class="utm-single-programme">

    <!-- ============ HERO ============ -->
    <div class="utm-single-hero">
        <div class="utm-hero-top">
            <a href="https://admission.utm.my/new2024/programmes/" class="utm-hero-back">&larr; All Programmes</a>
        </div>
        <h1><?php echo esc_html( $fields['program_name'] ?: get_the_title() ); ?></h1>
        <div class="utm-hero-meta">
            <?php if ( $has('level_of_study') ) : ?>
                <span class="utm-hero-badge<?php echo $is_pg ? ' is-pg' : ''; ?>"><?php echo esc_html( $fields['level_of_study'] ); ?></span>
            <?php endif; ?>
            <?php if ( $has('programme_field') ) : ?>
                <span class="utm-hero-field"><?php echo esc_html( $fields['programme_field'] ); ?></span>
            <?php endif; ?>
            <?php if ( $has('study_location') ) : ?>
                <span class="utm-hero-field"><?php echo esc_html( $fields['study_location'] ); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============ INTAKE STATUS ============ -->
    <?php if ( $has('offered_to') || $has('offered_to_intake_malaysian') || $has('offered_to_intake_international') ) : ?>
    <div class="utm-intake-status">
        <?php if ( $eligible_local ) : ?>
            <span class="utm-intake-tag is-<?php echo $local_closed ? 'not-offered' : 'offered'; ?>">
                <?php echo $local_closed ? '❌' : '✅'; ?> Malaysian: <?php echo $local_ok ? 'Intake Open' : ( $local_closed ? 'Not Available' : 'TBC' ); ?>
            </span>
        <?php endif; ?>
        <?php if ( $eligible_intl ) : ?>
            <span class="utm-intake-tag is-<?php echo $intl_closed ? 'not-offered' : 'offered'; ?>">
                <?php echo $intl_closed ? '❌' : '✅'; ?> International: <?php echo $intl_ok ? 'Intake Open' : ( $intl_closed ? 'Not Available' : 'TBC' ); ?>
            </span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ============ KEY INFO GRID ============ -->
    <h2 class="utm-section-title">Programme Overview</h2>
    <div class="utm-info-grid">
        <?php
        $info_cards = array(
            'faculty'        => array( 'Faculty', $fields['faculty'] ),
            'duration_of_study' => array( 'Duration', $fields['duration_of_study'] ),
            'programme_total_credit' => array( 'Total Credit', $fields['programme_total_credit'] ),
            'study_scheme'   => array( 'Study Scheme', $fields['study_scheme'] ),
            'study_mode'     => array( 'Study Mode', $fields['study_mode'] ),
            'delivery_mode'  => array( 'Delivery Mode', $fields['delivery_mode'] ),
        );
        foreach ( $info_cards as $key => $card ) :
            if ( ! $has( $key ) ) continue;
        ?>
        <div class="utm-info-card">
            <div class="utm-info-label"><?php echo esc_html( $card[0] ); ?></div>
            <div class="utm-info-value"><?php echo $display( $key ); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ============ FEES ============ -->
    <?php if ( $has('programme_fees_malaysian') || $has('programme_fees_international') ) : ?>
    <h2 class="utm-section-title">Fees</h2>
    <div class="utm-fees-row">
        <div class="utm-fee-card is-local">
            <div class="utm-fee-label">Malaysian</div>
            <div class="utm-fee-amount"><?php echo $has('programme_fees_malaysian') ? esc_html( $fields['programme_fees_malaysian'] ) : 'TBC'; ?></div>
            <div class="utm-fee-type">Local Student Fees</div>
        </div>
        <div class="utm-fee-card is-international">
            <div class="utm-fee-label">International</div>
            <div class="utm-fee-amount"><?php echo $has('programme_fees_international') ? esc_html( $fields['programme_fees_international'] ) : 'TBC'; ?></div>
            <div class="utm-fee-type">International Student Fees</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============ CODES ============ -->
    <?php if ( $has('programme_code_utm') || $has('programme_code_upu') || $has('nec_code') || $has('mqa_code') ) : ?>
    <h2 class="utm-section-title">Programme Codes</h2>
    <div class="utm-codes-bar">
        <?php if ( $has('programme_code_utm') ) : ?>
        <div class="utm-code-item">
            <span class="utm-code-label">UTM Code</span>
            <span class="utm-code-value"><?php echo esc_html( $fields['programme_code_utm'] ); ?></span>
        </div>
        <?php endif; ?>
        <?php if ( $has('programme_code_upu') ) : ?>
        <div class="utm-code-item">
            <span class="utm-code-label">UPU Code</span>
            <span class="utm-code-value"><?php echo esc_html( $fields['programme_code_upu'] ); ?></span>
        </div>
        <?php endif; ?>
        <?php if ( $has('nec_code') ) : ?>
        <div class="utm-code-item">
            <span class="utm-code-label">NEC</span>
            <span class="utm-code-value"><?php echo esc_html( $fields['nec_code'] ); ?></span>
        </div>
        <?php endif; ?>
        <?php if ( $has('mqa_code') ) : ?>
        <div class="utm-code-item">
            <span class="utm-code-label">MQA</span>
            <span class="utm-code-value"><?php echo esc_html( $fields['mqa_code'] ); ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ============ CONTENT ACCORDION ============ -->
    <?php if ( $has_any_section ) : ?>
    <div class="utm-accordion">
        <?php
        $is_first = true;
        foreach ( $sections as $sk => $sv ) :
            if ( ! $has( $sv[1] ) ) continue;
            $open_class = $is_first ? ' is-open' : '';
            $section_id = 'utm-sec-' . $sk;
            $is_first = false;
        ?>
        <div class="utm-accordion-item<?php echo $open_class; ?>">
            <button
                class="utm-accordion-header"
                aria-expanded="<?php echo $open_class ? 'true' : 'false'; ?>"
                aria-controls="<?php echo esc_attr( $section_id ); ?>"
            >
                <span><?php echo esc_html( $sv[0] ); ?></span>
                <span class="utm-accordion-icon" aria-hidden="true">+</span>
            </button>
            <div id="<?php echo esc_attr( $section_id ); ?>" class="utm-accordion-body" role="region">
                <?php echo wp_kses_post( wpautop( $fields[ $sv[1] ] ) ); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ============ PROGRAM DIRECTOR ============ -->
    <?php if ( $has('program_directorcoordinator') ) : ?>
    <h2 class="utm-section-title">Programme Director</h2>
    <div class="utm-director-card">
        <?php echo $display('program_directorcoordinator'); ?>
    </div>
    <?php endif; ?>

    <!-- ============ APPLY SECTION ============ -->
    <div class="utm-apply-section">
        <h2>Ready to Join UTM?</h2>
        <p>Take the next step towards your future — apply now for this programme.</p>
        <div class="utm-apply-row">
            <?php if ( $local_ok ) : ?>
            <a href="<?php echo esc_url( $url_local ); ?>" class="utm-apply-btn" target="_blank" rel="noopener">
                Apply (Malaysian) &rarr;
            </a>
            <?php endif; ?>
            <?php if ( $intl_ok ) : ?>
            <a href="<?php echo esc_url( $url_intl ); ?>" class="utm-apply-btn is-outline" target="_blank" rel="noopener">
                Apply (International) &rarr;
            </a>
            <?php endif; ?>
            <?php if ( ! $local_ok && ! $intl_ok && ( $eligible_local || $eligible_intl ) ) : ?>
            <p class="utm-apply-tbc">Intake details coming soon — check back later.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============ ADDITIONAL INFO ============ -->
    <?php
    $extra_items = array();
    if ( $has('category_malaysian') ) {
        $extra_items[] = array( 'Entry Category (Malaysian)', $fields['category_malaysian'] );
    }
    if ( $has('sept_intake_malaysian_upu') ) {
        $extra_items[] = array( 'Sept Intake (Malaysian)', $fields['sept_intake_malaysian_upu'] );
    }
    if ( $has('interview_and_non_interview_programme') ) {
        $val = $fields['interview_and_non_interview_programme'];
        $extra_items[] = array( 'Interview Required', 'YES' === strtoupper( trim( $val ) ) ? 'Yes' : 'No' );
    }
    if ( $has('colour_blind_test_cbt') ) {
        $val = $fields['colour_blind_test_cbt'];
        $extra_items[] = array( 'Colour Blind Test', 'YES' === strtoupper( trim( $val ) ) ? 'Required' : 'Not Required' );
    }
    if ( $has('area_of_research') ) {
        $extra_items[] = array( 'Area of Research', $fields['area_of_research'] );
    }
    if ( ! empty( $extra_items ) ) :
    ?>
    <div class="utm-extra-info">
        <?php foreach ( $extra_items as $item ) : ?>
        <div class="utm-extra-item">
            <strong><?php echo esc_html( $item[0] ); ?></strong>
            <?php echo esc_html( $item[1] ); ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

<!-- Sticky Mobile Apply Bar -->
<div class="utm-sticky-apply" id="utm-sticky-apply">
    <div class="utm-sticky-inner">
        <span class="utm-sticky-title"><?php echo esc_html( $fields['program_name'] ?: get_the_title() ); ?></span>
        <?php if ( $local_ok || $intl_ok ) : ?>
        <a href="<?php echo esc_url( $local_ok ? $url_local : $url_intl ); ?>" class="utm-sticky-apply-btn" target="_blank" rel="noopener">Apply Now &rarr;</a>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    // Accordion toggle
    document.querySelectorAll('.utm-accordion-header').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var item = this.parentElement;
            var isOpen = item.classList.toggle('is-open');
            this.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });

    // Hide sticky bar on scroll to bottom (desktop only)
    if (window.innerWidth <= 768) {
        var stickyBar = document.getElementById('utm-sticky-apply');
        if (stickyBar) {
            var lastScroll = 0;
            window.addEventListener('scroll', function() {
                var scrollBottom = window.scrollY + window.innerHeight;
                var docHeight = document.documentElement.scrollHeight;
                if (scrollBottom >= docHeight - 60) {
                    stickyBar.style.transform = 'translateY(100%)';
                } else {
                    stickyBar.style.transform = 'translateY(0)';
                }
            });
        }
    }
})();
</script>

<?php get_footer(); ?>
