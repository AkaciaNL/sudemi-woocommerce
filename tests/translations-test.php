<?php
/**
 * Every text the plugin shows exists in all twelve languages (owner: "everything in 12
 * languages"): each __(), esc_html__(), _n() ... in the plugin is in sudemi.pot and translated
 * in the 11 .po files, with the same placeholders, the right number of plural forms, and no long
 * dash anywhere. The compiled .mo files are newer than or as new as their .po.
 *
 * Runs without WordPress.
 */

declare(strict_types=1);

$root     = dirname( __DIR__ );
$failures = 0;
function expect_true( $label, $cond, $detail = '' ) {
	global $failures;
	if ( $cond ) {
		echo "ok   $label\n";
		return;
	}
	++$failures;
	echo "FAIL $label" . ( '' !== $detail ? ": $detail" : '' ) . "\n";
}

/** The texts in the PHP sources: [context, msgid, plural|null]. */
function sources( $root ) {
	$s     = "'((?:[^'\\\\]|\\\\.)*)'";
	$out   = array();
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	$unesc = function ( $v ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $v );
	};
	foreach ( $it as $file ) {
		$path = (string) $file;
		if ( '.php' !== substr( $path, -4 ) || false !== strpos( $path, '/tests/' ) || false !== strpos( $path, '/.git/' ) ) {
			continue;
		}
		$src = (string) file_get_contents( $path );
		preg_match_all( '/(?:__|esc_html__|esc_attr__|esc_html_e|esc_attr_e|_e)\(\s*' . $s . '\s*,\s*\'sudemi\'\s*\)/', $src, $m );
		foreach ( $m[1] as $id ) {
			$out[ "\x04" . $unesc( $id ) ] = array( '', $unesc( $id ), null );
		}
		preg_match_all( '/_n\(\s*' . $s . '\s*,\s*' . $s . '\s*,[^;]*?\'sudemi\'\s*\)/s', $src, $m );
		foreach ( $m[1] as $i => $id ) {
			$out[ "\x04" . $unesc( $id ) ] = array( '', $unesc( $id ), $unesc( $m[2][ $i ] ) );
		}
		preg_match_all( '/_x\(\s*' . $s . '\s*,\s*' . $s . '\s*,\s*\'sudemi\'\s*\)/', $src, $m );
		foreach ( $m[1] as $i => $id ) {
			$out[ $unesc( $m[2][ $i ] ) . "\x04" . $unesc( $id ) ] = array( $unesc( $m[2][ $i ] ), $unesc( $id ), null );
		}
	}
	return $out;
}

/** A .po/.pot file: key "context\x04msgid" => array of msgstr forms. */
function read_po( $path ) {
	$entries = array();
	$cur     = null;
	$field   = null;
	$flush   = function () use ( &$cur, &$entries ) {
		if ( null !== $cur && null !== $cur['id'] ) {
			$entries[ (string) $cur['ctx'] . "\x04" . $cur['id'] ] = $cur;
		}
	};
	$unq     = function ( $s ) {
		return stripcslashes( substr( trim( $s ), 1, -1 ) );
	};
	foreach ( file( $path ) as $line ) {
		$line = rtrim( $line, "\n" );
		if ( '' === trim( $line ) || '#' === substr( $line, 0, 1 ) ) {
			if ( null !== $cur && null !== $cur['id'] && null !== $field && 'str' === $field[0] ) {
				$flush();
				$cur = null;
			}
			$field = null;
			continue;
		}
		if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(".*")$/', $line, $m ) ) {
			if ( 'msgctxt' === $m[1] || ( 'msgid' === $m[1] && ( null === $cur || null !== $cur['id'] ) ) ) {
				if ( 'msgid' === $m[1] && null !== $cur && null === $cur['id'] ) {
					// msgctxt came first.
				} else {
					$flush();
					$cur = array( 'ctx' => '', 'id' => null, 'plural' => null, 'strs' => array() );
				}
			}
			$v = $unq( $m[3] );
			if ( 'msgctxt' === $m[1] ) {
				$cur['ctx'] = $v;
				$field      = array( 'ctx' );
			} elseif ( 'msgid' === $m[1] ) {
				$cur['id'] = $v;
				$field     = array( 'id' );
			} elseif ( 'msgid_plural' === $m[1] ) {
				$cur['plural'] = $v;
				$field         = array( 'plural' );
			} else {
				$i                  = '' === ( $m[2] ?? '' ) ? 0 : (int) $m[2];
				$cur['strs'][ $i ] = $v;
				$field              = array( 'str', $i );
			}
			continue;
		}
		if ( '"' === substr( $line, 0, 1 ) && null !== $field ) {
			$v = $unq( $line );
			if ( 'str' === $field[0] ) {
				$cur['strs'][ $field[1] ] .= $v;
			} else {
				$cur[ $field[0] ] .= $v;
			}
		}
	}
	$flush();
	return $entries;
}

