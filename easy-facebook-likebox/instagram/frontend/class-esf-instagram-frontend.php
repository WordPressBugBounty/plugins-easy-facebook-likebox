<?php

/**
 * Instagram Frontend Class.
 *
 * Registers the [esf_instagram_feed] shortcode and conditionally enqueues
 * CSS/JS on pages that render at least one Instagram feed.
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram/Frontend
 * @since 6.9.0
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Class ESF_Instagram_Frontend
 *
 * @since 6.9.0
 */
class ESF_Instagram_Frontend {
    use ESF_Instagram_Singleton;
    /**
     * Constructor.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Register WordPress hooks.
     *
     * @return void
     */
    private function init_hooks() {
        add_shortcode( 'esf_instagram_feed', array($this, 'render_shortcode') );
        /*
         * Permanent modern alias — not tied to legacy module code. Keeps old
         * embeds working after legacy removal; background rewriter may also
         * convert classic post_content over time.
         */
        add_shortcode( 'my-instagram-feed', array($this, 'render_legacy_shortcode_placeholder') );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_frontend_assets') );
        add_action( 'wp_footer', array($this, 'maybe_enqueue_missing_feed_admin_assets'), 8 );
    }

    /**
     * Render the [esf_instagram_feed id="1"] shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'id' => 0,
        ), $atts, 'esf_instagram_feed' );
        $feed_id = (int) $atts['id'];
        if ( $feed_id <= 0 ) {
            return '';
        }
        esf_instagram_flag_feed_script();
        $renderer = new ESF_Instagram_Renderer();
        return $renderer->render( $feed_id, array(), true );
    }

    /**
     * Permanent alias for legacy [my-instagram-feed] shortcodes.
     *
     * Resolves account/hashtag attributes onto a modern feed. Renders for
     * everyone when a feed is found — the alias stays even after legacy module
     * removal so sites never go blank.
     *
     * @param array $atts Legacy shortcode attributes.
     * @return string HTML output.
     */
    public function render_legacy_shortcode_placeholder( $atts ) {
        $atts = shortcode_atts( array(
            'user_id' => '',
            'hashtag' => '',
        ), ( is_array( $atts ) ? $atts : array() ), 'my-instagram-feed' );
        $default_feed_id = ( function_exists( 'esf_instagram_get_migration_default_feed_id' ) ? esf_instagram_get_migration_default_feed_id() : 0 );
        $feed_id = ( function_exists( 'esf_instagram_resolve_legacy_shortcode_feed_id' ) ? (int) esf_instagram_resolve_legacy_shortcode_feed_id( $atts, $default_feed_id ) : (int) $default_feed_id );
        if ( $feed_id <= 0 ) {
            $feed_id = (int) $default_feed_id;
        }
        if ( $feed_id <= 0 ) {
            $can_manage = function_exists( 'esf_instagram_user_can_manage' ) && esf_instagram_user_can_manage();
            if ( !$can_manage ) {
                return '';
            }
            $post_id = ( function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0 );
            $context = new \EasySocialFeed\Layouts\ValueObjects\MissingFeedContext(
                'instagram',
                0,
                'my-instagram-feed',
                $post_id,
                true,
                \EasySocialFeed\Layouts\Primitives\MissingFeedState::default_public_message( 'instagram' ),
                admin_url( 'admin.php?page=esf-instagram&screen=feeds' ),
                '/instagram/feeds/{id}/preview',
                '/instagram/feeds'
            );
            return \EasySocialFeed\Layouts\Primitives\MissingFeedState::render( $context );
        }
        esf_instagram_flag_feed_script();
        $renderer = new ESF_Instagram_Renderer();
        return $renderer->render( $feed_id, array(), true );
    }

    /**
     * Conditionally enqueue Instagram frontend assets in wp_footer.
     *
     * Only emits CSS when at least one Instagram feed actually rendered
     * during the request, mirroring the Twitter module behaviour.
     *
     * @return void
     */
    public function maybe_enqueue_frontend_assets() {
        global $esf_instagram_feed_script_needed;
        if ( empty( $esf_instagram_feed_script_needed ) ) {
            return;
        }
        $version = ( defined( 'FTA_VERSION' ) ? FTA_VERSION : '1.0.0' );
        $base_css_path = ESF_INSTAGRAM_DIR . 'frontend/assets/css/esf-ig-feed.css';
        if ( file_exists( $base_css_path ) && !wp_style_is( 'esf-instagram-feed', 'enqueued' ) ) {
            wp_enqueue_style(
                'esf-instagram-feed',
                ESF_INSTAGRAM_URL . 'frontend/assets/css/esf-ig-feed.css',
                array(),
                $version
            );
        }
        if ( class_exists( 'ESF_GDPR_Integrations' ) ) {
            ESF_GDPR_Integrations::maybe_enqueue_consent_script();
        }
        if ( function_exists( 'esf_enqueue_feed_loading_script' ) ) {
            esf_enqueue_feed_loading_script();
        }
    }

