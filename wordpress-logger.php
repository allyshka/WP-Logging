<?php
/**
 * Plugin Name: WP Logger
 * Plugin URI: http://automattic.com
 * Description: Provides an interface to log errors and actions within a WordPress installation.
 * Version: 0.1
 * Author: Eric Binnion
 * Author URI: http://manofhustle.com
 * License: GPLv2 or later
 * Text Domain: wp-logger
 */

class WP_Logger {

	/**
	 * Memeber data for ensuring singleton pattern
	 */
	private static $instance = null;

	/**
	 * Constant for the WP Logger taxonomy
	 */
	const TAXONOMY = 'plugin-errors';

	/**
	 * Constant for the WP Logger custom post type
	 */
	const CPT = 'wp-logger';

	/**
	 * Adds all of the filters and hooks and enforces singleton pattern
	 */
	function __construct() {

		// Enforces a single instance of this class.
		if ( isset( self::$instance ) ) {
			wp_die( esc_html__( 'The WP_Logger class has already been loaded.', 'wp-logger' ) );
		}

		self::$instance = $this;

		add_action( 'init',       array( $this, 'init' ), 1 );
		add_action( 'admin_menu', array( $this,'add_menu_page' ) );
	}

	/**
	 * Prefixes a string with 'wp-logger-' or $plugin_name
	 *
	 * @param  string $slug Plugin slug.
	 * @param string $plugin_name If set, slug will be prefixed with plugin_name
	 * @return string String that being with 'wp-logger-'
	 */
	static function prefix_slug( $slug, $plugin_name = '' ) {

		if ( ! empty( $plugin_name ) ) {
			return "$plugin_name-$slug";
		} else {
			return "wp-logger-$slug";
		}
	}

	/**
	 * Exposes method used to register taxonomy term with named with a plugin's slug
	 *
	 * @param  string $plugin_name Plugin slug.
	 * @return bool|WP_Error True on successfully creating term, false if plugin is already registered, 
	 * and WP_Error if wp_insert_term fails
	 */
	static function register_plugin( $plugin_name ) {

		if( ! term_exists( $plugin_name, self::TAXONOMY ) ) {
			$registered = wp_insert_term( 
				$plugin_name, 
				self::TAXONOMY,
				array(
					'slug' => self::prefix_slug( $plugin_name )
				)
			);

			if( is_wp_error( $registered ) ) {
				return $registered;
			} else {
				return true;
			}
		}

		return false;
	}

	/**
	 * Exposes a method to allow developers to log an error.
	 *
	 * @param  WP_Post|string $log WP_Post object if log already exists and a string if log needs to be created.
	 * @param  string $plugin_name The plugin's slug
	 * @return null|WP_Error Returns null on success or WP_Error object on failure
	 */
	static function add_entry( $log = 'message', $message, $plugin_name ) {

		if ( ! isset( $message ) ) {
			return new WP_Error( 'missing-parameter', esc_html__( 'You must pass a message as the second parameter.', 'wp-logger' ) );
		}

		// If log is a WP_Post object, then the log already exists. Else, then the log needs to be created 
		// before adding an entry.
		if( is_a( $log, 'WP_Post' ) ) {
			$post_id = $log->ID;
		} else {
			$post_id = wp_insert_post(
				array(
					'post_title'     => $log,
					'post_name'      => self::prefix_slug( $log, $plugin_name ),
					'post_type'      => self::CPT,
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'post_status'    => 'publish',
				)
			);

			$add_terms = wp_set_post_terms( 
				$post_id, 
				self::prefix_slug( $plugin_name ), 
				self::TAXONOMY 
			);
		}

		$time = current_time( 'mysql' );

		$comment_data = array(
			'comment_post_ID'      => $post_id,
			'comment_content'      => $message,
			'comment_author'       => $plugin_name,
			'comment_approved'     => 'wp-logger',
			'comment_author_IP'    => '',
			'comment_author_url'   => '',
			'comment_author_email' => '',
		);

		$comment_id = wp_insert_comment( wp_filter_comment( $comment_data ) );

		add_comment_meta(
			$comment_id,
			'_wp_logger_term',
			self::prefix_slug( $plugin_name ),
			true
		);
	}

