<?php
/**
 * Plugin Name: WP Logger
 * Plugin URI: http://automattic.com
 * Description: Provides an interface to log errors, messages, and actions within a WordPress installation.
 * Version: 0.1
 * Author: Eric Binnion
 * Author URI: http://manofhustle.com
 * License: GPLv2 or later
 * Text Domain: wp-logger
 */

class WP_Logger extends wpdb {

	/**
	 * Member data for ensuring singleton pattern
	 */
	private static $instance = null;

	/**
	 * Member data that holds the post_id for the current session, if there is one.
	 */
	private static $session_post = null;

	/**
	 * Constant for the WP Logger taxonomy
	 */
	const TAXONOMY = 'plugin-messages';

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

		// These actions setup the plugin and inject scripts and styles on our logs page.
		add_action( 'init',                  array( $this, 'init' ), 1 );
		add_action( 'admin_menu',            array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// These actions allow developers to add log entries, purge log entries for a plugin, and create/end sessions.
		add_action( 'wp_logger_add',            array( $this, 'add_entry' ), 10, 4  );
		add_action( 'wp_logger_purge',          array( $this, 'purge_plugin_logs' ) );
		add_action( 'wp_logger_create_session', array( $this, 'create_set_session' ), 10, 4 );
		add_action( 'wp_logger_end_session',    array( $this, 'end_session' ) );

		/*
		 * This filter allows developers to retrieve the current version of WP Logger, and also serves
		 * as a way for developers to check if WP Logger is installed.
		 */
		add_filter( 'wp_logger_version', array( $this, 'get_wp_logger_version' ) );

		// This filter allows comments to be queried by comment_author, which is where a plugin's slug is stored.
		add_filter( 'comments_clauses',  array( $this, 'add_comment_author' ), 10, 2 );

		// These actions handle displaying log and session selects via AJAX.
		add_action( 'wp_ajax_get_logger_log_select',     array( $this, 'ajax_gen_log_select' ) );
		add_action( 'wp_ajax_get_logger_session_select', array( $this, 'ajax_gen_session_select' ) );

		// This registers an AJAX handler to process email logs.
		add_action( 'wp_ajax_send_log_email',            array( $this, 'process_email_log' ) );
	}

	/**
	 * Will return the current version of WP Logger to plugins that call the `wp_logger_version` filter.
	 *
	 * @param  null
	 * @return string The current version of WP Logger
	 */
	function get_wp_logger_version( $version ) {
		return '0.1';
	}

	/**
	 * Callback function for 'wp_logger_purge' that will delete all log entries (comments) for a given plugin.
	 *
	 * @global $post Global WP_Post object.
	 *
	 * @param  string $plugin_name The plugins's slug.
	 */
	function purge_plugin_logs( $plugin_name ) {
		global $post;

		$logs = $this->get_logs( $plugin_name, 'purge' );

		if ( $logs->have_posts() ) {
			while ( $logs->have_posts() ) {
				$logs->the_post();

				wp_delete_post( $post->ID, true );
			}
		}
	}

	/**
	 * Exposes a method to allow developers to log a message.
	 *
	 * @global WP_Post $post The global WP_Post object.
	 *
	 * @param string $plugin_name The plugin's slug.
	 * @param string $log String identifying this plugin.
	 * @param string $message The message to log
	 * @param int    $severity The importance of the logged message.
	 *
	 * @return bool Returns true if entry was successfully added and false if entry failed.
	 */
	function add_entry( $plugin_name, $log, $message, $severity = 1 ) {
		global $post;

		$prefixed_term = $this->do_plugin_term( $plugin_name );

		/*
		 * If there is a current session, then attach this log entry to that session.
		 * Else, get the post_id for the matching log if exists, or create a log
		 * if one does not exist with the supplied log key.
		 */
		if ( self::$session_post ) {
			$post_id = self::$session_post;
		} else {

			$post_id = $this->check_existing_log( $plugin_name, $log );

			if ( false == $post_id ) {
				$post_id = $this->create_post_with_terms( $plugin_name, $log );

				if ( false == $post_id ) {
					return false;
				}
			}
		}

		$comment_data = array(
			'comment_post_ID'      => $post_id,
			'comment_content'      => $message,
			'comment_author'       => $plugin_name,
			'comment_approved'     => self::CPT,
			'comment_author_IP'    => '',
			'comment_author_url'   => '',
			'comment_author_email' => '',
			'user_id'              => intval( $severity ),
		);

		if ( self::$session_post ) {
			$comment_data['comment_parent'] = 1;
		}

		$comment_id = wp_insert_comment( wp_filter_comment( $comment_data ) );

		// Do not limit the logs for a session, as this might break the logic for the session.
		if ( ! self::$session_post ) {
			$this->limit_plugin_logs( $plugin_name, $log, $post_id );
		}

		// Returns true if comment/entry was successfully added and false on falure.
		return (boolean) $comment_id;
	}


