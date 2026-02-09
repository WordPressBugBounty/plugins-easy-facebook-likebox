<?php

/*
* Stop execution if someone tried to get file directly.
*/
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
//======================================================================
// Admin of Instagram Module
//======================================================================
if ( !class_exists( 'ESF_Instagram_Admin' ) ) {
    class ESF_Instagram_Admin {
        /**
         * Default HTTP args used for remote requests.
         *
         * Kept as a property so the same array instance can be reused
         * and adjusted in one place if needed.
         *
         * @var array
         */
        protected $http_args = array(
            'timeout'   => 150,
            'sslverify' => false,
        );

        function __construct() {
            add_action( 'admin_menu', array($this, 'esf_insta_menu'), 100 );
            add_action( 'admin_enqueue_scripts', array($this, 'esf_insta_style') );
            add_action( 'wp_ajax_mif_remove_access_token', array($this, 'esf_insta_remove_access_token') );
            add_action( 'wp_ajax_mif_save_access_token', array($this, 'esf_insta_save_access_token') );
            add_action( 'wp_ajax_mif_save_business_access_token', array($this, 'esf_insta_save_business_access_token') );
            add_action( 'wp_ajax_esf_insta_create_skin_url', array($this, 'esf_insta_create_skin_url') );
            add_action( 'wp_ajax_mif_delete_transient', array($this, 'esf_insta_delete_transient') );
            add_action( 'wp_ajax_mif_clear_all_cache', array($this, 'clear_all_cache') );
            add_action( 'wp_ajax_mif_get_moderate_feed', array($this, 'esf_insta_get_moderate_feed') );
            add_action( 'wp_ajax_mif_get_shoppable_feed', array($this, 'get_shoppable_feed') );
            add_action( 'wp_ajax_mif_preload_feed', array($this, 'preload_feed') );
        }

        /*
         * esf_insta_style will enqueue style and js files.
         * Returns hook name of the current page in admin.
         * $hook will contain the hook name.
         */
        public function esf_insta_style( $hook ) {
            if ( 'easy-social-feed_page_mif' !== $hook ) {
                return;
            }
            wp_enqueue_style( 'select2', ESF_INSTA_PLUGIN_URL . 'admin/assets/css/select2.min.css' );
            wp_enqueue_script( 'select2', ESF_INSTA_PLUGIN_URL . 'admin/assets/js/select2.min.js', array('jquery') );
            wp_enqueue_style( 'esf-insta-admin-style', ESF_INSTA_PLUGIN_URL . 'admin/assets/css/esf-insta-admin-style.css' );
            wp_enqueue_style( 'esf-insta-frontend', ESF_INSTA_PLUGIN_URL . 'frontend/assets/css/esf-insta-frontend.css' );
            wp_enqueue_script( 'esf-insta-admin-script', ESF_INSTA_PLUGIN_URL . 'admin/assets/js/esf-insta-admin-script.js', array('jquery', 'esf-admin') );
            $FTA = new Feed_Them_All();
            $fta_settings = $FTA->fta_get_settings();
            $default_skin_id = $fta_settings['plugins']['instagram']['default_skin_id'];
            wp_localize_script( 'esf-insta-admin-script', 'mif', array(
                'ajax_url'        => admin_url( 'admin-ajax.php' ),
                'nonce'           => wp_create_nonce( 'esf-ajax-nonce' ),
                'copied'          => __( 'Copied', 'easy-facebook-likebox' ),
                'error'           => __( 'Something went wrong!', 'easy-facebook-likebox' ),
                'saving'          => __( 'Saving', 'easy-facebook-likebox' ),
                'deleting'        => __( 'Deleting', 'easy-facebook-likebox' ),
                'generating'      => __( 'Generating Shortcode', 'easy-facebook-likebox' ),
                'connect_account' => __( 'Please connect your Instagram account with plugin', 'easy-facebook-likebox' ),
                'moderate_wait'   => __( 'Please wait, we are generating preview for you', 'easy-facebook-likebox' ),
                'default_skin_id' => $default_skin_id,
            ) );
            wp_enqueue_script( 'media-upload' );
            wp_enqueue_media();
        }

        /*
         * Adds Instagram sub-menu in dashboard
         */
        function esf_insta_menu() {
            if ( efl_fs()->is_free_plan() ) {
                $menu_position = 2;
            } else {
                $menu_position = null;
            }
            add_submenu_page(
                'feed-them-all',
                __( 'Instagram', 'easy-facebook-likebox' ),
                __( 'Instagram', 'easy-facebook-likebox' ),
                'manage_options',
                'mif',
                array($this, 'esf_insta_page'),
                $menu_position
            );
        }

        /*
         * esf_insta_page contains the html/markup of the Instagram page.
         */
        function esf_insta_page() {
            /**
             * Instagram page view.
             */
            include_once ESF_INSTA_PLUGIN_DIR . 'admin/views/html-admin-page-mif.php';
        }

        /*
         * Returns the Skin URL
         */
        function esf_insta_create_skin_url() {
            esf_check_ajax_referer();
            $skin_id = intval( $_POST['skin_id'] );
            $selectedVal = intval( $_POST['selectedVal'] );
            $page_id = intval( $_POST['page_id'] );
            $page_permalink = get_permalink( $page_id );
            $customizer_url = admin_url( 'customize.php' );
            if ( isset( $page_permalink ) ) {
                $customizer_url = add_query_arg( array(
                    'url'              => urlencode( $page_permalink ),
                    'autofocus[panel]' => 'mif_customize_panel',
                    'mif_skin_id'      => $skin_id,
                    'mif_customize'    => 'yes',
                    'mif_account_id'   => $selectedVal,
                ), $customizer_url );
            }
            wp_send_json_success( array(__( 'Please wait! We are generating a preview for you', 'easy-facebook-likebox' ), $customizer_url) );
        }

        /*
         * Deletes the cache
         */
        function esf_insta_delete_transient() {
            esf_check_ajax_referer();
            $transient_id = sanitize_text_field( $_POST['transient_id'] );
            $replaced_value = str_replace( '_transient_', '', $transient_id );
            // Delete feed media from local server.
            if ( esf_safe_strpos( $replaced_value, 'posts' ) !== false ) {
                $feed = get_transient( $replaced_value );
                if ( $feed ) {
                    $feed = json_decode( $feed );
                    if ( isset( $feed->data ) ) {
                        foreach ( $feed->data as $post ) {
                            esf_delete_media( $post->id, 'instagram' );
                            if ( isset( $post->media_type ) && isset( $post->children->data ) && $post->media_type == 'CAROUSEL_ALBUM' ) {
                                foreach ( $post->children->data as $feed_carousel ) {
                                    esf_delete_media( $feed_carousel->id, 'instagram' );
                                }
                            }
                        }
                    }
                }
            }
            $mif_deleted_trans = delete_transient( $replaced_value );
            $str = explode( '-', $replaced_value );
            if ( isset( $str[1] ) ) {
                delete_transient( 'esf_insta_logo_' . $str[1] );
                esf_delete_media( $str[1], 'instagram' );
            }
            if ( isset( $mif_deleted_trans ) ) {
                $returned_arr = array(__( 'Cache is successfully deleted.', 'easy-facebook-likebox' ), $transient_id);
                wp_send_json_success( $returned_arr );
            } else {
                wp_send_json_error( __( 'Something Went Wrong! Please try again.', 'easy-facebook-likebox' ) );
            }
        }

        /**
         * Delete all cached data
         *
         * @since 6.3.2
         */
        function clear_all_cache() {
            esf_check_ajax_referer();
            $cache = $this->get_cache( 'all' );
            if ( $cache ) {
                foreach ( $cache as $id => $single ) {
                    $transient_name = str_replace( '_transient_', '', $id );
                    $str = explode( '-', $id );
                    if ( isset( $str[1] ) ) {
                        delete_transient( 'esf_insta_logo_' . $str[1] );
                        esf_delete_media( $str[1], 'instagram' );
                    }
                    $mif_deleted_trans = delete_transient( $transient_name );
                }
                esf_delete_media_folder( 'instagram' );
            }
            if ( isset( $mif_deleted_trans ) ) {
                wp_send_json_success( __( 'Cache is successfully deleted.', 'easy-facebook-likebox' ) );
            } else {
                wp_send_json_error( __( 'Something Went Wrong! Please try again.', 'easy-facebook-likebox' ) );
            }
        }

        /*
         * Get the image ID by URL
         */
        function mif_get_image_id( $image_url ) {
            global $wpdb;
            $attachment = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE guid='%s';", $image_url ) );
            return $attachment[0];
        }

        /*
         * Gets the remote URL and sends back the json decoded data
         */
        public function esf_insta_get_data( $url ) {
            /*
             * Getting the data from remote URL.
             */
            $json_data = wp_remote_retrieve_body( wp_remote_get( $url ) );
            /*
             * Decoding the data.
             */
            $decoded_data = json_decode( $json_data );
            /*
             * Returning it to back.
             */
            return $decoded_data;
        }

        /*
         *  Return the user ID from access token.
         */
        public function mif_get_user_id( $access_token ) {
            $access_token_exploded = explode( '.', $access_token );
            return $access_token_exploded['0'];
        }

        /*
         *  Return the user name from access token.
         */
        public function mif_get_user_name( $access_token ) {
            $FTA = new Feed_Them_All();
            $fta_settings = $FTA->fta_get_settings();
            $authenticated_accounts = $fta_settings['plugins']['instagram']['authenticated_accounts'];
            $mif_user_id = $this->mif_get_user_id( $access_token );
            return $authenticated_accounts[$mif_user_id]['username'];
        }

        /*
         * Gets the access token, autenticate it and save it to DB.
         */
        public function esf_insta_save_access_token() {
            esf_check_ajax_referer();
            $access_token = sanitize_text_field( $_POST['access_token'] );
            $mif_accounts_html = '';
            $self_data = "https://graph.instagram.com/me?fields=id,username&access_token={$access_token}";
            $self_decoded_data = $this->esf_insta_get_data( $self_data );
            if ( isset( $self_decoded_data->error ) && !empty( $self_decoded_data->error ) ) {
                wp_send_json_error( $self_decoded_data->error->message );
            } elseif ( isset( $self_decoded_data ) && !empty( $self_decoded_data ) ) {
                $FTA = new Feed_Them_All();
                $fta_settings = $FTA->fta_get_settings();
                $mif_accounts_html .= '<ul class="collection with-header"> <li class="collection-header"><h5>' . __( 'Connected Instagram Account', 'easy-facebook-likebox' ) . '</h5> 
				<a href="#fta-remove-at" class="modal-trigger fta-remove-at-btn tooltipped" data-type="personal" data-position="left" data-delay="50" data-tooltip="' . __( 'Delete Access Token', 'easy-facebook-likebox' ) . '"><span class="dashicons dashicons-trash"></span></a></li>
				<li class="collection-item li-' . $self_decoded_data->id . '">
				 <div class="esf-bio-wrap">    
						  <span class="title">' . $self_decoded_data->username . '</span>
						  <p>' . __( 'ID:', 'easy-facebook-likebox' ) . ' ' . $self_decoded_data->id . ' <span class="dashicons dashicons-admin-page efbl_copy_id tooltipped" data-position="right" data-clipboard-text="' . $self_decoded_data->id . '" data-delay="100" data-tooltip="' . __( 'Copy', 'easy-facebook-likebox' ) . '"></span></p>
			   </div>
				</li>
			</ul>';
                $fta_settings['plugins']['instagram']['instagram_connected_account'][$self_decoded_data->id];
                $fta_settings['plugins']['instagram']['instagram_connected_account'][$self_decoded_data->id]['username'] = $self_decoded_data->username;
                $fta_settings['plugins']['instagram']['instagram_connected_account'][$self_decoded_data->id]['access_token'] = $access_token;
                $fta_settings['plugins']['instagram']['selected_type'] = 'personal';
                $mif_saved = update_option( 'fta_settings', $fta_settings );
                if ( isset( $mif_saved ) ) {
                    wp_send_json_success( array(__( 'Successfully Authenticated!', 'easy-facebook-likebox' ), $mif_accounts_html) );
                } else {
                    wp_send_json_error( __( 'Something went wrong! Refresh the page and try again', 'easy-facebook-likebox' ) );
                }
            } else {
                wp_send_json_error( __( 'Something went wrong! Refresh the page and try again', 'easy-facebook-likebox' ) );
            }
        }

        /**
         * Handle Instagram Business access token save.
         *
         * Validates the request, fetches connected Facebook pages and their
         * Instagram Business accounts from the Graph API, updates plugin
         * settings, and returns the rendered "Connected Instagram Accounts"
         * HTML as a JSON response.
         *
         * @return void Outputs a JSON response and exits.
         */
        public function esf_insta_save_business_access_token() {
            // Verify AJAX nonce when available.
            if ( function_exists( 'esf_check_ajax_referer' ) ) {
                esf_check_ajax_referer();
            }
            // Capability check.
            if ( !(current_user_can( 'editor' ) || current_user_can( 'administrator' )) ) {
                wp_send_json_error( __( 'You do not have permission to perform this action.', 'easy-facebook-likebox' ) );
            }
            $access_token = ( isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : '' );
            $connection_type = ( isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '' );
            if ( '' === $access_token || '' === $connection_type ) {
                wp_send_json_error( __( 'Invalid request. Missing required parameters.', 'easy-facebook-likebox' ) );
            }
            // Reuse a shared set of HTTP arguments for all remote requests.
            $http_args = $this->http_args;
            $pages_api_url = 'https://graph.facebook.com/me/accounts?fields=access_token,username,id,name,fan_count,category,about&access_token=' . $access_token;
            $pages_api_response = wp_remote_get( $pages_api_url, $http_args );
            if ( is_wp_error( $pages_api_response ) ) {
                $error_msg = $pages_api_response->get_error_message();
                if ( !$error_msg ) {
                    $error_msg = __( 'Something went wrong! Refresh the page and try again', 'easy-facebook-likebox' );
                }
                wp_send_json_error( $error_msg );
            }
            if ( !is_array( $pages_api_response ) ) {
                wp_send_json_error( __( 'Unexpected response from Facebook.', 'easy-facebook-likebox' ) );
            }
            $pages_body = wp_remote_retrieve_body( $pages_api_response );
            if ( '' === $pages_body ) {
                wp_send_json_error( __( 'Empty response from Facebook.', 'easy-facebook-likebox' ) );
            }
            $pages_data = json_decode( $pages_body );
            if ( empty( $pages_data ) || !isset( $pages_data->data ) || !is_array( $pages_data->data ) || empty( $pages_data->data ) ) {
                wp_send_json_error( __( 'No page found', 'easy-facebook-likebox' ) );
            }
            $approved_pages = array();
            $title = __( 'Connected Instagram Accounts', 'easy-facebook-likebox' );
            $efbl_all_pages_html = '<ul class="collection with-header"> <li class="collection-header"><h5>' . esc_html( $title ) . '</h5> ';
            $efbl_all_pages_html .= '<a href="#fta-remove-at" class="modal-trigger fta-remove-at-btn tooltipped" data-position="left" data-delay="50" data-tooltip="' . esc_attr__( 'Delete Access Token', 'easy-facebook-likebox' ) . '"><span class="dashicons dashicons-trash"></span></a></li>';
            // Collect list items separately for slightly more efficient concatenation.
            $efbl_list_items = array();
            // In-request logo cache to avoid repeated remote calls for the same Instagram ID.
            $logo_cache = array();
            foreach ( $pages_data->data as $page ) {
                if ( !isset( $page->id, $page->access_token ) ) {
                    continue;
                }
                $page_id = $page->id;
                $page_token = $page->access_token;
                $instagram_accounts_api_url = 'https://graph.facebook.com/v4.0/' . rawurlencode( $page_id ) . '/?fields=connected_instagram_account,instagram_accounts{username}&access_token=' . $page_token;
                $instagram_accounts_api_response = wp_remote_get( $instagram_accounts_api_url, $http_args );
                if ( is_wp_error( $instagram_accounts_api_response ) || !is_array( $instagram_accounts_api_response ) ) {
                    continue;
                }
                $instagram_accounts_body = wp_remote_retrieve_body( $instagram_accounts_api_response );
                if ( '' === $instagram_accounts_body ) {
                    continue;
                }
                $instagram_accounts = json_decode( $instagram_accounts_body );
                $instagram_business_account = null;
                if ( isset( $instagram_accounts->connected_instagram_account ) && !empty( $instagram_accounts->connected_instagram_account->id ) ) {
                    $instagram_account_id = $instagram_accounts->connected_instagram_account->id;
                    $instagram_account_api_url = 'https://graph.facebook.com/v4.0/' . rawurlencode( $instagram_account_id ) . '/?fields=name,profile_picture_url,ig_id,username&access_token=' . $page_token;
                    $instagram_account_api_result = wp_remote_get( $instagram_account_api_url, $http_args );
                    if ( is_array( $instagram_account_api_result ) && !is_wp_error( $instagram_account_api_result ) ) {
                        $instagram_account_body = wp_remote_retrieve_body( $instagram_account_api_result );
                        if ( '' !== $instagram_account_body ) {
                            $instagram_business_account = json_decode( $instagram_account_body );
                        }
                    }
                }
                if ( 'insta' === $connection_type && is_object( $instagram_business_account ) && !empty( $instagram_business_account->ig_id ) ) {
                    $instagram_ig_id = $instagram_business_account->ig_id;
                    $username = ( isset( $instagram_business_account->username ) ? $instagram_business_account->username : '' );
                    $display_name = ( isset( $instagram_business_account->name ) ? $instagram_business_account->name : '' );
                    $instagram_user_id = ( isset( $instagram_business_account->id ) ? $instagram_business_account->id : '' );
                    $logo_trasneint_name = 'esf_insta_logo_' . $instagram_ig_id;
                    // Try to use in-request cache first.
                    if ( isset( $logo_cache[$instagram_ig_id] ) ) {
                        $auth_img_src = $logo_cache[$instagram_ig_id];
                    } else {
                        $auth_img_src = get_transient( $logo_trasneint_name );
                        // If a local/transient URL exists but is no longer valid (404),
                        // treat it as empty so we can regenerate or fall back gracefully.
                        if ( !empty( $auth_img_src ) && function_exists( 'esf_is_valid_image_url' ) && !esf_is_valid_image_url( $auth_img_src ) ) {
                            $auth_img_src = '';
                        }
                        if ( empty( $auth_img_src ) ) {
                            $auth_img_src_url = 'https://graph.facebook.com/' . rawurlencode( $page_id ) . '/picture?type=large&redirect=0&access_token=' . $access_token;
                            $auth_img_src_response = wp_remote_get( $auth_img_src_url, $http_args );
                            if ( is_array( $auth_img_src_response ) && !is_wp_error( $auth_img_src_response ) ) {
                                $auth_img_src_body = wp_remote_retrieve_body( $auth_img_src_response );
                                if ( '' !== $auth_img_src_body ) {
                                    $auth_img_src_decoded = json_decode( $auth_img_src_body );
                                    if ( isset( $auth_img_src_decoded->data->url ) && !empty( $auth_img_src_decoded->data->url ) ) {
                                        $auth_img_src = $auth_img_src_decoded->data->url;
                                        $local_logo_url = esf_serve_media_locally( $instagram_ig_id, $auth_img_src, 'instagram' );
                                        if ( $local_logo_url ) {
                                            $auth_img_src = $local_logo_url;
                                        }
                                        set_transient( $logo_trasneint_name, $auth_img_src, 30 * 60 * 60 * 24 );
                                    }
                                }
                            }
                        }
                        // Fallback to Instagram profile picture if page picture failed or is empty.
                        if ( empty( $auth_img_src ) && isset( $instagram_business_account->profile_picture_url ) && !empty( $instagram_business_account->profile_picture_url ) ) {
                            $auth_img_src = $instagram_business_account->profile_picture_url;
                        }
                        // Cache the result for this request (including empty string).
                        $logo_cache[$instagram_ig_id] = $auth_img_src;
                    }
                    if ( '' !== $instagram_user_id ) {
                        $efbl_list_items[] = sprintf(
                            '<li class="collection-item avatar fta_insta_connected_account li-%1$s">
					 
					<a href="https://www.instagram.com/%2$s" target="_blank">
							  <img src="%3$s" alt="" class="circle">
					</a>  
					<div class="esf-bio-wrap">        
							  <span class="title">%4$s</span>
							 <p>%5$s <br> %6$s %7$s <span class="dashicons dashicons-admin-page efbl_copy_id tooltipped" data-position="right" data-clipboard-text="%7$s" data-delay="100" data-tooltip="%8$s"></span></p></div>
					 </li>',
                            esc_attr( $instagram_ig_id ),
                            esc_attr( $username ),
                            esc_url( $auth_img_src ),
                            esc_html( $display_name ),
                            esc_html( $username ),
                            esc_html__( 'ID:', 'easy-facebook-likebox' ),
                            esc_html( $instagram_user_id ),
                            esc_attr__( 'Copy', 'easy-facebook-likebox' )
                        );
                    }
                }
                // Store raw data for settings so other parts of the plugin keep working.
                $page_array = (array) $page;
                $approved_pages[$page_array['id']] = $page_array;
                $approved_pages[$page_array['id']]['instagram_accounts'] = ( isset( $instagram_accounts ) ? $instagram_accounts : null );
                $approved_pages[$page_array['id']]['instagram_connected_account'] = ( isset( $instagram_business_account ) ? $instagram_business_account : null );
            }
            // Append any collected list items to the wrapper.
            if ( !empty( $efbl_list_items ) ) {
                $efbl_all_pages_html .= implode( '', $efbl_list_items );
            }
            $efbl_all_pages_html .= '</ul>';
            $feed_them_all = new Feed_Them_All();
            $settings = $feed_them_all->fta_get_settings();
            $settings['plugins']['facebook']['approved_pages'] = $approved_pages;
            $settings['plugins']['facebook']['access_token'] = $access_token;
            $author_api_url = 'https://graph.facebook.com/me?fields=id,name&access_token=' . $access_token;
            $author_api_response = wp_remote_get( $author_api_url, $http_args );
            if ( is_array( $author_api_response ) && !is_wp_error( $author_api_response ) ) {
                $author_body = wp_remote_retrieve_body( $author_api_response );
                if ( '' !== $author_body ) {
                    $author_data = json_decode( $author_body );
                    $settings['plugins']['facebook']['author'] = $author_data;
                }
            }
            $settings['plugins']['instagram']['selected_type'] = 'business';
            update_option( 'fta_settings', $settings );
            wp_send_json_success( array(__( 'Successfully Authenticated!', 'easy-facebook-likebox' ), $efbl_all_pages_html) );
        }

        /*
         * Removes the access token
         */
        function esf_insta_remove_access_token() {
            esf_check_ajax_referer();
            $Feed_Them_All = new Feed_Them_All();
            $fta_settings = $Feed_Them_All->fta_get_settings();
            unset($fta_settings['plugins']['instagram']['instagram_connected_account']);
            $fta_settings['plugins']['instagram']['selected_type'] = 'business';
            $delted_data = update_option( 'fta_settings', $fta_settings );
            if ( isset( $delted_data ) ) {
                echo wp_send_json_success( __( 'Deleted', 'easy-facebook-likebox' ) );
                wp_die();
            } else {
                echo wp_send_json_error( __( 'Something Went Wrong! Please try again.', 'easy-facebook-likebox' ) );
                wp_die();
            }
        }

        /**
         * Get moderate tab data and render shortcode to get a preview
         *
         * @since 6.2.3
         */
        public function esf_insta_get_moderate_feed() {
            esf_check_ajax_referer();
            $user_id = intval( $_POST['user_id'] );
            $clear_cache = sanitize_text_field( $_POST['clear_cache'] );
            global $mif_skins;
            $skin_id = '';
            if ( isset( $mif_skins ) ) {
                foreach ( $mif_skins as $skin ) {
                    if ( $skin['layout'] == 'grid' ) {
                        $skin_id = $skin['ID'];
                    }
                }
            }
            if ( $clear_cache ) {
                $type = esf_insta_instagram_type();
                $transient_name = "esf_insta_user_posts-{$user_id}-30-{$type}-1-d";
                delete_transient( $transient_name );
            }
            $shortcode = '[my-instagram-feed user_id="' . $user_id . '" is_moderate="true" skin_id="' . $skin_id . '" words_limit="25" feeds_per_page="30" links_new_tab="1"]';
            wp_send_json_success( do_shortcode( $shortcode ) );
        }

        /**
         * Get Shoppable tab data and render shortcode to get a preview
         *
         * @since 6.4.5
         */
        public function get_shoppable_feed() {
            esf_check_ajax_referer();
            $user_id = intval( $_POST['user_id'] );
            $clear_cache = sanitize_text_field( $_POST['clear_cache'] );
            global $mif_skins;
            $FTA = new Feed_Them_All();
            $fta_settings = $FTA->fta_get_settings();
            $skin_id = '';
            if ( isset( $mif_skins ) ) {
                foreach ( $mif_skins as $skin ) {
                    if ( $skin['layout'] == 'grid' ) {
                        $skin_id = $skin['ID'];
                    }
                }
            }
            if ( $clear_cache ) {
                $type = esf_insta_instagram_type();
                $transient_name = "esf_insta_user_posts-{$user_id}-30-{$type}-1-d";
                delete_transient( $transient_name );
            }
            $link_text = '';
            $click_behaviour = '';
            $global_settings = array(
                'link_text'       => $link_text,
                'click_behaviour' => $click_behaviour,
            );
            $shortcode = '[my-instagram-feed user_id="' . $user_id . '" is_shoppable="true" skin_id="' . $skin_id . '" words_limit="25" feeds_per_page="30" links_new_tab="1"]';
            wp_send_json_success( array(
                'html'            => do_shortcode( $shortcode ),
                'global_settings' => $global_settings,
            ) );
        }

        /**
         * Preload feed data to cache
         *
         * @since 6.4.4
         */
        public function preload_feed() {
            esf_check_ajax_referer();
            if ( isset( $_POST['shortcode'] ) && !empty( $_POST['shortcode'] ) ) {
                $shortcode = wp_kses_stripslashes( sanitize_text_field( $_POST['shortcode'] ) );
                do_shortcode( $shortcode );
                wp_send_json_success();
            } else {
                wp_send_json_error();
            }
        }

        /**
         * Return Plugin cache data
         *
         * @since 6.2.3
         *
         * @param string $type
         *
         * @return array
         */
        public function get_cache( $type = 'posts' ) {
            global $wpdb;
            $mif_trans_sql = "SELECT `option_name` AS `name`, `option_value` AS `value` FROM  {$wpdb->options} WHERE `option_name` LIKE '%transient_%' ORDER BY `option_name`";
            $mif_trans_results = $wpdb->get_results( $mif_trans_sql );
            $mif_trans_posts = array();
            $mif_trans_bio = array();
            $mif_trans_stories = array();
            $all_cache = array();
            if ( $mif_trans_results ) {
                foreach ( $mif_trans_results as $mif_trans_result ) {
                    if ( esf_safe_strpos( $mif_trans_result->name, 'esf_insta' ) !== false && esf_safe_strpos( $mif_trans_result->name, 'posts' ) !== false && esf_safe_strpos( $mif_trans_result->name, 'timeout' ) == false ) {
                        $mif_trans_posts[$mif_trans_result->name] = $mif_trans_result->value;
                    }
                    if ( esf_safe_strpos( $mif_trans_result->name, 'esf_insta' ) !== false && esf_safe_strpos( $mif_trans_result->name, 'stories' ) !== false && esf_safe_strpos( $mif_trans_result->name, 'timeout' ) == false ) {
                        $mif_trans_stories[$mif_trans_result->name] = $mif_trans_result->value;
                    }
                    if ( esf_safe_strpos( $mif_trans_result->name, 'esf_insta' ) !== false && esf_safe_strpos( $mif_trans_result->name, 'bio' ) !== false && esf_safe_strpos( $mif_trans_result->name, 'timeout' ) == false ) {
                        $mif_trans_bio[$mif_trans_result->name] = $mif_trans_result->value;
                    }
                }
            }
            if ( $type == 'bio' ) {
                $cache = $mif_trans_bio;
            }
            if ( $type == 'stories' ) {
                $cache = $mif_trans_stories;
            }
            if ( $type == 'posts' ) {
                $cache = $mif_trans_posts;
            }
            if ( $type == 'all' ) {
                $cache = array_merge( $mif_trans_bio, $mif_trans_stories, $mif_trans_posts );
            }
            return $cache;
        }

    }

    $ESF_Instagram_Admin = new ESF_Instagram_Admin();
}