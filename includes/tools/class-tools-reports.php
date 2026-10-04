<?php
/**
 * MCP tools: branded client reports (PDF + email).
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_Tools_Reports {

	public function __construct( Site_Manager_Registry $r ) {
		$r->add_category( 'reports', __( 'Client reports', 'site-manager' ), __( 'Branded monthly PDF reports: settings, generation, archive and email delivery.', 'site-manager' ) );
		$s = 'Site_Manager_Schema';

		$period = array(
			'month'  => $s::str( 'YYYY-MM. Default: last calendar month.' ),
			'after'  => $s::str( 'Custom range start (YYYY-MM-DD), instead of month.' ),
			'before' => $s::str( 'Custom range end (YYYY-MM-DD).' ),
		);

		$r->register( 'report_settings_get', array(
			'category'     => 'reports',
			'description'  => 'Get the client report settings: title, agency name/website/contact, logo, accent color, paper size, intro text, sections, recipients, email subject, reply-to and monthly schedule.',
			'handler'      => array( $this, 'settings_get' ),
		) );

		$r->register( 'report_settings_update', array(
			'category'     => 'reports',
			'description'  => 'Update client report settings. Only fields you pass change. Subject supports {title}, {site}, {period} and {agency}.',
			'writes'       => true,
			'input_schema' => $s::obj( array(
				'title'          => $s::str( 'Report title shown on the PDF, e.g. "Monthly Website Care Report".' ),
				'agency_name'    => $s::str( 'Agency name shown in the letterhead, footer and email sender name.' ),
				'agency_url'     => $s::str(),
				'agency_contact' => $s::str( 'Contact line, e.g. "support@agency.com · (555) 123-4567".' ),
				'logo_id'        => $s::int( 'Media library image ID for the logo (0 removes it).' ),
				'accent_color'   => $s::str( 'Hex color, e.g. "#0a7c66".' ),
				'paper'          => $s::enum( array( 'letter', 'a4' ) ),
				'intro'          => $s::str( 'Text shown at the top of every report and email.' ),
				'sections'       => $s::arr( array( 'type' => 'string', 'enum' => array_keys( Site_Manager_Report::sections() ) ), 'Sections to include, in fixed order.' ),
				'recipients'     => $s::arr( 'string', 'Email addresses. Replaces the current list.' ),
				'subject'        => $s::str(),
				'reply_to'       => $s::str( 'Reply-To address for report emails.' ),
				'schedule'       => $s::enum( array( 'off', 'monthly' ), 'monthly = email last month\'s report automatically.' ),
				'send_day'       => $s::int( 'Day of month (1–28) to send the scheduled report.' ),
			) ),
			'handler'      => array( $this, 'settings_update' ),
		) );

		$r->register( 'report_generate', array(
			'category'     => 'reports',
			'description'  => 'Generate the branded PDF report for a month (default: last month) or date range and save it to the report archive. Returns the report data (updates, plugin changes, content, users and logins, security events, store sales, form submissions, health) plus the archive ID for report_send. Set include_pdf=true to also get the PDF as base64.',
			'writes'       => true,
			'input_schema' => $s::obj( array_merge( $period, array(
				'note'        => $s::str( 'Extra note printed under the intro for this report only.' ),
				'include_pdf' => $s::bool( '', false ),
			) ) ),
			'handler'      => array( $this, 'generate' ),
		) );

		$r->register( 'report_send', array(
			'category'     => 'reports',
			'description'  => 'Email a report as a PDF attachment. Pass report_id (from report_generate or reports_list), or a month/range to generate and send in one step. Uses the saved recipients unless recipients is given. Confirm recipients with the user first.',
			'writes'       => true,
			'open_world'   => true,
			'input_schema' => $s::obj( array_merge( array(
				'report_id'  => $s::str(),
				'recipients' => $s::arr( 'string', 'Override the saved recipients for this send.' ),
				'note'       => $s::str( 'Message included in this email (and the PDF when generating).' ),
			), $period ) ),
			'handler'      => array( $this, 'send' ),
		) );

		$r->register( 'reports_list', array(
			'category'     => 'reports',
			'description'  => 'List archived reports (newest first) with period, when generated, who received them and a wp-admin download link.',
			'handler'      => array( $this, 'archive' ),
		) );
	}

	public function settings_get() {
		$s = Site_Manager_Report::settings();
		$s['logo_url']           = $s['logo_id'] ? wp_get_attachment_url( $s['logo_id'] ) : null;
		$s['available_sections'] = Site_Manager_Report::sections();
		return $s;
	}

	public function settings_update( array $args ) {
		$result = Site_Manager_Report::update_settings( $args );
		return is_wp_error( $result ) ? $result : $this->settings_get();
	}

	private static function period( array $args ) {
		return Site_Manager_Report::period(
			(string) Site_Manager_Helpers::arg( $args, 'month', '' ),
			(string) Site_Manager_Helpers::arg( $args, 'after', '' ),
			(string) Site_Manager_Helpers::arg( $args, 'before', '' )
		);
	}

	private static function entry_out( array $entry ) {
		$entry['download_url'] = Site_Manager_Report::download_url( $entry['id'] );
		unset( $entry['file'] );
		return $entry;
	}

	public function generate( array $args ) {
		$period = self::period( $args );
		if ( is_wp_error( $period ) ) {
			return $period;
		}
		list( $after, $before, $label ) = $period;
		$report = Site_Manager_Report::generate( $after, $before, $label, true, (string) Site_Manager_Helpers::arg( $args, 'note', '' ) );
		$out    = array(
			'report' => self::entry_out( $report['entry'] ),
			'data'   => $report['data'],
		);
		if ( Site_Manager_Helpers::bool( $args, 'include_pdf' ) ) {
			$out['pdf_base64'] = base64_encode( $report['pdf'] );
		}
		return $out;
	}

	public function send( array $args ) {
		$recipients = array();
		if ( ! empty( $args['recipients'] ) ) {
			$recipients = Site_Manager_Report::parse_recipients( $args['recipients'] );
			if ( is_wp_error( $recipients ) ) {
				return $recipients;
			}
		}
		$note = (string) Site_Manager_Helpers::arg( $args, 'note', '' );
		$id   = (string) Site_Manager_Helpers::arg( $args, 'report_id', '' );
		if ( $id === '' ) {
			$period = self::period( $args );
			if ( is_wp_error( $period ) ) {
				return $period;
			}
			list( $after, $before, $label ) = $period;
			$id = Site_Manager_Report::generate( $after, $before, $label, true, $note )['entry']['id'];
		}
		$result = Site_Manager_Report::send( $id, $recipients, $note );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['report'] = self::entry_out( $result['report'] );
		return $result;
	}

	public function archive() {
		return array_map( array( __CLASS__, 'entry_out' ), Site_Manager_Report::archive() );
	}
}