        /**
         * Returns the filename mask of log file.
         * @return string
         */
        public function getFilenameMask()
        {
                return $this->filenameMask;
        }
        /**
         * Sets the filename mask for log files.
         * You can use the strftime specifiers.
         *
         * @param string $filenameMask
         * @see strftime()
         */
        public function setFilenameMask($filenameMask)
        {
                $this->filenameMask = $filenameMask;
                $this->file = NULL;
        }
        /**
         * Returns the directory path where log files reside.
         *
         * @return string
         */
        public function getLogDir()
        {
                return $this->logDir;
        }
        /**
         * Sets the directory path where log files reside.
         *
         * @param string $logDir
         */
        public function setLogDir($logDir)
        {
                $this->logDir = $logDir;
                $this->file = NULL;
        }
        /**
         * Returns log files granularity.
         * @return int in seconds
         */
        public function getGranularity()
        {
                return $this->granularity;
        }


	/**
	 * Register the wp-logger post type and plugin-messages taxonomy. Also, process bulk delete action and log mailing.
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
					'name'               => esc_html__( 'Logs'                  , 'wp-logger' ),
					'singular_name'      => esc_html__( 'Log'                   , 'wp-logger' ),
					'add_new'            => esc_html__( 'Add New Log'           , 'wp-logger' ),
					'add_new_item'       => esc_html__( 'Add New Log'           , 'wp-logger' ),
					'edit_item'          => esc_html__( 'Edit Log'              , 'wp-logger' ),
					'new_item'           => esc_html__( 'Add New Log'           , 'wp-logger' ),
					'view_item'          => esc_html__( 'View Log'              , 'wp-logger' ),
					'search_items'       => esc_html__( 'Search Logs'           , 'wp-logger' ),
					'not_found'          => esc_html__( 'No logs found'         , 'wp-logger' ),
					'not_found_in_trash' => esc_html__( 'No logs found in trash', 'wp-logger' )
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

		register_taxonomy(
			self::TAXONOMY,
			self::CPT,
			array(
				'query_var' => false,
				'public'    => false,
				'rewrite'   => false,
			)
		);

		// Delete any entries that were checked through the bulk action interface.
		if ( is_admin() && isset( $_GET['page'] ) ) {

			if ( 'wp_logger_messages' == $_GET['page'] && isset( $_POST['action'] ) && 'delete' == $_POST['action'] ) {

				check_admin_referer( 'wp_logger_generate_report', 'wp_logger_form_nonce' );

				if ( ! empty( $_POST['logs'] ) ) {

					foreach ( $_POST['logs'] as $log ) {
						wp_delete_comment( intval( $log ), true );
					}
				}
			}
		}

		// Condition for emailing logs. Logs are emailed as a JSON object.
		if ( isset( $_POST['send_logger_email'] ) ) {
			$this->process_email_log();
		}

		// Allows entering a page into the pagination input.
		if ( isset( $_POST['paged'] ) ) {
			$_GET['paged'] = $_POST['paged'];
		}

		// This will copy values from the $_GET superglobal to the $_POST superglobal which allows the use of the WP_List_Table class.
		$copy_get = array( 'search', 'plugin-select', 'log-select', 'session-select' );
		foreach ( $copy_get as $do_copy ) {
			if ( isset( $_GET[ $do_copy ] ) ) {
				$_POST[ $do_copy ] = $_GET[ $do_copy ];
			}
		}
	}

	/**
	 * Adds a menu page to the WordPress admin with a title of Plugin Logs
	 */
	function add_menu_page() {
		add_menu_page(
			esc_html__( 'Plugin Logs', 'wp-logger' ),
			esc_html__( 'Plugin Logs', 'wp-logger' ),
			'update_core',
			'wp_logger_messages',
			array( $this, 'generate_menu_page' ),
			'dashicons-editor-help',
			100
		);
	}

