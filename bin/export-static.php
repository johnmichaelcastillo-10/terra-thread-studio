<?php
/**
 * Exports the running local WordPress site to plain static files in dist/, ready for
 * Vercel (or any static host). Adapted from jmcastillo-portfolio's exporter.
 *
 *   php bin/export-static.php [--url=https://example.com] [--source=http://localhost:8091] [--out=dist]
 *
 * What it does:
 * - Starts from the home page, the main sections and the WordPress sitemap, then follows
 *   every internal link it finds.
 * - Downloads every asset those pages reference (CSS, JS, images), including url(...) inside
 *   CSS and the script-module URLs in WordPress's import map (the navigation block needs them).
 * - Self-hosts the Google Fonts stylesheet and its font files, so the CSP can stay font-src 'self'.
 * - Saves each workshop's seat availability from the REST API as /data/workshops/<id>/sessions.json
 *   and switches the theme's scripts to static mode (window.ttStudio.static): prices are
 *   computed in the browser and demo reservations are kept in the visitor's browser.
 * - Rewrites the local site URL to --url (absolute, for canonical tags and the sitemap) or,
 *   without --url, to root-relative paths for local previews.
 * - Drops head links that point at things a static site doesn't have (REST API, feeds, RSD).
 * - Adds the Vercel Web Analytics script to every page.
 * - Copies static/ (vercel.json) into the output and adds a Content-Security-Policy header that
 *   allows only the site's own inline scripts (by hash).
 * - Fails if any page contains a PHP warning or still points at the local site.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$opts   = getopt( '', array( 'source::', 'url::', 'out::' ) );
$source = rtrim( $opts['source'] ?? 'http://localhost:8091', '/' );
$target = rtrim( $opts['url'] ?? '', '/' ); // '' = root-relative output
$out    = $opts['out'] ?? dirname( __DIR__ ) . '/dist';

$src_parts = parse_url( $source );
$src_host  = $src_parts['host'] . ( isset( $src_parts['port'] ) ? ':' . $src_parts['port'] : '' );

/** GET a URL. Returns [status, body, content-type]. */
function fetch( string $url, string $agent = 'tt-static-export/1.0' ): array {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_USERAGENT      => $agent,
		)
	);
	// PHP's Windows build ships without a CA bundle; verify HTTPS against the Windows store.
	if ( PHP_OS_FAMILY === 'Windows' && defined( 'CURLSSLOPT_NATIVE_CA' ) ) {
		curl_setopt( $ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA );
	}
	$body   = curl_exec( $ch );
	$status = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$type   = (string) curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
	return array( $status, false === $body ? '' : $body, $type );
}

/** Path part of an internal URL, or null if the URL isn't on the local site. */
function internal_path( string $url, string $source, string $src_host ): ?string {
	$url = html_entity_decode( trim( $url ), ENT_QUOTES );
	if ( '' === $url || str_starts_with( $url, '#' ) || str_starts_with( $url, 'data:' )
		|| preg_match( '#^(mailto|tel|javascript):#i', $url ) ) {
		return null;
	}
	if ( str_starts_with( $url, '//' ) ) {
		$url = 'http:' . $url;
	}
	if ( preg_match( '#^https?://#i', $url ) ) {
		$p = parse_url( $url );
		$h = ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		if ( $h !== $src_host ) {
			return null;
		}
		$url = $p['path'] ?? '/';
	} elseif ( ! str_starts_with( $url, '/' ) ) {
		return null; // relative paths are resolved by the CSS handler only
	}
	$url = preg_replace( '/[?#].*$/', '', $url );
	return '' === $url ? '/' : $url;
}

/** Whether a path is something we never publish. */
function skipped( string $path ): bool {
	return (bool) preg_match( '#^/(wordpress/wp-admin|wordpress/wp-login\.php|wp-admin|wp-login\.php|wp-json|xmlrpc\.php|wp-cron\.php|feed|comments/feed|author/)|/feed/?$|/embed/?$|\.php$#', $path );
}

/** Where a path is written inside dist/. */
function out_file( string $out, string $path ): string {
	if ( str_ends_with( $path, '/' ) ) {
		$path .= 'index.html';
	}
	return $out . str_replace( '/', DIRECTORY_SEPARATOR, rawurldecode( $path ) );
}

function write_file( string $file, string $data ): void {
	if ( ! is_dir( dirname( $file ) ) ) {
		mkdir( dirname( $file ), 0777, true );
	}
	file_put_contents( $file, $data );
}

