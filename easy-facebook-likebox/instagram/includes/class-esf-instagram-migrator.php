<?php
/**
 * Instagram Legacy-to-Modern Migrator
 *
 * @package Easy_Social_Feed
 * @subpackage Instagram
 * @since 6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Instagram_Migrator
 *
 * @since 6.8.0
 */
class ESF_Instagram_Migrator {

	use ESF_Instagram_Singleton;

	/**
	 * Normalize Graph-style Instagram payload (Basic user, or Business object from a page).
	 *
	 * WordPress options may store nested objects as arrays or JSON strings.
	 *
	 * @param mixed $raw instagram_connected_account or similar.
	 * @return array{instagram_user_id:string,username:string,display_name:string,profile_image_url:string,payload:array}|null
	 */
	private function normalize_graph_ig_payload( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return null;
		}

		if ( is_string( $raw ) ) {
			$trim = trim( $raw );
			if ( '' === $trim ) {
				return null;
			}
			$decoded = json_decode( $trim, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				$obj = json_decode( $trim, false );
				if ( is_object( $obj ) ) {
					$raw = json_decode( wp_json_encode( $obj ), true );
				} else {
					return null;
				}
			} else {
				$raw = $decoded;
			}
		} elseif ( is_object( $raw ) ) {
			$raw = json_decode( wp_json_encode( $raw ), true );
		}

		if ( ! is_array( $raw ) ) {
			return null;
		}

		// Graph API node requests use the Instagram user object id (`id`). `ig_id` is a separate legacy id and breaks GET /{id} when used alone.
		$instagram_user_id = isset( $raw['id'] ) ? sanitize_text_field( (string) $raw['id'] ) : '';
		if ( '' === $instagram_user_id && isset( $raw['ig_id'] ) ) {
			$instagram_user_id = sanitize_text_field( (string) $raw['ig_id'] );
		}
		if ( '' === $instagram_user_id ) {
			return null;
		}

		$username = isset( $raw['username'] ) ? sanitize_text_field( (string) $raw['username'] ) : '';
		$name     = isset( $raw['name'] ) ? sanitize_text_field( (string) $raw['name'] ) : '';
		$display  = '' !== $name ? $name : $username;