	/**
	 * Adds the ability to query by comment author using the WP_Comment_Query class.
	 *
	 * @global wpdb $wpdb Global insantiation of WordPress database class wpdb.
	 *
	 * @param array $pieces Array containing the comment query arguments.
	 * @param WP_Comment_Quey &Comment Reference to the WP_Comment_Query object.
	 *
	 * @return array Containing the comment query arguments.
	 */
	function add_comment_author( $pieces, &$comment ) {
		//global $wpdb;

		if ( isset( $comment->query_vars['comment_author'] ) ) {
			$pieces['where'] .= $this->prepare( ' AND comment_author = %s', $comment->query_vars['comment_author'] );
		}

		return $pieces;
	}

	/**
	 * Create a session that is used to group all entries until another session is created or until
	 * session is destroyed.
	 *
	 * @param string $plugin_name   The plugin's slug
	 * @param int    $log           The log to assign the session to.
	 * @param string $session_title The session's title.
	 * @param int    $severity      The importance of the logged session.
	 *
	 * @return boolean               True if session was successfully set, false otherwise.
	 */
	function create_set_session( $plugin_name, $log, $session_title, $severity = 0 ) {
		$post_id = $this->create_post_with_terms( $plugin_name, $log, $session_title, $severity );

		if( false == $post_id ) {
			return false;
		}

		self::$session_post = $post_id;

		return true;
	}

	/**
	 * Ends the current session by setting the static session_post variable back to null.
	 *
	 * @return boolean True on success.
	 */
	function end_session() {
		self::$session_post = null;
		return true;
	}

	/**
	 * Returns build_log_select through AJAX.
	 */
	function ajax_gen_log_select() {
		$plugin_select = isset( $_POST['plugin_name'] ) ? $_POST['plugin_name'] : '';
		$this->build_log_select( sanitize_text_field( $plugin_select ) );
		exit;
	}

	/**
	 * Returns build_session_select through AJAX.
	 */
	function ajax_gen_session_select() {
		$log_select = isset( $_POST['log_select'] ) ? $_POST['log_select'] : '';
		$this->build_session_select( sanitize_text_field( $log_select ) );
		exit;
	}

	/**
	 * Enqueues styles and scripts for WP Logger only on WP Logger logs page.
	 *
	 * @param  string $hook The unique hook for the current admin page.
	 */
	function enqueue_scripts( $hook ) {
		if ( 'toplevel_page_wp_logger_messages' == $hook ) {
			wp_enqueue_script( 'wp_logger', plugins_url( 'js/script.js', __FILE__ ), array( 'jquery' ) );
			wp_enqueue_style( 'wp_logger', plugins_url( 'css/style.css', __FILE__ ) );
		}
	}

	/**
	 * Outputs the Plugin Messages menu page.
	 *
	 * @global WP_Post $post The global WP_Post object.
	 *
	 */
	function generate_menu_page() {
		global $post;

		// Include WP Logger copy of core WP_List_Table class
		require_once( trailingslashit( dirname( __FILE__ ) ) . 'lib/class-wp-logger-list-table.php' );

		$plugin_select = isset( $_POST['plugin-select'] ) ? $_POST['plugin-select'] : false;
		$log_id        = isset( $_POST['log-select'] ) ? $_POST['log-select'] : false;
		$session_id    = isset( $_POST['session-select'] ) ? $_POST['session-select'] : false;
		$search        = isset( $_POST['search'] ) ? $_POST['search'] : '';
		$hide_form     = isset( $_COOKIE['wp_logger_hide_form'] ) ? 'hide-form' : '';

		$logger_table  = new WP_Logger_List_Table( $this->get_entries() );
		$logger_table->prepare_items();

		// Load the different views to build the log table page.
		require_once( trailingslashit( dirname( __FILE__ ) ) . 'views/log-table.php' );
	}

