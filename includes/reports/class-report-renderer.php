<?php
/**
 * Renders client report data to PDF (via Site_Manager_PDF) and to the HTML
 * body of the delivery email.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Report_Renderer {

	const MARGIN = 48;
	const FOOTER = 40;

	const TEXT  = '#1d2327';
	const MUTED = '#646970';
	const RULE  = '#dcdcde';
	const ZEBRA = '#f6f7f7';
	const RED   = '#b32d2e';
	const GREEN = '#007017';

	/** @var Site_Manager_PDF */
	private $pdf;
	private $y;
	private $accent;
	private $tint;
	private $data;
	private $settings;

	/**
	 * Render report data to a PDF string.
	 */
	public static function render( array $data, array $settings ) {
		$r = new self( $data, $settings );
		return $r->build();
	}

	private function __construct( array $data, array $settings ) {
		$this->data     = $data;
		$this->settings = $settings;
		$this->accent   = $settings['accent_color'] ? $settings['accent_color'] : '#2271b1';
		$this->tint     = self::mix( $this->accent, 0.08 );
		$this->pdf      = new Site_Manager_PDF( $settings['paper'] );
	}

	/** Blend a hex color with white; $amount is the share of the color. */
	private static function mix( $hex, $amount ) {
		$hex = ltrim( $hex, '#' );
		$out = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$c    = hexdec( substr( $hex, $i * 2, 2 ) );
			$out .= sprintf( '%02x', (int) round( 255 - ( 255 - $c ) * $amount ) );
		}
		return $out;
	}

	private function content_width() {
		return $this->pdf->width - 2 * self::MARGIN;
	}

	private function bottom() {
		return $this->pdf->height - self::MARGIN - self::FOOTER;
	}

	private function new_page() {
		$this->pdf->add_page();
		$this->y = self::MARGIN;
	}

	/** Start a new page unless $h points still fit. */
	private function ensure( $h ) {
		if ( $this->y + $h > $this->bottom() ) {
			$this->new_page();
		}
	}

	private function enabled( $section ) {
		return in_array( $section, (array) $this->settings['sections'], true );
	}

	// ---------------------------------------------------------------
	// Layout
	// ---------------------------------------------------------------

	private function build() {
		$this->new_page();
		$this->letterhead();

		if ( ! empty( $this->data['intro'] ) ) {
			$this->paragraph( $this->data['intro'] );
		}
		if ( ! empty( $this->data['note'] ) ) {
			$this->paragraph( $this->data['note'] );
		}

		foreach ( array_keys( Site_Manager_Report::sections() ) as $section ) {
			if ( $this->enabled( $section ) ) {
				$this->{'section_' . $section}();
			}
		}

		$this->footers();
		$title = $this->data['title'] . ' — ' . $this->data['site']['name'] . ' — ' . $this->data['period']['label'];
		return $this->pdf->output( $title, $this->data['agency']['name'] );
	}

	private function letterhead() {
		$pdf    = $this->pdf;
		$x      = self::MARGIN;
		$w      = $this->content_width();
		$top    = $this->y;
		$bottom = $top;

		// Logo (left), scaled to fit 180 × 42 pt.
		$logo = $this->logo_jpeg();
		if ( $logo ) {
			$info = @getimagesize( $logo );
			if ( $info ) {
				$ratio = $info[0] / max( 1, $info[1] );
				$lh    = 42;
				$lw    = $lh * $ratio;
				if ( $lw > 180 ) {
					$lw = 180;
					$lh = $lw / $ratio;
				}
				$pdf->jpeg( $logo, $x, $top, $lw, $lh );
				$bottom = $top + $lh;
			}
			wp_delete_file( $logo );
		}

		// Agency details: right-aligned beside a logo, otherwise left.
		$agency = $this->data['agency'];
		$align  = $logo ? 'right' : 'left';
		$ry     = $top;
		if ( $agency['name'] ) {
			$pdf->set_font( true, 11 );
			$pdf->text( $x, $ry, $agency['name'], $logo ? self::TEXT : $this->accent, $align, $w );
			$ry += 15;
		}
		$pdf->set_font( false, 9 );
		$details = array_filter( array( $agency['url'] ? preg_replace( '#^https?://#', '', untrailingslashit( $agency['url'] ) ) : '', $agency['contact'] ) );
		foreach ( $details as $line ) {
			$pdf->text( $x, $ry, $line, self::MUTED, $align, $w );
			$ry += 12;
		}

		$this->y = max( $bottom, $ry );
		if ( $this->y > $top ) {
			$this->y += 14;
		}
		$pdf->rect( $x, $this->y, $w, 3, $this->accent );
		$this->y += 22;

		$pdf->set_font( true, 24 );
		$this->y = $pdf->paragraph( $x, $this->y, $w, $this->data['title'], self::TEXT, 28 );
		$pdf->set_font( true, 13 );
		$pdf->text( $x, $this->y + 2, $this->data['period']['label'], $this->accent );
		$this->y += 22;
		$pdf->set_font( false, 10 );
		$pdf->text( $x, $this->y, $this->data['site']['name'] . '  ·  ' . preg_replace( '#^https?://#', '', $this->data['site']['url'] ), self::MUTED );
		$pdf->text( $x, $this->y, sprintf( __( 'Generated %s', 'site-manager' ), $this->data['generated'] ), self::MUTED, 'right', $w );
		$this->y += 30;
	}

	/**
	 * Logo as a temporary JPEG on a white background (PDF core fonts only
	 * support JPEG images without extra decoding).
	 */
	private function logo_jpeg() {
		$id = (int) $this->settings['logo_id'];
		if ( ! $id || ! function_exists( 'imagecreatetruecolor' ) ) {
			return null;
		}
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}
		$info = @getimagesize( $file );
		if ( ! $info ) {
			return null;
		}
		$loaders = array( IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_GIF => 'imagecreatefromgif' );
		if ( defined( 'IMAGETYPE_WEBP' ) ) {
			$loaders[ IMAGETYPE_WEBP ] = 'imagecreatefromwebp';
		}
		if ( ! isset( $loaders[ $info[2] ] ) || ! function_exists( $loaders[ $info[2] ] ) ) {
			return null;
		}
		$src = @call_user_func( $loaders[ $info[2] ], $file );
		if ( ! $src ) {
			return null;
		}
		$scale = min( 1, 600 / max( 1, $info[0] ) );
		$w     = max( 1, (int) round( $info[0] * $scale ) );
		$h     = max( 1, (int) round( $info[1] * $scale ) );
		$dst   = imagecreatetruecolor( $w, $h );
		imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
		imagecopyresampled( $dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1] );
		$tmp = get_temp_dir() . 'sm-logo-' . wp_generate_password( 12, false, false ) . '.jpg';
		imagejpeg( $dst, $tmp, 90 );
		if ( PHP_VERSION_ID < 80000 ) {
			imagedestroy( $src ); // Frees memory on PHP 7; a no-op (deprecated) since 8.0.
			imagedestroy( $dst );
		}
		return $tmp;
	}

	private function paragraph( $text, $color = self::TEXT, $size = 10 ) {
		$this->pdf->set_font( false, $size );
		foreach ( $this->pdf->wrap( $text, $this->content_width() ) as $line ) {
			$this->ensure( $size * 1.45 );
			$this->pdf->text( self::MARGIN, $this->y, $line, $color );
			$this->y += $size * 1.45;
		}
		$this->y += 8;
	}

	private function heading( $text ) {
		$this->ensure( 70 ); // Keep headings with at least a little content.
		$this->y += 10;
		$this->pdf->set_font( true, 14 );
		$this->pdf->text( self::MARGIN, $this->y, $text, self::TEXT );
		$this->y += 20;
		$this->pdf->rect( self::MARGIN, $this->y, 28, 2, $this->accent );
		$this->y += 12;
	}

	private function muted( $text ) {
		$this->paragraph( $text, self::MUTED, 9.5 );
	}

	/**
	 * Draw a table. $columns: [ [label, relative width, align], … ];
	 * $rows: list of cell arrays; a cell may be [text, color].
	 */
	private function table( array $columns, array $rows ) {
		$pdf    = $this->pdf;
		$total  = array_sum( array_column( $columns, 1 ) );
		$widths = array();
		foreach ( $columns as $c ) {
			$widths[] = $this->content_width() * $c[1] / $total;
		}
		$pad = 6;
		$lh  = 11.5;

		$header = function () use ( $pdf, $columns, $widths, $pad ) {
			$pdf->rect( self::MARGIN, $this->y, $this->content_width(), 20, $this->tint );
			$pdf->set_font( true, 8.5 );
			$x = self::MARGIN;
			foreach ( $columns as $i => $c ) {
				$pdf->text( $x + $pad, $this->y + 6, strtoupper( $c[0] ), self::MUTED, isset( $c[2] ) ? $c[2] : 'left', $widths[ $i ] - 2 * $pad );
				$x += $widths[ $i ];
			}
			$this->y += 20;
		};

		// Keep short tables on one page; long ones flow with repeated headers.
		$estimate = 20 + count( $rows ) * ( $lh + 8 );
		$this->ensure( $estimate <= 260 ? $estimate : 20 + $lh + 8 );
		$header();
		foreach ( $rows as $n => $row ) {
			$pdf->set_font( false, 9 );
			$cells = array();
			$lines = 1;
			foreach ( $columns as $i => $c ) {
				$cell      = isset( $row[ $i ] ) ? $row[ $i ] : '';
				$text      = is_array( $cell ) ? (string) $cell[0] : (string) $cell;
				$wrapped   = array_slice( $pdf->wrap( $text, $widths[ $i ] - 2 * $pad ), 0, 4 );
				$cells[]   = array( $wrapped, is_array( $cell ) && isset( $cell[1] ) ? $cell[1] : self::TEXT );
				$lines     = max( $lines, count( $wrapped ) );
			}
			$h = $lines * $lh + 8;
			if ( $this->y + $h > $this->bottom() ) {
				$this->new_page();
				$header();
				$pdf->set_font( false, 9 );
			}
			if ( $n % 2 === 1 ) {
				$pdf->rect( self::MARGIN, $this->y, $this->content_width(), $h, self::ZEBRA );
			}
			$x = self::MARGIN;
			foreach ( $columns as $i => $c ) {
				$ly = $this->y + 4;
				foreach ( $cells[ $i ][0] as $line ) {
					$pdf->text( $x + $pad, $ly, $line, $cells[ $i ][1], isset( $c[2] ) ? $c[2] : 'left', $widths[ $i ] - 2 * $pad );
					$ly += $lh;
				}
				$x += $widths[ $i ];
			}
			$this->y += $h;
		}
		$pdf->line( self::MARGIN, $this->y, self::MARGIN + $this->content_width(), $this->y, self::RULE, 0.75 );
		$this->y += 14;
	}

	/** Row of stat cards: [ [value, label], … ]. */
	private function cards( array $cards ) {
		$pdf   = $this->pdf;
		$gap   = 10;
		$n     = count( $cards );
		$w     = ( $this->content_width() - $gap * ( $n - 1 ) ) / $n;
		$h     = 62;
		$this->ensure( $h + 10 );
		foreach ( $cards as $i => $card ) {
			$x = self::MARGIN + $i * ( $w + $gap );
			$pdf->rect( $x, $this->y, $w, $h, $this->tint );
			$pdf->rect( $x, $this->y, 3, $h, $this->accent );
			$pdf->set_font( true, 20 );
			$pdf->text( $x + 14, $this->y + 12, (string) $card[0], $this->accent );
			$pdf->set_font( false, 8.5 );
			$pdf->text( $x + 14, $this->y + 40, $card[1], self::MUTED );
		}
		$this->y += $h + 18;
	}

	private function footers() {
		$pdf   = $this->pdf;
		$total = $pdf->page_count();
		$agency = $this->data['agency']['name'];
		$left   = $agency
			? sprintf( __( 'Prepared by %1$s for %2$s', 'site-manager' ), $agency, $this->data['site']['name'] )
			: $this->data['site']['name'] . ' — ' . $this->data['period']['label'];
		for ( $i = 0; $i < $total; $i++ ) {
			$pdf->set_page( $i );
			$y = $pdf->height - self::MARGIN - 14;
			$pdf->line( self::MARGIN, $y - 8, $pdf->width - self::MARGIN, $y - 8, self::RULE, 0.75 );
			$pdf->set_font( false, 8 );
			$pdf->text( self::MARGIN, $y, $left, self::MUTED );
			$pdf->text( self::MARGIN, $y, sprintf( __( 'Page %1$d of %2$d', 'site-manager' ), $i + 1, $total ), self::MUTED, 'right', $this->content_width() );
		}
	}

	// ---------------------------------------------------------------
	// Sections
	// ---------------------------------------------------------------

	private function section_summary() {
		$s = $this->data['summary'];
		$this->heading( __( 'At a glance', 'site-manager' ) );
		$this->cards( array(
			array( number_format_i18n( $s['updates'] ), __( 'Updates applied', 'site-manager' ) ),
			array( number_format_i18n( $s['content_changes'] ), __( 'Content changes', 'site-manager' ) ),
			array( number_format_i18n( $s['security_events'] ), __( 'Security events', 'site-manager' ) ),
			array( number_format_i18n( $s['failed_logins'] ), __( 'Failed login attempts', 'site-manager' ) ),
		) );
	}

	private function section_updates() {
		$u = $this->data['updates'];
		$this->heading( __( 'Software updates', 'site-manager' ) );
		if ( ! $u['items'] ) {
			$this->muted( __( 'No updates were applied during this period.', 'site-manager' ) );
		} else {
			$rows = array();
			foreach ( $u['items'] as $item ) {
				$rows[] = array(
					$item['date'],
					$item['name'],
					$item['type'],
					sprintf( __( '%1$s to %2$s', 'site-manager' ), $item['from'] ?: '?', $item['to'] ),
					$item['automatic'] ? __( 'Automatic', 'site-manager' ) : __( 'Manual', 'site-manager' ),
				);
			}
			$this->table( array(
				array( __( 'Date', 'site-manager' ), 1.2 ),
				array( __( 'Component', 'site-manager' ), 3 ),
				array( __( 'Type', 'site-manager' ), 1.1 ),
				array( __( 'Version', 'site-manager' ), 2 ),
				array( __( 'How', 'site-manager' ), 1.2 ),
			), $rows );
		}
		if ( $u['translations'] ) {
			$this->muted( sprintf( _n( '%d translation pack was also updated.', '%d translation packs were also updated.', $u['translations'], 'site-manager' ), $u['translations'] ) );
		}
		foreach ( $u['failed'] as $f ) {
			$this->paragraph( sprintf( __( 'Failed update (%1$s): %2$s', 'site-manager' ), substr( $f['time'], 0, 10 ), $f['message'] ), self::RED, 9.5 );
		}
	}

	private function section_plugins() {
		$this->heading( __( 'Plugins & themes', 'site-manager' ) );
		if ( ! $this->data['plugins'] ) {
			$this->muted( __( 'No plugins or themes were added, removed, activated or deactivated.', 'site-manager' ) );
			return;
		}
		$rows = array();
		foreach ( $this->data['plugins'] as $c ) {
			$rows[] = array( $c['date'], $c['name'], $c['type'], $c['change'], $c['by'] );
		}
		$this->table( array(
			array( __( 'Date', 'site-manager' ), 1.2 ),
			array( __( 'Name', 'site-manager' ), 3 ),
			array( __( 'Type', 'site-manager' ), 1 ),
			array( __( 'Change', 'site-manager' ), 1.5 ),
			array( __( 'By', 'site-manager' ), 1.3 ),
		), $rows );
	}

	private function section_content() {
		$c = $this->data['content'];
		$this->heading( __( 'Content', 'site-manager' ) );
		if ( ! $c['by_type'] && ! $c['media_uploads'] ) {
			$this->muted( __( 'No content changes during this period.', 'site-manager' ) );
			return;
		}
		if ( $c['by_type'] ) {
			$rows = array();
			foreach ( $c['by_type'] as $row ) {
				$rows[] = array( $row['type'], $row['created'], $row['published'], $row['updated'], $row['trashed'] + $row['deleted'] );
			}
			$t      = $c['totals'];
			$rows[] = array( array( __( 'Total', 'site-manager' ), self::TEXT ), $t['created'], $t['published'], $t['updated'], $t['trashed'] + $t['deleted'] );
			$this->table( array(
				array( __( 'Content type', 'site-manager' ), 3 ),
				array( __( 'Created', 'site-manager' ), 1, 'right' ),
				array( __( 'Published', 'site-manager' ), 1, 'right' ),
				array( __( 'Edited', 'site-manager' ), 1, 'right' ),
				array( __( 'Removed', 'site-manager' ), 1, 'right' ),
			), $rows );
		}
		if ( $c['media_uploads'] ) {
			$this->muted( sprintf( _n( '%d file was uploaded to the media library.', '%d files were uploaded to the media library.', $c['media_uploads'], 'site-manager' ), $c['media_uploads'] ) );
		}
	}

	private function section_users() {
		$u = $this->data['users'];
		$this->heading( __( 'Users & logins', 'site-manager' ) );
		$facts = array();
		$names = function ( $rows ) {
			return implode( ', ', array_filter( wp_list_pluck( $rows, 'object' ) ) );
		};
		if ( $u['created'] ) {
			$facts[] = sprintf( __( 'New users: %s.', 'site-manager' ), $names( $u['created'] ) );
		}
		if ( $u['deleted'] ) {
			$facts[] = sprintf( __( 'Removed users: %s.', 'site-manager' ), $names( $u['deleted'] ) );
		}
		foreach ( $u['role_changes'] as $r ) {
			$facts[] = $r['message'];
		}
		if ( $u['password_changes'] ) {
			$facts[] = sprintf( _n( '%d password was changed or reset.', '%d passwords were changed or reset.', $u['password_changes'], 'site-manager' ), $u['password_changes'] );
		}
		$facts[] = $u['failed_logins']
			? sprintf( _n( '%d failed login attempt was recorded.', '%d failed login attempts were recorded.', $u['failed_logins'], 'site-manager' ), $u['failed_logins'] )
			: __( 'No failed login attempts were recorded.', 'site-manager' );
		$this->pdf->set_font( false, 9.5 );
		foreach ( $facts as $fact ) {
			foreach ( $this->pdf->wrap( $fact, $this->content_width() - 14 ) as $i => $line ) {
				$this->ensure( 14 );
				if ( $i === 0 ) {
					$this->pdf->text( self::MARGIN + 2, $this->y, '•', $this->accent );
				}
				$this->pdf->text( self::MARGIN + 14, $this->y, $line, self::TEXT );
				$this->y += 14;
			}
		}
		$this->y += 10;
		if ( $u['logins_by_user'] ) {
			$rows = array();
			foreach ( array_slice( $u['logins_by_user'], 0, 10, true ) as $user => $n ) {
				$rows[] = array( $user, $n );
			}
			$this->table( array(
				array( __( 'User', 'site-manager' ), 4 ),
				array( __( 'Successful logins', 'site-manager' ), 1.5, 'right' ),
			), $rows );
		}
	}

	private function section_security() {
		$this->heading( __( 'Security events', 'site-manager' ) );
		if ( ! $this->data['security'] ) {
			$this->muted( __( 'No notable security events during this period.', 'site-manager' ) );
			return;
		}
		$rows = array();
		foreach ( $this->data['security'] as $e ) {
			$critical = isset( $e['severity'] ) && $e['severity'] === 'critical';
			$rows[]   = array(
				substr( $e['time'], 0, 16 ),
				array( ucfirst( isset( $e['severity'] ) ? $e['severity'] : '' ), $critical ? self::RED : '#996800' ),
				$e['message'],
				$e['user'],
			);
		}
		$this->table( array(
			array( __( 'When', 'site-manager' ), 1.6 ),
			array( __( 'Level', 'site-manager' ), 0.9 ),
			array( __( 'Event', 'site-manager' ), 5 ),
			array( __( 'By', 'site-manager' ), 1.3 ),
		), $rows );
	}

	private function section_store() {
		$s = $this->data['store'];
		if ( ! $s ) {
			return;
		}
		$money = function ( $v ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $v ) ), ENT_QUOTES, 'UTF-8' );
		};
		$this->heading( __( 'Store', 'site-manager' ) );
		$this->cards( array(
			array( number_format_i18n( $s['orders'] ), __( 'Orders', 'site-manager' ) ),
			array( $money( $s['gross_sales'] ), __( 'Gross sales', 'site-manager' ) ),
			array( $money( $s['net_sales'] ), __( 'Net sales', 'site-manager' ) ),
			array( $money( $s['average_order_value'] ), __( 'Average order', 'site-manager' ) ),
		) );
		if ( $s['refunds'] ) {
			$this->muted( sprintf( __( 'Refunds: %s.', 'site-manager' ), $money( $s['refunds'] ) ) );
		}
		if ( $s['top_products'] ) {
			$rows = array();
			foreach ( $s['top_products'] as $p ) {
				$rows[] = array( $p['name'], $p['quantity'], $money( $p['revenue'] ) );
			}
			$this->table( array(
				array( __( 'Top products', 'site-manager' ), 4 ),
				array( __( 'Sold', 'site-manager' ), 1, 'right' ),
				array( __( 'Revenue', 'site-manager' ), 1.5, 'right' ),
			), $rows );
		}
	}

	private function section_forms() {
		if ( ! $this->data['forms'] ) {
			return;
		}
		$this->heading( __( 'Form submissions', 'site-manager' ) );
		$rows = array();
		foreach ( $this->data['forms'] as $f ) {
			$rows[] = array( $f['form'], $f['plugin'], $f['submissions'] );
		}
		$this->table( array(
			array( __( 'Form', 'site-manager' ), 4 ),
			array( __( 'Plugin', 'site-manager' ), 2 ),
			array( __( 'Submissions', 'site-manager' ), 1.3, 'right' ),
		), $rows );
	}

	private function section_health() {
		$h  = $this->data['health'];
		$ok = function ( $good, $yes, $no ) {
			return array( $good ? $yes : $no, $good ? self::GREEN : self::RED );
		};
		$this->heading( __( 'Site health snapshot', 'site-manager' ) );
		$rows = array(
			array( __( 'WordPress', 'site-manager' ), $ok( $h['wordpress_latest'], $h['wordpress'] . ' — ' . __( 'up to date', 'site-manager' ), $h['wordpress'] . ' — ' . sprintf( __( '%s available', 'site-manager' ), $h['wordpress_update'] ) ) ),
			array( __( 'PHP', 'site-manager' ), $h['php'] ),
			array( __( 'Theme', 'site-manager' ), $h['theme'] ),
			array( __( 'Active plugins', 'site-manager' ), $h['active_plugins'] ),
			array( __( 'Pending updates', 'site-manager' ), $ok( ! $h['plugin_updates'] && ! $h['theme_updates'], __( 'None', 'site-manager' ), sprintf( __( '%1$d plugin, %2$d theme', 'site-manager' ), $h['plugin_updates'], $h['theme_updates'] ) ) ),
			array( __( 'HTTPS', 'site-manager' ), $ok( $h['https'], __( 'Enabled', 'site-manager' ), __( 'Not enabled', 'site-manager' ) ) ),
			array( __( 'Search engines', 'site-manager' ), $ok( $h['search_visible'], __( 'Allowed to index the site', 'site-manager' ), __( 'Discouraged from indexing', 'site-manager' ) ) ),
		);
		$this->table( array(
			array( __( 'Check', 'site-manager' ), 2 ),
			array( __( 'Status', 'site-manager' ), 5 ),
		), $rows );
		$this->muted( sprintf( __( 'Snapshot taken %s.', 'site-manager' ), $this->data['generated'] ) );
	}

	// ---------------------------------------------------------------
	// Email
	// ---------------------------------------------------------------

	public static function email_html( array $data, array $settings, $note = '' ) {
		$accent = esc_attr( $settings['accent_color'] ?: '#2271b1' );
		$s      = $data['summary'];
		$cell   = function ( $value, $label ) use ( $accent ) {
			return '<td style="padding:12px 8px;text-align:center;background:#f6f7f7;border-radius:4px;"><div style="font-size:22px;font-weight:bold;color:' . $accent . ';">' . esc_html( $value ) . '</div><div style="font-size:12px;color:#646970;">' . esc_html( $label ) . '</div></td>';
		};
		$agency = $data['agency'];
		ob_start();
		?>
<div style="margin:0;padding:24px 0;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1d2327;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-top:4px solid <?php echo $accent; ?>;">
		<tr><td style="padding:28px 32px 8px;">
			<?php if ( $agency['name'] ) : ?><div style="font-size:13px;font-weight:bold;color:<?php echo $accent; ?>;"><?php echo esc_html( $agency['name'] ); ?></div><?php endif; ?>
			<h1 style="margin:8px 0 4px;font-size:22px;"><?php echo esc_html( $data['title'] ); ?></h1>
			<div style="font-size:14px;color:#646970;"><?php echo esc_html( $data['site']['name'] . ' · ' . $data['period']['label'] ); ?></div>
		</td></tr>
		<?php if ( $data['intro'] || $note ) : ?>
		<tr><td style="padding:12px 32px 0;font-size:14px;line-height:1.6;">
			<?php echo wp_kses_post( wpautop( esc_html( trim( $data['intro'] . "\n\n" . $note ) ) ) ); ?>
		</td></tr>
		<?php endif; ?>
		<tr><td style="padding:16px 24px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="8"><tr>
				<?php
				echo $cell( number_format_i18n( $s['updates'] ), __( 'Updates applied', 'site-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo $cell( number_format_i18n( $s['content_changes'] ), __( 'Content changes', 'site-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo $cell( number_format_i18n( $s['security_events'] ), __( 'Security events', 'site-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</tr></table>
		</td></tr>
		<tr><td style="padding:0 32px 24px;font-size:14px;line-height:1.6;">
			<?php esc_html_e( 'The full report is attached as a PDF.', 'site-manager' ); ?>
		</td></tr>
		<?php if ( $agency['name'] || $agency['contact'] || $agency['url'] ) : ?>
		<tr><td style="padding:16px 32px 24px;border-top:1px solid #dcdcde;font-size:12px;color:#646970;line-height:1.6;">
			<?php echo esc_html( $agency['name'] ); ?>
			<?php if ( $agency['contact'] ) : ?><br /><?php echo esc_html( $agency['contact'] ); ?><?php endif; ?>
			<?php if ( $agency['url'] ) : ?><br /><a href="<?php echo esc_url( $agency['url'] ); ?>" style="color:<?php echo $accent; ?>;"><?php echo esc_html( preg_replace( '#^https?://#', '', untrailingslashit( $agency['url'] ) ) ); ?></a><?php endif; ?>
		</td></tr>
		<?php endif; ?>
	</table>
</div>
		<?php
		return ob_get_clean();
	}
}
