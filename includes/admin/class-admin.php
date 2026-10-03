<?php
/**
 * Admin screen: Settings → Site Manager.
 *
 * Four tabs, all standard WordPress admin markup:
 *   Connect      — endpoint URL and setup steps for Claude apps
 *   Tools        — enable/disable tool categories and gated capabilities
 *   Connections  — OAuth clients holding tokens, with revoke
 *   Activity     — log of tool calls
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
			'activity'    => __( 'Activity', 'site-manager' ),
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
		);
		if ( isset( $_GET['message'] ) && isset( $messages[ $_GET['message'] ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $_GET['message'] ] ) );
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
					<th scope="row"><?php esc_html_e( 'Log reads', 'site-manager' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="log_reads" value="1" <?php checked( ! empty( $settings['log_reads'] ) ); ?> />
							<?php esc_html_e( 'Also log read-only tool calls (writes and errors are always logged).', 'site-manager' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sm-retention"><?php esc_html_e( 'Keep entries for', 'site-manager' ); ?></label></th>
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
		} elseif ( ! empty( $_POST['client_id'] ) ) {
			Site_Manager_OAuth_Store::revoke_client( sanitize_text_field( wp_unslash( $_POST['client_id'] ) ), isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : null );
		}
		wp_safe_redirect( self::url( 'connections', array( 'message' => 'revoked' ) ) );
		exit;
	}

	// ---------------------------------------------------------------
	// Activity
	// ---------------------------------------------------------------

	private function render_activity() {
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
			<input type="hidden" name="tab" value="activity" />
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
		wp_safe_redirect( self::url( 'activity', array( 'message' => 'cleared' ) ) );
		exit;
	}
}