	/**
	 * AJAX callback to send a log as an email attachment.
	 *
	 * @global $wpdb Global instantiation of wpdb class.
	 *
	 */
	public function process_email_log() {
		global $wpdb;

		check_ajax_referer( 'wp_logger_generate_report', 'wp_logger_form_nonce' );

		$entries = $this->get_entries( 1000 );

		if ( ! empty( $entries ) ) {

			// Build an array of just the data that needs to be sent to the developer.
			$data = array();

			foreach ( $entries['entries'] as $entry ){
				$data[] = array(
					'id'           => $entry->the_ID,
					'log_severity' => $entry->severity,
					'log_msg'      => $entry->message,
					'log_date'     => $entry->the_date,
					'log_plugin'   => $entry->log_plugin,
					'session'      => $entry->session
				);

				if ( ! empty( $entry->session ) ) {
					$session_entries = array();
					$comments_query = new WP_Comment_Query;

					$sessions = $comments_query->query(
						array(
							'post_id'          => $entry->the_ID,
							'comment_approved' => 'wp-logger'
						)
					);

					$sessions = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->comments WHERE comment_post_ID = %d", $entry->the_ID ) );

					if ( $sessions ) {
						foreach ( $sessions as $session ) {
							$session_entries[] = array(
								'id'           => $session->comment_ID,
								'log_severity' => $session->user_id,
								'log_msg'      => $session->comment_content,
								'log_date'     => $session->comment_date,
								'log_plugin'   => $session->comment_author,
							);
						}

						$data['session_entries'] = $sessions;
					}
				}
			}

			$plugin_email = isset( $_POST['email-logs'] ) ?  sanitize_email( $_POST['email-logs'] ) : '';
			$current_site = get_option( 'home' );
			$time         = time();

			$filename = 'wp-logger-' . sanitize_title( $current_site ) . '-' . $time;

			// Make sure that wp-content/wp-logger exists, if not, create it.
			if ( ! is_dir( WP_CONTENT_DIR . '/wp-logger' ) ) {
				mkdir( WP_CONTENT_DIR . '/wp-logger' );
			}

			// Test again to make sure that wp-content/wp-logger exists before attempting to open a file in the directory.
			if ( is_dir( WP_CONTENT_DIR . '/wp-logger' ) ) {
				$file = fopen( WP_CONTENT_DIR . "/wp-logger/{$filename}.json",'w' );

				/*
				 * If the JSON file was created successfully, then let's send that as an attachment.
				 * If the file was no created successfully, then attempt to send the logs directly within the email message.
				 */
				if ( false !== $file ) {
					fwrite( $file, json_encode( $data ) );

					$send_email = wp_mail(
						$plugin_email,
						sprintf( __( 'Logs from %s', 'wp-logger' ), $current_site ),
						sprintf( __( 'Attached is a log in JSON format from %s', 'wp-logger' ), $current_site ),
						'From: WP Logger Logs' . "\r\n",
						array( WP_CONTENT_DIR . "/wp-logger/{$time}.json" )
					);
				} else {
					$send_email = wp_mail(
						$plugin_email,
						sprintf( __( 'Logs from %s', 'wp-logger' ), $current_site ),
						json_encode( $data ),
						'From: WP Logger Logs' . "\r\n"
					);
				}

				fclose( $file );
			}
		}

		if( isset( $send_email ) && $send_email ) : ?>

			<div class="updated">
				<p><?php esc_html_e( 'Your message was sent successfully!', 'wp-logger' ); ?></p>
			</div>

		<?php else : ?>

			<div class="error">
				<p><?php esc_html_e( 'Your message failed to send.', 'wp-logger' ); ?></p>
			</div>

		<?php endif;

		exit;
	}

