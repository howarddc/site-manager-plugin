<?php
/**
 * Admin screen: Settings → Site Manager.
 *
 * Tabs, all standard WordPress admin markup:
 *   Connect      — endpoint URL and setup steps for Claude apps
 *   Tools        — tool categories, gated capabilities, log settings
 *   Connections  — OAuth clients holding tokens, with revoke
 *   Reports      — branded monthly client reports (PDF + email)
 *   Activity Log — site-wide audit trail of every detectable action
 *   MCP Calls    — log of MCP tool calls
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Admin {

	const PAGE = 'site-manager';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_site_manager_save_tools', array( $this, 'save_tools' ) );
		add_action( 'admin_post_site_manager_revoke', array( $this, 'revoke' ) );
		add_action( 'admin_post_site_manager_clear_log', array( $this, 'clear_log' ) );
		add_action( 'admin_post_site_manager_activity_export', array( $this, 'activity_export' ) );
		add_action( 'admin_post_site_manager_report_settings', array( $this, 'report_settings_save' ) );
		add_action( 'admin_post_site_manager_report_run', array( $this, 'report_run' ) );
		add_action( 'admin_post_site_manager_report_download', array( $this, 'report_download' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public static function url( $tab = '', array $args = array() ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		if ( $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, $url );
	}

	public function add_menu() {
		add_options_page(
			__( 'Site Manager', 'site-manager' ),
			__( 'Site Manager', 'site-manager' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	private static function tabs() {
		return array(
			'connect'     => __( 'Connect', 'site-manager' ),
			'tools'       => __( 'Tools', 'site-manager' ),
			'connections' => __( 'Connections', 'site-manager' ),
			'reports'     => __( 'Reports', 'site-manager' ),
			'activity'    => __( 'Activity Log', 'site-manager' ),
			'mcp'         => __( 'MCP Calls', 'site-manager' ),
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'connect';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Site Manager', 'site-manager' ); ?></h1>
			<?php $this->notices(); ?>
			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Secondary menu', 'site-manager' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $slug ) ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $slug === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php call_user_func( array( $this, 'render_' . $tab ) ); ?>
		</div>
		<?php
	}

	private function notices() {
		$messages = array(
			'saved'   => __( 'Settings saved.', 'site-manager' ),
			'revoked' => __( 'Connection revoked.', 'site-manager' ),
			'cleared' => __( 'Activity log cleared.', 'site-manager' ),
			'report_saved' => __( 'Report settings saved.', 'site-manager' ),
			'report_sent'  => __( 'Report emailed.', 'site-manager' ),
		);
		if ( isset( $_GET['message'] ) && isset( $messages[ $_GET['message'] ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $_GET['message'] ] ) );
		}
		$error = get_transient( 'site_manager_admin_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'site_manager_admin_error_' . get_current_user_id() );
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $error ) );
		}
	}

	// ---------------------------------------------------------------
	// Connect
	// ---------------------------------------------------------------

	private function render_connect() {
		$endpoint = Site_Manager_Server::endpoint_url();
		$count    = count( Site_Manager_Registry::instance()->available() );
		$user     = wp_get_current_user();
		$cli      = sprintf( 'claude mcp add --transport http site-manager %s', $endpoint );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sm-endpoint"><?php esc_html_e( 'MCP server URL', 'site-manager' ); ?></label></th>
				<td>
					<input type="text" id="sm-endpoint" class="large-text code" readonly value="<?php echo esc_attr( $endpoint ); ?>" onclick="this.select();" />
					<p class="description">
						<?php
						printf(
							/* translators: %d: number of tools */
							esc_html( _n( '%d tool available.', '%d tools available.', $count, 'site-manager' ) ),
							(int) $count
						);
						?>
						<?php esc_html_e( 'Only administrators can connect.', 'site-manager' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Claude (claude.ai, Desktop, Cowork)', 'site-manager' ); ?></h2>
		<ol>
			<li><?php echo wp_kses_post( __( 'In Claude, open <strong>Settings → Connectors → Add custom connector</strong>.', 'site-manager' ) ); ?></li>
			<li><?php esc_html_e( 'Paste the MCP server URL above and add it. Leave the OAuth client fields empty.', 'site-manager' ); ?></li>
			<li><?php esc_html_e( 'Claude opens this site in your browser. Sign in as an administrator and click Approve.', 'site-manager' ); ?></li>
		</ol>

		<h2><?php esc_html_e( 'Claude Code', 'site-manager' ); ?></h2>
		<p><?php esc_html_e( 'Run this in a terminal, then use /mcp inside Claude Code to authenticate:', 'site-manager' ); ?></p>
		<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $cli ); ?>" onclick="this.select();" /></p>

		<h2><?php esc_html_e( 'Other clients (Application Password)', 'site-manager' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: link to profile, 2: username */
				wp_kses_post( __( 'For clients without OAuth support, create an <a href="%1$s">Application Password</a> and send it as HTTP Basic auth with your username <code>%2$s</code>.', 'site-manager' ) ),
				esc_url( admin_url( 'profile.php#application-passwords-section' ) ),
				esc_html( $user->user_login )
			);
			?>
		</p>
		<pre class="code" style="background:#fff;border:1px solid #c3c4c7;padding:10px 12px;overflow:auto;max-width:100%;">curl -s -u "<?php echo esc_html( $user->user_login ); ?>:APPLICATION_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
  <?php echo esc_html( $endpoint ); ?></pre>

		<details>
			<summary><?php esc_html_e( 'Discovery URLs', 'site-manager' ); ?></summary>
			<ul>
				<li><code><?php echo esc_html( home_url( '/' . Site_Manager_OAuth_Discovery::PR_PATH ) ); ?></code></li>
				<li><code><?php echo esc_html( home_url( '/' . Site_Manager_OAuth_Discovery::AS_PATH ) ); ?></code></li>
			</ul>
		</details>
		<?php
	}

	// ---------------------------------------------------------------
	// Tools
	// ---------------------------------------------------------------

	private function render_tools() {
		$registry   = Site_Manager_Registry::instance();
		$categories = $registry->categories();
		$by_cat     = $registry->by_category();
		$settings   = Site_Manager_Settings::all();
		$file_lock  = ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="site_manager_save_tools" />
			<?php wp_nonce_field( 'site_manager_save_tools' ); ?>

			<h2><?php esc_html_e( 'Tool groups', 'site-manager' ); ?></h2>
			<p><?php esc_html_e( 'Unchecked groups are hidden from connected clients.', 'site-manager' ); ?></p>
			<table class="widefat striped" style="max-width:960px;">
				<thead>
					<tr>
						<td class="check-column"></td>
						<th scope="col"><?php esc_html_e( 'Group', 'site-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tools', 'site-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $categories as $slug => $cat ) : ?>
					<?php $tools = isset( $by_cat[ $slug ] ) ? $by_cat[ $slug ] : array(); ?>
					<tr>
						<th scope="row" class="check-column">
							<input type="checkbox" id="sm-cat-<?php echo esc_attr( $slug ); ?>" name="categories[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( Site_Manager_Settings::category_enabled( $slug ) ); ?> />
						</th>
						<td>
							<label for="sm-cat-<?php echo esc_attr( $slug ); ?>"><strong><?php echo esc_html( $cat['label'] ); ?></strong></label>
							<p class="description"><?php echo esc_html( $cat['description'] ); ?></p>
						</td>
						<td>
							<details>
								<summary><?php echo esc_html( sprintf( _n( '%d tool', '%d tools', count( $tools ), 'site-manager' ), count( $tools ) ) ); ?></summary>
								<ul style="margin:6px 0 0;">
									<?php foreach ( $tools as $name => $tool ) : ?>
										<li>
											<code><?php echo esc_html( $name ); ?></code>
											<?php if ( $tool['destructive'] ) : ?>
												<span class="description">&middot; <?php esc_html_e( 'destructive', 'site-manager' ); ?></span>
											<?php elseif ( $tool['writes'] ) : ?>
												<span class="description">&middot; <?php esc_html_e( 'writes', 'site-manager' ); ?></span>
											<?php endif; ?>
											<?php if ( $tool['gate'] && ! Site_Manager_Settings::gate_open( $tool['gate'] ) ) : ?>
												<span class="description">&middot; <?php esc_html_e( 'off (see below)', 'site-manager' ); ?></span>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							</details>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Advanced capabilities', 'site-manager' ); ?></h2>
			<p><?php esc_html_e( 'These give a connected client direct control of the server. Leave them off unless you need them.', 'site-manager' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( Site_Manager_Settings::gates() as $key => $gate ) : ?>
					<?php $locked = $key === 'allow_file_write' && $file_lock; ?>
					<tr>
						<th scope="row"><?php echo esc_html( $gate['label'] ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?> <?php disabled( $locked ); ?> />
								<?php esc_html_e( 'Enable', 'site-manager' ); ?>
							</label>
							<p class="description"><?php echo esc_html( $gate['description'] ); ?></p>
							<?php if ( $locked ) : ?>
								<p class="description"><em><?php esc_html_e( 'Unavailable: file editing is disabled in wp-config.php.', 'site-manager' ); ?></em></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2><?php esc_html_e( 'Activity log', 'site-manager' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Site activity', 'site-manager' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="activity_enabled" value="1" <?php checked( ! empty( $settings['activity_enabled'] ) ); ?> />
							<?php esc_html_e( 'Record every detectable action on the site (logins, updates, content, users, settings…).', 'site-manager' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-activity-retention"><?php esc_html_e( 'Keep site activity for', 'site-manager' ); ?></label></th>
					<td>
						<input type="number" id="sm-activity-retention" name="activity_retention_days" min="0" max="3650" class="small-text" value="<?php echo (int) $settings['activity_retention_days']; ?>" />
						<?php esc_html_e( 'days', 'site-manager' ); ?>
						<p class="description"><?php esc_html_e( '0 keeps entries forever. Audits usually need at least 365 days.', 'site-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-activity-ip"><?php esc_html_e( 'IP addresses', 'site-manager' ); ?></label></th>
					<td>
						<select id="sm-activity-ip" name="activity_ip">
							<option value="full" <?php selected( $settings['activity_ip'], 'full' ); ?>><?php esc_html_e( 'Store full IP', 'site-manager' ); ?></option>
							<option value="anonymized" <?php selected( $settings['activity_ip'], 'anonymized' ); ?>><?php esc_html_e( 'Anonymize (drop last octet)', 'site-manager' ); ?></option>
							<option value="off" <?php selected( $settings['activity_ip'], 'off' ); ?>><?php esc_html_e( 'Do not store', 'site-manager' ); ?></option>
						</select>
						<label for="sm-activity-ip-header" style="margin-left:12px;"><?php esc_html_e( 'Read from', 'site-manager' ); ?></label>
						<select id="sm-activity-ip-header" name="activity_ip_header">
							<?php foreach ( self::ip_headers() as $header => $label ) : ?>
								<option value="<?php echo esc_attr( $header ); ?>" <?php selected( $settings['activity_ip_header'], $header ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Only choose a proxy header if the site is behind that proxy (otherwise visitors can fake their IP).', 'site-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Log reads', 'site-manager' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="log_reads" value="1" <?php checked( ! empty( $settings['log_reads'] ) ); ?> />
							<?php esc_html_e( 'Also log read-only tool calls (writes and errors are always logged).', 'site-manager' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-retention"><?php esc_html_e( 'Keep MCP calls for', 'site-manager' ); ?></label></th>
					<td>
						<input type="number" id="sm-retention" name="log_retention_days" min="1" max="3650" class="small-text" value="<?php echo (int) $settings['log_retention_days']; ?>" />
						<?php esc_html_e( 'days', 'site-manager' ); ?>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	public function save_tools() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'site-manager' ), 403 );
		}
		check_admin_referer( 'site_manager_save_tools' );

		$enabled  = isset( $_POST['categories'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['categories'] ) ) : array();
		$all      = array_keys( Site_Manager_Registry::instance()->categories() );
		$values   = array(
			'disabled_categories' => array_values( array_diff( $all, $enabled ) ),
			'log_reads'           => ! empty( $_POST['log_reads'] ),
			'log_retention_days'  => max( 1, min( 3650, (int) ( isset( $_POST['log_retention_days'] ) ? $_POST['log_retention_days'] : 30 ) ) ),
			'activity_enabled'        => ! empty( $_POST['activity_enabled'] ),
			'activity_retention_days' => max( 0, min( 3650, (int) ( isset( $_POST['activity_retention_days'] ) ? $_POST['activity_retention_days'] : 365 ) ) ),
			'activity_ip'             => isset( $_POST['activity_ip'] ) && in_array( $_POST['activity_ip'], array( 'full', 'anonymized', 'off' ), true ) ? $_POST['activity_ip'] : 'full',
			'activity_ip_header'      => isset( $_POST['activity_ip_header'] ) && isset( self::ip_headers()[ $_POST['activity_ip_header'] ] ) ? $_POST['activity_ip_header'] : 'REMOTE_ADDR',
		);
		foreach ( array_keys( Site_Manager_Settings::gates() ) as $gate ) {
			$values[ $gate ] = ! empty( $_POST[ $gate ] );
		}
		Site_Manager_Settings::update( $values );

		wp_safe_redirect( self::url( 'tools', array( 'message' => 'saved' ) ) );
		exit;
	}

	// ---------------------------------------------------------------
	// Connections
	// ---------------------------------------------------------------

	private function render_connections() {
		$rows = Site_Manager_OAuth_Store::connections();
		$fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<p><?php esc_html_e( 'Applications connected through OAuth. Revoking signs the application out immediately; it can reconnect only with an administrator\'s approval.', 'site-manager' ); ?></p>
		<table class="widefat striped" style="max-width:960px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Application', 'site-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'User', 'site-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Connected', 'site-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last used', 'site-manager' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No applications are connected.', 'site-manager' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<?php $u = get_userdata( (int) $row['user_id'] ); ?>
				<tr>
					<td><strong><?php echo esc_html( $row['client_name'] ?: __( '(unknown)', 'site-manager' ) ); ?></strong></td>
					<td><?php echo esc_html( $u ? $u->user_login : '#' . $row['user_id'] ); ?></td>
					<td><?php echo esc_html( wp_date( $fmt, strtotime( $row['connected_at'] . ' UTC' ) ) ); ?></td>
					<td><?php echo $row['last_used_at'] ? esc_html( sprintf( __( '%s ago', 'site-manager' ), human_time_diff( strtotime( $row['last_used_at'] . ' UTC' ) ) ) ) : '&mdash;'; ?></td>
					<td style="text-align:right;">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<input type="hidden" name="action" value="site_manager_revoke" />
							<input type="hidden" name="client_id" value="<?php echo esc_attr( $row['client_id'] ); ?>" />
							<input type="hidden" name="user_id" value="<?php echo (int) $row['user_id']; ?>" />
							<?php wp_nonce_field( 'site_manager_revoke' ); ?>
							<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Revoke', 'site-manager' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( count( $rows ) > 1 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<input type="hidden" name="action" value="site_manager_revoke" />
				<input type="hidden" name="all" value="1" />
				<?php wp_nonce_field( 'site_manager_revoke' ); ?>
				<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Disconnect every application?', 'site-manager' ) ); ?>');"><?php esc_html_e( 'Revoke all', 'site-manager' ); ?></button>
			</form>
		<?php endif; ?>
		<p class="description" style="margin-top:16px;">
			<?php
			printf(
				/* translators: %s: link to profile */
				wp_kses_post( __( 'Application Passwords are managed on each user\'s <a href="%s">profile</a>.', 'site-manager' ) ),
				esc_url( admin_url( 'profile.php#application-passwords-section' ) )
			);
			?>
		</p>
		<?php
	}

	public function revoke() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'site-manager' ), 403 );
		}
		check_admin_referer( 'site_manager_revoke' );
		if ( ! empty( $_POST['all'] ) ) {
			Site_Manager_OAuth_Store::revoke_all();
			$name = __( 'all clients', 'site-manager' );
		} elseif ( ! empty( $_POST['client_id'] ) ) {
			$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ) );
			$client    = Site_Manager_OAuth_Store::get_client( $client_id );
			$name      = $client ? $client['client_name'] : $client_id;
			Site_Manager_OAuth_Store::revoke_client( $client_id, isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : null );
		}
		if ( isset( $name ) ) {
			Site_Manager_Activity::log( array(
				'category'    => 'site_manager',
				'action'      => 'mcp_client_revoked',
				'severity'    => 'notice',
				'object_type' => 'oauth_client',
				'object_name' => $name,
				'message'     => sprintf( 'MCP access revoked for %s.', $name ),
			) );
		}
		wp_safe_redirect( self::url( 'connections', array( 'message' => 'revoked' ) ) );
		exit;
	}

	// ---------------------------------------------------------------
	// Reports
	// ---------------------------------------------------------------

	public function enqueue( $hook ) {
		if ( $hook === 'settings_page_' . self::PAGE && isset( $_GET['tab'] ) && $_GET['tab'] === 'reports' ) {
			wp_enqueue_media();
		}
	}

	private static function fail( $tab, $message ) {
		set_transient( 'site_manager_admin_error_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/** The last 12 complete months plus the current month, newest first. */
	private static function month_options() {
		$out = array();
		$d   = new DateTime( 'first day of this month', wp_timezone() );
		for ( $i = 0; $i < 13; $i++ ) {
			$out[ $d->format( 'Y-m' ) ] = wp_date( 'F Y', $d->getTimestamp() ) . ( $i === 0 ? ' ' . __( '(so far)', 'site-manager' ) : '' );
			$d->modify( '-1 month' );
		}
		return $out;
	}

	private function render_reports() {
		$s        = Site_Manager_Report::settings();
		$logo_url = $s['logo_id'] ? wp_get_attachment_image_url( $s['logo_id'], 'medium' ) : '';
		$archive  = Site_Manager_Report::archive();
		$last     = ( new DateTime( 'first day of last month', wp_timezone() ) )->format( 'Y-m' );
		$fmt      = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<p><?php esc_html_e( 'A branded PDF summarizing updates, content, users, security, sales and site health — for your client, by month.', 'site-manager' ); ?></p>

		<div class="card" style="max-width:none;margin:16px 0;">
			<h2 class="title"><?php esc_html_e( 'Generate a report', 'site-manager' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="site_manager_report_run" />
				<?php wp_nonce_field( 'site_manager_report_run' ); ?>
				<label for="sm-report-month"><?php esc_html_e( 'Month', 'site-manager' ); ?></label>
				<select id="sm-report-month" name="month">
					<?php foreach ( self::month_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $last ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" name="do" value="download" class="button"><?php esc_html_e( 'Download PDF', 'site-manager' ); ?></button>
				<button type="submit" name="do" value="send" class="button button-primary" onclick="return confirm('<?php echo esc_js( $s['recipients'] ? sprintf( __( 'Email this report to %s?', 'site-manager' ), implode( ', ', $s['recipients'] ) ) : __( 'No recipients are set yet — add them below first.', 'site-manager' ) ); ?>');" <?php disabled( ! $s['recipients'] ); ?>><?php esc_html_e( 'Email to recipients', 'site-manager' ); ?></button>
				<p>
					<label for="sm-report-note" class="screen-reader-text"><?php esc_html_e( 'Note for this report', 'site-manager' ); ?></label>
					<input type="text" id="sm-report-note" name="note" class="large-text" placeholder="<?php esc_attr_e( 'Optional note for this report only (e.g. “We also redesigned the contact page this month.”)', 'site-manager' ); ?>" />
				</p>
			</form>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="site_manager_report_settings" />
			<?php wp_nonce_field( 'site_manager_report_settings' ); ?>

			<h2><?php esc_html_e( 'Branding', 'site-manager' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sm-r-title"><?php esc_html_e( 'Report title', 'site-manager' ); ?></label></th>
					<td><input type="text" id="sm-r-title" name="title" class="regular-text" value="<?php echo esc_attr( $s['title'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-r-agency"><?php esc_html_e( 'Agency name', 'site-manager' ); ?></label></th>
					<td>
						<input type="text" id="sm-r-agency" name="agency_name" class="regular-text" value="<?php echo esc_attr( $s['agency_name'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Shown in the letterhead and footer, and used as the email sender name.', 'site-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-r-url"><?php esc_html_e( 'Agency website', 'site-manager' ); ?></label></th>
					<td><input type="url" id="sm-r-url" name="agency_url" class="regular-text" value="<?php echo esc_attr( $s['agency_url'] ); ?>" placeholder="https://" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-r-contact"><?php esc_html_e( 'Contact line', 'site-manager' ); ?></label></th>
					<td><input type="text" id="sm-r-contact" name="agency_contact" class="regular-text" value="<?php echo esc_attr( $s['agency_contact'] ); ?>" placeholder="<?php esc_attr_e( 'support@agency.com · (555) 123-4567', 'site-manager' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Logo', 'site-manager' ); ?></th>
					<td>
						<input type="hidden" id="sm-r-logo" name="logo_id" value="<?php echo (int) $s['logo_id']; ?>" />
						<div id="sm-r-logo-preview" style="margin-bottom:8px;"><?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" alt="" style="max-height:48px;max-width:200px;" /><?php endif; ?></div>
						<button type="button" class="button" id="sm-r-logo-pick"><?php esc_html_e( 'Choose logo', 'site-manager' ); ?></button>
						<button type="button" class="button-link button-link-delete" id="sm-r-logo-clear" <?php echo $s['logo_id'] ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'Remove', 'site-manager' ); ?></button>
						<p class="description"><?php esc_html_e( 'JPEG, PNG, GIF or WebP. Transparent areas print on white.', 'site-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-r-color"><?php esc_html_e( 'Accent color', 'site-manager' ); ?></label></th>
					<td><input type="color" id="sm-r-color" name="accent_color" value="<?php echo esc_attr( $s['accent_color'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Paper size', 'site-manager' ); ?></th>
					<td>
						<label><input type="radio" name="paper" value="letter" <?php checked( $s['paper'], 'letter' ); ?> /> <?php esc_html_e( 'US Letter', 'site-manager' ); ?></label>
						&nbsp; <label><input type="radio" name="paper" value="a4" <?php checked( $s['paper'], 'a4' ); ?> /> A4</label>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Content', 'site-manager' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sm-r-intro"><?php esc_html_e( 'Introduction', 'site-manager' ); ?></label></th>
					<td>
						<textarea id="sm-r-intro" name="intro" rows="3" class="large-text"><?php echo esc_textarea( $s['intro'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Shown at the top of every report and email.', 'site-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sections', 'site-manager' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( Site_Manager_Report::sections() as $key => $label ) : ?>
								<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="sections[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $s['sections'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Store and form sections only appear when there is data for them.', 'site-manager' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Delivery', 'site-manager' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sm-r-recipients"><?php esc_html_e( 'Recipients', 'site-manager' ); ?></label></th>
					<td>
						<textarea id="sm-r-recipients" name="recipients" rows="3" class="large-text code" placeholder="client@example.com"><?php echo esc_textarea( implode( "\n", $s['recipients'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One email address per line (commas also work). Any addresses — they do not need WordPress accounts.', 'site-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-r-subject"><?php esc_html_e( 'Email subject', 'site-manager' ); ?></label></th>
					<td>
						<input type="text" id="sm-r-subject" name="subject" class="large-text" value="<?php echo esc_attr( $s['subject'] ); ?>" />
						<p class="description"><?php echo wp_kses_post( __( 'Placeholders: <code>{title}</code> <code>{site}</code> <code>{period}</code> <code>{agency}</code>', 'site-manager' ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-r-reply"><?php esc_html_e( 'Reply-To', 'site-manager' ); ?></label></th>
					<td><input type="email" id="sm-r-reply" name="reply_to" class="regular-text" value="<?php echo esc_attr( $s['reply_to'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Schedule', 'site-manager' ); ?></th>
					<td>
						<label><input type="checkbox" name="schedule" value="monthly" <?php checked( $s['schedule'], 'monthly' ); ?> /> <?php esc_html_e( 'Email last month’s report automatically on day', 'site-manager' ); ?></label>
						<input type="number" name="send_day" min="1" max="28" class="small-text" value="<?php echo (int) $s['send_day']; ?>" aria-label="<?php esc_attr_e( 'Day of month', 'site-manager' ); ?>" />
						<?php esc_html_e( 'of each month', 'site-manager' ); ?>
						<?php if ( $s['last_sent'] ) : ?>
							<p class="description"><?php echo esc_html( sprintf( __( 'Last scheduled report: %s.', 'site-manager' ), $s['last_sent'] ) ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save report settings', 'site-manager' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Archive', 'site-manager' ); ?></h2>
		<table class="widefat striped" style="max-width:960px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Period', 'site-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Generated', 'site-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Emailed to', 'site-manager' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $archive ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No reports yet.', 'site-manager' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $archive as $entry ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $entry['period'] ); ?></strong></td>
					<td><?php echo esc_html( mysql2date( $fmt, $entry['generated_at'] ) . ' · ' . $entry['generated_by'] ); ?></td>
					<td><?php echo $entry['sent_to'] ? esc_html( implode( ', ', $entry['sent_to'] ) . ' (' . mysql2date( $fmt, $entry['sent_at'] ) . ')' ) : '&mdash;'; ?></td>
					<td style="text-align:right;"><a class="button button-small" href="<?php echo esc_url( Site_Manager_Report::download_url( $entry['id'] ) ); ?>"><?php esc_html_e( 'Download', 'site-manager' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<script>
		// Media scripts load in the footer, after this markup; wait for them.
		document.addEventListener( 'DOMContentLoaded', function () {
			var frame, pick = document.getElementById( 'sm-r-logo-pick' ), clear = document.getElementById( 'sm-r-logo-clear' );
			var input = document.getElementById( 'sm-r-logo' ), preview = document.getElementById( 'sm-r-logo-preview' );
			if ( ! pick || ! window.wp || ! wp.media ) { return; }
			pick.addEventListener( 'click', function () {
				frame = frame || wp.media( { title: <?php echo wp_json_encode( __( 'Choose logo', 'site-manager' ) ); ?>, library: { type: 'image' }, multiple: false } );
				frame.off( 'select' ).on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					input.value = a.id;
					var url = ( a.sizes && a.sizes.medium ) ? a.sizes.medium.url : a.url;
					preview.innerHTML = '<img src="' + url + '" alt="" style="max-height:48px;max-width:200px;" />';
					clear.style.display = '';
				} );
				frame.open();
			} );
			clear.addEventListener( 'click', function () { input.value = 0; preview.innerHTML = ''; clear.style.display = 'none'; } );
		} );
		</script>
		<?php
	}

	public function report_settings_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'site-manager' ), 403 );
		}
		check_admin_referer( 'site_manager_report_settings' );
		$post   = wp_unslash( $_POST );
		$result = Site_Manager_Report::update_settings( array(
			'title'          => isset( $post['title'] ) ? $post['title'] : '',
			'agency_name'    => isset( $post['agency_name'] ) ? $post['agency_name'] : '',
			'agency_url'     => isset( $post['agency_url'] ) ? $post['agency_url'] : '',
			'agency_contact' => isset( $post['agency_contact'] ) ? $post['agency_contact'] : '',
			'logo_id'        => isset( $post['logo_id'] ) ? (int) $post['logo_id'] : 0,
			'accent_color'   => isset( $post['accent_color'] ) ? $post['accent_color'] : '#2271b1',
			'paper'          => isset( $post['paper'] ) ? $post['paper'] : 'letter',
			'intro'          => isset( $post['intro'] ) ? $post['intro'] : '',
			'sections'       => isset( $post['sections'] ) ? (array) $post['sections'] : array(),
			'recipients'     => isset( $post['recipients'] ) ? $post['recipients'] : '',
			'subject'        => isset( $post['subject'] ) ? $post['subject'] : '',
			'reply_to'       => isset( $post['reply_to'] ) ? $post['reply_to'] : '',
			'schedule'       => isset( $post['schedule'] ) ? 'monthly' : 'off',
			'send_day'       => isset( $post['send_day'] ) ? (int) $post['send_day'] : 3,
		) );
		if ( is_wp_error( $result ) ) {
			self::fail( 'reports', $result->get_error_message() );
		}
		wp_safe_redirect( self::url( 'reports', array( 'message' => 'report_saved' ) ) );
		exit;
	}

	/** Generate a report from the admin, then download it or email it. */
	public function report_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'site-manager' ), 403 );
		}
		check_admin_referer( 'site_manager_report_run' );
		$month  = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$period = Site_Manager_Report::period( $month );
		if ( is_wp_error( $period ) ) {
			self::fail( 'reports', $period->get_error_message() );
		}
		list( $after, $before, $label ) = $period;
		$report = Site_Manager_Report::generate( $after, $before, $label, true, $note );

		if ( isset( $_POST['do'] ) && $_POST['do'] === 'send' ) {
			$result = Site_Manager_Report::send( $report['entry']['id'], array(), $note );
			if ( is_wp_error( $result ) ) {
				self::fail( 'reports', $result->get_error_message() );
			}
			wp_safe_redirect( self::url( 'reports', array( 'message' => 'report_sent' ) ) );
			exit;
		}
		$this->stream_pdf( $report['pdf'], $report['data'] );
	}

	public function report_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'site-manager' ), 403 );
		}
		check_admin_referer( 'site_manager_report_download' );
		$entry = Site_Manager_Report::archive_get( isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '' );
		if ( ! $entry || ! file_exists( Site_Manager_Report::archive_path( $entry ) ) ) {
			self::fail( 'reports', __( 'That report is no longer in the archive.', 'site-manager' ) );
		}
		$this->stream_pdf( file_get_contents( Site_Manager_Report::archive_path( $entry ) ), array(
			'site'   => array( 'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'title'  => Site_Manager_Report::settings()['title'],
			'period' => array( 'label' => $entry['period'] ),
		) );
	}

	private function stream_pdf( $pdf, array $data ) {
		$name = sanitize_file_name( $data['site']['name'] . ' - ' . $data['title'] . ' - ' . $data['period']['label'] ) . '.pdf';
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF.
		exit;
	}

	// ---------------------------------------------------------------
	// Activity
	// ---------------------------------------------------------------

	public static function ip_headers() {
		return array(
			'REMOTE_ADDR'           => __( 'Connection (REMOTE_ADDR)', 'site-manager' ),
			'HTTP_CF_CONNECTING_IP' => __( 'Cloudflare (CF-Connecting-IP)', 'site-manager' ),
			'HTTP_X_FORWARDED_FOR'  => __( 'Proxy / load balancer (X-Forwarded-For)', 'site-manager' ),
			'HTTP_X_REAL_IP'        => __( 'Nginx proxy (X-Real-IP)', 'site-manager' ),
		);
	}

	/** Activity-log filters from the query string. */
	private static function activity_filters() {
		$f = array();
		foreach ( array( 'after', 'before', 'category', 'severity', 'source', 'search', 'user_login', 'action' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && $_GET[ $key ] !== '' ) {
				$f[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		return $f;
	}

	private function render_activity() {
		$filters  = self::activity_filters();
		$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = 50;
		$result   = Site_Manager_Activity::query( $filters, $page, $per_page );
		$pages    = (int) ceil( $result['total'] / $per_page );
		$colors   = array( 'notice' => '#2271b1', 'warning' => '#996800', 'critical' => '#d63638' );
		$select   = function ( $name, $label, array $values ) use ( $filters ) {
			echo '<label class="screen-reader-text" for="sm-f-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
			echo '<select id="sm-f-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"><option value="">' . esc_html( $label ) . '</option>';
			foreach ( $values as $v ) {
				echo '<option value="' . esc_attr( $v ) . '"' . selected( isset( $filters[ $name ] ) ? $filters[ $name ] : '', $v, false ) . '>' . esc_html( $v ) . '</option>';
			}
			echo '</select> ';
		};
		if ( ! Site_Manager_Activity::enabled() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Site activity logging is turned off (Tools tab). Existing entries are shown below.', 'site-manager' ) . '</p></div>';
		}
		?>
		<form method="get" style="margin:12px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<input type="hidden" name="tab" value="activity" />
			<label for="sm-f-after"><?php esc_html_e( 'From', 'site-manager' ); ?></label>
			<input type="date" id="sm-f-after" name="after" value="<?php echo esc_attr( isset( $filters['after'] ) ? $filters['after'] : '' ); ?>" />
			<label for="sm-f-before"><?php esc_html_e( 'to', 'site-manager' ); ?></label>
			<input type="date" id="sm-f-before" name="before" value="<?php echo esc_attr( isset( $filters['before'] ) ? $filters['before'] : '' ); ?>" />
			<?php
			$select( 'category', __( 'All categories', 'site-manager' ), Site_Manager_Activity::distinct( 'category' ) );
			$select( 'severity', __( 'All severities', 'site-manager' ), Site_Manager_Activity::SEVERITIES );
			$select( 'source', __( 'All sources', 'site-manager' ), Site_Manager_Activity::distinct( 'source' ) );
			?>
			<label class="screen-reader-text" for="sm-f-user"><?php esc_html_e( 'User', 'site-manager' ); ?></label>
			<input type="search" id="sm-f-user" name="user_login" size="12" placeholder="<?php esc_attr_e( 'Username', 'site-manager' ); ?>" value="<?php echo esc_attr( isset( $filters['user_login'] ) ? $filters['user_login'] : '' ); ?>" />
			<label class="screen-reader-text" for="sm-f-search"><?php esc_html_e( 'Search', 'site-manager' ); ?></label>
			<input type="search" id="sm-f-search" name="search" placeholder="<?php esc_attr_e( 'Search text, IP…', 'site-manager' ); ?>" value="<?php echo esc_attr( isset( $filters['search'] ) ? $filters['search'] : '' ); ?>" />
			<?php submit_button( __( 'Filter', 'site-manager' ), 'secondary', '', false ); ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( $filters, array( 'action' => 'site_manager_activity_export' ) ), admin_url( 'admin-post.php' ) ), 'site_manager_activity_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'site-manager' ); ?></a>
		</form>

		<p class="description">
			<?php
			printf(
				/* translators: %s: number of events */
				esc_html__( '%s events match. Times are in the site timezone.', 'site-manager' ),
				esc_html( number_format_i18n( $result['total'] ) )
			);
			?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col" style="width:150px;"><?php esc_html_e( 'Time', 'site-manager' ); ?></th>
					<th scope="col" style="width:140px;"><?php esc_html_e( 'User', 'site-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event', 'site-manager' ); ?></th>
					<th scope="col" style="width:180px;"><?php esc_html_e( 'Source', 'site-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $result['rows'] ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No activity recorded yet.', 'site-manager' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $result['rows'] as $raw ) : ?>
				<?php $row = Site_Manager_Activity::format_row( $raw ); ?>
				<tr>
					<td>
						<?php echo esc_html( $row['time'] ); ?>
						<?php if ( $row['occurrences'] > 1 ) : ?>
							<br /><span class="description"><?php echo esc_html( sprintf( __( '×%1$d, last %2$s', 'site-manager' ), $row['occurrences'], $row['last_time'] ) ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php echo $row['user'] !== '' ? esc_html( $row['user'] ) : '<span class="description">' . esc_html( $row['source'] === 'cron' ? __( 'system', 'site-manager' ) : __( 'visitor', 'site-manager' ) ) . '</span>'; ?>
						<?php if ( $row['role'] ) : ?><br /><span class="description"><?php echo esc_html( $row['role'] ); ?></span><?php endif; ?>
					</td>
					<td>
						<?php if ( $row['severity'] !== 'info' ) : ?>
							<strong style="color:<?php echo esc_attr( $colors[ $row['severity'] ] ); ?>;text-transform:uppercase;font-size:11px;"><?php echo esc_html( $row['severity'] ); ?></strong>
						<?php endif; ?>
						<?php echo esc_html( $row['message'] ); ?>
						<br /><span class="description"><code style="font-size:11px;"><?php echo esc_html( $row['category'] . ' / ' . $row['action'] ); ?></code></span>
						<?php if ( $row['details'] ) : ?>
							<details style="display:inline;"><summary style="display:inline;cursor:pointer;" class="description"> <?php esc_html_e( 'details', 'site-manager' ); ?></summary><pre style="white-space:pre-wrap;word-break:break-all;font-size:12px;max-width:640px;"><?php echo esc_html( wp_json_encode( $row['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre></details>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( $row['source'] ); ?><?php echo $row['via'] ? '<br /><span class="description">' . esc_html( $row['via'] ) . '</span>' : ''; ?>
						<?php if ( $row['ip'] ) : ?><br /><span class="description" title="<?php echo esc_attr( $row['user_agent'] ); ?>"><?php echo esc_html( $row['ip'] ); ?></span><?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post( paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $page,
					'total'   => $pages,
				) ) );
				?>
			</div></div>
		<?php endif; ?>
		<?php
	}

	/** Stream the filtered activity log as CSV. */
	public function activity_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'site-manager' ), 403 );
		}
		check_admin_referer( 'site_manager_activity_export' );
		$filters = self::activity_filters();
		unset( $filters['action'] ); // "action" here is the admin-post route.

		Site_Manager_Activity::log( array(
			'category'    => 'site_manager',
			'action'      => 'activity_log_exported',
			'severity'    => 'notice',
			'object_type' => 'log',
			'object_name' => 'Activity log',
			'message'     => 'Activity log exported to CSV.',
			'details'     => array( 'filters' => $filters ),
		) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="activity-' . sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . wp_date( 'Y-m-d' ) . '.csv"' );
		$out     = fopen( 'php://output', 'w' );
		$columns = array( 'id', 'time', 'last_time', 'occurrences', 'user', 'role', 'user_id', 'severity', 'category', 'action', 'object_type', 'object_id', 'object_name', 'message', 'source', 'via', 'ip', 'user_agent', 'details' );
		fputcsv( $out, $columns, ',', '"', '' );
		$page = 1;
		do {
			$result = Site_Manager_Activity::query( $filters, $page, 1000 );
			foreach ( $result['rows'] as $raw ) {
				$row            = Site_Manager_Activity::format_row( $raw );
				$row['details'] = $row['details'] ? wp_json_encode( $row['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
				$line           = array();
				foreach ( $columns as $col ) {
					// Neutralize spreadsheet formula injection.
					$value  = (string) $row[ $col ];
					$line[] = preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value;
				}
				fputcsv( $out, $line, ',', '"', '' );
			}
			$page++;
		} while ( count( $result['rows'] ) === 1000 && $page <= 100 );
		fclose( $out );
		exit;
	}

	private function render_mcp() {
		$page    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$filters = array(
			'tool'   => isset( $_GET['tool'] ) ? sanitize_key( $_GET['tool'] ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
		);
		$result  = Site_Manager_Log::query( $filters, $page, 50 );
		$pages   = (int) ceil( $result['total'] / 50 );
		$fmt     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<form method="get" style="margin:12px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<input type="hidden" name="tab" value="mcp" />
			<label class="screen-reader-text" for="sm-tool"><?php esc_html_e( 'Tool', 'site-manager' ); ?></label>
			<input type="search" id="sm-tool" name="tool" placeholder="<?php esc_attr_e( 'Tool name', 'site-manager' ); ?>" value="<?php echo esc_attr( $filters['tool'] ); ?>" />
			<select name="status">
				<option value=""><?php esc_html_e( 'All results', 'site-manager' ); ?></option>
				<option value="ok" <?php selected( $filters['status'], 'ok' ); ?>><?php esc_html_e( 'Succeeded', 'site-manager' ); ?></option>
				<option value="error" <?php selected( $filters['status'], 'error' ); ?>><?php esc_html_e( 'Failed', 'site-manager' ); ?></option>
			</select>
			<?php submit_button( __( 'Filter', 'site-manager' ), 'secondary', '', false ); ?>
		</form>

		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col" style="width:160px;"><?php esc_html_e( 'Time', 'site-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tool', 'site-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'User / client', 'site-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'site-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Arguments', 'site-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $result['rows'] ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No activity yet.', 'site-manager' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $result['rows'] as $row ) : ?>
				<?php $u = get_userdata( (int) $row['user_id'] ); ?>
				<tr>
					<td><?php echo esc_html( wp_date( $fmt, strtotime( $row['created_at'] . ' UTC' ) ) ); ?></td>
					<td><code><?php echo esc_html( $row['tool'] ); ?></code><?php echo $row['writes'] ? ' <span class="description">' . esc_html__( 'write', 'site-manager' ) . '</span>' : ''; ?></td>
					<td><?php echo esc_html( ( $u ? $u->user_login : '#' . $row['user_id'] ) . ' · ' . $row['client'] ); ?></td>
					<td>
						<?php if ( $row['status'] === 'ok' ) : ?>
							<?php echo esc_html( sprintf( __( 'OK (%d ms)', 'site-manager' ), (int) $row['duration_ms'] ) ); ?>
						<?php else : ?>
							<span style="color:#d63638;"><?php echo esc_html( $row['message'] ); ?></span>
						<?php endif; ?>
					</td>
					<td><details><summary><?php esc_html_e( 'View', 'site-manager' ); ?></summary><pre style="white-space:pre-wrap;word-break:break-all;max-width:520px;font-size:12px;"><?php echo esc_html( $row['args'] ); ?></pre></details></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post( paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $page,
					'total'   => $pages,
				) ) );
				?>
			</div></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
			<input type="hidden" name="action" value="site_manager_clear_log" />
			<?php wp_nonce_field( 'site_manager_clear_log' ); ?>
			<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Delete all activity log entries?', 'site-manager' ) ); ?>');"><?php esc_html_e( 'Clear log', 'site-manager' ); ?></button>
		</form>
		<?php
	}

	public function clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'site-manager' ), 403 );
		}
		check_admin_referer( 'site_manager_clear_log' );
		Site_Manager_Log::clear();
		Site_Manager_Activity::log( array(
			'category'    => 'site_manager',
			'action'      => 'mcp_log_cleared',
			'severity'    => 'warning',
			'object_type' => 'log',
			'object_name' => 'MCP calls log',
			'message'     => 'MCP calls log cleared.',
		) );
		wp_safe_redirect( self::url( 'mcp', array( 'message' => 'cleared' ) ) );
		exit;
	}
}