		return array(
			'instagram_user_id' => $instagram_user_id,
			'username'          => $username,
			'display_name'      => $display,
			'profile_image_url' => isset( $raw['profile_picture_url'] ) ? esc_url_raw( (string) $raw['profile_picture_url'] ) : '',
			'payload'           => $raw,
		);
	}

	/**
	 * Instagram Graph user node id for GET /v…/{id} (prefer payload `id` over stored value when they differ).
	 *
	 * @param string               $stored_instagram_user_id Value saved on the account row.
	 * @param array<string,mixed>|null $payload                instagram_connected_account payload (optional).
	 * @return string Digits-only node id or empty string.
	 */
	private function instagram_graph_user_node_id_for_request( $stored_instagram_user_id, $payload = null ) {
		$stored = preg_replace( '/[^0-9]/', '', (string) $stored_instagram_user_id );
		if ( is_array( $payload ) && isset( $payload['id'] ) ) {
			$from_payload = preg_replace( '/[^0-9]/', '', (string) $payload['id'] );
			if ( '' !== $from_payload ) {
				return $from_payload;
			}
		}
		return $stored;
	}

	/**
	 * GET Instagram user node from Graph (biography, counts, etc.).
	 *
	 * @param string $page_access_token Page access token.
	 * @param string $node_id           Numeric Graph node id for the IG user.
	 * @return object|null Decoded body or null on transport/HTTP/Graph error.
	 */
	private function graph_get_instagram_user_node_body( $page_access_token, $node_id ) {
		$page_access_token = trim( (string) $page_access_token );
		$node_id           = preg_replace( '/[^0-9]/', '', (string) $node_id );
		if ( '' === $node_id || '' === $page_access_token ) {
			return null;
		}

		$url = add_query_arg(
			array(
				'fields'       => 'id,biography,followers_count,media_count,name,username,website,profile_picture_url',
				'access_token' => $page_access_token,
			),
			sprintf( 'https://graph.facebook.com/v18.0/%s', rawurlencode( $node_id ) )
		);

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), false );
		if ( ! is_object( $body ) || isset( $body->error ) ) {
			return null;
		}

		return $body;
	}

	/**
	 * Normalize a personal/basic row from instagram_connected_account.
	 *
	 * @param string               $legacy_id Legacy array key (Instagram user id).
	 * @param array<string,mixed>|object $legacy_account Stored account data.
	 * @return array<string,mixed>|null Normalized row or null if unusable.
	 */
	private function normalize_personal_account_row( $legacy_id, $legacy_account ) {
		$instagram_user_id = sanitize_text_field( (string) $legacy_id );
		if ( '' === $instagram_user_id ) {
			return null;
		}

		if ( is_object( $legacy_account ) ) {
			$legacy_account = json_decode( wp_json_encode( $legacy_account ), true );
		}
		if ( ! is_array( $legacy_account ) ) {
			$legacy_account = array();
		}

		$username     = isset( $legacy_account['username'] ) ? sanitize_text_field( (string) $legacy_account['username'] ) : '';
		$access_token = isset( $legacy_account['access_token'] ) ? sanitize_text_field( (string) $legacy_account['access_token'] ) : '';
		$profile_url  = '';
		if ( isset( $legacy_account['profile_picture_url'] ) ) {
			$profile_url = esc_url_raw( (string) $legacy_account['profile_picture_url'] );
		}

		return array(
			'instagram_user_id' => $instagram_user_id,
			'username'          => $username,
			'display_name'      => '' !== $username ? $username : $instagram_user_id,
			'access_token'      => $access_token,
			'profile_image_url' => $profile_url,
			'legacy_row'        => $legacy_account,
		);
	}

	/**
	 * Resolve Facebook user id from legacy fta_settings author blob.
	 *
	 * @param array<string,mixed> $fta_settings Full fta_settings option.
	 * @return string|null
	 */
	private function resolve_facebook_user_id( $fta_settings ) {
		$author = isset( $fta_settings['plugins']['facebook']['author'] )
			? $fta_settings['plugins']['facebook']['author']
			: null;
		if ( is_object( $author ) && isset( $author->id ) ) {
			return sanitize_text_field( (string) $author->id );
		}
		if ( is_array( $author ) && isset( $author['id'] ) ) {
			return sanitize_text_field( (string) $author['id'] );
		}
		return null;
	}

	/**
	 * Instagram user ids that are already stored under legacy Basic (instagram_connected_account
	 * and optional authenticated_accounts keys / id fields).
	 * Used to avoid the business loop overwriting the same IG id with account_type=business.
	 *
	 * @param array<string,mixed> $personal                Legacy instagram_connected_account array.
	 * @param array<string,mixed> $authenticated_accounts Legacy instagram authenticated_accounts (optional).
	 * @return array<string,bool>
	 */
	private function collect_basic_instagram_user_ids( $personal, $authenticated_accounts = array() ) {
		$ids = array();
		if ( is_array( $personal ) ) {
			foreach ( $personal as $legacy_key => $legacy_account ) {
				$key = sanitize_text_field( (string) $legacy_key );
				if ( '' !== $key ) {
					$ids[ $key ] = true;
				}
				if ( is_array( $legacy_account ) && isset( $legacy_account['id'] ) ) {
					$alt = sanitize_text_field( (string) $legacy_account['id'] );
					if ( '' !== $alt ) {
						$ids[ $alt ] = true;
					}
				}
			}
		}
		if ( is_array( $authenticated_accounts ) ) {
			foreach ( $authenticated_accounts as $legacy_key => $auth_row ) {
				$key = sanitize_text_field( (string) $legacy_key );
				if ( '' !== $key ) {
					$ids[ $key ] = true;
				}
				if ( is_array( $auth_row ) && isset( $auth_row['id'] ) ) {
					$alt = sanitize_text_field( (string) $auth_row['id'] );
					if ( '' !== $alt ) {
						$ids[ $alt ] = true;
					}
				} elseif ( is_object( $auth_row ) ) {
					$decoded = json_decode( wp_json_encode( $auth_row ), true );
					if ( is_array( $decoded ) && isset( $decoded['id'] ) ) {
						$alt = sanitize_text_field( (string) $decoded['id'] );
						if ( '' !== $alt ) {
							$ids[ $alt ] = true;
						}
					}
				}
			}
		}
		return $ids;
	}

	/**
	 * Basic Display: live /me fields during migration (does not change instagram_user_id in caller).
	 *
	 * @param string $access_token Long-lived user token.
	 * @param string $stats_time   GMT mysql datetime.
	 * @return array<string,mixed>
	 */
	private function fetch_basic_display_me_migration_patch( $access_token, $stats_time ) {
		$access_token = trim( (string) $access_token );
		if ( '' === $access_token ) {
			return array();
		}

		$url = add_query_arg(
			array(
				'fields'       => 'id,username,account_type,media_count,name,profile_picture_url,biography,website,followers_count',
				'access_token' => $access_token,
			),
			'https://graph.instagram.com/me'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), false );
		if ( ! is_object( $body ) || isset( $body->error ) ) {
			return array();
		}

		$username = isset( $body->username ) ? sanitize_text_field( (string) $body->username ) : '';
		$display  = '' !== $username ? $username : '';
		$media    = isset( $body->media_count ) ? (int) $body->media_count : 0;

		$patch = array(
			'media_count'        => $media,
			'followers_count'    => 0,
			'stats_refreshed_at' => $stats_time,
		);
		if ( '' !== $username ) {
			$patch['username']     = $username;
			$patch['display_name'] = $display;
		}
		if ( isset( $body->profile_picture_url ) ) {
			$pic = esc_url_raw( (string) $body->profile_picture_url );
			if ( '' !== $pic ) {
				$patch['profile_image_url'] = $pic;
			}
		}
		if ( isset( $body->biography ) ) {
			$bio = sanitize_textarea_field( (string) $body->biography );
			if ( '' !== $bio ) {
				$patch['biography'] = $bio;
			}
		}
		if ( isset( $body->website ) ) {
			$website = esc_url_raw( (string) $body->website );
			if ( '' !== $website ) {
				$patch['website'] = $website;
			}
		}

		return $patch;
	}

	/**
	 * Classify a Graph API probe response for migration token health.
	 *
	 * @since 6.9.0
	 *
	 * @param array<string,mixed>|WP_Error $response wp_remote_get() result.
	 * @return string `valid`, `invalid`, or `unknown`.
	 */
	private function classify_token_probe_response( $response ) {
		if ( class_exists( 'ESF_Meta_Graph_Error' ) ) {
			return ESF_Meta_Graph_Error::classify_token_probe_response( $response );
		}

		if ( is_wp_error( $response ) ) {
			return 'unknown';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), false );

		if ( $code >= 200 && $code < 300 && is_object( $body ) && ! isset( $body->error ) ) {
			return 'valid';
		}

		if ( ! is_object( $body ) || ! isset( $body->error ) ) {
			return 'unknown';
		}

		$err_code = isset( $body->error->code ) ? (int) $body->error->code : 0;
		$err_type = isset( $body->error->type ) ? (string) $body->error->type : '';

		if ( in_array( $err_code, array( 102, 190, 463, 467 ), true ) ) {
			return 'invalid';
		}

		if ( 'OAuthException' === $err_type && $err_code >= 100 ) {
			return 'invalid';
		}

		$message = isset( $body->error->message ) ? strtolower( (string) $body->error->message ) : '';
		if (
			'' !== $message
			&& (
				false !== strpos( $message, 'session has been invalidated' )
				|| false !== strpos( $message, 'changed their password' )
				|| false !== strpos( $message, 'error validating access token' )
			)
		) {
			return 'invalid';
		}

		return 'unknown';
	}

	/**
	 * Probe an Instagram Login user token.
	 *
	 * @since 6.9.0
	 *
	 * @param string $access_token User access token.
	 * @return string `valid`, `invalid`, or `unknown`.
	 */
	private function probe_instagram_login_token( $access_token ) {
		$access_token = trim( (string) $access_token );
		if ( '' === $access_token ) {
			return 'invalid';
		}

		$url = add_query_arg(
			array(
				'fields'       => 'id',
				'access_token' => $access_token,
			),
			'https://graph.instagram.com/me'
		);

		return $this->classify_token_probe_response(
			wp_remote_get( $url, array( 'timeout' => 15 ) )
		);
	}

	/**
	 * Probe a Facebook Page token against an Instagram Graph user node.
	 *
	 * @since 6.9.0
	 *
	 * @param string $page_access_token Page access token.
	 * @param string $node_id           Instagram Graph node id.
	 * @return string `valid`, `invalid`, or `unknown`.
	 */
	private function probe_graph_page_token( $page_access_token, $node_id ) {
		$page_access_token = trim( (string) $page_access_token );
		$node_id           = preg_replace( '/[^0-9]/', '', (string) $node_id );
		if ( '' === $page_access_token || '' === $node_id ) {
			return 'invalid';
		}

		$url = add_query_arg(
			array(
				'fields'       => 'id',
				'access_token' => $page_access_token,
			),
			sprintf( 'https://graph.facebook.com/v18.0/%s', rawurlencode( $node_id ) )
		);

		return $this->classify_token_probe_response(
			wp_remote_get( $url, array( 'timeout' => 15 ) )
		);
	}

	/**
	 * Apply migration account status from token probe result.
	 *
	 * Keeps legacy profile fields but marks dead tokens as `invalid` so feeds do
	 * not surface Graph "session invalidated" errors as if the account were healthy.
	 *
	 * @since 6.9.0
	 *
	 * @param array<string,mixed> $data         Upsert payload.
	 * @param string            $token_status   Probe result.
	 * @return array<string,mixed>
	 */
	private function apply_migration_token_status( array $data, $token_status ) {
		if ( 'invalid' === $token_status ) {
			$data['status'] = 'invalid';
		}

		return $data;
	}

	/**
	 * Instagram Graph (professional): live user node fields during migration.
	 *
	 * @param string               $instagram_user_id  Stored Instagram user / row id (digits).
	 * @param string               $page_access_token Page access token.
	 * @param string               $stats_time        GMT mysql datetime.
	 * @param array<string,mixed>|null $payload       instagram_connected_account payload for correct Graph node id.
	 * @return array<string,mixed>
	 */
	private function fetch_graph_ig_user_migration_patch( $instagram_user_id, $page_access_token, $stats_time, $payload = null ) {
		$page_access_token = trim( (string) $page_access_token );
		if ( '' === $page_access_token ) {
			return array();
		}

		$payload_arr = is_array( $payload ) ? $payload : null;
		$node_id     = $this->instagram_graph_user_node_id_for_request( $instagram_user_id, $payload_arr );
		$fallback    = '';
		if ( is_array( $payload_arr ) && isset( $payload_arr['ig_id'] ) ) {
			$fallback = preg_replace( '/[^0-9]/', '', (string) $payload_arr['ig_id'] );
		}
		if ( $fallback === $node_id ) {
			$fallback = '';
		}
		if ( '' === $node_id && '' !== $fallback ) {
			$node_id  = $fallback;
			$fallback = '';
		}
		if ( '' === $node_id ) {
			return array();
		}

		$body = $this->graph_get_instagram_user_node_body( $page_access_token, $node_id );
		if ( null === $body && '' !== $fallback ) {
			$body = $this->graph_get_instagram_user_node_body( $page_access_token, $fallback );
		}
		if ( null === $body ) {
			return array();
		}

		$username  = isset( $body->username ) ? sanitize_text_field( (string) $body->username ) : '';
		$name      = isset( $body->name ) ? sanitize_text_field( (string) $body->name ) : '';
		$display   = '' !== $name ? $name : $username;
		$bio       = isset( $body->biography ) ? sanitize_textarea_field( (string) $body->biography ) : '';
		$website   = isset( $body->website ) ? esc_url_raw( (string) $body->website ) : '';
		$pic       = isset( $body->profile_picture_url ) ? esc_url_raw( (string) $body->profile_picture_url ) : '';
		$followers = isset( $body->followers_count ) ? (int) $body->followers_count : 0;
		$media     = isset( $body->media_count ) ? (int) $body->media_count : 0;

		$patch = array(
			'followers_count'    => $followers,
			'media_count'        => $media,
			'stats_refreshed_at' => $stats_time,
		);
		if ( '' !== $username ) {
			$patch['username'] = $username;
		}
		if ( '' !== $display ) {
			$patch['display_name'] = $display;
		}
		if ( '' !== $pic ) {
			$patch['profile_image_url'] = $pic;
		}
		if ( '' !== $bio ) {
			$patch['biography'] = $bio;
		}
		if ( '' !== $website ) {
			$patch['website'] = $website;
		}

		return $patch;
	}

	/**
	 * Legacy frontend caches profile JSON under esf_insta_user_bio_{personal|business}-{ig_user_id}.
	 *
	 * @param string $ig_user_id              Primary cache key (Graph id or legacy ig id).
	 * @param string $alternate_ig_user_id Optional second key (often `ig_id` when row stores Graph `id`).
	 * @return object|null
	 */
	private function get_legacy_bio_transient_object( $ig_user_id, $alternate_ig_user_id = '' ) {
		$candidates = array();
		$primary    = sanitize_text_field( (string) $ig_user_id );
		if ( '' !== $primary ) {
			$candidates[] = $primary;
		}
		$alt = sanitize_text_field( (string) $alternate_ig_user_id );
		if ( '' !== $alt && ! in_array( $alt, $candidates, true ) ) {
			$candidates[] = $alt;
		}
		if ( empty( $candidates ) ) {
			return null;
		}
		foreach ( $candidates as $try_id ) {
			foreach ( array( 'business', 'personal' ) as $type ) {
				$key = 'esf_insta_user_bio_' . $type . '-' . $try_id;
				$raw = get_transient( $key );
				if ( false === $raw || null === $raw || '' === $raw ) {
					continue;
				}
				if ( is_string( $raw ) ) {
					$obj = json_decode( $raw, false );
				} elseif ( is_object( $raw ) ) {
					$obj = $raw;
				} else {
					continue;
				}
				if ( is_object( $obj ) && ! isset( $obj->error ) ) {
					return $obj;
				}
			}
		}
		return null;
	}

	/**
	 * Map Graph-style bio object to repository upsert patch.
	 *
	 * @param object $body Decoded API or transient payload.
	 * @param object $row  Current account row.
	 * @param string $stats_time GMT mysql datetime.
	 * @return array<string,mixed>
	 */
	private function build_profile_patch_from_bio_object( $body, $row, $stats_time ) {
		$account_id = sanitize_text_field( (string) $row->instagram_user_id );
		$patch      = array(
			'instagram_user_id'  => $account_id,
			'stats_refreshed_at' => $stats_time,
		);

		if ( ! is_object( $body ) ) {
			return $patch;
		}

		if ( isset( $body->username ) ) {
			$patch['username'] = sanitize_text_field( (string) $body->username );
		}
		if ( isset( $body->name ) ) {
			$patch['display_name'] = sanitize_text_field( (string) $body->name );
		} elseif ( isset( $patch['username'] ) ) {
			$patch['display_name'] = $patch['username'];
		}
		if ( isset( $body->biography ) ) {
			$patch['biography'] = sanitize_textarea_field( (string) $body->biography );
		}
		if ( isset( $body->website ) ) {
			$patch['website'] = esc_url_raw( (string) $body->website );
		}
		if ( isset( $body->profile_picture_url ) ) {
			$patch['profile_image_url'] = esc_url_raw( (string) $body->profile_picture_url );
		}
		if ( isset( $body->followers_count ) ) {
			$patch['followers_count'] = (int) $body->followers_count;
		}
		if ( isset( $body->media_count ) ) {
			$patch['media_count'] = (int) $body->media_count;
		}
		return $patch;
	}

	/**
	 * True if transient (or object) carries at least one profile field we care about.
	 *
	 * @param object|null $obj Payload.
	 * @return bool
	 */
	private function bio_object_has_profile_stats( $obj ) {
		if ( ! is_object( $obj ) ) {
			return false;
		}
		return isset( $obj->followers_count ) || isset( $obj->media_count ) || isset( $obj->biography )
			|| isset( $obj->name ) || isset( $obj->username ) || isset( $obj->profile_picture_url );
	}

	/**
	 * Merge missing profile / token fields from legacy bio transients into an upsert payload.
	 *
	 * @param array<string,mixed> $data Upsert row.
	 * @param string              $stats_time GMT mysql datetime for stats_refreshed_at in the patch source.
	 * @return array<string,mixed>
	 */
	private function fill_from_legacy_bio_transient( array $data, $stats_time ) {
		$ig = isset( $data['instagram_user_id'] ) ? sanitize_text_field( (string) $data['instagram_user_id'] ) : '';
		if ( '' === $ig ) {
			return $data;
		}
		$alt_ig = '';
		if ( ! empty( $data['account_data'] ) ) {
			$decoded = json_decode( (string) $data['account_data'], true );
			if ( is_array( $decoded ) && isset( $decoded['ig_id'] ) ) {
				$alt_ig = sanitize_text_field( (string) $decoded['ig_id'] );
				if ( $alt_ig === $ig ) {
					$alt_ig = '';
				}
			}
		}
		$cached = $this->get_legacy_bio_transient_object( $ig, $alt_ig );
		if ( ! is_object( $cached ) ) {
			return $data;
		}
		$fake_row = (object) array( 'instagram_user_id' => $ig );
		$patch    = $this->build_profile_patch_from_bio_object( $cached, $fake_row, $stats_time );
		unset( $patch['instagram_user_id'] );

		foreach ( $patch as $key => $value ) {
			if ( 'stats_refreshed_at' === $key ) {
				continue;
			}
			if ( in_array( $key, array( 'followers_count', 'media_count' ), true ) ) {
				$cur = isset( $data[ $key ] ) ? (int) $data[ $key ] : 0;
				$pv  = (int) $value;
				if ( $cur <= 0 && $pv > 0 ) {
					$data[ $key ] = $pv;
				}
				continue;
			}
			if ( ! isset( $data[ $key ] ) || '' === $data[ $key ] ) {
				$data[ $key ] = $value;
			}
		}

		return $data;
	}

	/**
	 * Fill profile counters and bios from remote APIs (not stored in legacy fta_settings).
	 *
	 * @param ESF_Instagram_Account_Repository $repo Repository.
	 * @param int                              $wp_user_id WordPress user id.
	 * @return void
	 */
	private function enrich_accounts_from_graph( $repo, $wp_user_id ) {
		$accounts = $repo->get_all_by_user( $wp_user_id );
		if ( empty( $accounts ) ) {
			return;
		}

		$stats_time = current_time( 'mysql', true );

		foreach ( $accounts as $row ) {
			if ( ! is_object( $row ) ) {
				continue;
			}
			if ( in_array( $row->account_type, array( 'business', 'creator' ), true ) && ! empty( $row->page_access_token ) && ! empty( $row->instagram_user_id ) ) {
				$this->enrich_instagram_graph_profile( $repo, $row, $stats_time );
			} elseif ( ! empty( $row->access_token ) ) {
				$this->enrich_basic_display_profile( $repo, $row, $stats_time );
			}
		}
	}

	/**
	 * Instagram user access token: graph.instagram.com/me (Basic Display or Instagram API with Instagram Login).
	 *
	 * @param ESF_Instagram_Account_Repository $repo Repository.
	 * @param object                           $row Account row.
	 * @param string                           $stats_time MySQL datetime (GMT).
	 * @return void
	 */
	private function enrich_basic_display_profile( $repo, $row, $stats_time ) {
		$cached = $this->get_legacy_bio_transient_object( (string) $row->instagram_user_id );
		if ( is_object( $cached ) && $this->bio_object_has_profile_stats( $cached ) ) {
			$repo->upsert( $this->build_profile_patch_from_bio_object( $cached, $row, $stats_time ) );
		}

		$fields_sets = array(
			implode(
				',',
				array(
					'id',
					'username',
					'account_type',
					'media_count',
					'name',
					'profile_picture_url',
					'biography',
					'website',
					'followers_count',
				)
			),
			'id,username,account_type,media_count',
		);

		$body = null;
		foreach ( $fields_sets as $fields ) {
			$url      = add_query_arg(
				array(
					'fields'       => $fields,
					'access_token' => $row->access_token,
				),
				'https://graph.instagram.com/me'
			);
			$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
				continue;
			}
			$candidate = json_decode( wp_remote_retrieve_body( $response ), false );
			if ( is_object( $candidate ) && ! isset( $candidate->error ) ) {
				$body = $candidate;
				break;
			}
		}

		if ( ! is_object( $body ) ) {
			$token = trim( (string) $row->access_token );
			if ( '' !== $token && 'invalid' === $this->probe_instagram_login_token( $token ) ) {
				$repo->upsert(
					array(
						'instagram_user_id' => sanitize_text_field( (string) $row->instagram_user_id ),
						'status'            => 'invalid',
					)
				);
			}
			return;
		}

		$username   = isset( $body->username ) ? sanitize_text_field( (string) $body->username ) : (string) $row->username;
		$name       = isset( $body->name ) ? sanitize_text_field( (string) $body->name ) : '';
		$media      = isset( $body->media_count ) ? (int) $body->media_count : (int) $row->media_count;
		$display    = '' !== $name ? $name : ( '' !== $username ? $username : (string) $row->display_name );
		$bio        = isset( $body->biography ) ? sanitize_textarea_field( (string) $body->biography ) : '';
		$website    = isset( $body->website ) ? esc_url_raw( (string) $body->website ) : '';
		$pic        = isset( $body->profile_picture_url ) ? esc_url_raw( (string) $body->profile_picture_url ) : '';
		$followers  = isset( $body->followers_count ) ? (int) $body->followers_count : 0;
		$account_id = sanitize_text_field( (string) $row->instagram_user_id );

		$api_patch = array(
			'instagram_user_id'  => $account_id,
			'username'             => $username,
			'display_name'         => $display,
			'media_count'          => $media,
			'followers_count'      => $followers,
			'stats_refreshed_at'   => $stats_time,
			'auth_source'          => 'instagram_login',
		);
		if ( '' !== $bio ) {
			$api_patch['biography'] = $bio;
		}
		if ( '' !== $website ) {
			$api_patch['website'] = $website;
		}
		if ( '' !== $pic ) {
			$api_patch['profile_image_url'] = $pic;
		}
		$account_json = wp_json_encode( $body );
		if ( is_string( $account_json ) ) {
			$api_patch['account_data'] = $account_json;
		}
		$repo->upsert( $api_patch );
	}

	/**
	 * Instagram professional (Graph): node fields with page access token.
	 *
	 * @param ESF_Instagram_Account_Repository $repo Repository.
	 * @param object                           $row Account row.
	 * @param string                           $stats_time MySQL datetime (GMT).
	 * @return void
	 */
	private function enrich_instagram_graph_profile( $repo, $row, $stats_time ) {
		$payload = null;
		if ( ! empty( $row->account_data ) ) {
			$decoded = json_decode( (string) $row->account_data, true );
			$payload = is_array( $decoded ) ? $decoded : null;
		}

		$row_digits = preg_replace( '/[^0-9]/', '', (string) $row->instagram_user_id );
		$alt_digits  = '';
		if ( is_array( $payload ) && isset( $payload['ig_id'] ) ) {
			$alt_digits = preg_replace( '/[^0-9]/', '', (string) $payload['ig_id'] );
		}
		if ( $alt_digits === $row_digits ) {
			$alt_digits = '';
		}
		if ( '' === $row_digits && '' === $alt_digits ) {
			return;
		}

		$cached = $this->get_legacy_bio_transient_object( '' !== $row_digits ? $row_digits : $alt_digits, '' !== $row_digits ? $alt_digits : '' );
		if ( is_object( $cached ) && $this->bio_object_has_profile_stats( $cached ) ) {
			$repo->upsert( $this->build_profile_patch_from_bio_object( $cached, $row, $stats_time ) );
		}

		$node_id  = $this->instagram_graph_user_node_id_for_request( (string) $row->instagram_user_id, $payload );
		$fallback = $alt_digits;
		if ( $fallback === $node_id ) {
			$fallback = '';
		}
		if ( '' === $node_id && '' !== $fallback ) {
			$node_id  = $fallback;
			$fallback = '';
		}
		if ( '' === $node_id ) {
			return;
		}

		$body = $this->graph_get_instagram_user_node_body( $row->page_access_token, $node_id );
		if ( null === $body && '' !== $fallback ) {
			$body = $this->graph_get_instagram_user_node_body( $row->page_access_token, $fallback );
		}
		if ( null === $body ) {
			$page_token = trim( (string) $row->page_access_token );
			$node_id    = $this->instagram_graph_user_node_id_for_request( (string) $row->instagram_user_id, $payload );
			if ( '' !== $page_token && 'invalid' === $this->probe_graph_page_token( $page_token, $node_id ) ) {
				$repo->upsert(
					array(
						'instagram_user_id' => sanitize_text_field( (string) $row->instagram_user_id ),
						'status'            => 'invalid',
					)
				);
			}
			return;
		}

		$username = isset( $body->username ) ? sanitize_text_field( (string) $body->username ) : (string) $row->username;
		$name     = isset( $body->name ) ? sanitize_text_field( (string) $body->name ) : '';
		$display  = '' !== $name ? $name : $username;
		$bio      = isset( $body->biography ) ? sanitize_textarea_field( (string) $body->biography ) : '';
		$website  = isset( $body->website ) ? esc_url_raw( (string) $body->website ) : '';
		$pic      = isset( $body->profile_picture_url ) ? esc_url_raw( (string) $body->profile_picture_url ) : (string) $row->profile_image_url;
		$followers = isset( $body->followers_count ) ? (int) $body->followers_count : (int) $row->followers_count;
		$media     = isset( $body->media_count ) ? (int) $body->media_count : (int) $row->media_count;
		$account_id = sanitize_text_field( (string) $row->instagram_user_id );

		$patch = array(
			'instagram_user_id'  => $account_id,
			'username'           => $username,
			'display_name'       => $display,
			'profile_image_url'  => $pic,
			'biography'          => $bio,
			'website'            => $website,
			'followers_count'    => $followers,
			'media_count'        => $media,
			'stats_refreshed_at' => $stats_time,
			'auth_source'        => 'facebook_page',
		);
		$account_json = wp_json_encode( $body );
		if ( is_string( $account_json ) ) {
			$patch['account_data'] = $account_json;
		}
		$repo->upsert( $patch );
	}

	/**
	 * Migrate legacy accounts from fta_settings into modern accounts table.
	 *
	 * @since 6.8.0
	 * @return array{migrated:int,skipped:int}
	 */
	public function migrate() {
		$repo          = ESF_Instagram_Account_Repository::get_instance();
		$stats_time    = current_time( 'mysql', true );
		$fta_settings  = get_option( 'fta_settings', array() );
		$migrated      = 0;
		$skipped       = 0;
		$user_id       = (int) get_current_user_id();
		$facebook_uid  = $this->resolve_facebook_user_id( $fta_settings );
		$facebook_token = isset( $fta_settings['plugins']['facebook']['access_token'] )
			? sanitize_text_field( (string) $fta_settings['plugins']['facebook']['access_token'] )
			: '';
		$global_insta_token = isset( $fta_settings['plugins']['instagram']['access_token'] )
			? sanitize_text_field( (string) $fta_settings['plugins']['instagram']['access_token'] )
			: '';

		$auth_accounts = array();
		if ( isset( $fta_settings['plugins']['instagram']['authenticated_accounts'] )
			&& is_array( $fta_settings['plugins']['instagram']['authenticated_accounts'] ) ) {
			$auth_accounts = $fta_settings['plugins']['instagram']['authenticated_accounts'];
		}

		$personal = isset( $fta_settings['plugins']['instagram']['instagram_connected_account'] )
			? $fta_settings['plugins']['instagram']['instagram_connected_account']
			: array();

		$basic_ig_ids = $this->collect_basic_instagram_user_ids(
			is_array( $personal ) ? $personal : array(),
			$auth_accounts
		);

		if ( is_array( $personal ) ) {
			foreach ( $personal as $legacy_id => $legacy_account ) {
				$row = $this->normalize_personal_account_row( $legacy_id, $legacy_account );
				if ( null === $row ) {
					++$skipped;
					continue;
				}

				$merged = $row['legacy_row'];
				if ( isset( $auth_accounts[ $row['instagram_user_id'] ] ) && is_array( $auth_accounts[ $row['instagram_user_id'] ] ) ) {
					$merged = array_merge( $merged, $auth_accounts[ $row['instagram_user_id'] ] );
				}

				$access_token = $row['access_token'];
				if ( '' === $access_token && '' !== $global_insta_token ) {
					$access_token = $global_insta_token;
				}
				if ( '' === $access_token && isset( $merged['access_token'] ) ) {
					$access_token = sanitize_text_field( (string) $merged['access_token'] );
				}

				$token_status = ( '' === $access_token )
					? 'invalid'
					: $this->probe_instagram_login_token( $access_token );

				$upsert_personal = $this->fill_from_legacy_bio_transient(
					array(
						'user_id'            => $user_id,
						'account_type'       => 'personal',
						'auth_source'        => 'instagram_login',
						'instagram_user_id'  => $row['instagram_user_id'],
						'username'           => isset( $merged['username'] ) ? sanitize_text_field( (string) $merged['username'] ) : $row['username'],
						'display_name'       => isset( $merged['username'] ) ? sanitize_text_field( (string) $merged['username'] ) : $row['display_name'],
						'profile_image_url'  => ! empty( $row['profile_image_url'] ) ? $row['profile_image_url'] : '',
						'access_token'       => $access_token,
						'account_data'       => wp_json_encode( $merged ),
						'status'             => 'active',
					),
					$stats_time
				);
				if ( 'valid' === $token_status ) {
					$me_patch = $this->fetch_basic_display_me_migration_patch( $access_token, $stats_time );
					$upsert_personal = array_merge( $upsert_personal, $me_patch );
				}
				$upsert_personal = $this->apply_migration_token_status( $upsert_personal, $token_status );

				$id = $repo->upsert( $upsert_personal );

				if ( false === $id ) {
					++$skipped;
				} else {
					++$migrated;
				}
			}
		}

		$pages = isset( $fta_settings['plugins']['facebook']['approved_pages'] )
			? $fta_settings['plugins']['facebook']['approved_pages']
			: array();

		if ( is_array( $pages ) ) {
			foreach ( $pages as $legacy_page ) {
				if ( ! is_array( $legacy_page ) || empty( $legacy_page['instagram_connected_account'] ) ) {
					continue;
				}

				$normalized = $this->normalize_graph_ig_payload( $legacy_page['instagram_connected_account'] );
				if ( null === $normalized ) {
					++$skipped;
					continue;
				}

				if ( isset( $basic_ig_ids[ $normalized['instagram_user_id'] ] ) ) {
					continue;
				}
				$payload_graph_id = isset( $normalized['payload']['id'] )
					? sanitize_text_field( (string) $normalized['payload']['id'] )
					: '';
				if ( '' !== $payload_graph_id && isset( $basic_ig_ids[ $payload_graph_id ] ) ) {
					continue;
				}
				$payload_igid = isset( $normalized['payload']['ig_id'] )
					? sanitize_text_field( (string) $normalized['payload']['ig_id'] )
					: '';
				if ( '' !== $payload_igid && isset( $basic_ig_ids[ $payload_igid ] ) ) {
					continue;
				}

				$ig_json      = wp_json_encode( $normalized['payload'] );
				$page_access  = isset( $legacy_page['access_token'] ) ? sanitize_text_field( (string) $legacy_page['access_token'] ) : '';
				$token_status = ( '' === $page_access )
					? 'invalid'
					: $this->probe_graph_page_token(
						$page_access,
						$this->instagram_graph_user_node_id_for_request(
							$normalized['instagram_user_id'],
							$normalized['payload']
						)
					);

				$upsert_business = $this->fill_from_legacy_bio_transient(
					array(
						'user_id'              => $user_id,
						'account_type'         => 'business',
						'auth_source'          => 'facebook_page',
						'instagram_user_id'    => $normalized['instagram_user_id'],
						'username'             => $normalized['username'],
						'display_name'         => '' !== $normalized['display_name'] ? $normalized['display_name'] : $normalized['username'],
						'profile_image_url'    => $normalized['profile_image_url'],
						'facebook_page_id'     => isset( $legacy_page['id'] ) ? sanitize_text_field( (string) $legacy_page['id'] ) : null,
						'facebook_page_name'   => isset( $legacy_page['name'] ) ? sanitize_text_field( (string) $legacy_page['name'] ) : null,
						'facebook_user_id'     => $facebook_uid,
						'facebook_user_token'  => $facebook_token,
						'page_access_token'    => '' !== $page_access ? $page_access : null,
						'account_data'         => $ig_json,
						'status'               => 'active',
					),
					$stats_time
				);
				if ( 'valid' === $token_status && '' !== $page_access ) {
					$graph_patch = $this->fetch_graph_ig_user_migration_patch(
						$normalized['instagram_user_id'],
						$page_access,
						$stats_time,
						$normalized['payload']
					);
					$upsert_business = array_merge( $upsert_business, $graph_patch );
				}
				$upsert_business = $this->apply_migration_token_status( $upsert_business, $token_status );

				$id = $repo->upsert( $upsert_business );

				if ( false === $id ) {
					++$skipped;
				} else {
					++$migrated;
				}
			}
		}

		$this->enrich_accounts_from_graph( $repo, $user_id );

		$this->localize_migrated_account_avatars( $repo );

		$default_feed_id = $this->create_default_migrated_feed( $repo );
		if ( $default_feed_id > 0 ) {
			update_option( 'esf_instagram_migration_default_feed_id', $default_feed_id );
		}

		if ( class_exists( 'ESF_Module_System' ) ) {
			ESF_Module_System::switch_to_modern( 'instagram' );
		} else {
			update_option( 'esf_instagram_use_new_system', 'new' );
		}

		return array(
			'migrated'        => $migrated,
			'skipped'         => $skipped,
			'default_feed_id' => $default_feed_id,
		);
	}

	/**
	 * Create one default user-timeline feed for the first migrated account.
	 *
	 * Most legacy sites use a single Instagram account; one starter feed is enough.
	 *
	 * @since 6.9.0
	 *
	 * @param ESF_Instagram_Account_Repository $account_repo Account repository.
	 * @return int Feed id or 0 on failure.
	 */
	private function create_default_migrated_feed( $account_repo ) {
		if ( ! class_exists( 'ESF_Instagram_Feed_Repository' ) ) {
			return 0;
		}

		$feed_repo   = ESF_Instagram_Feed_Repository::get_instance();
		$existing_id = (int) get_option( 'esf_instagram_migration_default_feed_id', 0 );
		if ( $existing_id > 0 && $feed_repo->get_by_id( $existing_id ) ) {
			return $existing_id;
		}

		$accounts = $account_repo->get_all();
		if ( empty( $accounts ) || ! is_object( $accounts[0] ) ) {
			return 0;
		}

		$account_row = $accounts[0];
		$account_id  = isset( $account_row->id ) ? (int) $account_row->id : 0;
		if ( $account_id <= 0 ) {
			return 0;
		}

		$name = __( 'Instagram Feed', 'easy-facebook-likebox' );
		if ( class_exists( 'ESF_Feed_Name' ) ) {
			$name = ESF_Feed_Name::build_username_feed_name( $account_row, $name );
		}

		$settings = ESF_Instagram_Feed_Repository::get_default_settings();
		if ( class_exists( 'ESF_Feed_Name' ) ) {
			$settings = ESF_Feed_Name::set_name_meta( $settings, 'auto', $account_id );
		}

		$feed_id = $feed_repo->create(
			array(
				'name'       => $name,
				'account_id' => $account_id,
				'feed_type'  => 'user_timeline',
				'settings'   => $settings,
			)
		);

		return is_int( $feed_id ) ? $feed_id : 0;
	}

	/**
	 * Download profile images locally for every migrated account row.
	 *
	 * @since 6.9.0
	 *
	 * @param ESF_Instagram_Account_Repository $repo Account repository.
	 * @return void
	 */
	private function localize_migrated_account_avatars( $repo ) {
		foreach ( $repo->get_all() as $row ) {
			if ( ! is_object( $row ) || empty( $row->instagram_user_id ) ) {
				continue;
			}

			$profile_url = trim( (string) ( $row->profile_image_url ?? '' ) );
			if ( '' === $profile_url ) {
				continue;
			}

			$repo->upsert(
				array(
					'instagram_user_id' => sanitize_text_field( (string) $row->instagram_user_id ),
					'profile_image_url' => esc_url_raw( $profile_url ),
				)
			);
		}
	}

	/**
	 * Rotate stored tokens before profile enrichment (remote `refresh.php` when available;
	 * Instagram Login can fall back to graph.instagram.com without an app secret).
	 *
	 * @param ESF_Instagram_Account_Repository $repo Repository.
	 * @param object                           $row Account row.
	 * @return void Silent on failure; caller may still use the previous token.
	 */
	private function maybe_refresh_instagram_login_long_lived_token( $repo, $row ) {
		$token = isset( $row->access_token ) ? trim( (string) $row->access_token ) : '';
		if ( '' === $token ) {
			return;
		}
		if ( '' !== trim( (string) ( $row->page_access_token ?? '' ) ) ) {
			return;
		}
		if ( 'instagram_login' !== esf_instagram_auth_source_for_account_row( $row ) ) {
			return;
		}

		$patch = ESF_Instagram_Token_Refresh_Client::refresh_tokens( $row );
		if ( is_wp_error( $patch ) ) {
			if ( function_exists( 'esf_instagram_maybe_mark_account_reconnect_from_graph_error' ) ) {
				esf_instagram_maybe_mark_account_reconnect_from_graph_error( $row, $patch );
			}
			return;
		}
		if ( ! is_array( $patch ) || array() === $patch ) {
			return;
		}

		$patch['instagram_user_id'] = (string) $row->instagram_user_id;
		$patch['status']            = 'active';
		$repo->upsert( $patch );
	}

	/**
	 * Pull latest profile fields from Instagram Graph or Basic Display for one stored account.
	 *
	 * @param int      $account_id esf_instagram_accounts.id.
	 * @param int|null $owner_user_id When set, row must belong to this WordPress user. When null, any row (caller must enforce manage capability).
	 * @return true|WP_Error
	 */
	public function refresh_account_by_id( $account_id, $owner_user_id = null ) {
		$account_id = (int) $account_id;
		if ( $account_id <= 0 ) {
			return new WP_Error(
				'invalid_params',
				__( 'Invalid account.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		if ( null !== $owner_user_id && (int) $owner_user_id <= 0 ) {
			return new WP_Error(
				'invalid_params',
				__( 'Invalid account.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$repo = ESF_Instagram_Account_Repository::get_instance();
		$row  = $repo->get_by_id( $account_id );
		if ( ! $row ) {
			return new WP_Error(
				'not_found',
				__( 'Account not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}
		if ( null !== $owner_user_id && (int) $row->user_id !== (int) $owner_user_id ) {
			return new WP_Error(
				'not_found',
				__( 'Account not found.', 'easy-facebook-likebox' ),
				array( 'status' => 404 )
			);
		}

		$stats_time = current_time( 'mysql', true );
		if ( in_array( $row->account_type, array( 'business', 'creator' ), true ) && ! empty( $row->page_access_token ) && ! empty( $row->instagram_user_id ) ) {
			$this->enrich_instagram_graph_profile( $repo, $row, $stats_time );
		} elseif ( ! empty( $row->access_token ) ) {
			$this->maybe_refresh_instagram_login_long_lived_token( $repo, $row );
			$row = $repo->get_by_id( $account_id );
			if ( ! $row || ( null !== $owner_user_id && (int) $row->user_id !== (int) $owner_user_id ) ) {
				return new WP_Error(
					'not_found',
					__( 'Account not found after token refresh.', 'easy-facebook-likebox' ),
					array( 'status' => 404 )
				);
			}
			$this->enrich_basic_display_profile( $repo, $row, $stats_time );
		} else {
			return new WP_Error(
				'cannot_refresh',
				__( 'This account has no usable token to refresh profile data. Reconnect if needed.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}
}
