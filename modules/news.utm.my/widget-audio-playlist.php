<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Audio Playlist Widget
 * 
 * Displays a widget showing recent posts with audio shortcasts
 * Queries for posts with _audio_attachment_id meta key
 * Shows post titles as links, post dates, and audio players
 */
class UTM_News_Audio_Playlist_Widget extends WP_Widget {
    
    /**
     * Widget initialization
     */
    public function __construct() {
        parent::__construct(
            'utm_news_audio_playlist',
            'Audio Shortcasts Playlist',
            array(
                'description' => 'Display recent posts with audio shortcasts'
            )
        );
    }
    
    /**
     * Frontend widget display
     * 
     * @param array $args Widget arguments (before_widget, after_widget, before_title, after_title)
     * @param array $instance Widget settings (count)
     */
    public function widget( $args, $instance ) {
        // Get count from instance with default of 10
        $count = isset( $instance['count'] ) ? intval( $instance['count'] ) : 10;
        $count = $count > 0 ? $count : 10;
        
        // Query posts with audio attachment meta
        $query = new WP_Query( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'meta_key'       => '_audio_attachment_id',
            'meta_compare'   => 'EXISTS',
            'posts_per_page' => $count,
            'orderby'        => 'date',
            'order'          => 'DESC'
        ) );
        
        // Output widget container
        echo $args['before_widget'];
        echo $args['before_title'] . 'Audio Shortcasts' . $args['after_title'];
        
        // Output inline CSS
        echo '<style>
.utm-audio-playlist { list-style: none; padding: 0; margin: 0; }
.utm-audio-playlist .playlist-item { 
    margin-bottom: 20px; 
    padding-bottom: 20px; 
    border-bottom: 1px solid #eee; 
}
.utm-audio-playlist .playlist-item:last-child { border-bottom: none; }
.utm-audio-playlist .playlist-item h4 { margin: 0 0 5px 0; font-size: 16px; }
.utm-audio-playlist .post-date { 
    display: block; 
    color: #666; 
    font-size: 12px; 
    margin-bottom: 10px; 
}
</style>';
        
        // Display posts or empty message
        if ( $query->have_posts() ) {
            echo '<ul class="utm-audio-playlist">';
            
            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();
                $audio_id = get_post_meta( $post_id, '_audio_attachment_id', true );
                
                // Get audio URL and MIME type
                $audio_url = wp_get_attachment_url( $audio_id );
                $mime_type = get_post_mime_type( $audio_id );
                
                // Fallback MIME type
                if ( empty( $mime_type ) ) {
                    $mime_type = 'audio/mpeg';
                }
                
                // Get post date
                $post_date = get_the_date( 'F j, Y' );
                $post_url = get_permalink();
                $post_title = get_the_title();
                
                // Render list item
                echo '<li class="playlist-item">';
                echo '<h4><a href="' . esc_url( $post_url ) . '">' . esc_html( $post_title ) . '</a></h4>';
                echo '<span class="post-date">' . esc_html( $post_date ) . '</span>';
                
                if ( $audio_url ) {
                    echo '<audio controls style="width: 100%; margin: 10px 0;">';
                    echo '<source src="' . esc_url( $audio_url ) . '" type="' . esc_attr( $mime_type ) . '">';
                    echo 'Your browser does not support the audio element.';
                    echo '</audio>';
                }
                
                echo '</li>';
            }
            
            echo '</ul>';
        } else {
            echo '<p>No audio shortcasts found.</p>';
        }
        
        // Restore original post data
        wp_reset_postdata();
        
        echo $args['after_widget'];
    }
    
    /**
     * Widget admin form
     * 
     * @param array $instance Current widget settings
     */
    public function form( $instance ) {
        $count = isset( $instance['count'] ) ? intval( $instance['count'] ) : 10;
        $field_id = $this->get_field_id( 'count' );
        $field_name = $this->get_field_name( 'count' );
        
        echo '<p>';
        echo '<label for="' . esc_attr( $field_id ) . '">Number of posts:</label><br />';
        echo '<input type="number" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( $count ) . '" min="1" max="50" />';
        echo '</p>';
    }
    
    /**
     * Save widget settings
     * 
     * @param array $new_instance New settings
     * @param array $old_instance Old settings
     * @return array Updated settings
     */
    public function update( $new_instance, $old_instance ) {
        $instance = array();
        
        // Sanitize count value
        $instance['count'] = ( ! empty( $new_instance['count'] ) ) ? absint( $new_instance['count'] ) : 10;
        
        return $instance;
    }
}

/**
 * Register the widget
 */
add_action( 'widgets_init', function() {
    register_widget( 'UTM_News_Audio_Playlist_Widget' );
} );