/** Every URL referenced by an HTML document. */
function html_refs( string $html, string $source ): array {
	$refs = array();
	preg_match_all( '/\b(?:href|src|content)\s*=\s*(["\'])(.*?)\1/is', $html, $m );
	$refs = array_merge( $refs, $m[2] );
	preg_match_all( '/\bsrcset\s*=\s*(["\'])(.*?)\1/is', $html, $m );
	foreach ( $m[2] as $set ) {
		foreach ( explode( ',', $set ) as $candidate ) {
			$refs[] = preg_split( '/\s+/', trim( $candidate ) )[0];
		}
	}
	// Local URLs inside inline JSON, such as WordPress's script-module import map.
	$escaped = str_replace( '/', '\/', $source );
	preg_match_all( '#' . preg_quote( $escaped, '#' ) . '((?:\\\\/[^"\\\\\s]*)+)#', $html, $m );
	foreach ( $m[1] as $path ) {
		$refs[] = $source . str_replace( '\/', '/', $path );
	}
	return array_merge( $refs, css_refs( $html ) );
}

/** url(...) references in CSS (also used on inline <style> in HTML). */
function css_refs( string $css ): array {
	preg_match_all( '/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', $css, $m );
	return $m[2];
}

// ---------------------------------------------------------------------------

if ( is_dir( $out ) ) {
	// Start clean so pages deleted in WordPress don't linger in the export.
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $out, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
} else {
	mkdir( $out, 0777, true );
}

$pages     = array( '/', '/shop/', '/workshops/', '/about/' );
$assets    = array();
$seen      = array();
$errors    = array();
$workshops = array();
$fonts_url = null;

// Seed from the sitemap index and its child sitemaps.
[ $status, $index ] = fetch( "$source/wp-sitemap.xml" );
if ( 200 === $status ) {
	preg_match_all( '#<loc>(.*?)</loc>#', $index, $m );
	foreach ( $m[1] as $child ) {
		[ , $xml ] = fetch( html_entity_decode( $child ) );
		preg_match_all( '#<loc>(.*?)</loc>#', $xml, $mm );
		foreach ( $mm[1] as $loc ) {
			$p = internal_path( $loc, $source, $src_host );
			if ( null !== $p ) {
				$pages[] = $p;
			}
		}
	}
} else {
	$errors[] = "wp-sitemap.xml returned $status";
}

$rewrite = static function ( string $text ) use ( $source, $target ): string {
	// Plain and JSON-escaped forms of the local URL.
	$text = str_replace( $source, $target, $text );
	return str_replace( str_replace( '/', '\/', $source ), str_replace( '/', '\/', $target ), $text );
};

$collect = static function ( string $html ) use ( &$assets, &$pages, &$workshops, &$fonts_url, $source, $src_host ): void {
	foreach ( html_refs( $html, $source ) as $ref ) {
		$p = internal_path( $ref, $source, $src_host );
		if ( null === $p || skipped( $p ) ) {
			continue;
		}
		if ( str_ends_with( $p, '/' ) ) {
			$pages[] = $p;
		} elseif ( preg_match( '/\.[a-z0-9]{2,5}$/i', $p ) ) {
			$assets[ $p ] = true;
		}
	}
	preg_match_all( '#data-tt-book="(\d+)"#', $html, $m );
	foreach ( $m[1] as $id ) {
		$workshops[ (int) $id ] = true;
	}
	if ( preg_match( '#https://fonts\.googleapis\.com/css2\?[^"\']+#', $html, $m ) ) {
		$fonts_url = html_entity_decode( $m[0], ENT_QUOTES );
	}
};

// Crawl pages.
while ( $pages ) {
	$path = array_shift( $pages );
	if ( isset( $seen[ $path ] ) || skipped( $path ) ) {
		continue;
	}
	$seen[ $path ] = true;

	[ $status, $html, $type ] = fetch( $source . $path );
	if ( 200 !== $status || ! str_contains( $type, 'text/html' ) ) {
		$errors[] = "$path returned $status";
		continue;
	}
	if ( preg_match( '#<b>(Warning|Notice|Deprecated|Fatal error)</b>#', $html ) ) {
		$errors[] = "$path contains a PHP warning";
	}

	$collect( $html );
	write_file( out_file( $out, $path ), $html ); // transformed below, after the crawl
	echo "page   $path\n";
}

// The 404 page: static hosts serve /404.html for any missing path.
[ $status, $html ] = fetch( "$source/__static-export-404__/" );
if ( 404 === $status ) {
	write_file( "$out/404.html", $html );
	$collect( $html );
	echo "page   /404.html\n";
}

