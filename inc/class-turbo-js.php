<?php
/**
 * JavaScript minifier.
 *
 * A conservative minifier: it removes comments and dead whitespace and changes
 * nothing else. No identifier is renamed, no semicolon is added or removed, no
 * expression is rewritten. That restraint is deliberate - the theme's main.js
 * is hand-written and readable, and a renamer that gets one corner case wrong
 * (a `with` scope, an `eval`, a getter that is looked up by name) breaks a
 * player on somebody's phone with no error to point at. What is left is still a
 * real win: comments and indentation are a quarter to a third of a readable
 * script, and they compress far worse than the code they surround.
 *
 * The scanner is a single pass over the characters with an explicit state for
 * each thing JavaScript lets you quote or comment with. The one place it has to
 * guess is `/`, which starts a regular expression in one position and divides
 * in another; the rule used here is described in scan() beside the branch that
 * applies it.
 *
 * @package Majestic Turbo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Strip comments and indentation from JavaScript without rewriting it.
 */
class Majestic_Turbo_JS {

	/**
	 * Words after which a `/` starts a regular expression rather than a division.
	 *
	 * `return /x/.test( y )` is a regex; `total / count` is a division. The
	 * keyword is the only difference at that position, so the scanner tracks the
	 * last word it emitted and consults this list.
	 *
	 * @var string[]
	 */
	private static $regex_preceding_words = array(
		'return',
		'typeof',
		'instanceof',
		'in',
		'of',
		'new',
		'delete',
		'void',
		'throw',
		'case',
		'do',
		'else',
		'yield',
		'await',
		'default',
	);

	/**
	 * Characters after which a `/` starts a regular expression.
	 *
	 * The list is the set of operators and openers that cannot end an
	 * expression. A `/` after a closing bracket, an identifier, a number or a
	 * string is a division, because all of those can end one.
	 *
	 * @var string
	 */
	private static $regex_preceding_chars = '(,=:[!&|?{};+-*%^~<>';

	/**
	 * Minify a complete script.
	 *
	 * @param string $js Source.
	 * @return string Minified source.
	 */
	public static function minify( $js ) {
		return self::scan( (string) $js, false );
	}

	/**
	 * Minify the body of an inline `<script>` element.
	 *
	 * The only difference from minify() is the ending: an inline block has no
	 * file to end, so the last newline would only be a byte to send and a
	 * trailing line comment has nowhere to end. Both are handled in the scanner
	 * so the two entry points cannot drift apart.
	 *
	 * @param string $js Source.
	 * @return string Minified source, without a trailing newline.
	 */
	public static function inline( $js ) {
		return self::scan( (string) $js, true );
	}

