<?php
/**
 * CSS processing: minification, URL rewriting and the unused-rule reducer.
 *
 * The three jobs in this file are all string work on a stylesheet, and they are
 * deliberately free of WordPress: the renderer that uses them decides *which*
 * files to process, while everything that can be got wrong about CSS lives
 * here, where it can be exercised without an install.
 *
 * The reducer is the interesting one. PageSpeed's "reduce unused CSS" audit is
 * the largest single CSS cost on a tube page, because main.css carries the
 * single-video player, the profile screens, the moderation list and the
 * comment form while the home page paints none of them. A browser cannot know
 * which of those rules the next page will need, so the split happens on the
 * server, against the markup that was actually rendered: rules the page uses
 * go inline (the above-the-fold ones) or into the deferred sheet (the rest),
 * and rules no element on the page can match are left out. Rules that a script
 * adds after load cannot be found in the markup, so a safelist keeps them.
 *
 * @package Majestic Turbo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minify and reduce stylesheets.
 */
class Majestic_Turbo_CSS {

	/**
	 * Grouping at-rules whose body holds ordinary rules.
	 *
	 * Everything else that opens a block - @font-face, @keyframes, @page,
	 * @property, @counter-style - is a single declaration block with its own
	 * grammar, so it is copied whole rather than searched for selectors.
	 */
	const GROUP_AT_RULES = 'media|supports|container|layer|scope|document|starting-style';

	/**
	 * Minify a stylesheet.
	 *
	 * Comments go, whitespace runs collapse, and the spaces that carry no
	 * meaning are removed. The two places where a space *is* meaningful are
	 * left alone on purpose:
	 *
	 *   - inside calc(), where `100% - 1em` is arithmetic and `100%-1em` is a
	 *     parse error;
	 *   - after the colon of a custom property, because `--x:` takes the rest
	 *     of the declaration verbatim.
	 *
	 * url() is copied through untouched so a data URI full of braces, commas
	 * and semicolons cannot be mistaken for CSS structure, and every string is
	 * copied verbatim so `content: "a, b"` keeps its comma and its space.
	 *
	 * The result is idempotent: minifying a minified sheet returns it unchanged.
	 *
	 * @param string $css Stylesheet.
	 * @return string
	 */
	public static function minify( $css ) {
		$css = (string) $css;

		if ( '' === trim( $css ) ) {
			return '';
		}

		$length         = strlen( $css );
		$output         = '';
		$last           = '';
		$declaration    = '';
		$pending        = false;
		$index          = 0;
		$in_declaration = false;
		$blocks         = array();

		while ( $index < $length ) {
			$char = $css[ $index ];

			// A comment. Kept only when it is a bang comment, which is how a
			// license notice asks to survive minification.
			if ( '/' === $char && $index + 1 < $length && '*' === $css[ $index + 1 ] ) {
				$end     = strpos( $css, '*/', $index + 2 );
				$comment = false === $end ? substr( $css, $index ) : substr( $css, $index, ( $end + 2 ) - $index );

				if ( 0 === strpos( $comment, '/*!' ) ) {
					$output .= self::flush_space( $pending, $last, '/' ) . rtrim( $comment );
					$last    = '/';
					$pending = false;
				}

				$index = false === $end ? $length : $end + 2;
				continue;
			}

			if ( ctype_space( $char ) ) {
				$pending = true;

				// The declaration or prelude being read keeps one space per run,
				// so a block prelude still reads as an at-rule followed by its
				// condition when the block is classified further down.
				if ( '' !== $declaration && ' ' !== substr( $declaration, -1 ) ) {
					$declaration .= ' ';
				}

				++$index;
				continue;
			}

			// A string: copied verbatim, escapes included.
			if ( '"' === $char || "'" === $char ) {
				$string  = self::read_string( $css, $index );
				$output .= self::flush_space( $pending, $last, $char ) . $string;
				$last    = substr( $string, -1 );
				$pending = false;
				$index  += strlen( $string );
				continue;
			}

			// url(...): one atomic token, whatever it contains.
			if ( ( 'u' === $char || 'U' === $char ) && 0 === strcasecmp( 'url(', substr( $css, $index, 4 ) )
				&& ( 0 === $index || ! preg_match( '/[\w-]/', $css[ $index - 1 ] ) ) ) {
				$url     = self::read_url( $css, $index );
				$output .= self::flush_space( $pending, $last, 'u' ) . $url;
				$last    = ')';
				$pending = false;
				$index  += strlen( $url );
				continue;
			}

			// The colon of a custom property keeps the single space that
			// separates the name from its value.
			if ( ':' === $char && self::is_custom_property_name( $declaration ) ) {
				$output .= ':';
				$last    = ':';
				$pending = false;
				++$index;

				// Exactly one space, however much whitespace the source had:
				// the value keeps a leading space, the run itself carries no
				// meaning.
				if ( $index < $length && ctype_space( $css[ $index ] ) ) {
					$output .= ' ';
					$last    = ' ';

					while ( $index < $length && ctype_space( $css[ $index ] ) ) {
						++$index;
					}
				}

				continue;
			}

			// A semicolon immediately before a closing brace is the last
			// declaration's terminator and says nothing; the lookahead skips
			// whitespace and comments, so `a{b:c; /* x */}` loses it too.
			if ( ';' === $char && '}' === self::next_significant( $css, $index + 1 ) ) {
				++$index;
				continue;
			}

			/*
			 * The text since the last brace or semicolon is the rule's prelude at
			 * an opening brace, so that is where the kind of the block is decided:
			 * a grouping at-rule holds rules, everything else holds declarations.
			 * It is the only thing that tells a colon in `ul :first-child` (a
			 * descendant combinator) from one in `color : red` (dead space).
			 */
			if ( '{' === $char ) {
				$blocks[]       = $in_declaration;
				$in_declaration = ! self::is_group_prelude( $declaration );
			} elseif ( '}' === $char && $blocks ) {
				$in_declaration = array_pop( $blocks );
			}

			$property = ':' === $char && $in_declaration && self::is_property_name( $declaration );

			$output .= self::flush_space( $pending, $last, $char, $property ) . $char;
			$last    = $char;
			$pending = false;

			// The text of the declaration being read, so the colon check above
			// is a comparison rather than a scan back through the output. Each
			// brace and semicolon starts the next one.
			$declaration = ( '{' === $char || '}' === $char || ';' === $char ) ? '' : $declaration . $char;
			++$index;
		}

		return trim( $output );
	}