// Download assets; follow url(...) inside CSS.
$queue = array_keys( $assets );
$done  = array();
while ( $queue ) {
	$path = array_shift( $queue );
	if ( isset( $done[ $path ] ) ) {
		continue;
	}
	$done[ $path ] = true;
	[ $status, $body ] = fetch( $source . $path );
	if ( 200 !== $status ) {
		$errors[] = "asset $path returned $status";
		continue;
	}
	if ( str_ends_with( $path, '.css' ) ) {
		foreach ( css_refs( $body ) as $ref ) {
			if ( str_starts_with( $ref, 'data:' ) ) {
				continue;
			}
			$p = str_starts_with( $ref, '/' ) || preg_match( '#^https?://#', $ref )
				? internal_path( $ref, $source, $src_host )
				: internal_path( dirname( $path ) . '/' . $ref, $source, $src_host );
			if ( null !== $p ) {
				// Collapse ../ segments.
				$parts = array();
				foreach ( explode( '/', $p ) as $seg ) {
					if ( '..' === $seg ) {
						array_pop( $parts );
					} elseif ( '.' !== $seg ) {
						$parts[] = $seg;
					}
				}
				$queue[] = implode( '/', $parts );
			}
		}
		$body = $rewrite( $body );
	}
	write_file( out_file( $out, $path ), $body );
}
echo 'assets ' . count( $done ) . " files\n";

// Google Fonts, self-hosted: the stylesheet (as served to a current browser, so woff2) and
// every font file it points at.
if ( $fonts_url ) {
	[ $status, $css ] = fetch( $fonts_url, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' );
	if ( 200 !== $status ) {
		$errors[] = "Google Fonts stylesheet returned $status";
	} else {
		preg_match_all( '#url\((https://fonts\.gstatic\.com/[^)]+)\)#', $css, $m );
		foreach ( array_unique( $m[1] ) as $font ) {
			[ $fs, $data ] = fetch( $font );
			if ( 200 !== $fs ) {
				$errors[] = "font $font returned $fs";
				continue;
			}
			$name = basename( parse_url( $font, PHP_URL_PATH ) );
			write_file( "$out/assets/fonts/$name", $data );
			$css = str_replace( $font, "/assets/fonts/$name", $css );
		}
		write_file( "$out/assets/fonts/fonts.css", $css );
		echo 'fonts  ' . count( array_unique( $m[1] ) ) . " files\n";
	}
}

// Workshop availability for the booking calendar (read by studio.js in static mode).
foreach ( array_keys( $workshops ) as $id ) {
	[ $status, $json ] = fetch( "$source/wp-json/tt/v1/workshops/$id/sessions" );
	if ( 200 !== $status ) {
		$errors[] = "sessions for workshop $id returned $status";
		continue;
	}
	write_file( "$out/data/workshops/$id/sessions.json", $json );
}
echo 'data   ' . count( $workshops ) . " workshop availability files\n";

// robots.txt and sitemaps.
foreach ( array( '/robots.txt', '/wp-sitemap.xml', '/wp-sitemap.xsl', '/wp-sitemap-index.xsl' ) as $path ) {
	[ $status, $body ] = fetch( $source . $path );
	if ( 200 === $status ) {
		write_file( out_file( $out, $path ), $rewrite( $body ) );
	}
}
[ , $index ] = fetch( "$source/wp-sitemap.xml" );
preg_match_all( '#<loc>(.*?)</loc>#', $index, $m );
foreach ( $m[1] as $child ) {
	$p = internal_path( $child, $source, $src_host );
	[ $status, $body ] = fetch( html_entity_decode( $child ) );
	if ( null !== $p && 200 === $status ) {
		write_file( out_file( $out, $p ), $rewrite( $body ) );
	}
}

// Content-Security-Policy: every inline script on every page is allowed by its SHA-256 hash,
// collected here and written into the vercel.json header after the loop.
$csp_hashes  = array();
$collect_csp = static function ( string $html, string $page ) use ( &$errors, &$csp_hashes ): void {
	if ( preg_match( '#<[^>]+\son[a-z]+\s*=|href\s*=\s*["\']\s*javascript:#i', $html ) ) {
		$errors[] = "$page has an inline event handler or javascript: URL, which the CSP would block";
	}
	preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $html, $m, PREG_SET_ORDER );
	foreach ( $m as $s ) {
		$type = preg_match( '#\btype\s*=\s*["\']?([^"\'\s>]+)#i', $s[1], $t ) ? strtolower( $t[1] ) : '';
		// Data blocks (the product configurator's JSON and the like) never run.
		$runs = in_array( $type, array( '', 'text/javascript', 'module', 'speculationrules', 'importmap' ), true );
		if ( $runs && ! preg_match( '#\bsrc\s*=#i', $s[1] ) && '' !== $s[2] ) {
			$csp_hashes[ "'sha256-" . base64_encode( hash( 'sha256', $s[2], true ) ) . "'" ] = true;
		}
	}
};

