<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Register Facebook blocks
 *
 * @since 6.7.9
 */
if ( !class_exists( 'ESF_FB_Blocks' ) ) {
    class ESF_FB_Blocks {
        function __construct() {
            $this->includes();
            add_action( 'enqueue_block_editor_assets', array($this, 'enqueue_block_editor_assets') );
        }

        /**
         * Get the list of blocks
         *
         * @return array
         * @since 6.7.9
         */
        public function blocks_list() {
            $list = array('halfwidth', 'fullwidth', 'thumbnail');
            return apply_filters( 'esf_fb_blocks_list', $list );
        }

        /**
         * Include all required files
         *
         * @since 6.7.9
         */
        public function includes() {
            // get the list of blocks
            $blocks = $this->blocks_list();
            // Register all blocks
            if ( $blocks ) {
                foreach ( $blocks as $block ) {
                    register_block_type( EFBL_PLUGIN_DIR . 'includes/blocks/build/' . $block, $this->block_attributes( $block ) );
                }
            }
        }

        /**
         * Enqueue block editor scripts and preview styles.
         *
         * Modern mode never loads `Easy_Facebook_Likebox`; instantiating it
         * here fatals on every Gutenberg screen. Preview CSS then comes from
         * the modern feed assets. Inspector pages prefer `esf_facebook_pages`
         * in the legacy `approved_pages` shape (`id` → `{ name }`).
         *
         * @since 6.7.9
         * @return void
         */
        public function enqueue_block_editor_assets() {
            $blocks = $this->blocks_list();
            if ( $blocks ) {
                if ( class_exists( 'Easy_Facebook_Likebox' ) ) {
                    $efbl = new Easy_Facebook_Likebox();
                    $efbl->enqueue_styles();
                    $efbl->enqueue_scripts();
                } elseif ( function_exists( 'esf_facebook_enqueue_block_editor_preview_assets' ) ) {
                    esf_facebook_enqueue_block_editor_preview_assets();
                }
                $settings = get_option( 'fta_settings', false );
                $pages = false;
                $groups = false;
                if ( function_exists( 'esf_facebook_get_block_editor_pages' ) ) {
                    $pages = esf_facebook_get_block_editor_pages();
                }
                if ( isset( $settings['plugins']['facebook'] ) ) {
                    $settings = $settings['plugins']['facebook'];
                    if ( false === $pages && isset( $settings['approved_pages'] ) && !empty( $settings['approved_pages'] ) ) {
                        $pages = $settings['approved_pages'];
                    }
                    if ( isset( $settings['approved_groups'] ) && !empty( $settings['approved_groups'] ) ) {
                        $groups = $settings['approved_groups'];
                        $result = array();
                        foreach ( $groups as $value ) {
                            if ( is_object( $value ) && isset( $value->id ) ) {
                                $result[$value->id] = $value;
                            }
                        }
                        $groups = $result;
                    }
                } else {
                    $settings = false;
                }
                foreach ( $blocks as $block ) {
                    wp_enqueue_script(
                        'esf-fb-' . $block . '-editor-script',
                        EFBL_PLUGIN_URL . 'includes/blocks/src/' . $block . '/edit.js',
                        array(),
                        '1.0',
                        true
                    );
                    wp_localize_script( 'esf-fb-' . $block . '-editor-script', 'esfFBBlockData', array(
                        'ajax_url' => admin_url( 'admin-ajax.php' ),
                        'settings' => $settings,
                        'pages'    => $pages,
                        'groups'   => $groups,
                        'adminUrl' => admin_url(),
                        'nonce'    => wp_create_nonce( 'efbl-ajax-nonce' ),
                    ) );
                }
            }
        }

        /**
         * Define block attributes
         *
         * @return array
         */
        public function block_attributes( $block = 'halfwidth' ) {
            $attributes = array();
            if ( 'halfwidth' === $block ) {
                $attributes = apply_filters( 'esf/fb/halfwidth/options', array(
                    'icon'            => 'facebook',
                    'title'           => 'ESF FB: Half Width',
                    'description'     => __( 'Display Facebook Page Feed in Half Width', 'easy-facebook-likebox' ),
                    'attributes'      => array(
                        'skin'           => array(
                            'type'    => 'string',
                            'default' => 'half',
                        ),
                        'fanpage_id'     => array(
                            'type' => 'string',
                        ),
                        'type'           => array(
                            'type'    => 'string',
                            'default' => '',
                        ),
                        'words_limit'    => array(
                            'type'    => 'number',
                            'default' => 25,
                        ),
                        'links_new_tab'  => array(
                            'type'    => 'number',
                            'default' => 1,
                        ),
                        'post_limit'     => array(
                            'type'    => 'number',
                            'default' => 10,
                        ),
                        'cache_unit'     => array(
                            'type'    => 'number',
                            'default' => 1,
                        ),
                        'cache_duration' => array(
                            'type'    => 'string',
                            'default' => 'days',
                        ),
                        'show_like_box'  => array(
                            'type'    => 'number',
                            'default' => 0,
                        ),
                        'filter'         => array(
                            'type'    => 'string',
                            'default' => '',
                        ),
                        'events_filter'  => array(
                            'type'    => 'string',
                            'default' => '',
                        ),
                        'album_id'       => array(
                            'type'    => 'string',
                            'default' => '',
                        ),
                    ),
                    'render_callback' => array($this, 'load_block_html'),
                ) );
            } elseif ( 'fullwidth' === $block ) {
                $attributes = apply_filters( 'esf/fb/fullwidth/options', array(
                    'icon'            => 'facebook',
                    'title'           => 'ESF FB: Full Width',
                    'description'     => __( 'Display Facebook Page Feed in Full Width', 'easy-facebook-likebox' ),
                    'attributes'      => array(
                        'skin'           => array(
                            'type'    => 'string',
                            'default' => 'full',
                        ),
                        'fanpage_id'     => array(
                            'type' => 'string',
                        ),
                        'type'           => array(
                            'type'    => 'string',
                            'default' => '',
                        ),
                        'words_limit'    => array(
                            'type'    => 'number',
                            'default' => 25,
                        ),
                        'links_new_tab'  => array(
                            'type'    => 'number',
                            'default' => 1,
                        ),
                        'post_limit'     => array(
                            'type'    => 'number',
                            'default' => 10,
                        ),
                        'cache_unit'     => array(
                            'type'    => 'number',
                            'default' => 1,
                        ),
                        'cache_duration' => array(
                            'type'    => 'string',
                            'default' => 'days',
                        ),
                        'show_like_box'  => array(
                            'type'    => 'number',
                            'default' => 0,
                        ),
                        'filter'         => array(
                            'type'    => 'string',
                            'default' => '',
                        ),
                        'events_filter'  => array(
                            'type'    => 'string',
                            'default' => '',
                        ),
                        'album_id'       => array(
                            'type'    => 'string',
                            'default' => '',
                        ),
                    ),
                    'render_callback' => array($this, 'load_block_html'),
                ) );
            } elseif ( 'thumbnail' === $block ) {
                $attributes = apply_filters( 'esf/fb/thumbnail/options', array(
                    'icon'            => 'facebook',
                    'title'           => 'ESF FB: Thumbnail',
                    'description'     => __( 'Display Facebook Page Feed in Thumbnail', 'easy-facebook-likebox' ),
                    'attributes'      => array(
                        'skin'           => array(
                            'type'    => 'string',
                            'default' => 'thumbnail',
                        ),
                        'fanpage_id'     => array(
                            'type' => 'string',
                        ),
                        'type'           => array(
                            'type'    => 'string',
                            'default' => '',
                        ),
                        'words_limit'    => array(
                            'type'    => 'number',
                            'default' => 25,
                        ),
                        'links_new_tab'  => array(
                            'type'    => 'number',
                            'default' => 1,
                        ),
                        'post_limit'     => array(
                            'type'    => 'number',
                            'default' => 10,
                        ),
                        'cache_unit'     => array(
                            'type'    => 'number',
                            'default' => 1,
                        ),
                        'cache_duration' => array(
                            'type'    => 'string',
                            'default' => 'days',
                        ),
                        'show_like_box'  => array(
                            'type'    => 'number',
                            'default' => 0,
                        ),
                        'filter'         => array(
                            'type'    => 'string',
                            'default' => '',
                        ),
                        'events_filter'  => array(
                            'type'    => 'string',
                            'default' => '',
                        ),
                        'album_id'       => array(
                            'type'    => 'string',
                            'default' => '',
                        ),
                    ),
                    'render_callback' => array($this, 'load_block_html'),
                ) );
            }
            return $attributes;
        }

        /**
         * Load carousel block html
         *
         * @param $attributes
         *
         * @return false|string
         * @since 6.7.9
         */
        public function load_block_html( $attributes ) {
            $attributes = ( is_array( $attributes ) ? $attributes : array() );
            /*
             * After migration (modern mode): resolve fanpage_id through the legacy
             * feed map without changing block attributes stored in post content.
             */
            if ( function_exists( 'esf_facebook_use_new_system' ) && esf_facebook_use_new_system() && !empty( $attributes['fanpage_id'] ) ) {
                $feed_id = ( function_exists( 'esf_facebook_resolve_legacy_shortcode_feed_id' ) ? (int) esf_facebook_resolve_legacy_shortcode_feed_id( $attributes ) : 0 );
                if ( $feed_id > 0 ) {
                    $settings_override = array();
                    if ( isset( $attributes['show_like_box'] ) ) {
                        $settings_override['feed'] = array(
                            'show_like_box' => !empty( $attributes['show_like_box'] ),
                        );
                    }
                    if ( class_exists( 'ESF_Facebook_Frontend', false ) ) {
                        return ESF_Facebook_Frontend::get_instance()->render_shortcode( array(
                            'id'                        => $feed_id,
                            '_esf_fb_settings_override' => $settings_override,
                        ) );
                    }
                    if ( class_exists( 'ESF_Facebook_Renderer', false ) ) {
                        if ( function_exists( 'esf_facebook_flag_feed_script' ) ) {
                            esf_facebook_flag_feed_script();
                        }
                        return ( new ESF_Facebook_Renderer() )->render( $feed_id, $settings_override, true );
                    }
                    return do_shortcode( '[esf_facebook_feed id="' . $feed_id . '"]' );
                }
            }
            if ( !empty( $attributes['fanpage_id'] ) && isset( $attributes['skin'] ) ) {
                $attributes['skin_id'] = $this->get_skin_id( $attributes['skin'] );
                if ( class_exists( 'Easy_Facebook_Likebox' ) ) {
                    $efbl = new Easy_Facebook_Likebox();
                    return $efbl->render_fbfeed_box( $attributes );
                }
            }
            ob_start();
            require EFBL_PLUGIN_DIR . 'includes/blocks/templates/html-no-account-selected.php';
            $content = ob_get_contents();
            ob_end_clean();
            return $content;
        }

        /**
         * Get skin id
         *
         * @since 6.7.9
         * @return int|mixed|string
         */
        public function get_skin_id( $skin = 'half' ) {
            $skin_id = 0;
            $settings = get_option( 'fta_settings', false );
            if ( isset( $settings['plugins']['facebook']['default_skin_id'] ) && !empty( $settings['plugins']['facebook']['default_skin_id'] ) ) {
                $skin_id = $settings['plugins']['facebook']['default_skin_id'];
            }
            global $efbl_skins;
            if ( $efbl_skins ) {
                foreach ( $efbl_skins as $key => $value ) {
                    if ( $value['layout'] === $skin ) {
                        $skin_id = $key;
                        break;
                    }
                }
            }
            return $skin_id;
        }

    }

    new ESF_FB_Blocks();
}