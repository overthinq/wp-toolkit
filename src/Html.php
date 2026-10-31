<?php

namespace ContentToolkit;

defined( 'ABSPATH' ) || exit;

/**
 * Il markup dentro i commenti HTML è testo disattivato: i tool non lo toccano.
 */
final class Html {

	/**
	 * @return array<int, array{int, int}> Inizio e fine di ogni commento.
	 */
	public static function comment_ranges( string $content ): array {
		preg_match_all( '/<!--.*?-->/s', $content, $matches, PREG_OFFSET_CAPTURE );
		return array_map( static fn( $comment ) => array( $comment[1], $comment[1] + strlen( $comment[0] ) ), $matches[0] );
	}

	public static function in_ranges( int $offset, array $ranges ): bool {
		foreach ( $ranges as [ $start, $end ] ) {
			if ( $offset >= $start && $offset < $end ) {
				return true;
			}
		}
		return false;
	}
}
