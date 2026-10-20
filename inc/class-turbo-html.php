<?php
/**
 * The page transformer.
 *
 * Everything that can only be decided once the page exists happens here: which
 * of the stylesheet's rules the markup actually uses, which image is the
 * largest contentful paint, and how many bytes the markup wastes on comments
 * and template indentation.
 *
 * The buffer is started on template_redirect, after WordPress has decided what
 * the request is and before a template prints anything, and the callback below
 * receives the finished page. That is the only point where the reducer can see
 * the markup it is reducing against; a head-only hook would have to guess.
 *
 * @package Majestic Turbo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rewrite the finished page.
 */
class Majestic_Turbo_HTML {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		/*
		 * Priority 1 rather than 0: the page cache opens its own buffer at 0,
		 * so the transformer's buffer sits inside it and the cache stores the
		 * finished page - minified, reduced and hinted - instead of the raw
		 * template output.
		 */
		add_action( 'template_redirect', array( __CLASS__, 'start' ), 1 );
	}

	/**
	 * Open the output buffer on a request that produces an HTML page.
	 *
	 * @return void
	 */
	public static function start() {
		if ( ! self::applies() ) {
			return;
		}

		ob_start( array( __CLASS__, 'transform' ) );
	}

	/**
	 * Whether this request is an HTML page a visitor reads.
	 *
	 * Feeds, embeds, previews, the REST API, the admin, AJAX and robots.txt all
	 * leave through this hook, and none of them may be rewritten: an XML feed
	 * minified as HTML is a broken feed, and a preview must show what the
	 * editor really has.
	 *
	 * @return bool
	 */
	private static function applies() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		foreach ( array( 'is_feed', 'is_robots', 'is_trackback', 'is_embed', 'is_preview', 'is_customize_preview' ) as $condition ) {
			if ( function_exists( $condition ) && $condition() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Rewrite one finished page.
	 *
	 * @param string $html Page markup.
	 * @return string
	 */
	public static function transform( $html ) {
		$html = (string) $html;

		// A redirect, an empty buffer or something that is not a document is
		// left for whatever produced it.
		if ( strlen( $html ) < 256 || false === stripos( $html, '<html' ) ) {
			return $html;
		}

		$html = self::stylesheet( $html );
		$html = self::inline_assets( $html );

		if ( majestic_turbo_option_is_on( 'image_hints' ) ) {
			$html = self::images( $html );
		}

		if ( majestic_turbo_option_is_on( 'minify_html' ) ) {
			$html = self::minify( $html );
		}

		return $html;
	}

	/* ---------------------------------------------------------------------
	 * Stylesheet
	 * ------------------------------------------------------------------ */

	/**
	 * Replace the combined stylesheet link with what the page actually needs.
	 *
	 * Three answers, in the order the settings ask for them:
	 *
	 *   1. reduce unused CSS on: the rules the page uses are inlined and the
	 *      link is gone. One document, nothing render-blocking, no rule on the
	 *      wire that no element on the page can match.
	 *   2. reduce off, inline critical CSS on: the above-the-fold rules are
	 *      inlined and the full sheet loads without blocking the first paint.
	 *   3. both off: the link is left exactly as WordPress printed it.
	 *
	 * The inline path has a ceiling. Past it - a page that genuinely uses most
	 * of a large stylesheet - inlining would make the document larger than the
	 * request it replaces, so the split falls back to critical inline plus a
	 * deferred sheet.
	 *
	 * @param string $html Page markup.
	 * @return string
	 */
	private static function stylesheet( $html ) {
		$bundle = Majestic_Turbo_Assets::bundle();

		if ( ! $bundle || ! preg_match( '/<link[^>]*data-turbo="css"[^>]*>/i', $html, $matches ) ) {
			return $html;
		}

		$tag = $matches[0];

		if ( false !== strpos( $tag, 'media=' ) && ! preg_match( '/media=[\'"]all[\'"]/i', $tag ) ) {
			// A conditional media attribute on the combined sheet means the
			// site meant it to apply only somewhere; leave that decision alone.
			return $html;
		}

		$css = Majestic_Turbo_Cache::get( 'css/' . basename( $bundle['path'] ) );

		if ( false === $css || '' === trim( $css ) ) {
			return $html;
		}

		$reduce   = majestic_turbo_option_is_on( 'reduce_unused_css' );
		$critical = majestic_turbo_option_is_on( 'critical_css' );

		if ( ! $reduce && ! $critical ) {
			return $html;
		}

		$keep  = majestic_turbo_option_lines( 'css_keep' );
		$limit = (int) apply_filters( 'majestic_turbo_inline_css_limit', 98304 );

		if ( $reduce ) {
			/*
			 * A fold length past the end of the document puts every used rule in
			 * one half, in source order. Concatenating a fold half and a
			 * deferred half would instead reorder the sheet - all the rules near
			 * the top first - which can flip a same-specificity cascade.
			 */
			$used = Majestic_Turbo_CSS::split( $css, $html, PHP_INT_MAX, $keep, true );

			if ( $used['safe'] && '' !== trim( $used['critical'] ) && strlen( $used['critical'] ) <= $limit ) {
				return str_replace(
					$tag,
					'<style id="majestic-turbo-css">' . $used['critical'] . '</style>',
					$html
				);
			}
		}

		if ( ! $critical ) {
			// Reduction alone could not inline the sheet: a whole stylesheet is
			// too large, and a deferred sheet with no inline rules would flash
			// unstyled content, so the link stays render-blocking.
			return $html;
		}

		$fold = Majestic_Turbo_CSS::split(
			$css,
			$html,
			(int) apply_filters( 'majestic_turbo_fold_bytes', 45000 ),
			$keep
		);

		if ( ! $fold['safe'] || '' === trim( $fold['critical'] ) ) {
			return $html;
		}

		$fold = $fold['critical'];

		// The plain link: the tag without its `media="all"`, which is the
		// default anyway. This is what the noscript fallback prints, so a
		// visitor without JavaScript gets the complete stylesheet.
		$plain = str_replace(
			array( ' media=\'all\'', ' media="all"' ),
			'',
			$tag
		);

		$async = str_replace(
			'<link ',
			'<link media="print" onload="this.media=\'all\'" ',
			$plain
		);

		$noscript = '<noscript>' . $plain . '</noscript>';

		return str_replace(
			$tag,
			'<style id="majestic-turbo-critical">' . $fold . '</style>' . $async . $noscript,
			$html
		);
	}

	/* ---------------------------------------------------------------------
	 * Inline styles and scripts
	 * ------------------------------------------------------------------ */

	/**
	 * Minify inline styles and scripts, and drop core's block styles a classic
	 * page cannot use.
	 *
	 * @param string $html Page markup.
	 * @return string
	 */
	private static function inline_assets( $html ) {
		/*
		 * Inline blocks follow the switch they belong to, not the HTML switch:
		 * a site that leaves HTML alone but turns on Minify JavaScript still
		 * expects its inline scripts minified, and one that turns Minify
		 * JavaScript off does not expect the HTML switch to rewrite them.
		 */
		$minify_css = majestic_turbo_option_is_on( 'minify_css' );
		$minify_js  = majestic_turbo_option_is_on( 'minify_js' );
		$tokens     = self::class_tokens( $html );

		/*
		 * Core prints its block stylesheet on every classic-theme page, including
		 * pages with no block on them. Since WordPress 6.9 the global styles are
		 * printed in the footer by a hoisting pass and arrive here as an inline
		 * block, so a dequeue alone no longer removes them - the finished document
		 * is the first place where the whole set can be seen and dropped.
		 */
		$strip_core = majestic_turbo_option_is_on( 'strip_block_css' )
			&& class_exists( 'Majestic_Turbo_Assets' )
			&& ! Majestic_Turbo_Assets::page_has_blocks();

		$html = preg_replace_callback(
			'#<style([^>]*)>(.*?)</style>#is',
			function ( $matches ) use ( $minify_css, $tokens, $strip_core ) {
				$attributes = $matches[1];
				$css        = $matches[2];

				// A block this plugin printed is already minified - the reducer's
				// output and the picture rule a few lines down. Minifying it again
				// would be a second full walk over the largest inline block on the
				// page, on every render, to change nothing.
				if ( preg_match( '/\bid=[\'"]majestic-turbo-[^\'"]*[\'"]/i', $attributes ) ) {
					return $matches[0];
				}

				if ( $strip_core && preg_match( '/\bid=[\'"](?:global-styles|wp-block-library|wp-block-library-theme|classic-theme-styles|wp-global-styles-placeholder)-inline-css[\'"]/i', $attributes ) ) {
					return '';
				}

				if ( preg_match( '/\bid=[\'"]wp-block-[^\'"]+[\'"]/i', $attributes ) && ! self::css_is_used( $css, $tokens ) ) {
					return '';
				}

				return '<style' . $attributes . '>' . ( $minify_css ? Majestic_Turbo_CSS::minify( $css ) : $css ) . '</style>';
			},
			$html
		);

		$html = preg_replace_callback(
			'#<script([^>]*)>(.*?)</script>#is',
			function ( $matches ) use ( $minify_js ) {
				$attributes = $matches[1];
				$javascript = $matches[2];

				if ( '' === trim( $javascript ) ) {
					return $matches[0];
				}

				// Structured data and templates are data, not code: a minifier
				// that is allowed to remove whitespace inside JSON-LD's strings
				// would be changing what a crawler reads.
				if ( preg_match( '/\btype=[\'"](?!text\/javascript|application\/javascript|module)[^\'"]*[\'"]/i', $attributes ) ) {
					return $matches[0];
				}

				return '<script' . $attributes . '>' . ( $minify_js ? Majestic_Turbo_JS::inline( $javascript ) : $javascript ) . '</script>';
			},
			$html
		);

		return (string) $html;
	}

	/**
	 * Whether an inline stylesheet names anything the page has.
	 *
	 * Core prints the stylesheet for every block type it knows, used or not.
	 * This asks the narrow question a core block stylesheet can answer: does
	 * the markup contain one of the classes the sheet is written for? A sheet
	 * that mentions no class at all is kept.
	 *
	 * @param string                $css    Stylesheet.
	 * @param array<string, bool>   $tokens Class tokens of the page.
	 * @return bool
	 */
	private static function css_is_used( $css, $tokens ) {
		if ( ! preg_match_all( '/\.(-?[_a-zA-Z][\w-]*)/', $css, $matches ) ) {
			return true;
		}

		foreach ( array_unique( $matches[1] ) as $class ) {
			if ( isset( $tokens[ $class ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The class tokens of a page.
	 *
	 * @param string $html Markup.
	 * @return array<string, bool>
	 */
	private static function class_tokens( $html ) {
		$tokens = array();

		if ( preg_match_all( '/\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$value = isset( $match[1] ) && '' !== $match[1] ? $match[1] : ( isset( $match[2] ) ? $match[2] : '' );

				foreach ( preg_split( '/\s+/', trim( $value ) ) as $class ) {
					if ( '' !== $class ) {
						$tokens[ $class ] = true;
					}
				}
			}
		}

		return $tokens;
	}

	/* ---------------------------------------------------------------------
	 * Images
	 * ------------------------------------------------------------------ */

	/**
	 * Point the browser at the first screenful and keep the rest back.
	 *
	 * The largest contentful paint on a tube page is a picture, and on a grid
	 * of equal cards it is whichever card the browser paints last. That is not
	 * a fixed card: it moves with the content, the column count and the time
	 * each thumbnail takes to arrive. A single promoted image is therefore a
	 * guess that loses the tie as often as it wins, which is exactly what the
	 * PageSpeed insight reports - the element it scored was one of the cards
	 * that had been left lazy and without a priority hint.
	 *
	 * So the whole first screenful is promoted: every image in the window is
	 * eager and carries `fetchpriority="high"`, whichever one wins the tie is
	 * then already the paint the audit is asking about. The window is 12 cards
	 * by default, which covers six rows of a two-column phone grid, four rows
	 * at three columns and the two rows a five-column desktop grid shows.
	 * `majestic_turbo_eager_images` changes the count.
	 *
	 * Widths and heights are left alone: guessing them would trade a layout
	 * shift for a wrong one.
	 *
 * Carrying the first screenful's pictures in the document is the other half.
 * Inlining takes those requests out of the queue the largest paint waits on.
 * Past three the document grows by more than the requests it saves, so three
 * is the default and `majestic_turbo_inline_images` changes it.
	 *
	 * @param string $html Page markup.
	 * @return string
	 */
	private static function images( $html ) {
		$carried = 0;
		$wrapped = 0;
		$inlined = false;

		/*
		 * A listing page shows a grid, so the largest paint is one card among
		 * many and the whole first screenful has to be promoted. A single page
		 * shows one hero - the player poster, or the featured image of a page -
		 * and everything after it is a recommendation below the fold. Making a
		 * row of those urgent would put them in front of the poster the visitor
		 * is waiting for.
		 */
		$default = function_exists( 'is_singular' ) && is_singular() ? 1 : 12;
		$eager   = max( 1, (int) apply_filters( 'majestic_turbo_eager_images', $default ) );

		/**
		 * How many of the first images carry their bytes in the page.
		 *
		 * A grid of equal cards makes the largest contentful paint whichever
		 * card is painted last, so the number moves with the content. Taking
		 * the request away from the first screenful is the one lever left on
		 * the page: those pictures paint with the markup instead of waiting
		 * behind a queue of thirty requests on a slow connection.
		 *
		 * @param int $count Images to inline.
		 */
		$inline = max( 0, (int) apply_filters( 'majestic_turbo_inline_images', 3 ) );

		$html = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $matches ) use ( &$carried, &$wrapped, &$inlined, $eager, $inline ) {
				$tag = $matches[0];

				// A placeholder is not the paint: it takes neither a promotion
				// slot nor a place in the inlined screenful.
				$placeholder = false !== strpos( $tag, 'data:image' );

				/*
				 * An avatar, an icon or a tracking pixel is not the paint, so it
				 * does not take one of the slots the first screenful has and is
				 * not made urgent for a card that is.
				 */
				$tiny = $placeholder || self::is_tiny( $tag );

				if ( ! $placeholder ) {
					if ( ! $tiny ) {
						++$carried;
					}

					if ( ! $tiny && $carried <= $eager ) {
						$tag = self::set_attribute( $tag, 'loading', 'eager' );

						/*
						 * Every card in the window, not one: the browser scores the
						 * card it paints last, and a tie can land on any of them. A
						 * priority hint on the first card alone is the guess the audit
						 * reports as missing.
						 */
						$tag = self::set_attribute( $tag, 'fetchpriority', 'high' );
					} elseif ( ! $tiny && ! preg_match( '/(?<=\s)loading=/i', $tag ) && ! preg_match( '/(?<=\s)fetchpriority=[\'"]high/i', $tag ) ) {
						$tag = self::set_attribute( $tag, 'loading', 'lazy' );
					}
				}

				if ( ! preg_match( '/(?<=\s)decoding=/i', $tag ) ) {
					$tag = self::set_attribute( $tag, 'decoding', 'async' );
				}

				// Only a card takes a place in the inlined screenful. An icon
				// that happens to fall between two cards must not spend one: it
				// would carry kilobytes nobody measures and leave a card that is
				// measured to be fetched over the network.
				$picture = self::picture( $tag, ! $tiny && $carried > 0 && $carried <= $inline );

				/*
				 * The document already preloads the first content image. If that one
				 * is also the one carried in the page there is no request left to
				 * hint, and the head must not name its JPEG instead.
				 */
				if ( 1 === $carried && false !== strpos( $picture, 'srcset="data:image/' ) ) {
					$inlined = true;
				}

				if ( '' !== $picture ) {
					++$wrapped;

					return $picture;
				}

				return $tag;
			},
			$html
		);

		$head = '';

		// The wrapper has to be as big as the box it sits in, or a card whose
		// image is `height: 100%` of an absolutely positioned container would
		// measure itself against an auto-height inline box instead. One rule,
		// only on a page that has a wrapper.
		if ( $wrapped > 0 ) {
			$head .= '<style id="majestic-turbo-picture">picture.majestic-turbo-picture{display:block;width:100%;height:100%}</style>';
		}

		// An inlined picture needs no preload: its bytes are already in the
		// document. Printing one would name the JPEG fallback and download a
		// file the browser never paints.
		if ( ! $inlined ) {
			$head .= self::preload_link( (string) $html );
		}

		if ( '' !== $head ) {
			/*
			 * A callback, not a replacement string: the preload link is built
			 * from a URL, and preg_replace() reads `$1` or `\\1` in a replacement
			 * as a backreference. A query string that happens to contain one
			 * would be rewritten instead of printed.
			 */
			$html = preg_replace_callback(
				'#</head>#i',
				static function () use ( $head ) {
					return $head . '</head>';
				},
				$html,
				1
			);
		}

		return (string) $html;
	}

	/**
	 * Offer a converted candidate list in front of an image, when one exists.
	 *
	 * `<picture>` is the only way to hand a browser a second format without
	 * JavaScript: the source is tried first and the untouched `<img>` stays as
	 * the fallback, so a browser that cannot read AVIF or WebP - and a page
	 * whose converted files were deleted - still show the original.
	 *
	 * Only candidates whose converted file really exists are listed. A list
	 * that keeps a JPEG entry would let a browser download the file this is
	 * trying to replace, and a source with no candidates is worse than none at
	 * all.
	 *
	 * @param string $tag    Image markup.
	 * @param bool   $inline Whether the first source should carry the file itself.
	 * @return string Picture markup, or an empty string when there is nothing to offer.
	 */
	private static function picture( $tag, $inline = false ) {
		if ( false !== strpos( $tag, 'data:image' ) ) {
			return '';
		}

		$avif = self::avif_source( $tag );
		$webp = self::webp_source( $tag );

		if ( null === $avif && null === $webp ) {
			return '';
		}

		/*
		 * The order is the whole feature: the first source a browser claims is
		 * the one it downloads, so AVIF goes in front of WebP and a browser
		 * that reads neither falls through to the untouched <img>.
		 *
		 * An image with no srcset of its own - an upload whose intermediate
		 * sizes the library never generated - is still offered: the source
		 * names that one file. A source with no srcset at all is ignored by the
		 * browser, which then quietly downloads the JPEG and leaves the
		 * preloaded file unused, so a list is never empty.
		 */
		$sources   = '';
		$attempted = false;

		foreach ( array( 'avif' => $avif, 'webp' => $webp ) as $format => $list ) {
			if ( null === $list ) {
				continue;
			}

			$srcset = '' !== $list['srcset'] ? $list['srcset'] : $list['href'];
			$sizes  = $list['sizes'];

			/*
			 * The first source is the one a browser takes, so that is the one
			 * that can carry the picture's bytes. The other sources stay as
			 * they are: a browser that cannot read this format falls back to
			 * the next source, and one that reads none of them still has the
			 * untouched img, so inlining a picture never removes an image the
			 * page used to show. A data URI has no width to choose against,
			 * so the sizes attribute goes with the candidate list.
			 */
			if ( $inline && ! $attempted ) {
				$attempted = true;
				$data      = self::inline_uri( $list['href'], $format );

				if ( '' !== $data ) {
					$srcset = $data;
					$sizes  = '';
				}
			}

			$sources .= '<source type="image/' . $format . '" srcset="' . esc_attr( $srcset ) . '"';

			if ( '' !== $list['srcset'] && '' !== $sizes ) {
				$sources .= ' sizes="' . esc_attr( $sizes ) . '"';
			}

			$sources .= ' />';
		}

		return '<picture class="majestic-turbo-picture">' . $sources . $tag . '</picture>';
	}

	/**
	 * Whether an image is too small to be the paint.
	 *
	 * An admin bar's avatar and a theme's icons are images in the document too,
	 * and on a page a signed-in visitor opens they come first: carrying them
	 * would spend the first screenful's slots on pictures nobody measures, and
	 * push the cards that are measured out of the list. The declared size is the
	 * only signal that needs no layout, and an image that declares none stays
	 * eligible rather than being skipped on a guess.
	 *
	 * @param string $tag Image markup.
	 * @return bool
	 */
	private static function is_tiny( $tag ) {
		$width  = (int) self::attribute( $tag, 'width' );
		$height = (int) self::attribute( $tag, 'height' );

		return ( $width > 0 && $width < 100 ) || ( $height > 0 && $height < 50 );
	}

	/**
	 * A converted file as a data URI, when it is small enough to carry.
	 *
	 * The first screenful's pictures are the ones a slow connection waits on,
	 * and the converted copies are already small - a 288px card thumbnail is a
	 * few kilobytes. Carrying one costs the same bytes the browser would have
	 * downloaded, plus a third for the encoding, and saves a request from the
	 * queue the largest paint is sitting behind.
	 *
	 * Past the cap the page would grow more than the request it saves, so the
	 * file is left where it is and loaded as usual.
	 *
	 * @param string $url    Converted file URL.
	 * @param string $format avif or webp.
	 * @return string Data URI, or an empty string when the file cannot be read.
	 */
	private static function inline_uri( $url, $format ) {
		if ( '' === $url || false !== strpos( $url, 'data:' ) ) {
			return '';
		}

		if ( ! class_exists( 'Majestic_Turbo_Images' ) || ! method_exists( 'Majestic_Turbo_Images', 'path' ) ) {
			return '';
		}

		$path = Majestic_Turbo_Images::path( $url );

		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}

		/**
		 * Filter the largest file, in bytes, that may be carried in the page.
		 *
		 * @param int $limit Byte cap.
		 */
		$limit = max( 0, (int) apply_filters( 'majestic_turbo_inline_bytes', 32768 ) );
		$size  = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable file falls through below.

		if ( $size <= 0 || ( $limit > 0 && $size > $limit ) ) {
			return '';
		}

		$bytes = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.

		if ( false === $bytes || '' === $bytes ) {
			return '';
		}

		return 'data:image/' . $format . ';base64,' . base64_encode( $bytes );
	}

	/**
	 * The AVIF side of an image, as a source list.
	 *
	 * @param string $tag Image markup.
	 * @return array{srcset: string, href: string, sizes: string}|null Null when no member of the image has an AVIF file that wins.
	 */
	private static function avif_source( $tag ) {
		return self::format_source( $tag, 'avif' );
	}

	/**
	 * The WebP side of an image, as a source list.
	 *
	 * @param string $tag Image markup.
	 * @return array{srcset: string, href: string, sizes: string}|null Null when no member of the image has a WebP file.
	 */
	private static function webp_source( $tag ) {
		return self::format_source( $tag, 'webp' );
	}

	/**
	 * One format's side of an image, as a source list.
	 *
	 * @param string $tag    Image markup.
	 * @param string $format webp or avif.
	 * @return array{srcset: string, href: string, sizes: string}|null Null when no member of the image has that file.
	 */
	private static function format_source( $tag, $format ) {
		if ( ! class_exists( 'Majestic_Turbo_Images' ) ) {
			return null;
		}

		if ( 'avif' === $format ) {
			if ( ! Majestic_Turbo_Images::avif_enabled() ) {
				return null;
			}
		} elseif ( ! Majestic_Turbo_Images::enabled() ) {
			return null;
		}

		$src    = self::attribute( $tag, 'src' );
		$srcset = self::attribute( $tag, 'srcset' );
		$sizes  = self::attribute( $tag, 'sizes' );
		$list   = array();
		$first  = '';

		/*
		 * Either every candidate has a WebP file or the image is offered as
		 * JPEG. A source list with a hole in it is worse than no source: the
		 * browser cannot pick the missing width, so it takes the next size up
		 * - and a card whose 320 wide WebP was missing downloaded the next
		 * size up instead of its own.
		 */
		if ( '' !== $srcset ) {
			foreach ( explode( ',', $srcset ) as $candidate ) {
				$candidate = trim( $candidate );

				if ( '' === $candidate ) {
					continue;
				}

				$parts = preg_split( '/\s+/', $candidate );
				$file  = 'avif' === $format ? Majestic_Turbo_Images::avif_sibling( $parts[0] ) : Majestic_Turbo_Images::sibling( $parts[0] );

				if ( '' === $file ) {
					return null;
				}

				$parts[0] = $file;
				$list[]   = implode( ' ', $parts );

				if ( '' === $first ) {
					$first = $file;
				}
			}

			if ( ! $list ) {
				return null;
			}

			$fallback = '' !== $src ? ( 'avif' === $format ? Majestic_Turbo_Images::avif_sibling( $src ) : Majestic_Turbo_Images::sibling( $src ) ) : '';

			// href mirrors the img's src, so a browser that ignores
			// imagesrcset fetches the same picture the img would have loaded
			// rather than a second one.
			return array(
				'srcset' => implode( ', ', $list ),
				'href'   => '' !== $fallback ? $fallback : $first,
				'sizes'  => $sizes,
			);
		}

		$fallback = '' !== $src ? ( 'avif' === $format ? Majestic_Turbo_Images::avif_sibling( $src ) : Majestic_Turbo_Images::sibling( $src ) ) : '';

		if ( '' === $fallback ) {
			return null;
		}

		return array(
			'srcset' => '',
			'href'   => $fallback,
			'sizes'  => $sizes,
		);
	}

	/**
	 * The preload link for the first content image, when it is worth one.
	 *
	 * A preloaded picture that is never painted is a slower page: the request
	 * competes with the stylesheet and the poster that really is the paint. So
	 * the link is only printed while the page does not already preload an
	 * image of its own - and never for a placeholder.
	 *
	 * A responsive image is preloaded through imagesrcset and imagesizes, the
	 * same candidate list its img carries, so the browser picks the candidate
	 * the preload already fetched instead of downloading a second file. The
	 * href is still the img's src: a browser that ignores imagesrcset fetches
	 * that one, which is a smaller image than the layout needs but never a
	 * wrong one.
	 *
	 * @param string $html Page markup.
	 * @return string
	 */
	private static function preload_link( $html ) {
		if ( preg_match( '/rel=[\'"]preload[\'"][^>]*as=[\'"]image[\'"]/i', $html ) ) {
			return '';
		}

		$body = strpos( $html, '<body' );

		if ( false === $body ) {
			return '';
		}

		if ( ! preg_match_all( '#<img\b[^>]*>#i', substr( $html, $body ), $matches ) ) {
			return '';
		}

		/*
		 * The first *content* image, not the first image: a logo, an avatar and
		 * a tracking pixel are all earlier in the document than the card the
		 * audit scores, and preloading one of them would spend the hint, and a
		 * request, on a picture no measurement is waiting for.
		 */
		$tag = '';

		foreach ( $matches[0] as $candidate ) {
			if ( false === strpos( $candidate, 'data:image' ) && ! self::is_tiny( $candidate ) ) {
				$tag = $candidate;

				break;
			}
		}

		if ( '' === $tag ) {
			return '';
		}

		$href = self::attribute( $tag, 'src' );

		if ( '' === $href ) {
			return '';
		}

		$srcset = self::attribute( $tag, 'srcset' );
		$sizes  = self::attribute( $tag, 'sizes' );

		/*
		 * When the image is offered in a converted format, the preload has to
		 * name the format the browser will really pick and that candidate
		 * list: a preload of the JPEG would be a second download the page never
		 * uses, because the picture element takes the first source it can read.
		 * AVIF is checked first because it is the first source in the markup.
		 */
		$source = self::avif_source( $tag );
		$type   = 'avif';

		if ( null === $source ) {
			$source = self::webp_source( $tag );
			$type   = 'webp';
		}

		if ( null !== $source ) {
			$link = '<link rel="preload" as="image" type="image/' . $type . '" href="' . esc_url( $source['href'] ) . '"';

			if ( '' !== $source['srcset'] ) {
				$link .= ' imagesrcset="' . esc_attr( $source['srcset'] ) . '"';

				if ( '' !== $source['sizes'] ) {
					$link .= ' imagesizes="' . esc_attr( $source['sizes'] ) . '"';
				}
			}

			return $link . ' fetchpriority="high" />';
		}

		$link = '<link rel="preload" as="image" href="' . esc_url( $href ) . '"';

		if ( '' !== $srcset ) {
			$link .= ' imagesrcset="' . esc_attr( $srcset ) . '"';

			// imagesizes without imagesrcset means nothing, and with a
			// srcset that has no sizes the browser applies 100vw - which is
			// what the srcset's own w descriptors assume as well.
			if ( '' !== $sizes ) {
				$link .= ' imagesizes="' . esc_attr( $sizes ) . '"';
			}
		}

		return $link . ' fetchpriority="high" />';
	}

	/**
	 * Set an attribute, replacing a value that is already there.
	 *
	 * @param string $tag   Element markup.
	 * @param string $name  Attribute name.
	 * @param string $value Attribute value.
	 * @return string
	 */
	private static function set_attribute( $tag, $name, $value ) {
		// The name has to start an attribute, which is to say it follows
		// whitespace: `\b` also matches after the hyphen of `data-loading`,
		// and rewriting that would set the wrong attribute and leave the real
		// one alone.
		$pattern = '/(?<=\s)' . preg_quote( $name, '/' ) . '=([\'"])(.*?)\1/i';

		if ( preg_match( $pattern, $tag ) ) {
			return preg_replace( $pattern, $name . '="' . $value . '"', $tag, 1 );
		}

		return preg_replace( '/\s*\/?>$/', ' ' . $name . '="' . $value . '" />', $tag, 1 );
	}

	/**
	 * Read one attribute out of an element's markup.
	 *
	 * @param string $tag  Element markup.
	 * @param string $name Attribute name.
	 * @return string
	 */
	private static function attribute( $tag, $name ) {
		if ( preg_match( '/(?<=\s)' . preg_quote( $name, '/' ) . '=([\'"])(.*?)\1/i', $tag, $match ) ) {
			return $match[2];
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * HTML
	 * ------------------------------------------------------------------ */

	/**
	 * Elements whose whitespace the browser renders.
	 *
	 * Whitespace beside one of these is a word space, so the line break that
	 * carried it is kept; between two block-level elements it renders nothing
	 * and goes. `display` is a stylesheet decision, so the list errs towards
	 * keeping the space: a block element listed here only costs a byte, and an
	 * inline element left out would join two words.
	 *
	 * @var string[]
	 */
	private static $inline_elements = array(
		'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'button', 'canvas', 'cite', 'code',
		'data', 'dfn', 'em', 'i', 'iframe', 'img', 'input', 'kbd', 'label', 'mark',
		'meter', 'object', 'output', 'picture', 'progress', 'q', 'rp', 'rt', 'ruby',
		's', 'samp', 'select', 'small', 'span', 'strong', 'sub', 'sup', 'svg',
		'textarea', 'time', 'u', 'var', 'video', 'wbr',
	);

	/**
	 * Remove the bytes a document spends on formatting.
	 *
	 * Two rules keep this safe. Text is never rewritten, only template
	 * indentation between two tags is dropped - and only when whitespace there
	 * renders nothing. Whitespace that spans a line break between two elements
	 * is a word space in HTML, so it collapses to the line break, which is
	 * still a space; the break is removed only when both neighbours are
	 * block-level, where it never painted anything. And the contents of
	 * `<pre>`, `<textarea>`, `<script>` and `<style>` are copied out untouched,
	 * because whitespace is the content there.
	 *
	 * @param string $html Page markup.
	 * @return string
	 */
	private static function minify( $html ) {
		$placeholders = array();

		// Protect the elements whose whitespace means something.
		$html = preg_replace_callback(
			'#<(pre|textarea|script|style)\b[^>]*>.*?</\1>#is',
			function ( $matches ) use ( &$placeholders ) {
				$key                  = "\x1A" . count( $placeholders ) . "\x1A";
				$placeholders[ $key ] = $matches[0];

				return $key;
			},
			$html
		);

		// Comments, except the two kinds that are instructions: conditional
		// comments for old browsers and block delimiters a block parser reads.
		$html = preg_replace( '/<!--(?!\[if|<!\[endif|wp:)[\s\S]*?-->/i', '', $html );

		// A whitespace run that spans a line break collapses to that break,
		// which renders as the single space the run meant. One newline, not
		// none: `<a>x</a>\n<a>y</a>` paints a space between the words.
		$html = preg_replace( '/>\s*\n\s*</', ">\n<", $html );

		// Now that every boundary is a single break, the ones between two
		// block-level elements can lose it. Boundaries that do not match - a
		// void element before an opening tag, an opening tag before another -
		// keep their newline, which is a byte, not a rendering change.
		$html = preg_replace_callback(
			'#</([a-z][a-z0-9:-]*)\s*>\n<([a-z][a-z0-9:-]*)#i',
			function ( $matches ) {
				$before = strtolower( $matches[1] );
				$after  = strtolower( $matches[2] );

				if ( in_array( $before, self::$inline_elements, true ) || in_array( $after, self::$inline_elements, true ) ) {
					return $matches[0];
				}

				return '</' . $matches[1] . '><' . $matches[2];
			},
			$html
		);

		// Template indentation: none of it paints, whatever it sits beside.
		$html = preg_replace( '/^[ \t]+/m', '', $html );
		$html = preg_replace( "/[ \t]+\n/", "\n", $html );

		// Restore the protected elements.
		if ( $placeholders ) {
			$html = strtr( $html, $placeholders );
		}

		return trim( $html ) . "\n";
	}
}
