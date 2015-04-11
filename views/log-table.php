<div class="wrap">
	<h2>
		<span id="ajax"><?php esc_html_e( 'Plugin Logs', 'wp-logger' ); ?></span>
	</h2>

	<form method="post" id="logger-form" action="<?php echo admin_url( 'admin.php?page=wp_logger_messages' ); ?>">
		<?php wp_nonce_field( 'wp_logger_generate_report', 'wp_logger_form_nonce' ) ?>
		<input type="hidden" id="session-select" name="session-select" value="<?php echo $session_id; ?>">

		<div class="tablenav top selects">
			<div class="alignleft actions">
				<select id="plugin-select" name="plugin-select">
					<option value=""><?php esc_html_e( 'All Plugins', 'wp-logger' ); ?></option>

					<?php
						foreach ( $this->get_plugins() as $plugin ) {
							$temp_plugin_name = esc_attr( $plugin->name );
							echo "<option value='$temp_plugin_name'" . selected( $plugin->name, $plugin_select, false ) . ">$temp_plugin_name</option>";
						}
					?>
				</select>

				<span id="log-select-contain">
					<?php $this->build_log_select( $plugin_select, $log_id ); ?>
				</span>

				<?php if ( ! empty( $session_id ) ) : ?>
					<div class="tagchecklist">
						<span>
							<a class="clear-session ntdelbutton">X</a>&nbsp;<?php echo get_the_title( $session_id ); ?>
						</span>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="tablenav top">
			<div class="alignleft actions">
				<input type="text" placeholder="Search" name="search" value="<?php echo $search; ?>">

				<input type="submit" class="button button-primary" value="<?php esc_html_e( 'Generate Report', 'wp-logger' ); ?>">
			</div>

			<?php $logger_table->pagination( 'top' ); ?>
			<br class="clear">
		</div>

		<table class="wp-list-table <?php echo implode( ' ', $logger_table->get_table_classes() ); ?>">
			<thead>
				<tr>
					<?php $logger_table->print_column_headers(); ?>
				</tr>
			</thead>

			<tfoot>
				<tr>
					<?php $logger_table->print_column_headers( false ); ?>
				</tr>
			</tfoot>

			<tbody>
				<?php $logger_table->display_rows_or_placeholder(); ?>
			</tbody>
		</table>
	</form>

	<h3><?php esc_html_e( 'Email Results', 'wp-logger' ); ?></h3>

	<div id="email-response"></div>

	<div class="form-field send-email-form">
		<p><?php esc_html_e( 'You can easily email the current log report that you have generated by entering an email below and clicking send!', 'wp-logger' ); ?></p>
		<label for="email-results">Email</label>
		<input name="email-logs" value="<?php echo esc_attr( $this->get_plugin_email( $plugin_select ) ); ?>" id="email-results" type="text" size="40" aria-required="true" placeholder="<?php esc_html_e( 'Email', 'wp-logger' ); ?>">
	</div>

	<p>
		<span>
			<button id="send-logger-email" class="button"><?php esc_html_e( 'Send', 'wp-logger' ); ?></button>
		</span>
	</p>
</div>