<?php // phpcs:ignore WordPress.NamingConventions
/**
 * This file belongs to the YIT Plugin Framework.
 *
 * This source file is subject to the GNU GENERAL PUBLIC LICENSE (GPL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.txt
 *
 * @package YITH\Waitlist\Admin
 */

defined( 'YITH_WCWTL' ) || exit;

if ( ! class_exists( 'YITH_WCWTL_Admin_Users' ) ) {
	/**
	 * Waiting List Users - handles users registration by admin side.
	 *
	 * @package     YITH WooCommerce Waiting List
	 * @version     3.0.0
	 */
	class YITH_WCWTL_Admin_Users {

		use YITH_WCWTL_Singleton_Trait;

		/**
		 * YITH_WCWTL_Admin_Users constructor.
		 *
		 * @access private
		 * @author YITH <plugins@yithemes.com>
		 * @return void
		 * @since  3.0.0
		 */
		public function __construct() {
			if ( ! is_ajax() && yith_wcwtl_is_users_page() && ! isset( $_GET['export_action'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification
				include_once YITH_WCWTL_ADMIN_VIEWS_PATH . '/add-user-modal.php';
			}

			add_action( 'wp_ajax_yith_wcwtl_add_user', array( $this, 'add_user_in_waiting_list' ) );

			add_action( 'wp_ajax_yith_wcwtl_generate_password', array( $this, 'generate_password' ) );
		}

		/**
		 * Add user in waitlist.
		 *
		 * @access public
		 * @return false|string
		 * @since 2.0.0
		 */
		public function add_user_in_waiting_list() {
			if ( isset( $_POST['params'] ) ) {  //phpcs:ignore WordPress.Security.NonceVerification
				parse_str( $_POST['params'], $params );
				extract( $params );

				if ( isset( $customer_type ) ) {

					if ( 'new' === $customer_type ) {
						$role = sanitize_key( $role );
						$disallowed_roles  = array( 'administrator', 'editor', 'shop_manager' );

						if ( in_array( $role, $disallowed_roles, true ) ) {
							wp_send_json_error( array( 'errors' => array( __( 'This role cannot be assigned when creating a waiting-list user.', 'yith-woocommerce-waiting-list' ) ) ), 403 );
						}

						$result = wp_create_user( $username, $password, $email );

						if ( is_numeric( $result ) ) {

							$user = get_user_by( 'ID', $result );

							if ( $user instanceof WP_User ) {
								$user->set_role( $role );
							}

							yith_waitlist_register_user( $user->user_email, $list_id, null, $user->ID );

							if ( isset( $send_user_notification ) ) {
								$email_new_account = WC()->mailer()->get_emails()['WC_Email_Customer_New_Account'];

								$email_new_account->trigger( $user->ID, $password, true );
							}

							wp_send_json_success(
								array(
									'msg'     => 'User added correctly',
									'user_id' => $result,
								),
								200
							);

						} else {

							wp_send_json_error(
								array(
									'errors' => $result->errors,
								)
							);
						}
					} else {
						$user_id = $customer;
						$user    = get_user_by( 'ID', $user_id );

						yith_waitlist_register_user( $user->user_email, $list_id, null, null, $user_id );

						wp_send_json_success(
							array(
								'msg' => 'User added correctly',
							),
							200
						);
					}
				}
			}

			die();
		}

		/**
		 * Generate password for user account creation.
		 *
		 * @access public
		 * @return void
		 * @since 2.0.0
		 */
		public function generate_password() {
			wp_send_json_success(
				array(
					'psw' => wp_generate_password( 24 ),
				)
			);
			die();
		}

	}
}

if( ! function_exists( 'YITH_WCWTL_Admin_Users' ) ){
	/**
	 * Unique access to instance of YITH_WCWTL_Users class
	 *
	 * @since 2.0.0
	 * @return object YITH_WCWTL_Admin_Users
	 */
	function YITH_WCWTL_Admin_Users() { // phpcs:ignore WordPress.NamingConventions
		return YITH_WCWTL_Admin_Users::get_instance();
	}
}