function placeholders( $s ) {
	preg_match_all( '/%(?:\d+\$)?[sd]/', $s, $m );
	$p = array_unique( $m[0] );
	sort( $p );
	return $p;
}

$texts = sources( $root );
expect_true( 'the plugin has texts to translate', count( $texts ) > 400, (string) count( $texts ) );

$pot = read_po( $root . '/languages/sudemi.pot' );
$missing = array();
foreach ( $texts as $key => $t ) {
	if ( ! isset( $pot[ $key ] ) ) {
		$missing[] = $t[1];
	}
}
expect_true( 'every text is in sudemi.pot', empty( $missing ), implode( ' | ', array_slice( $missing, 0, 5 ) ) );

$plurals = array( 'ar' => 6, 'de_DE' => 2, 'es_ES' => 2, 'fr_FR' => 2, 'he_IL' => 2, 'it_IT' => 2, 'nl_NL' => 2, 'pl_PL' => 3, 'ru_RU' => 3, 'tr_TR' => 2, 'zh_CN' => 1 );
foreach ( $plurals as $lang => $forms ) {
	$po      = read_po( $root . "/languages/sudemi-$lang.po" );
	$untrans = array();
	$badph   = array();
	$badpl   = array();
	$dash    = array();
	foreach ( $texts as $key => $t ) {
		if ( ! isset( $po[ $key ] ) || '' === trim( implode( '', $po[ $key ]['strs'] ) ) ) {
			$untrans[] = $t[1];
			continue;
		}
		$strs = $po[ $key ]['strs'];
		if ( null !== $t[2] && count( $strs ) !== $forms ) {
			$badpl[] = $t[1];
		}
		$want = placeholders( $t[1] . ( null !== $t[2] ? ' ' . $t[2] : '' ) );
		foreach ( $strs as $str ) {
			if ( '' === trim( $str ) ) {
				$untrans[] = $t[1];
			}
			$got = placeholders( $str );
			// A plural form may leave the number out ("one order").
			if ( $got !== $want && ! ( null !== $t[2] && array_diff( $got, $want ) === array() ) ) {
				$badph[] = $t[1] . ' => ' . $str;
			}
			if ( false !== strpos( $str, "\u{2014}" ) ) {
				$dash[] = $t[1];
			}
		}
	}
	expect_true( "$lang: every text translated", empty( $untrans ), implode( ' | ', array_slice( $untrans, 0, 5 ) ) );
	expect_true( "$lang: same placeholders as the English", empty( $badph ), implode( ' | ', array_slice( $badph, 0, 3 ) ) );
	expect_true( "$lang: $forms plural forms where the English has a plural", empty( $badpl ), implode( ' | ', array_slice( $badpl, 0, 3 ) ) );
	expect_true( "$lang: no long dash", empty( $dash ), implode( ' | ', array_slice( $dash, 0, 3 ) ) );
	$mo = $root . "/languages/sudemi-$lang.mo";
	expect_true( "$lang: compiled .mo present", is_file( $mo ) && filesize( $mo ) > 1000 );
}

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
