<?php
/**
 * Manual (pasted) access-token connect for X (Twitter) accounts.
 *
 * @package Easy_Social_Feed
 * @subpackage Twitter
 * @since 6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESF_Twitter_Manual_Oauth_Service
 *
 * @since 6.9.0
 */
class ESF_Twitter_Manual_Oauth_Service {

	/**
	 * Validate a pasted token and connect the X account.
	 *
	 * @since 6.9.0
	 * @param int    $user_id      WordPress user id.
	 * @param string $access_token Raw pasted token.
	 * @return array{mode:string,account_id:int}|WP_Error
	 */
	public static function submit_manual_access_token( $user_id, $access_token ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return new WP_Error( 'esf_tw_invalid_user', __( 'Invalid user.', 'easy-facebook-likebox' ), array( 'status' => 400 ) );
		}

		if ( ! class_exists( 'ESF_Manual_Access_Token' ) ) {
			return new WP_Error(
				'esf_tw_manual_unavailable',
				__( 'Manual token setup is not available.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		$access_token = ESF_Manual_Access_Token::sanitize( $access_token );
		if ( ! ESF_Manual_Access_Token::is_valid_format( $access_token ) ) {
			return new WP_Error(
				'esf_tw_invalid_token',
				__( 'Enter a valid X access token.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$repo     = ESF_Twitter_Account_Repository::get_instance();
		$has_plan = function_exists( 'esf_twitter_has_twitter_plan' ) && esf_twitter_has_twitter_plan();
		if ( ! $has_plan && $repo->get_total_connected_account_count() >= 1 ) {
			return new WP_Error(
				'account_limit_reached',
				__( 'Free includes one connected X account per site. X charges for API access, so connecting more accounts requires Pro.', 'easy-facebook-likebox' ),
				array( 'status' => 403 )
			);
		}

		$api_service = ESF_Twitter_API_Service::get_instance();
		$user_data   = $api_service->validate_token_and_fetch_user( $access_token );
		if ( is_wp_error( $user_data ) ) {
			return new WP_Error(
				'esf_tw_invalid_token',
				__( 'This access token could not be validated with X.', 'easy-facebook-likebox' ),
				array( 'status' => 400 )
			);
		}

		$account_id = $repo->upsert_from_tokens(
			$user_id,
			$access_token,
			'',
			0,
			ESF_Manual_Access_Token::AUTH_MANUAL
		);
		if ( ! $account_id ) {
			return new WP_Error(
				'esf_tw_account_save_failed',
				__( 'Could not save the X account.', 'easy-facebook-likebox' ),
				array( 'status' => 500 )
			);
		}

		if ( function_exists( 'esf_review_request_record_milestone' ) ) {
			esf_review_request_record_milestone( 'account_connected', 'twitter' );
		}

		return array(
			'mode'       => 'connected',
			'account_id' => (int) $account_id,
		);
	}
}