	/**
	 * Checks if there is an existing log for a plugin.
	 *
	 * @param  string $plugin_name The plugin's slug.
	 * @param  string $log         The log's name.
	 *
	 * @return boolean|int         Returns the post ID on success or false on failure.
	 */
	private function check_existing_log( $plugin_name, $log ) {
		global $post;

		$prefixed_term = $this->do_plugin_term( $plugin_name );

		$log_exists = new WP_Query(
			array(
				'post_type' => self::CPT,
				'name'      => $this->prefix_slug( $log, $plugin_name ),
				'tax_query' => array(
					array(
						'taxonomy' => self::TAXONOMY,
						'field'    => 'slug',
						'terms'    => $prefixed_term
					)
				)
			)
		);

		// If there is an existing log, return post ID. Else, retun false.
		if ( $log_exists->have_posts() ) {
			$log_exists->the_post();
			return $post->ID;

		} else {
			return false;
		}
	}

	/**
	 * Creates a post and sets the terms for the post.
	 *
	 * @param string $plugin_name   The plugin's slug
	 * @param int    $log           The post ID for the log.
	 * @param string $session_title The name of the session title.
	 * @param int    $severity      The importance of the logged session.
	 *
	 * @return boolean|int           False on failure or post_id on success.
	 */
	private function create_post_with_terms( $plugin_name, $log, $session_title = '', $severity = 0 ) {
		$prefixed_term = $this->do_plugin_term( $plugin_name );

		$args = array(
			'post_title'     => $log,
			'post_name'      => $this->prefix_slug( $log, $plugin_name ),
			'post_type'      => self::CPT,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'post_status'    => 'publish',
		);

		if ( ! empty( $session_title ) ) {
			$existing_log = $this->check_existing_log( $plugin_name, $log );

			// If there is not an existing log to attach session to, create it.
			if ( false == $existing_log ) {
				$existing_log = $this->create_post_with_terms( $plugin_name, $log );
			}

			$args['post_parent']  = $existing_log;
			$args['post_title']   = $session_title;
			$args['post_excerpt'] = $plugin_name;
			$args['menu_order']   = $severity;
		}

		$post_id = wp_insert_post( $args );

		if ( 0 == $post_id ) {
			return false;
		}

		$add_terms = wp_set_post_terms(
			$post_id,
			$prefixed_term,
			self::TAXONOMY
		);

		/*
		 * A successful call to wp_set_post_terms will return an array. A failure could return
		 * a WP_Error object, false, or a string.
		 */
		if ( ! is_array( $add_terms ) ) {
			return false;
		}

		return $post_id;
	}

	/**
	 * Will return a unique, prefixed string that represents a term for a plugin.
	 *
	 * @param  string $plugin_name The plugin's slug.
	 *
	 * @return string $prefixed_terms The prefixed term for the plugin.
	 */
	private function do_plugin_term( $plugin_name ) {
		$prefixed_term = $this->prefix_slug( $plugin_name );

		// If there is not currently a term (category) for this plugin, create it.
		if ( ! term_exists( $prefixed_term, self::TAXONOMY ) ) {

			// Create a taxonomy term that distinguishes current plugin from others.
			$registered = wp_insert_term(
				$plugin_name,
				self::TAXONOMY,
				array(
					'slug' => $prefixed_term
				)
			);

			// Check if taxonomy term was succeessfully added and return if not.
			if ( is_wp_error( $registered ) ) {
				return false;
			}
		}

		return $prefixed_term;
	}

	/**
	 * Builds the log select for WP Logger admin.
	 *
	 * @global  WP_Post $post The global WP_Post instantiation
	 *
	 * @param  string  $plugin_name The plugin's slug.
	 * @param  int $log_id The selected log's post ID
	 */
	private function build_log_select( $plugin_name, $log_id = false ) {
		global $post;

		$logs = $this->get_logs( $plugin_name );

		if ( false != $logs && $logs->have_posts() ) {
			?>

			<select id="log-select" name="log-select">
				<option value=""><?php esc_html_e( 'All Logs', 'wp-logger' ); ?></option>

				<?php
					while ( $logs->have_posts() ) {
						$logs->the_post();
						$temp_log_id    = esc_attr( $post->ID );
						$temp_log_title = esc_attr( $post->post_title );
						echo "<option value='$temp_log_id'" . selected( $post->ID, $log_id, false ) . ">$temp_log_title</option>";
					}
				?>
			</select>

			<?php
		}
	}

