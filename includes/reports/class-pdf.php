<?php
/**
 * Minimal PDF writer for Site Manager reports.
 *
 * Dependency-free: uses the standard Helvetica / Helvetica-Bold fonts (no
 * embedding) with WinAnsi encoding, and supports text, word-wrapped
 * paragraphs, filled rectangles, lines and JPEG images. Coordinates are in
 * points from the top-left corner of the page.
 *
 * Character widths come from Adobe's Helvetica AFM metrics, indexed by
 * Windows-1252 byte value.
 *
 * @package Site_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Manager_PDF {

	const PAPER = array(
		'letter' => array( 612, 792 ),
		'a4'     => array( 595.28, 841.89 ),
	);

	const WIDTHS = array(
		'regular' => array( 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 0, 556, 0, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 0, 611, 0, 0, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 0, 500, 667, 278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333, 400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611, 667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278, 722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611, 556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278, 556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500 ),
		'bold' => array( 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584, 0, 556, 0, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 0, 611, 0, 0, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 0, 500, 667, 278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 333, 737, 333, 400, 584, 333, 333, 333, 611, 556, 278, 333, 333, 365, 556, 834, 834, 834, 611, 722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278, 722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611, 556, 556, 556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278, 611, 611, 611, 611, 611, 611, 611, 584, 611, 611, 611, 611, 611, 556, 611, 556 ),
	);

	public $width;
	public $height;

	private $pages   = array();
	private $current = -1;
	private $images  = array();
	private $font    = 'regular';
	private $size    = 10;

	public function __construct( $paper = 'letter' ) {
		list( $this->width, $this->height ) = isset( self::PAPER[ $paper ] ) ? self::PAPER[ $paper ] : self::PAPER['letter'];
	}

	// ---------------------------------------------------------------
	// Pages
	// ---------------------------------------------------------------

	public function add_page() {
		$this->pages[]  = '';
		$this->current  = count( $this->pages ) - 1;
		return $this->current;
	}

	public function page_count() {
		return count( $this->pages );
	}

	/** Switch to an existing page (e.g. to draw footers after layout). */
	public function set_page( $index ) {
		$this->current = (int) $index;
	}

	private function out( $s ) {
		$this->pages[ $this->current ] .= $s . "\n";
	}

	// ---------------------------------------------------------------
	// Text
	// ---------------------------------------------------------------

	public function set_font( $bold = false, $size = 10 ) {
		$this->font = $bold ? 'bold' : 'regular';
		$this->size = (float) $size;
	}

	/** UTF-8 → Windows-1252, transliterating what can't be represented. */
	public static function encode( $text ) {
		$text = str_replace(
			array( '→', '←', '↑', '↓', '✓', '✔', '✗', '✕', '•', '…', '≥', '≤', '≠', "\t" ),
			array( '->', '<-', '^', 'v', 'Yes', 'Yes', 'No', 'x', "\xE2\x80\xA2", '...', '>=', '<=', '!=', '    ' ),
			(string) $text
		);
		$text = preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text );
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'CP1252//TRANSLIT//IGNORE', $text );
			if ( $out !== false ) {
				return $out;
			}
		}
		return function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $text, 'CP1252', 'UTF-8' ) : $text;
	}

	/** Width in points of UTF-8 text at the given font/size. */
	public function text_width( $text, $bold = null, $size = null ) {
		$font  = $bold === null ? $this->font : ( $bold ? 'bold' : 'regular' );
		$size  = $size === null ? $this->size : $size;
		$bytes = self::encode( $text );
		$w     = 0;
		$len   = strlen( $bytes );
		for ( $i = 0; $i < $len; $i++ ) {
			$w += self::WIDTHS[ $font ][ ord( $bytes[ $i ] ) ];
		}
		return $w * $size / 1000;
	}

	private static function escape( $bytes ) {
		return strtr( $bytes, array( '\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ' ) );
	}

	private static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			$hex = '000000';
		}
		return sprintf( '%.3F %.3F %.3F', hexdec( substr( $hex, 0, 2 ) ) / 255, hexdec( substr( $hex, 2, 2 ) ) / 255, hexdec( substr( $hex, 4, 2 ) ) / 255 );
	}

	/**
	 * Draw one line of text. $y is the top of the line box; the baseline sits
	 * at roughly 80% of the font size below it.
	 */
	public function text( $x, $y, $text, $color = '#1d2327', $align = 'left', $box_width = 0 ) {
		if ( $align !== 'left' && $box_width > 0 ) {
			$w  = $this->text_width( $text );
			$x += $align === 'right' ? $box_width - $w : ( $box_width - $w ) / 2;
		}
		$baseline = $this->height - $y - $this->size * 0.8;
		$font     = $this->font === 'bold' ? 'F2' : 'F1';
		$this->out( sprintf( 'BT %s rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET', self::rgb( $color ), $font, $this->size, $x, $baseline, self::escape( self::encode( $text ) ) ) );
	}

	/** Split text into lines that fit $width at the current font. */
	public function wrap( $text, $width ) {
		$lines = array();
		foreach ( preg_split( '/\r?\n/', (string) $text ) as $paragraph ) {
			$words = preg_split( '/\s+/', trim( $paragraph ) );
			$line  = '';
			foreach ( $words as $word ) {
				$try = $line === '' ? $word : $line . ' ' . $word;
				if ( $line !== '' && $this->text_width( $try ) > $width ) {
					$lines[] = $line;
					$line    = $word;
				} else {
					$line = $try;
				}
				// Break single words longer than the line (URLs, hashes).
				while ( $this->text_width( $line ) > $width && strlen( $line ) > 1 ) {
					$cut = strlen( $line );
					while ( $cut > 1 && $this->text_width( substr( $line, 0, $cut ) ) > $width ) {
						$cut--;
					}
					$lines[] = substr( $line, 0, $cut );
					$line    = substr( $line, $cut );
				}
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Word-wrapped paragraph. Returns the y position below the last line.
	 */
	public function paragraph( $x, $y, $width, $text, $color = '#1d2327', $line_height = null ) {
		$lh = $line_height ? $line_height : $this->size * 1.4;
		foreach ( $this->wrap( $text, $width ) as $line ) {
			$this->text( $x, $y, $line, $color );
			$y += $lh;
		}
		return $y;
	}

	// ---------------------------------------------------------------
	// Shapes and images
	// ---------------------------------------------------------------

	public function rect( $x, $y, $w, $h, $fill ) {
		$this->out( sprintf( '%s rg %.2F %.2F %.2F %.2F re f', self::rgb( $fill ), $x, $this->height - $y - $h, $w, $h ) );
	}

	public function line( $x1, $y1, $x2, $y2, $color = '#dcdcde', $width = 0.5 ) {
		$this->out( sprintf( '%s RG %.2F w %.2F %.2F m %.2F %.2F l S', self::rgb( $color ), $width, $x1, $this->height - $y1, $x2, $this->height - $y2 ) );
	}

	/**
	 * Place a JPEG. Pass $w or $h as 0 to keep the aspect ratio.
	 * Returns the drawn height, or 0 if the file isn't a usable JPEG.
	 */
	public function jpeg( $path, $x, $y, $w = 0, $h = 0 ) {
		$info = @getimagesize( $path );
		if ( ! $info || $info[2] !== IMAGETYPE_JPEG ) {
			return 0;
		}
		$key = md5( $path );
		if ( ! isset( $this->images[ $key ] ) ) {
			$channels = isset( $info['channels'] ) ? (int) $info['channels'] : 3;
			$this->images[ $key ] = array(
				'name'   => 'Im' . ( count( $this->images ) + 1 ),
				'data'   => file_get_contents( $path ),
				'w'      => $info[0],
				'h'      => $info[1],
				'space'  => $channels === 1 ? 'DeviceGray' : ( $channels === 4 ? 'DeviceCMYK' : 'DeviceRGB' ),
				'invert' => $channels === 4,
			);
		}
		$img = $this->images[ $key ];
		if ( ! $w && ! $h ) {
			$w = $img['w'];
		}
		if ( ! $w ) {
			$w = $h * $img['w'] / $img['h'];
		}
		if ( ! $h ) {
			$h = $w * $img['h'] / $img['w'];
		}
		$this->out( sprintf( 'q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q', $w, $h, $x, $this->height - $y - $h, $img['name'] ) );
		return $h;
	}

	// ---------------------------------------------------------------
	// Output
	// ---------------------------------------------------------------

	public function output( $title = '', $author = '' ) {
		$objects = array();
		$add     = function ( $body ) use ( &$objects ) {
			$objects[] = $body;
			return count( $objects );
		};

		$catalog = $add( '' ); // Filled in once the page tree exists.
		$pages   = $add( '' );
		$font1   = $add( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>' );
		$font2   = $add( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>' );

		$xobjects = array();
		foreach ( $this->images as $img ) {
			$decode = $img['invert'] ? ' /Decode [1 0 1 0 1 0 1 0]' : '';
			$id     = $add( sprintf(
				"<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode%s /Length %d >>\nstream\n%s\nendstream",
				$img['w'], $img['h'], $img['space'], $decode, strlen( $img['data'] ), $img['data']
			) );
			$xobjects[] = '/' . $img['name'] . ' ' . $id . ' 0 R';
		}
		$resources = sprintf( '<< /Font << /F1 %d 0 R /F2 %d 0 R >> /XObject << %s >> >>', $font1, $font2, implode( ' ', $xobjects ) );

		$kids = array();
		foreach ( $this->pages as $content ) {
			$filter = '';
			if ( function_exists( 'gzcompress' ) ) {
				$content = gzcompress( $content, 6 );
				$filter  = ' /Filter /FlateDecode';
			}
			$stream = $add( sprintf( "<< /Length %d%s >>\nstream\n%s\nendstream", strlen( $content ), $filter, $content ) );
			$kids[] = $add( sprintf( '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>', $pages, $this->width, $this->height, $resources, $stream ) );
		}
		$objects[ $pages - 1 ]   = sprintf( '<< /Type /Pages /Kids [%s] /Count %d >>', implode( ' ', array_map( function ( $k ) {
			return $k . ' 0 R';
		}, $kids ) ), count( $kids ) );
		$objects[ $catalog - 1 ] = sprintf( '<< /Type /Catalog /Pages %d 0 R >>', $pages );

		$info = $add( sprintf(
			'<< /Title (%s) /Author (%s) /Producer (Site Manager %s) /CreationDate (D:%s) >>',
			self::escape( self::encode( $title ) ),
			self::escape( self::encode( $author ) ),
			defined( 'SITE_MANAGER_VERSION' ) ? SITE_MANAGER_VERSION : '',
			gmdate( 'YmdHis' ) . 'Z'
		) );

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $i => $body ) {
			$offsets[ $i + 1 ] = strlen( $pdf );
			$pdf              .= ( $i + 1 ) . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= 'xref' . "\n" . '0 ' . ( count( $objects ) + 1 ) . "\n" . "0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		$pdf .= sprintf( "trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF", count( $objects ) + 1, $catalog, $info, $xref );
		return $pdf;
	}
}