	/**
	 * Register the wp-logger post type.
	 *
	 * Registers the wp-logger post type such that only admins can see the errors in the admin
	 * and admins can not add new errors through the admin UI interface.
	 */
	function init() {

		register_post_type(
			self::CPT,
			array(
				'public'        => false,
				'show_ui'       => false,
				'rewrite'       => false,
				'menu_position' => 100,
				'supports'      => false,
				'labels'        => array(
					'name'               => esc_html__( 'Errors', 'wp-logger' ),
					'singular_name'      => esc_html__( 'Error', 'wp-logger' ),
					'add_new'            => esc_html__( 'Add New Error', 'wp-logger' ),
					'add_new_item'       => esc_html__( 'Add New Error', 'wp-logger' ),
					'edit_item'          => esc_html__( 'Edit Error', 'wp-logger' ),
					'new_item'           => esc_html__( 'Add New Error', 'wp-logger' ),
					'view_item'          => esc_html__( 'View Error', 'wp-logger' ),
					'search_items'       => esc_html__( 'Search Errors', 'wp-logger' ),
					'not_found'          => esc_html__( 'No errors found', 'wp-logger' ),
					'not_found_in_trash' => esc_html__( 'No errors found in trash', 'wp-logger' )
				),
				'capabilities' => array(
					'edit_post'          => 'update_core',
					'read_post'          => 'update_core',
					'delete_post'        => 'update_core',
					'edit_posts'         => 'update_core',
					'edit_others_posts'  => 'update_core',
					'publish_posts'      => 'update_core',
					'read_private_posts' => 'update_core',
					'create_posts'       => false,
				),
			)
		);
	
		$labels = array(
			'name'              => esc_html__( 'Plugins', 'wp-logger' ),
			'singular_name'     => esc_html__( 'Plugin', 'wp-logger' ),
			'search_items'      => esc_html__( 'Search Plugins', 'wp-logger' ),
			'all_items'         => esc_html__( 'All Plugins', 'wp-logger' ),
			'parent_item'       => esc_html__( 'Parent Plugin', 'wp-logger' ),
			'parent_item_colon' => esc_html__( 'Parent Plugin:', 'wp-logger' ),
			'edit_item'         => esc_html__( 'Edit Plugin', 'wp-logger' ),
			'update_item'       => esc_html__( 'Update Plugin', 'wp-logger' ),
			'add_new_item'      => esc_html__( 'Add New Plugin', 'wp-logger' ),
			'new_item_name'     => esc_html__( 'New Plugin Name', 'wp-logger' ),
			'menu_name'         => esc_html__( 'Plugins', 'wp-logger' ),
		);

		register_taxonomy( 
			self::TAXONOMY, 
			self::CPT, 
			array(
				'labels'            => $labels,
				'show_in_nav_menus' => true,
				'show_ui'           => true,
				'query_var'         => true,
			)
		);
	}

	function add_menu_page() {
		add_menu_page( 'Errors', 'Errors', 'update_core', 'wp_logger_errors', array( $this, 'generate_menu_page' ), 'dashicons-editor-help', 100 );
	}

	function get_entries() {
		$log_query = new WP_Comment_Query;

		$args = array(
			'status' => self::CPT,
		);

		if ( isset( $_GET['orderby'] ) ) {
			if ( 'error_plugin' == $_GET['orderby'] ) {
				$args['orderby'] = 'comment_author';
			} else if ( 'error_date' == $_GET['orderby'] ) {
				$args['orderby'] = 'comment_date';
			}

			if( isset( $_GET['order'] ) ) {
				$args['order'] = $_GET['order'];
			} else {
				$args['order'] = 'desc';
			}
		}

		if( ! empty( $_POST['search'] ) ) {
			$args['search'] = $_POST['search'];
		}

		if ( ! empty( $_POST['plugin-select'] ) ) {
			$args['comment_author'] = $_POST['plugin-select'];
		}
		}

		$return = array();

		// Get the count of all comments that fit these arguments
		$args['count'] = true;
		$return['count'] = $log_query->query( $args );

		$args['count'] = false;
		$args['number'] = 20;

		if( isset( $_GET['paged'] ) && intval( $_GET['paged'] ) > 1 ) {
			$args['offset'] = ( intval( $_GET['paged'] ) - 1 ) * 20;
		}