    /**
     * Enqueue the public Instagram Load More script.
     *
     * @param string $version Plugin version for cache busting.
     * @return void
     */
    private function enqueue_load_more_public_script( $version ) {
        $script_path = ESF_INSTAGRAM_DIR . 'frontend/assets/js/esf-ig-public__premium_only.js';
        if ( !file_exists( $script_path ) ) {
            return;
        }
        wp_enqueue_script(
            'esf-instagram-public',
            ESF_INSTAGRAM_URL . 'frontend/assets/js/esf-ig-public__premium_only.js',
            array(),
            (string) filemtime( $script_path ),
            true
        );
        wp_localize_script( 'esf-instagram-public', 'esfInstagramPublic', array(
            'restUrl'     => rest_url( 'esf/v1' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'loadingText' => __( 'Loading…', 'easy-facebook-likebox' ),
            'version'     => $version,
        ) );
    }

    /**
     * Enqueue shared missing-feed admin recovery assets when placeholders rendered.
     *
     * @since 6.9.0
     * @return void
     */
    public function maybe_enqueue_missing_feed_admin_assets() {
        if ( !function_exists( 'esf_instagram_user_can_manage' ) || !esf_instagram_user_can_manage() ) {
            return;
        }
        $should_enqueue = false;
        if ( function_exists( 'esf_missing_feed_get_queue' ) && !empty( esf_missing_feed_get_queue() ) ) {
            $should_enqueue = true;
        }
        if ( !$should_enqueue && function_exists( 'is_singular' ) && is_singular() ) {
            $post = get_post();
            if ( $post instanceof WP_Post ) {
                $content = (string) $post->post_content;
                if ( false !== strpos( $content, '[esf_instagram_feed' ) || false !== strpos( $content, '[my-instagram-feed' ) ) {
                    $should_enqueue = true;
                }
            }
        }
        if ( !$should_enqueue ) {
            return;
        }
        $script_path = FTA_PLUGIN_DIR . 'admin/shared/frontend/js/esf-missing-feed-admin.js';
        if ( !file_exists( $script_path ) ) {
            return;
        }
        $style_path = FTA_PLUGIN_DIR . 'admin/shared/frontend/css/esf-missing-feed.css';
        if ( file_exists( $style_path ) && !wp_style_is( 'esf-missing-feed', 'enqueued' ) ) {
            wp_enqueue_style(
                'esf-missing-feed',
                FTA_PLUGIN_URL . 'admin/shared/frontend/css/esf-missing-feed.css',
                array(),
                (string) filemtime( $style_path )
            );
        }
        wp_enqueue_script(
            'esf-missing-feed-admin',
            FTA_PLUGIN_URL . 'admin/shared/frontend/js/esf-missing-feed-admin.js',
            array(),
            (string) filemtime( $script_path ),
            true
        );
        wp_localize_script( 'esf-missing-feed-admin', 'esfMissingFeedAdmin', array(
            'restUrl'              => rest_url( 'esf/v1' ),
            'nonce'                => wp_create_nonce( 'wp_rest' ),
            'loadingText'          => __( 'Loading…', 'easy-facebook-likebox' ),
            'titleText'            => __( 'Feed not found', 'easy-facebook-likebox' ),
            'adminIntroText'       => __( 'This shortcode points to a feed that was deleted. Choose another feed to preview or update this page.', 'easy-facebook-likebox' ),
            'legacyIntroText'      => __( 'This legacy Instagram shortcode needs updating after migration. Choose a feed to preview, then save the new shortcode to this page.', 'easy-facebook-likebox' ),
            'legacyTitleText'      => __( 'Legacy Instagram shortcode', 'easy-facebook-likebox' ),
            'selectFeedText'       => __( 'Select a feed', 'easy-facebook-likebox' ),
            'previewText'          => __( 'Preview feed', 'easy-facebook-likebox' ),
            'saveText'             => __( 'Save to this page', 'easy-facebook-likebox' ),
            'savedText'            => __( 'Shortcode updated. Reloading…', 'easy-facebook-likebox' ),
            'copyShortcodeText'    => __( 'Copy shortcode', 'easy-facebook-likebox' ),
            'copiedText'           => __( 'Copied!', 'easy-facebook-likebox' ),
            'dashboardText'        => __( 'Open dashboard', 'easy-facebook-likebox' ),
            'noFeedsText'          => __( 'No feeds available. Create one in the dashboard first.', 'easy-facebook-likebox' ),
            'noPostText'           => __( 'This shortcode is not inside editable page content. Copy the new shortcode and paste it where needed.', 'easy-facebook-likebox' ),
            'previewErrorText'     => __( 'Could not load feed preview.', 'easy-facebook-likebox' ),
            'saveErrorText'        => __( 'Could not update the shortcode on this page.', 'easy-facebook-likebox' ),
            'replaceShortcodePath' => '/missing-feed/replace-shortcode',
        ) );
    }

}