// Vercel Web Analytics: cookieless page views, once enabled on the project (dashboard →
// Analytics → Enable); until then the script 404s harmlessly.
$analytics = '<script>window.va = window.va || function () { (window.vaq = window.vaq || []).push(arguments); };</script>'
	. "\n" . '<script defer src="/_vercel/insights/script.js"></script>' . "\n";

$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $out, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( 'html' !== $f->getExtension() ) {
		continue;
	}
	$page = substr( $f->getPathname(), strlen( $out ) );
	$html = file_get_contents( $f->getPathname() );
	$html = preg_replace( '#</head>#i', $analytics . '</head>', $html, 1 );
	$html = preg_replace( '#<link[^>]+(wp-json|xmlrpc\.php|/feed/|EditURI|rel=["\']shortlink)[^>]*>\s*#i', '', $html );
	// Preconnect / dns-prefetch hints for Google's font servers (fonts are self-hosted now).
	$html = preg_replace( '#<link(?=[^>]*rel=["\'](?:dns-prefetch|preconnect)["\'])(?=[^>]*fonts\.(?:googleapis|gstatic)\.com)[^>]*>\s*#i', '', $html );
	$html = preg_replace( '#<meta name="generator"[^>]*>\s*#i', '', $html );
	if ( $fonts_url ) {
		$html = preg_replace( '#https://fonts\.googleapis\.com/css2\?[^"\']+#', $source . '/assets/fonts/fonts.css', $html );
	}
	// Static mode for the theme's scripts: no REST API on a static host.
	if ( str_contains( $html, 'window.ttStudio' ) ) {
		$html = preg_replace( '#"rest":"[^"]*"#', '"rest":"\/data\/","static":true', $html, 1, $count );
		if ( ! $count ) {
			$errors[] = "$page: window.ttStudio has no \"rest\" key to switch to static mode";
		}
	}
	$html = $rewrite( $html );
	$collect_csp( $html, $page );
	file_put_contents( $f->getPathname(), $html );
}

// Host config: copy static/ (vercel.json) in, then add the Content-Security-Policy header to
// its catch-all rule. Styles stay 'unsafe-inline': WordPress prints several <style> blocks and
// style="" attributes (swatch colours), which can't all be hashed.
$static_dir = dirname( __DIR__ ) . '/static';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $static_dir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	write_file( $out . substr( $f->getPathname(), strlen( $static_dir ) ), file_get_contents( $f->getPathname() ) );
}
$csp = implode(
	'; ',
	array(
		"default-src 'self'",
		"script-src 'self' 'unsafe-inline' " . implode( ' ', array_keys( $csp_hashes ) ),
		"style-src 'self' 'unsafe-inline'",
		"img-src 'self' data:",
		"font-src 'self'",
		"connect-src 'self'",
		"form-action 'self'",
		"frame-ancestors 'none'",
		"base-uri 'self'",
		"object-src 'none'",
	)
);
$vercel_file = "$out/vercel.json";
$vercel      = json_decode( (string) @file_get_contents( $vercel_file ), true );
$catch_all   = is_array( $vercel ) ? array_search( '/(.*)', array_column( $vercel['headers'] ?? array(), 'source' ), true ) : false;
if ( false === $catch_all ) {
	$errors[] = 'static/vercel.json needs a headers rule with source "/(.*)" to carry the Content-Security-Policy';
} else {
	$vercel['headers'][ $catch_all ]['headers'][] = array( 'key' => 'Content-Security-Policy', 'value' => $csp );
	file_put_contents( $vercel_file, json_encode( $vercel, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
}
echo 'csp    ' . count( $csp_hashes ) . " inline script hashes\n";

// Final checks: nothing may still point at the local site or at Google's font servers.
$left = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $out, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	if ( preg_match( '/\.(html|css|js|json|xml|xsl|txt)$/', $f->getFilename() ) ) {
		$text = file_get_contents( $f->getPathname() );
		if ( str_contains( $text, $src_host ) || str_contains( $text, 'fonts.googleapis.com' ) ) {
			$left[] = substr( $f->getPathname(), strlen( $out ) );
		}
	}
}
if ( $left ) {
	$errors[] = "still references $src_host or Google Fonts: " . implode( ', ', $left );
}

if ( $errors ) {
	fwrite( STDERR, "\nExport finished with problems:\n - " . implode( "\n - ", $errors ) . "\n" );
	exit( 1 );
}
echo "\nExported " . count( $seen ) . ' pages to ' . realpath( $out ) . ( $target ? " for $target" : ' (root-relative links)' ) . "\n";