		// Only return the first 20 of the comments that fit these arguments
		$return['entries'] = $log_query->query( $args );

		return $return;
	}

	function get_logs( $plugin_term ) {
		if( ! $plugin_term ) {
			return false;
		}

		$logs = new WP_Query(
			array(
				'post_type' => self::CPT,
				'tax_query' => array(
					array(
						'taxonomy' => self::TAXONOMY,
						'field'    => 'slug',
						'terms'    => self::prefix_slug( $plugin_term )
					)
				)
			)
		);

		return $logs;
	}

	function get_plugin_email( $plugin_term ) {
		$plugin_slug = str_replace( 'wp-logger-', '', $plugin_term );
		return get_option( "{$plugin_slug}_email", false );
	}

	function get_plugins() {
		$plugins = get_terms( 
			self::TAXONOMY,
			array(
				'orderby' => 'name',
				'order' => 'ASC'
			)
		);

		return $plugins;
	}

	function generate_menu_page() {
		global $post;

		// Include WP Logger copy of core WP_List_Table class
		require_once( trailingslashit( dirname( __FILE__ ) ) . 'class-wp-logger-list-table.php' );

		$plugin_select = isset( $_POST['plugin-select'] ) ? $_POST['plugin-select'] : false;
		$plugin_email = $this->get_plugin_email( $plugin_select );
		$logs = $this->get_logs( $plugin_select );
		$log_id = isset( $_POST['log-select'] ) ? $_POST['log-select'] : false;

		$entries = $this->get_entries();

		$plugins = $this->get_plugins();

		$logger_table = new WP_Logger_List_Table( $entries );
		$logger_table->prepare_items();

		?>

		<div class="wrap">
			<h2>Errors</h2>

			<form method="post" action="<?php echo admin_url( 'admin.php?page=wp_logger_errors' ); ?>">
				<div id="col-container">
					<div id="col-right">
						<div class="col-wrap">
							<?php $logger_table->display(); ?>
						</div>
					</div>

					<div id="col-left">
						<h3>Generate Error Report</h3>

						<div class="form-field">
							<label for="search">Search</label>
							<input name="search" id="search" type="text" value="<?php if( isset( $_POST['search'] ) ) { echo $_POST['search']; } ?>" size="40" aria-required="true">
						</div>

						<?php if ( ! empty( $plugins ) ) : ?>

							<div class="form-field">
								<p>
									<label for="plugin-select">Plugin</label><br>
									<select id="plugin-select" name="plugin-select">
										<option value="">All Plugins</option>

										<?php
											foreach ( $plugins as $plugin ) {
												echo "<option value='$plugin->name'" . selected( $plugin->name, $plugin_select ) . ">$plugin->name</option>";
											}
										?>
									</select>
									<br>
									Select a plugin to view errors for.
								</p>
							</div>

						<?php endif; ?>

						<?php if ( false != $logs && $logs->have_posts() ): ?>

							<div class="form-field">
								<p>
									<label for="log-select">Log</label><br>
									<select id="log-select" name="log-select">
										<option value="">All Logs</option>

										<?php
											while ( $logs->have_posts() ) {
												$logs->the_post();
												echo "<option value='$post->ID'" . selected( $post->ID, $log_id ) . ">$post->post_title</option>";
											}
										?>
									</select>
									<br>
									Select a log for this plugin.
								</p>
							</div>

						<?php endif; ?>

						<button class="button button-primary">Generate Report</button>
						
						<?php if( $plugin_select && $plugin_email ) :?>
							<br>
							<br>
							<hr>

							<h3>Send Report to Developer</h3>
							<p>By clicking this button below, you can send the logs for this plugin directly to the developer.</p>
							<a class="button">Send Report to Developer</a>
						<?php endif; ?>

						<br>
						<br>
						<hr>

						<h3>Email Results</h3>

						<div class="form-field">
							<label for="email-results">Email</label>
							<input name="s" id="email-results" type="text" size="40" aria-required="true">
							<p>Enter an email above to email a log.</p>
						</div>

						<p><a class="button">Email Results</a></p>

					</div>

				</div>
			</form>
		</div>

		<?php
	}
}

new WP_Logger();