	/**
	 * Resolve every relative url() and @import against the stylesheet's URL.
	 *
	 * A combined stylesheet lives in uploads while the rules it carries came
	 * from the theme, core and several plugins, so a relative `../fonts/x.woff2`
	 * has to be resolved against the file it was written in - not against the
	 * bundle's own directory. This is the step that makes combining safe.
	 *
	 * @param string $css      Stylesheet.
	 * @param string $base_url Directory URL the stylesheet was loaded from.
	 * @return string
	 */
	public static function urls_absolute( $css, $base_url ) {
		$css      = (string) $css;
		$base_url = trim( (string) $base_url );

		if ( '' === $css || '' === $base_url ) {
			return $css;
		}

		$css = preg_replace_callback(
			'/url\(\s*([\'"]?)(.*?)\1\s*\)/is',
			function ( $matches ) use ( $base_url ) {
				$reference = trim( $matches[2] );

				if ( '' === $reference ) {
					return $matches[0];
				}

				$resolved = self::resolve_url( $reference, $base_url );

				if ( $resolved === $reference ) {
					return $matches[0];
				}

				$quote = '' === $matches[1] ? '' : $matches[1];

				return 'url(' . $quote . $resolved . $quote . ')';
			},
			$css
		);

		// An @import without url() carries its target as a bare string.
		$css = preg_replace_callback(
			'/@import\s+([\'"])([^\'"]+)\1/is',
			function ( $matches ) use ( $base_url ) {
				$resolved = self::resolve_url( $matches[2], $base_url );

				return '@import ' . $matches[1] . $resolved . $matches[1];
			},
			$css
		);

		return (string) $css;
	}