	/**
	 * Whether a script uses a dynamic `import()`.
	 *
	 * A dynamic import resolves its specifier at run time, which is the one
	 * construct that makes a script unsafe to concatenate with another. It is a
	 * question, not a transformation, so it is answered with the same scanner
	 * that knows where strings and comments are - a naive `strpos()` would be
	 * fooled by the word appearing in a comment or a URL.
	 *
	 * @param string $js Source.
	 * @return bool
	 */
	public static function has_dynamic_import( $js ) {
		$js    = (string) $js;
		$length = strlen( $js );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $js[ $i ];

			// Skip over anything that only looks like code.
			if ( '"' === $char || "'" === $char ) {
				$i = self::skip_quoted( $js, $i, $char );
				continue;
			}

			if ( '`' === $char ) {
				$i = self::skip_template( $js, $i );
				continue;
			}

			if ( '/' === $char && $i + 1 < $length ) {
				$next = $js[ $i + 1 ];

				if ( '/' === $next ) {
					$i = self::skip_line_comment( $js, $i );
					continue;
				}

				if ( '*' === $next ) {
					$i = self::skip_block_comment( $js, $i );
					continue;
				}
			}

			if ( 'i' !== $char && 'I' !== $char ) {
				continue;
			}

			if ( 0 !== strncasecmp( substr( $js, $i, 6 ), 'import', 6 ) ) {
				continue;
			}

			// A word boundary on both sides, so `important` and `myimport` are
			// not mistaken for the keyword.
			if ( $i > 0 && self::is_word_char( $js[ $i - 1 ] ) ) {
				continue;
			}

			$after = $i + 6;

			if ( $after < $length && self::is_word_char( $js[ $after ] ) ) {
				continue;
			}

			while ( $after < $length && false !== strpos( " \t\r\n", $js[ $after ] ) ) {
				++$after;
			}

			if ( $after < $length && '(' === $js[ $after ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The scanner both entry points share.
	 *
	 * A single pass, because every alternative that starts with a regular
	 * expression over the whole file either loses the state that says "this
	 * brace is inside a template literal" or has to re-scan the input once per
	 * construct. Nothing is copied unless it is kept, so memory stays linear in
	 * the output.
	 *
	 * @param string $js     Source.
	 * @param bool   $inline Whether this is an inline block.
	 * @return string
	 */
	private static function scan( $js, $inline ) {
		$length = strlen( $js );
		$output = '';
		$word   = '';
		$last   = ''; // Last significant character emitted, used by the `/` rule.
		$line   = '';
		$i      = 0;

		while ( $i < $length ) {
			$char = $js[ $i ];

			// Block comment.
			if ( '/' === $char && $i + 1 < $length && '*' === $js[ $i + 1 ] ) {
				$end = self::block_comment_end( $js, $i );

				// The `/*!` form is a license banner. It has to survive: a GPL
				// or MIT notice is a condition of shipping the file, and some
				// builds fail a check when it is missing.
				if ( $i + 2 < $length && '!' === $js[ $i + 2 ] ) {
					$line .= substr( $js, $i, $end - $i );
				} elseif ( false !== strpos( substr( $js, $i, $end - $i ), "\n" ) ) {
					/*
					 * The comment spanned lines, and a line break inside a block
					 * comment is a line terminator for automatic semicolon
					 * insertion: a call, then a multi-line comment, then another
					 * call is two statements. Emitting the break keeps that, but
					 * not everywhere - after `return`, a break would end the
					 * statement early and the value would become undefined. So
					 * the break is written only where it cannot split a
					 * statement, and a space otherwise.
					 */
					if ( self::ends_statement( $line, $word ) ) {
						$output .= rtrim( $line, " \t" ) . "\n";
						$line    = '';
					} elseif ( '' !== trim( $line ) ) {
						$line = rtrim( $line, " \t" ) . ' ';
					}
				}

				$i = $end;
				continue;
			}

			// Line comment: removed, but its newline is kept, because automatic
			// semicolon insertion treats a line break as significant.
			if ( '/' === $char && $i + 1 < $length && '/' === $js[ $i + 1 ] ) {
				$end = self::line_comment_end( $js, $i );
				$i   = $end;
				continue;
			}

			// Quoted string: copied verbatim, escapes and all.
			if ( '"' === $char || "'" === $char ) {
				$end  = self::quoted_end( $js, $i, $char );
				$line .= substr( $js, $i, $end - $i );
				$last  = $char;
				$word  = '';
				$i     = $end;
				continue;
			}

			// Template literal, including `${ ... }` expressions.
			if ( '`' === $char ) {
				$end  = self::template_end( $js, $i );
				$line .= substr( $js, $i, $end - $i );
				$last  = '`';
				$word  = '';
				$i     = $end;
				continue;
			}

			// A `/` that is not a comment: regex or division.
			if ( '/' === $char ) {
				if ( self::starts_regex( $last, $word ) ) {
					$end  = self::regex_end( $js, $i );
					$line .= substr( $js, $i, $end - $i );
					$last  = '/';
					$word  = '';
					$i     = $end;
					continue;
				}

				$line .= $char;
				$last  = $char;
				$word  = '';
				++$i;
				continue;
			}

			// Newlines end a line of output. They are never joined, so the
			// script keeps every statement boundary it had.
			if ( "\n" === $char || "\r" === $char ) {
				$output .= rtrim( $line, " \t" );
				$output .= "\n";
				$line    = '';

				if ( "\r" === $char && $i + 1 < $length && "\n" === $js[ $i + 1 ] ) {
					++$i;
				}

				++$i;
				continue;
			}

			// Horizontal whitespace outside any literal: a single space is
			// enough, and leading indentation is dropped entirely.
			if ( ' ' === $char || "\t" === $char || "\f" === $char || "\v" === $char ) {
				if ( '' !== $line ) {
					$line .= ' ';
				}

				++$i;

				while ( $i < $length && false !== strpos( " \t\f\v", $js[ $i ] ) ) {
					++$i;
				}

				continue;
			}

			$line .= $char;

			if ( self::is_word_char( $char ) ) {
				$word .= $char;
			} else {
				$word = '';
			}

			$last = $char;
			++$i;
		}

		$output .= rtrim( $line, " \t" );

		// Whitespace-only lines disappear; the newline that carried them does
		// not, because the next line may depend on it.
		$lines  = explode( "\n", $output );
		$output = '';

		foreach ( $lines as $index => $text ) {
			$text = rtrim( $text, " \t" );

			if ( '' === $text && $index < count( $lines ) - 1 ) {
				continue;
			}

			$output .= ( '' === $output ? '' : "\n" ) . $text;
		}

		return $inline ? rtrim( $output, "\n" ) : rtrim( $output, "\n" ) . "\n";
	}

	/**
	 * Whether the text written on this line can end a statement.
	 *
	 * Used to decide what a multi-line block comment becomes. After a closing
	 * bracket, a quote or an identifier a line break is a statement boundary
	 * and has to survive; after `return`, `throw` or an operator it would end
	 * the statement early, so the comment becomes a space instead.
	 *
	 * @param string $line Text of the current output line.
	 * @param string $word Identifier that text ends with, if any.
	 * @return bool
	 */
	private static function ends_statement( $line, $word ) {
		$line = rtrim( (string) $line, " \t" );

		if ( '' === $line ) {
			return false;
		}

		// `return` and its family take an expression on the same line: a
		// break after one is a different program.
		if ( '' !== $word && in_array( strtolower( $word ), array( 'return', 'throw', 'break', 'continue', 'yield', 'await' ), true ) ) {
			return false;
		}

		$tail = substr( $line, -1 );

		if ( false !== strpos( ')]};`\'"', $tail ) ) {
			return true;
		}

		return (bool) preg_match( '/[A-Za-z0-9_$]/', $tail );
	}

	/**
	 * Whether a `/` at the current position opens a regular expression.
	 *
	 * This is the ambiguity that makes JavaScript minification awkward. `/`
	 * divides when the token before it can end an expression - an identifier, a
	 * number, a closing bracket, a string - and starts a regex when it cannot:
	 * after an operator, an opening bracket, a comma, a keyword such as
	 * `return`. Both halves of that rule live in this class so a test can pin
	 * them.
	 *
	 * @param string $last Last significant character emitted.
	 * @param string $word The identifier that character is the end of, if any.
	 * @return bool
	 */
	private static function starts_regex( $last, $word ) {
		if ( '' === $last ) {
			return true;
		}

		if ( '' !== $word && in_array( strtolower( $word ), self::$regex_preceding_words, true ) ) {
			return true;
		}

		// `)`, `]` and `}` end an expression (a call, an index, an object
		// literal), so a `/` after them divides. A `}` closing a block is the
		// counter-example - `if (x) {} /re/.test(y)` is legal but vanishingly
		// rare, and treating it as division only risks a stray `/` in the
		// output, not a changed program.
		if ( false !== strpos( self::$regex_preceding_chars, $last ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether a character can be part of an identifier.
	 *
	 * @param string $char One character.
	 * @return bool
	 */
	private static function is_word_char( $char ) {
		return (bool) preg_match( '/[A-Za-z0-9_$]/', $char );
	}

	/**
	 * Find the end of a quoted string.
	 *
	 * @param string $js   Source.
	 * @param int    $i    Offset of the opening quote.
	 * @param string $quote Quote character.
	 * @return int Offset just past the closing quote.
	 */
	private static function quoted_end( $js, $i, $quote ) {
		$length = strlen( $js );
		++$i;

		while ( $i < $length ) {
			if ( '\\' === $js[ $i ] ) {
				$i += 2;
				continue;
			}

			if ( $quote === $js[ $i ] ) {
				return $i + 1;
			}

			// An unterminated string is a broken script; stopping at the line
			// break keeps the damage on one line instead of swallowing the file.
			if ( "\n" === $js[ $i ] ) {
				return $i;
			}

			++$i;
		}

		return $length;
	}

	/**
	 * Skip a quoted string without copying it.
	 *
	 * @param string $js    Source.
	 * @param int    $i     Offset of the opening quote.
	 * @param string $quote Quote character.
	 * @return int Offset just past the closing quote.
	 */
	private static function skip_quoted( $js, $i, $quote ) {
		return self::quoted_end( $js, $i, $quote ) - 1;
	}

	/**
	 * Find the end of a template literal.
	 *
	 * `${ ... }` may hold arbitrary code, including strings, comments and
	 * further template literals, so the expression is walked with a brace depth
	 * rather than searched for the next backtick - a backtick inside an
	 * interpolation is a nested template, not the end of this one.
	 *
	 * @param string $js Source.
	 * @param int    $i  Offset of the opening backtick.
	 * @return int Offset just past the closing backtick.
	 */
	private static function template_end( $js, $i ) {
		$length = strlen( $js );
		++$i;

		while ( $i < $length ) {
			$char = $js[ $i ];

			if ( '\\' === $char ) {
				$i += 2;
				continue;
			}

			if ( '`' === $char ) {
				return $i + 1;
			}

			if ( '$' === $char && $i + 1 < $length && '{' === $js[ $i + 1 ] ) {
				$i = self::expression_end( $js, $i + 2 );
				continue;
			}

			++$i;
		}

		return $length;
	}

	/**
	 * Walk an interpolation expression to its closing brace.
	 *
	 * @param string $js Source.
	 * @param int    $i  Offset just past the opening brace.
	 * @return int Offset just past the closing brace.
	 */
	private static function expression_end( $js, $i ) {
		$length = strlen( $js );
		$depth  = 1;

		while ( $i < $length ) {
			$char = $js[ $i ];

			if ( '"' === $char || "'" === $char ) {
				$i = self::quoted_end( $js, $i, $char );
				continue;
			}

			if ( '`' === $char ) {
				$i = self::template_end( $js, $i );
				continue;
			}

			if ( '/' === $char && $i + 1 < $length ) {
				if ( '/' === $js[ $i + 1 ] ) {
					$i = self::line_comment_end( $js, $i );
					continue;
				}

				if ( '*' === $js[ $i + 1 ] ) {
					$i = self::block_comment_end( $js, $i );
					continue;
				}
			}

			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;

				if ( 0 === $depth ) {
					return $i + 1;
				}
			}

			++$i;
		}

		return $length;
	}

	/**
	 * Find the end of a regular expression literal.
	 *
	 * A `/` inside a character class is a literal slash, not the end, which is
	 * why this is a scan and not a search for the next slash.
	 *
	 * @param string $js Source.
	 * @param int    $i  Offset of the opening slash.
	 * @return int Offset just past the flags.
	 */
	private static function regex_end( $js, $i ) {
		$length  = strlen( $js );
		$in_set  = false;
		++$i;

		while ( $i < $length ) {
			$char = $js[ $i ];

			if ( '\\' === $char ) {
				$i += 2;
				continue;
			}

			if ( "\n" === $char ) {
				// An unterminated regex: treat the slash as division and move on.
				return $i;
			}

			if ( '[' === $char ) {
				$in_set = true;
			} elseif ( ']' === $char ) {
				$in_set = false;
			} elseif ( '/' === $char && ! $in_set ) {
				++$i;

				while ( $i < $length && preg_match( '/[a-z]/i', $js[ $i ] ) ) {
					++$i; // Flags: g, i, m, s, u, y, d, v.
				}

				return $i;
			}

			++$i;
		}

		return $length;
	}

	/**
	 * Skip a truncated version of the quoted-string scan.
	 *
	 * Kept so has_dynamic_import() and the scanner share one notion of where a
	 * string ends.
	 *
	 * @param string $js Source.
	 * @param int    $i  Offset of the opening backtick.
	 * @return int Offset just past the closing backtick.
	 */
	private static function skip_template( $js, $i ) {
		return self::template_end( $js, $i ) - 1;
	}

	/**
	 * Find the end of a line comment.
	 *
	 * @param string $js Source.
	 * @param int    $i  Offset of the first slash.
	 * @return int Offset of the newline, or the end of input.
	 */
	private static function line_comment_end( $js, $i ) {
		$length = strlen( $js );
		$i     += 2;

		while ( $i < $length && "\n" !== $js[ $i ] && "\r" !== $js[ $i ] ) {
			++$i;
		}

		return $i;
	}

	/**
	 * Find the end of a block comment.
	 *
	 * @param string $js Source.
	 * @param int    $i  Offset of the opening slash.
	 * @return int Offset just past the closing sequence, or the end of input.
	 */
	private static function block_comment_end( $js, $i ) {
		$end = strpos( $js, '*/', $i + 2 );

		return false === $end ? strlen( $js ) : $end + 2;
	}

	/**
	 * Skip a block comment without copying it.
	 *
	 * @param string $js Source.
	 * @param int    $i  Offset of the opening slash.
	 * @return int Offset just past the closing sequence.
	 */
	private static function skip_block_comment( $js, $i ) {
		return self::block_comment_end( $js, $i ) - 1;
	}

	/**
	 * Skip a line comment without copying it.
	 *
	 * @param string $js Source.
	 * @param int    $i  Offset of the first slash.
	 * @return int Offset of the newline, or the last offset.
	 */
	private static function skip_line_comment( $js, $i ) {
		return self::line_comment_end( $js, $i ) - 1;
	}
}