	/**
	 * Will remove excess log entries for a plugin. This method is called by add_entry after adding an entry.
	 *
	 * @param string $plugin_name The plugin's slug, as passed by developer.
	 * @param string $log_name The log for the current entry.
	 * @param int $log_id The log's post ID.
	 */
	private function limit_plugin_logs( $plugin_name, $log_name, $log_id ) {
		global $wpdb;

		/**
		 * Allows plugin developers to modify the log entry limit for their plugin.
		 *
		 * @param int Limit defaults to 20 entries per plugin log.
		 * @param string $log_name The log for the current entry.
		 */
		$limit = apply_filters( 'wp_logger_limit_' . $plugin_name, 20, $log_name );

		$comments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $wpdb->comments WHERE comment_approved = 'wp-logger' AND comment_author = %s AND comment_post_ID = %d ORDER BY comment_date ASC",
				$plugin_name,
				$log_id
			)
		);

		// Get the number of rows returned by last query.
		$count = $wpdb->num_rows;

		/*
		 * To account for switching log limits, allow for deleting multiple log entries at a time.
		 */
		if ( $count > $limit ) {
			$diff = $count - $limit;

			for ( $i = 0; $i < $diff; $i++ ) {
				wp_delete_comment( $comments[ $i ]->comment_ID, true );
			}
		}
	}

	/**
	 * Prefixes a string with 'wp-logger-' or $plugin_name
	 *
	 * @param  string $slug Plugin slug.
	 * @param  string $plugin_name If set, slug will be prefixed with plugin_name
	 *
	 * @return string String that being with 'wp-logger-'
	 */
	private function prefix_slug( $slug, $plugin_name = '' ) {
		if ( ! empty( $plugin_name ) ) {
			return sanitize_title( $plugin_name ) . '-' . sanitize_title( $slug );
		} else {
			return 'log-' . sanitize_title( $slug );
		}
	}

	/**
	 * Will retrieve the developer email for the current plugin
	 *
	 * @param  string $plugin_name The unique string identifying this plugin. Also acts as term for plugin.
	 *
	 * @return string The developers email or empty string.
	 */
	private function get_plugin_email( $plugin_name ) {

		/**
		 * Allows plugin developers to register their email by using a filter..
		 *
		 * @param string Empty string
		 */
		return apply_filters( "wp_logger_author_email_{$plugin_name}", '' );
	}

	/**
	 * Returns an array of logger messages (comments) and the count of those entries.
	 *
	 * @global $wpdb Global instantiation of wpdb class.
	 *
	 * @param int $limit The number of entries to return.
	 *
	 * @return array {
	 *     int $count The number of entries that fit the paraemters.
	 *     array $entries An array of comment comment rows.
	 * }
	 */
	private function get_entries( $limit = 20 ) {
		global $wpdb;

		$post_where = "post_type = 'wp-logger' AND post_parent != 0";
		$comment_where = "comment_approved = 'wp-logger'";

		if ( ! empty( $_POST['session-select'] ) ) {
			$comment_where .= $wpdb->prepare( " AND comment_post_ID = %d AND comment_parent = 1", $_POST['session-select'] );
		} else {
			$comment_where .= ' AND comment_parent = 0';
		}

		if ( isset( $_GET['orderby'] ) ) {
			if ( 'log_plugin' == $_GET['orderby'] ) {
				$args['orderby'] = 'log_plugin';
			} else if ( 'log_date' == $_GET['orderby'] ) {
				$args['orderby'] = 'the_date';
			} else if ( 'log_severity' == $_GET['orderby'] ) {
				$args['orderby'] = 'severity';
			}
		} else {
			$args['orderby'] = 'the_date';
		}

		if ( isset( $_GET['order'] ) ) {
			$args['order'] = $_GET['order'];
		} else {
			$args['order'] = 'desc';
		}

		if ( ! empty( $_POST['search'] ) ) {
			$post_where .= $wpdb->prepare( " AND ( {$wpdb->posts}.post_title LIKE %s )", '%'.$_POST['search'].'%' );
			$comment_where .= $wpdb->prepare( " AND ( {$wpdb->comments}.comment_content LIKE %s OR {$wpdb->comments}.comment_author LIKE %s )", '%'.$_POST['search'].'%', '%'.$_POST['search'].'%' );
		}

		$args['join'] = '';

		if ( ! empty( $_POST['plugin-select'] ) ) {
			// $args['comment_author'] = $_POST['plugin-select'];
			$term = get_term_by( 'slug', $this->prefix_slug( $_POST['plugin-select'] ), self::TAXONOMY );

			$post_where .= $wpdb->prepare( " AND (wp_term_relationships.term_taxonomy_id IN (%d))", intval( $term->term_id ) );
			$comment_where .= $wpdb->prepare( " AND comment_author = %s", $_POST['plugin-select'] );

			$args['join'] = 'INNER JOIN wp_term_relationships ON wp_posts.ID = wp_term_relationships.object_id ';
		}

		if ( ! empty( $_POST['log-select'] ) && empty( $_POST['session-select'] ) ) {
			$comment_where .= $wpdb->prepare( " AND comment_post_ID = %d", $_POST['log-select'] );
			$post_where .= $wpdb->prepare( " AND post_parent = %d", $_POST['log-select'] );
		}

		if ( isset( $_GET['paged'] ) && intval( $_GET['paged'] ) > 1 ) {
			$args['limit'] = $wpdb->prepare( " LIMIT 20 OFFSET %d", ( intval( $_GET['paged'] ) - 1 ) * 20 );
		} else  {
			$args['limit'] = " LIMIT $limit";
		}

		$session_select = "SELECT
				ID AS the_ID,
				menu_order AS severity,
				post_title AS message,
				post_date AS the_date,
				post_excerpt AS log_plugin,
				1 AS session
			FROM
				$wpdb->posts
			{$args['join']}
			WHERE
				{$post_where}
			UNION ";

		$sql = "SELECT
				comment_ID AS the_ID,
				user_ID AS severity,
				comment_content AS message,
				comment_date AS the_date,
				comment_author AS log_plugin,
				0 AS session
			FROM
				{$wpdb->comments}
			WHERE
				{$comment_where}
			ORDER BY
			{$args['orderby']}
			{$args['order']}, the_ID ASC";

		if ( empty( $_POST['session-select'] ) ) {
			$sql = $session_select . $sql;
		}

		return array(
			'entries' => $wpdb->get_results( "{$sql} {$args['limit']}" ),
			'count'   => $wpdb->query( $sql )
		);
	}

	/**
	 * Return the posts (logs) for the posts with $plugin_term term
	 *
	 * @param  string $plugin_term The term that for the current plugin.
	 *
	 * @return false|WP_Query False if no $plugin_term is passed or WP_Query object containing the posts for this plugin.
	 */
	private function get_logs( $plugin_term, $purge = false ) {
		if ( ! $plugin_term ) {
			return false;
		}

		$args = array(
			'post_type'      => self::CPT,
			'posts_per_page' => -1,
			'tax_query'      => array(
				array(
					'taxonomy' => self::TAXONOMY,
					'field'    => 'slug',
					'terms'    => $this->prefix_slug( $plugin_term )
				)
			)
		);

		if ( false == $purge ) {
			$args['post_parent'] = 0;
		}

		$logs = new WP_Query( $args );

		return $logs;
	}

	/**
	 * Retrive log file to show
	 */
	private function get_log_file() {
		print file_get_contents(dirname(__FILE__).'/logs/'.basename($this->$logfile));
	}

	/**
	 * Retrieves the terms (plugins) for the plugin-messages taxonomy.
	 *
	 * @return array. An array of term objects.
	 */
	private function get_plugins() {
		$plugins = get_terms(
			self::TAXONOMY,
			array(
				'orderby' => 'name',
				'order'   => 'ASC'
			)
		);

		return $plugins;
	}
}

$wp_logger = new WP_Logger();