	/**
	 * Split a stylesheet into what a page uses above the fold, what it uses
	 * further down, and what it does not use at all.
	 *
	 * @param string   $css        Stylesheet; minified here, so either form is accepted.
	 * @param string   $html       The rendered page.
	 * @param int      $fold_bytes Bytes of body markup counted as above the fold.
	 * @param string[] $keep       Selector prefixes that are never reduced away.
	 * @param bool     $inline_all Put every used rule in `critical`, including the
	 *                             safelisted ones. A caller that prints one inline
	 *                             stylesheet and no deferred one needs this: the
	 *                             safelist normally lands in `rest`, and that half
	 *                             would otherwise be thrown away.
	 * @return array{critical: string, rest: string, kept: int, dropped: int, total: int, safe: bool}
	 */
	public static function split( $css, $html, $fold_bytes = 45000, $keep = array(), $inline_all = false ) {
		$css = (string) $css;

		/*
		 * The combined bundle on disk is already minified, and minifying 80KB
		 * again on every render is the most expensive thing this class does. A
		 * sheet with no newline and no comment is treated as minified: a
		 * hand-written one almost always has at least one of the two, and the
		 * check is a strpos rather than a guess.
		 */
		if ( false !== strpos( $css, "\n" ) || false !== strpos( $css, '/*' ) ) {
			$css = self::minify( $css );
		} else {
			$css = trim( $css );
		}

		$html = (string) $html;

		$page_tokens  = self::tokens( $html );
		$fold_tokens  = self::tokens( self::fold_markup( $html, (int) $fold_bytes ) );
		$keep         = self::normalize_keep( (array) $keep );
		$nodes        = self::parse_rules( $css );
		$total        = self::count_rules( $nodes );

		$unsafe = array(
			'critical' => $css,
			'rest'     => '',
			'kept'     => $total,
			'dropped'  => 0,
			'total'    => $total,
			'safe'     => false,
		);

		// Nothing to measure against, or nothing to measure: keep everything.
		if ( 0 === $total || '' === trim( $html ) || ( empty( $page_tokens['class'] ) && empty( $page_tokens['id'] ) ) ) {
			return $unsafe;
		}

		$kept    = 0;
		$dropped = 0;

		foreach ( $nodes as $node ) {
			$verdict = self::count_node( $node, $page_tokens, $keep );

			$kept    += $verdict['kept'];
			$dropped += $verdict['dropped'];
		}

		// A reducer that believes a real page uses almost none of a stylesheet
		// is broken - a truncated page, an unparseable template - and shipping
		// that answer would strip the site to unstyled HTML. Keep everything.
		if ( $total > 0 && ( $dropped / $total ) > 0.85 ) {
			return $unsafe;
		}

		return array(
			'critical' => self::render_rules( $nodes, $page_tokens, $fold_tokens, $keep, 'fold', $inline_all ),
			'rest'     => self::render_rules( $nodes, $page_tokens, $fold_tokens, $keep, 'rest', $inline_all ),
			'kept'     => $kept,
			'dropped'  => $dropped,
			'total'    => $total,
			'safe'     => true,
		);
	}

	/* ---------------------------------------------------------------------
	 * Minifier internals
	 * ------------------------------------------------------------------ */

	/**
	 * Append a pending space when it separates two meaningful characters.
	 *
	 * @param bool   $pending        Whether whitespace was seen.
	 * @param string $last           Last character written.
	 * @param string $next           Character about to be written.
	 * @param bool   $in_declaration Whether the colon being written separates a
	 *                               property from its value.
	 * @return string Either one space or nothing.
	 */
	private static function flush_space( $pending, $last, $next, $in_declaration = false ) {
		if ( ! $pending || '' === $last || ' ' === $last ) {
			return '';
		}

		$tight = '{};,>';

		if ( false !== strpos( $tight, $last ) || false !== strpos( $tight, $next ) ) {
			return '';
		}

		// No space just inside parentheses. The space *before* an opening
		// parenthesis is left alone: `@media screen and (min-width: 48em)`
		// needs it, and `and(min-width: 48em)` is a different token.
		if ( '(' === $last || ')' === $next ) {
			return '';
		}

		/*
		 * A space before a colon is dead in a declaration - `color : red` is
		 * `color:red` - and meaningful in a selector, where it is a descendant
		 * combinator: `ul :first-child` is not `ul:first-child`, and joining the
		 * two would restyle a page.
		 */
		if ( ':' === $next && ! $in_declaration ) {
			return ' ';
		}

		if ( ':' === $last || ':' === $next ) {
			return '';
		}

		return ' ';
	}

	/**
	 * Whether the text since the last brace or semicolon is a custom property's
	 * name.
	 *
	 * @param string $declaration Declaration text written so far.
	 * @return bool
	 */
	private static function is_custom_property_name( $declaration ) {
		return (bool) preg_match( '/^--[\w-]+$/', trim( $declaration ) );
	}

	/**
	 * Whether the text since the last brace or semicolon is a property name.
	 *
	 * Used to tell a declaration's colon from a selector's, so the answer has
	 * to be strict: a bare element selector such as `ul` also looks like one,
	 * which is why the caller checks the block as well.
	 *
	 * @param string $declaration Declaration text written so far.
	 * @return bool
	 */
	private static function is_property_name( $declaration ) {
		return (bool) preg_match( '/^-{0,2}[a-zA-Z_][\w-]*$/', trim( $declaration ) );
	}

	/**
	 * Whether a block prelude opens a group of rules rather than a list of
	 * declarations.
	 *
	 * @param string $prelude Text before the opening brace.
	 * @return bool
	 */
	private static function is_group_prelude( $prelude ) {
		return (bool) preg_match( '/^@(?:' . self::GROUP_AT_RULES . ')\b/i', trim( (string) $prelude ) );
	}

	/**
	 * Read a quoted string, escapes included.
	 *
	 * @param string $css   Stylesheet.
	 * @param int    $index Offset of the opening quote.
	 * @return string The string, with both quotes.
	 */
	private static function read_string( $css, $index ) {
		$quote  = $css[ $index ];
		$length = strlen( $css );
		$out    = $quote;
		$i      = $index + 1;

		while ( $i < $length ) {
			$char = $css[ $i ];
			$out .= $char;

			if ( '\\' === $char && $i + 1 < $length ) {
				$out .= $css[ $i + 1 ];
				$i   += 2;
				continue;
			}

			++$i;

			if ( $char === $quote ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Read a url(...) token, including a quoted argument.
	 *
	 * @param string $css   Stylesheet.
	 * @param int    $index Offset of the `u`.
	 * @return string The whole token.
	 */
	private static function read_url( $css, $index ) {
		$length = strlen( $css );
		$out    = '';
		$depth  = 0;
		$i      = $index;

		while ( $i < $length ) {
			$char = $css[ $i ];

			if ( '"' === $char || "'" === $char ) {
				$string = self::read_string( $css, $i );
				$out   .= $string;
				$i     += strlen( $string );
				continue;
			}

			$out .= $char;
			++$i;

			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;

				if ( $depth <= 0 ) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * The next character that is neither whitespace nor inside a comment.
	 *
	 * @param string $css   Stylesheet.
	 * @param int    $index Offset to start at.
	 * @return string One character, or an empty string at the end.
	 */
	private static function next_significant( $css, $index ) {
		$length = strlen( $css );

		while ( $index < $length ) {
			if ( '/' === $css[ $index ] && $index + 1 < $length && '*' === $css[ $index + 1 ] ) {
				$end   = strpos( $css, '*/', $index + 2 );
				$index = false === $end ? $length : $end + 2;
				continue;
			}

			if ( ctype_space( $css[ $index ] ) ) {
				++$index;
				continue;
			}

			return $css[ $index ];
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * URL resolution
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve one reference against a directory URL.
	 *
	 * @param string $reference Relative URL.
	 * @param string $base_url  Directory URL.
	 * @return string
	 */
	private static function resolve_url( $reference, $base_url ) {
		$reference = trim( $reference );

		if ( '' === $reference ) {
			return $reference;
		}

		// Absolute already: a scheme, a protocol-relative host, a fragment or
		// a root-relative path. A root-relative path is left to the browser,
		// which resolves it against the page's own origin correctly.
		if ( preg_match( '#^(?:[a-z][a-z0-9+.\-]*:|//|\#|/)#i', $reference ) ) {
			return $reference;
		}

		$suffix = '';

		if ( preg_match( '/^([^?#]*)([?#].*)$/', $reference, $parts ) ) {
			$reference = $parts[1];
			$suffix    = $parts[2];
		}

		if ( '' === $reference ) {
			return $suffix;
		}

		$base = rtrim( $base_url, '/' );

		if ( ! preg_match( '#^(https?:)?//([^/]+)(/.*)?$#i', $base, $parts ) ) {
			return $reference . $suffix;
		}

		$scheme = $parts[1];
		$host   = $parts[2];
		$path   = isset( $parts[3] ) ? $parts[3] : '';
		$stack  = array();

		foreach ( explode( '/', $path . '/' . $reference ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $stack );
				continue;
			}

			$stack[] = $segment;
		}

		return $scheme . '//' . $host . '/' . implode( '/', $stack ) . $suffix;
	}

	/* ---------------------------------------------------------------------
	 * Rule splitting
	 * ------------------------------------------------------------------ */

	/**
	 * Parse a stylesheet into a small tree of nodes.
	 *
	 * @param string $css Stylesheet.
	 * @return array<int, array<string, mixed>>
	 */
	private static function parse_rules( $css ) {
		$nodes  = array();
		$length = strlen( $css );
		$index  = 0;

		while ( $index < $length ) {
			while ( $index < $length && ctype_space( $css[ $index ] ) ) {
				++$index;
			}

			if ( $index >= $length ) {
				break;
			}

			$prelude = '';
			$parens  = 0;
			$closed  = false;

			// The prelude: a selector list or an at-rule condition. It ends at
			// the first `{` or `;` outside parentheses and strings, because a
			// pseudo-class may hold a comma and an @supports condition may hold
			// both.
			while ( $index < $length ) {
				$char = $css[ $index ];

				if ( '"' === $char || "'" === $char ) {
					$string   = self::read_string( $css, $index );
					$prelude .= $string;
					$index   += strlen( $string );
					continue;
				}

				if ( '(' === $char ) {
					++$parens;
				} elseif ( ')' === $char ) {
					$parens = max( 0, $parens - 1 );
				} elseif ( '}' === $char && 0 === $parens ) {
					// A stray closing brace: skip it rather than swallow the
					// rest of the sheet.
					++$index;
					$closed = true;
					break;
				} elseif ( 0 === $parens && ( '{' === $char || ';' === $char ) ) {
					break;
				}

				$prelude .= $char;
				++$index;
			}

			$prelude = trim( $prelude );

			if ( '' === $prelude ) {
				// A brace or semicolon with no prelude is malformed input. Step
				// over it rather than reading the same character for ever.
				if ( $index < $length && ( '{' === $css[ $index ] || ';' === $css[ $index ] ) ) {
					++$index;
				}

				continue;
			}

			if ( $closed ) {
				// Text that ran into a stray closing brace is not a rule; dropping
				// it is safer than printing a fragment into the split half.
				continue;
			}

			if ( $index >= $length ) {
				$nodes[] = array(
					'type' => 'statement',
					'text' => $prelude,
				);
				break;
			}

			if ( ';' === $css[ $index ] ) {
				$nodes[] = array(
					'type' => 'statement',
					'text' => $prelude . ';',
				);
				++$index;
				continue;
			}

			++$index;
			$body = self::read_block( $css, $index );

			if ( '@' === $prelude[0] && preg_match( '/^@(?:' . self::GROUP_AT_RULES . ')\b/i', $prelude ) ) {
				$nodes[] = array(
					'type'     => 'group',
					'prelude'  => $prelude,
					'children' => self::parse_rules( $body ),
				);
				continue;
			}

			$nodes[] = array(
				'type'    => 'rule',
				'prelude' => $prelude,
				'body'    => $body,
			);
		}

		return $nodes;
	}

	/**
	 * Read a block body, leaving the offset after its closing brace.
	 *
	 * @param string $css   Stylesheet.
	 * @param int    $index Offset just after the opening brace; advanced.
	 * @return string Body without the braces.
	 */
	private static function read_block( $css, &$index ) {
		$length = strlen( $css );
		$depth  = 1;
		$start  = $index;

		while ( $index < $length ) {
			$char = $css[ $index ];

			if ( '"' === $char || "'" === $char ) {
				$index += strlen( self::read_string( $css, $index ) );
				continue;
			}

			if ( '/' === $char && $index + 1 < $length && '*' === $css[ $index + 1 ] ) {
				$end    = strpos( $css, '*/', $index + 2 );
				$index  = false === $end ? $length : $end + 2;
				continue;
			}

			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;

				if ( 0 === $depth ) {
					$body = substr( $css, $start, $index - $start );
					++$index;

					return $body;
				}
			}

			++$index;
		}

		return substr( $css, $start );
	}

	/**
	 * Count the declaration blocks in a tree.
	 *
	 * @param array<int, array<string, mixed>> $nodes Nodes.
	 * @return int
	 */
	private static function count_rules( $nodes ) {
		$count = 0;

		foreach ( $nodes as $node ) {
			if ( 'rule' === $node['type'] ) {
				++$count;
			} elseif ( 'group' === $node['type'] ) {
				$count += self::count_rules( $node['children'] );
			}
		}

		return $count;
	}

	/**
	 * How many rules a node contributes to the kept and dropped totals.
	 *
	 * @param array<string, mixed> $node   Node.
	 * @param array<string, mixed> $tokens Page tokens.
	 * @param string[]             $keep   Safelist prefixes.
	 * @return array{kept: int, dropped: int}
	 */
	private static function count_node( $node, $tokens, $keep ) {
		if ( 'group' === $node['type'] ) {
			$kept    = 0;
			$dropped = 0;

			foreach ( $node['children'] as $child ) {
				$verdict  = self::count_node( $child, $tokens, $keep );
				$kept    += $verdict['kept'];
				$dropped += $verdict['dropped'];
			}

			return array(
				'kept'    => $kept,
				'dropped' => $dropped,
			);
		}

		if ( 'statement' === $node['type'] ) {
			return array(
				'kept'    => 0,
				'dropped' => 0,
			);
		}

		$used = self::rule_is_used( $node, $tokens, $keep );

		return array(
			'kept'    => $used ? 1 : 0,
			'dropped' => $used ? 0 : 1,
		);
	}

	/**
	 * Whether a rule belongs on the page.
	 *
	 * An at-rule block (@font-face, @keyframes, @page) is always kept: it has
	 * no selector to match, and a font or an animation the page does not
	 * visibly use is still needed the moment a script plays it.
	 *
	 * @param array<string, mixed> $node   Rule node.
	 * @param array<string, mixed> $tokens Page tokens.
	 * @param string[]             $keep   Safelist prefixes.
	 * @return bool
	 */
	private static function rule_is_used( $node, $tokens, $keep ) {
		$prelude = (string) $node['prelude'];

		if ( '@' === substr( $prelude, 0, 1 ) ) {
			return true;
		}

		return self::selector_is_used( $prelude, $tokens, $keep );
	}

	/**
	 * Render the rules that belong in one half of the split.
	 *
	 * @param array<int, array<string, mixed>> $nodes       Nodes.
	 * @param array<string, mixed>             $page_tokens Tokens of the whole page.
	 * @param array<string, mixed>             $fold_tokens Tokens above the fold.
	 * @param string[]                         $keep        Safelist prefixes.
	 * @param string                           $half        Either `fold` or `rest`.
	 * @param bool                             $inline_all  Whether every used rule belongs in the inline half.
	 * @return string
	 */
	private static function render_rules( $nodes, $page_tokens, $fold_tokens, $keep, $half, $inline_all = false ) {
		$out = '';

		foreach ( $nodes as $node ) {
			if ( 'statement' === $node['type'] ) {
				// @charset and @import belong at the top of the inline block,
				// which is the first stylesheet the page sees.
				if ( 'fold' === $half ) {
					$out .= $node['text'];
				}

				continue;
			}

			if ( 'group' === $node['type'] ) {
				$children = self::render_rules( $node['children'], $page_tokens, $fold_tokens, $keep, $half, $inline_all );

				if ( '' !== $children ) {
					$out .= $node['prelude'] . '{' . $children . '}';
				}

				continue;
			}

			$prelude = (string) $node['prelude'];

			// An at-rule block is printed once, in the inline half.
			if ( '@' === substr( $prelude, 0, 1 ) ) {
				if ( 'fold' === $half ) {
					$out .= $prelude . '{' . $node['body'] . '}';
				}

				continue;
			}

			// A rule belongs to exactly one half. The fold half is matched
			// against the above-the-fold markup only; the deferred half is
			// everything else the page uses, plus everything the safelist keeps.
			if ( $inline_all ) {
				if ( 'fold' === $half && self::selector_is_used( $prelude, $page_tokens, $keep, true ) ) {
					$out .= $prelude . '{' . $node['body'] . '}';
				}

				continue;
			}

			$in_fold = self::selector_is_used( $prelude, $fold_tokens, $keep, false );

			if ( 'fold' === $half ) {
				if ( $in_fold ) {
					$out .= $prelude . '{' . $node['body'] . '}';
				}

				continue;
			}

			if ( $in_fold ) {
				continue;
			}

			// The safelist is honored for the deferred half only. A rule kept
			// because a script may add its class later is not above the fold, so
			// it belongs in the deferred sheet - keeping it inline would grow the
			// critical block with markup the first paint never uses.
			if ( self::selector_is_used( $prelude, $page_tokens, $keep, true ) ) {
				$out .= $prelude . '{' . $node['body'] . '}';
			}
		}

		return $out;
	}

	/**
	 * Whether any comma-separated selector part could match the page.
	 *
	 * The test is deliberately generous. Only a part that names a class, an id
	 * or an element the page does not contain at all is rejected; a part with
	 * no such anchor - `:root`, `*`, `[data-x]`, a pseudo-element - is kept,
	 * because there is nothing in it to disprove.
	 *
	 * @param string               $selector    Selector list.
	 * @param array<string, mixed> $tokens      Page tokens.
	 * @param string[]             $keep        Safelist prefixes.
	 * @param bool                 $honor_keep Whether the safelist counts as a match.
	 * @return bool
	 */
	private static function selector_is_used( $selector, $tokens, $keep, $honor_keep = true ) {
		foreach ( self::split_selectors( $selector ) as $part ) {
			if ( self::selector_part_is_used( $part, $tokens, $keep, $honor_keep ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether one selector part could match the page.
	 *
	 * @param string               $part       Selector without commas.
	 * @param array<string, mixed> $tokens     Page tokens.
	 * @param string[]             $keep       Safelist prefixes.
	 * @param bool                 $honor_keep Whether the safelist counts as a match.
	 * @return bool
	 */
	private static function selector_part_is_used( $part, $tokens, $keep, $honor_keep = true ) {
		// Only real class markers: a dotted token that follows a digit is a
		// decimal (`.5em`), not a class.
		$classes = array();

		if ( preg_match_all( '/(?<![\w.-])\.(-?[_a-zA-Z][\w-]*)/', $part, $class_matches ) ) {
			$classes = $class_matches[1];
		}

		$ids = array();

		if ( preg_match_all( '/(?<![\w-])#([_a-zA-Z][\w-]*)/', $part, $id_matches ) ) {
			$ids = $id_matches[1];
		}

		foreach ( $classes as $class ) {
			if ( isset( $tokens['class'][ $class ] ) ) {
				return true;
			}
		}

		foreach ( $ids as $id ) {
			if ( isset( $tokens['id'][ $id ] ) ) {
				return true;
			}
		}

		if ( $honor_keep ) {
			foreach ( $keep as $prefix ) {
				foreach ( $classes as $class ) {
					if ( 0 === strpos( $class, $prefix ) ) {
						return true;
					}
				}

				foreach ( $ids as $id ) {
					if ( 0 === strpos( $id, $prefix ) ) {
						return true;
					}
				}
			}
		}

		if ( preg_match_all( '/(?<![\w.#:-])([a-zA-Z][\w-]*)/', $part, $tag_matches ) ) {
			foreach ( $tag_matches[1] as $tag ) {
				// A pseudo-class name (`hover`) is not an element. It only
				// counts when the page really contains that tag.
				if ( isset( $tokens['tag'][ strtolower( $tag ) ] ) ) {
					return true;
				}
			}
		}

		// Nothing to disprove: a universal selector, an attribute selector, a
		// pseudo-element or a bare `:root`.
		return empty( $classes ) && empty( $ids );
	}

	/**
	 * Split a selector list on the commas that separate top-level selectors.
	 *
	 * A comma inside `:is(a, b)` belongs to one selector, so the split walks
	 * the string and counts parentheses.
	 *
	 * @param string $selector Selector list.
	 * @return string[]
	 */
	private static function split_selectors( $selector ) {
		$parts = array();
		$depth = 0;
		$part  = '';
		$length = strlen( $selector );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $selector[ $i ];

			if ( '"' === $char || "'" === $char ) {
				$string = self::read_string( $selector, $i );
				$part  .= $string;
				$i     += strlen( $string ) - 1;
				continue;
			}

			if ( '(' === $char || '[' === $char ) {
				++$depth;
			} elseif ( ')' === $char || ']' === $char ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ',' === $char && 0 === $depth ) {
				$parts[] = trim( $part );
				$part    = '';
				continue;
			}

			$part .= $char;
		}

		$parts[] = trim( $part );

		return array_values( array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Build the token sets a selector is compared against.
	 *
	 * @param string $html Markup.
	 * @return array{class: array<string, bool>, id: array<string, bool>, tag: array<string, bool>}
	 */
	private static function tokens( $html ) {
		$tokens = array(
			'class' => array(),
			'id'    => array(),
			'tag'   => array(),
		);

		if ( '' === $html ) {
			return $tokens;
		}

		if ( preg_match_all( '/\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$value = isset( $match[1] ) && '' !== $match[1] ? $match[1] : ( isset( $match[2] ) ? $match[2] : '' );

				foreach ( preg_split( '/\s+/', trim( $value ) ) as $class ) {
					if ( '' !== $class ) {
						$tokens['class'][ $class ] = true;
					}
				}
			}
		}

		if ( preg_match_all( '/\bid\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$value = isset( $match[1] ) && '' !== $match[1] ? $match[1] : ( isset( $match[2] ) ? $match[2] : '' );

				if ( '' !== $value ) {
					$tokens['id'][ $value ] = true;
				}
			}
		}

		if ( preg_match_all( '/<\s*([a-zA-Z][a-zA-Z0-9:_-]*)/', $html, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				$tokens['tag'][ strtolower( $tag ) ] = true;
			}
		}

		return $tokens;
	}

	/**
	 * The markup above the fold.
	 *
	 * The head is always included - it decides how the fold paints - and the
	 * body is cut at a byte budget, rounded forward to the end of the tag the
	 * cut fell inside so a half-read `class` attribute cannot hide a token.
	 *
	 * @param string $html       Markup.
	 * @param int    $fold_bytes Body bytes to keep.
	 * @return string
	 */
	private static function fold_markup( $html, $fold_bytes ) {
		$body_at = stripos( $html, '<body' );

		if ( false === $body_at ) {
			return substr( $html, 0, max( 0, $fold_bytes ) );
		}

		$head = substr( $html, 0, $body_at );
		$body = substr( $html, $body_at );

		if ( $fold_bytes > 0 && strlen( $body ) > $fold_bytes ) {
			$body = substr( $body, 0, $fold_bytes );
			$cut  = strrpos( $body, '>' );

			if ( false !== $cut ) {
				$body = substr( $body, 0, $cut + 1 );
			}
		}

		return $head . $body;
	}

	/**
	 * Normalize safelist entries to bare prefixes.
	 *
	 * @param string[] $keep Selector prefixes, with or without a `.` or `#`.
	 * @return string[]
	 */
	private static function normalize_keep( $keep ) {
		$normalized = array();

		foreach ( $keep as $prefix ) {
			$prefix = trim( (string) $prefix );
			$prefix = ltrim( $prefix, '.#' );
			$prefix = rtrim( $prefix, '*' );

			if ( '' !== $prefix ) {
				$normalized[] = $prefix;
			}
		}

		return $normalized;
	}
}
