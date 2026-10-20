=== Majestic Turbo ===
Contributors: majestictube
Tags: performance, pagespeed, cache, minify, critical css, webp, avif
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A performance plugin for any WordPress theme: minified and reduced CSS, minified and deferred JavaScript, a smaller document, hinted images, WebP and/or AVIF conversion, and cached pages.

== Description ==

Majestic Turbo is built for one job: leave a PageSpeed audit with as little to report as possible. It was built as the companion to the Majestic Tube theme, and it does not depend on it: any block or classic theme works, as does any page builder that prints standard markup.

It does that by rewriting the finished page on its way out, not by asking the theme to change:

* **CSS** - every local stylesheet is minified, combined into one content-addressed file, and reduced to the rules the rendered page actually uses. The used rules are printed inline, so no stylesheet blocks the first paint and nothing unused is downloaded.
* **JavaScript** - local scripts are minified and deferred. Nothing is renamed, concatenated or rewritten, so a script that works keeps working.
* **HTML** - comments and template indentation go; inline styles and scripts are minified; the block library's CSS is dropped when the page contains no blocks.
* **Preconnect** - a hostname listed under **Preconnect hosts** is announced with `preconnect` in the head, so the DNS and TLS handshake to a video CDN, an ad server or an analytics endpoint overlaps the first paint.
* **Images** - every card in the first screenful is eager and carries `fetchpriority="high"`, because a grid of equal cards makes the largest contentful paint a tie that can land on any of them; the first content image is also preloaded from the head with its own `imagesrcset`/`imagesizes` candidate list when it is responsive, the first three thumbnails carry their converted file in the document so the largest paint needs no request, and everything further down is lazily decoded. A single page promotes one image instead of a row, so its poster is not put behind a set of related cards.
* **Conversion log** - a conversion run records what it did, not only a total: Settings -> Majestic Turbo shows the last run, the reasons files were left alone and the last 40 file results, `wp majestic-turbo images --status` prints the same record, and `--verbose` prints every file with its size before and after. An upload records its own run as well.
* **WebP** - a WebP copy of every JPEG and PNG is written beside the original and offered through a `<picture>` source, so the browser downloads the smaller file and the JPEG or PNG stays as the fallback. A WebP is kept only when it is genuinely smaller; the originals, the media library and every filename are left untouched. New uploads are converted as they arrive, and Settings -> Majestic Turbo converts an existing library a batch at a time.
* **AVIF** - an optional second pass, off by default. When it is on, an AVIF source is printed in front of the WebP one, and a file is kept only when it is smaller - than the WebP beside it when there is one, otherwise than the original. A browser that reads AVIF gets the smaller file and one that does not falls back to WebP and then the original. It is off by default because encoding takes several times longer than WebP for a modest size win; a file is kept only when it beats the WebP beside it.
* **Either format alone** - WebP and AVIF stand independently. A host whose GD or Imagick can write only one of them generates that one and prints only the `<source>` it actually wrote; a host that can write both prints AVIF in front of WebP. Nothing has to be installed for the other format to work.
* **Pages** - anonymous visits are answered from a stored copy, before WordPress runs the query. Signed-in visitors, POSTs, previews, feeds, sitemaps, search results and password-protected posts are never cached, and neither is any response that is not an HTML document - a JSON endpoint or an XML feed keeps its own content type.
* **Before WordPress** - when **Serve before WordPress** is on and `WP_CACHE` is defined, the plugin writes WordPress's `advanced-cache.php` drop-in, so a stored page is read and printed before core, the plugins and the database load. That is the switch that moves time to first byte: only the drop-in and the stored file are paid for. A foreign drop-in is never overwritten, a deleted plugin directory degrades to an ordinary request instead of a fatal, and the settings screen says which of the two is in place.
* **Warming** - publishing a post re-primes the home page and the new permalink in the background, so the first visitor after a publish is not the one waiting for the query. `wp majestic-turbo warm` does the same from the command line.

Everything it generates lives in `wp-content/uploads/majestic-turbo/`, apart from the `advanced-cache.php` drop-in WordPress includes, and is safe to delete at any time. The theme, this plugin and WordPress can all be updated without touching a generated file.

== Installation ==

1. Upload the `majestic-turbo` folder to `wp-content/plugins/`, or install the zip from Plugins -> Add New -> Upload Plugin.
2. Activate it through the Plugins screen.
3. Open Settings -> Majestic Turbo and check that every switch it needs is on. The defaults are the recommended set for most sites; the CSS safelist is where a theme with JavaScript-built markup is taught its class prefixes.

== Frequently Asked Questions ==

= Does it work with any theme? =

Yes. Nothing in the pipeline names a theme, a template or a CSS class. The HTML, image and JavaScript passes only read the finished document; the CSS reducer matches selectors against the markup it was handed. Two switches deserve a look on a new theme: **Reduce unused CSS** keeps only the rules the rendered page uses, so a theme that builds panels in JavaScript needs its class prefixes in the safelist (the default covers common state classes and UI libraries), and **Defer JavaScript** changes execution order for theme scripts that expect to run before inline markup.

= What if the server can write only WebP, or only AVIF? =

That is fine. Both formats are checked separately against the image editor, and only the ones it can write are generated and offered. A host with AVIF support alone converts and serves AVIF; one with WebP alone serves WebP.

= Does it improve time to first byte? =

Yes, for a cached page. Without the drop-in, WordPress core, every active plugin and the database connection are loaded before the plugin can answer, so TTFB includes all of that. With `define( 'WP_CACHE', true );` in wp-config.php and **Serve before WordPress** on, the answer comes from `wp-content/advanced-cache.php` and the stored file, and none of that work happens. The saving is the whole WordPress boot: small on a fast local machine, large on shared hosting, where that boot is the largest part of the metric. The page body is byte-identical either way. The settings screen tells you when WP_CACHE is missing or another plugin owns the drop-in.

= What happens to a page a visitor is signed in to? =

It is never stored and never served from the cache. A logged-in request always renders normally.

= Why are my stylesheet requests gone from the network panel? =

They were combined and inlined. Settings -> Majestic Turbo shows the generated files and their size, with a button that deletes them.

= Can I keep one stylesheet out of the pipeline? =

Yes. Add its handle or a fragment of its path to "Excluded stylesheets". Scripts the same way.

= How do I convert the images I already uploaded? =

New uploads convert themselves: the request that stores the image also writes the converted files beside it, so a post is published with them already on disk. That covers an image sideloaded by an importer and a crop the theme registers. One upload may spend 8 seconds converting (filter `majestic_turbo_upload_budget`); anything past that keeps its JPEG and is finished by the next batch.

For images already in the library, open Settings -> Majestic Turbo and press "Convert the media library". It converts 15 attachments per click (filter `majestic_turbo_image_batch` to change that) and offers a continue button while there are more, so a host with a 30 second PHP limit still finishes. Converted files are written beside the originals as `.webp` - and `.avif` too when the AVIF switch is on; only files that came out smaller are kept, and the page cache is cleared so the new markup is served.

= I deactivated it and the .webp files are still in uploads =

That is deliberate. The converted copies sit beside the originals rather than in the plugin's own directory, so every one of them can be deleted at any time without touching the media library, and a site that turns the feature off or uninstalls keeps working on the JPEGs. To reclaim the space, delete `*.webp` and `*.avif` inside `wp-content/uploads/`; WordPress will not miss them.

= Can I fill the cache from the command line? =

Yes. `wp majestic-turbo warm` primes the home page and the five newest posts, `wp majestic-turbo warm --post=482` primes the home page and one post, and `wp majestic-turbo warm --dry-run` prints the URLs without requesting them. Each line reports whether the page was stored, already cached or answered by something else. `wp majestic-turbo purge` deletes every generated file.

= The CSS cache headers still say "no-cache" =

Those are your server's headers for static files, not this plugin's, and nothing needs to be configured for the plugin to work. Every generated stylesheet and script carries a hash of its own contents in the filename, so a browser revalidates it and gets a `304` instead of the file. If your server reads `.htaccess` (Apache, LiteSpeed), the plugin also writes one that adds a one-year expiry and denies the stored pages; nginx, Caddy, IIS and other servers work without it.

== Screenshots ==

1. Settings -> Majestic Turbo.

== Changelog ==

= 1.6.8 =
* The WordPress core sitemap is no longer stored as a page. `/wp-sitemap.xml` matched no private path - the check looked for `/sitemap`, and the character before `sitemap` is a hyphen - so an XML response with no query string was cached and then answered with `Content-Type: text/html`, which a crawler cannot read. The path is excluded, and a response whose content type is not HTML or XHTML is never stored and never has its type replaced, so any other JSON or XML endpoint keeps its own content type too.
* A cached page is now cleared when the site title, tagline, permalink structure, reading settings, site language, timezone, date or time format, week start or a menu item changes. None of those fires `save_post`, so a stored page could keep the old header, title or links until its lifetime ran out.
* A cookie that chooses a language is now part of the cache key. WPML and Polylang can serve a different language at the same URL with `wp-wpml_current_language`, `_icl_current_language`, `pll_language` or `wpml_language`; each language gets its own stored page instead of the first one being handed to every visitor, and the cache still answers in every language.
* The image preload is inserted into the document with a callback instead of a `preg_replace()` replacement string, so a URL containing `$1` or `\1` is printed rather than read as a backreference.

= 1.6.7 =
* The combined stylesheet and script URLs now use the scheme the site is served with. On a site whose pages are https while the uploads base URL still says http - a TLS proxy, or a domain moved to https without a database update - the browser blocked them as mixed content, so the page ran without the combined CSS and without the deferred JavaScript. The host still has to match the site's own, so an offloaded CDN keeps the URL it was given.

= 1.6.6 =
* No server configuration is required, and none is shipped. The bundled nginx sample is gone: the `.htaccess` the plugin writes is a bonus on servers that read it, and everywhere else the plugin works from the generated files themselves - stored pages have salted hash names and gzipped bodies, and every stylesheet or script carries a content hash in its filename. Apache, nginx, Caddy, IIS and shared hosts that harden uploads all run the same code path.

= 1.6.5 =
* The readme now says what the plugin is: a general performance plugin that began as the Majestic Tube companion, not one that requires that theme. Nothing in the pipeline names a template, a CSS class or a theme function, and the companion prefixes are added only while Majestic Tube is active.
* Fixed the image formats disappearing on a site whose pages print `https://` while the uploads record says `http://` (a site behind a TLS proxy, or one moved to https without a database update). The path of the URL is now matched instead of the whole string, so a WebP or AVIF file already on disk is found again and the `<picture>` is printed. The host still has to be the site's own, and a traversal never resolves.

= 1.6.4 =
* The CSS safelist default is now written for the theme that is active. Generic state classes and common UI libraries are always there; Majestic Tube's player prefixes are added only while that theme is the active template, so a site running any other theme starts with a list that names nothing it does not print. A site that already saved its own safelist keeps it untouched.

= 1.6.3 =
* A cached page can now be answered before WordPress loads. When **Serve before WordPress** and **Cache pages** are on and wp-config.php has `define( 'WP_CACHE', true );`, the plugin keeps WordPress's `advanced-cache.php` drop-in in place: it reads the stored page, the salts and the request rules straight from disk, sends the document, the `304` or nothing, and never boots core. The stored bytes are identical either way; only the work before the response changes.
* The drop-in embeds the store path, host, lifetime, charset and the theme and plugin versions, and is rewritten when any of them changes. It also stamps the modification time of the theme's style.css and the plugin's main file, so a theme or plugin update makes the next request ordinary instead of serving pages built by the old code. It is removed when either switch goes off or the plugin is deactivated or uninstalled.
* It refuses to overwrite another caching plugin's `advanced-cache.php` - the in-plugin cache keeps working in that case - and the settings screen reports a missing WP_CACHE, a foreign drop-in or an unwritable wp-content instead of leaving it to be discovered from a slow TTFB.
* A plugin directory removed by FTP no longer fatals: the drop-in checks that the two class files exist and otherwise lets the request through.

= 1.6.2 =
* Works with any theme. The pipeline names no template, CSS class or theme function; the plugin's description, settings copy and readme no longer read as a Majestic Tube-only tool. The CSS safelist default now covers generic state classes and common UI libraries, and the two switches that read theme output - Reduce unused CSS and Defer JavaScript - are documented for other themes.
* WebP and AVIF stand independently. A host that can write only one of them generates and offers that one: no `<source>` is printed for a format the host cannot write, and the AVIF pass no longer depends on a WebP file existing to compare against - with no WebP beside it, the original image is the baseline.
* The command line and the settings screen no longer refuse to run when WebP is unavailable or switched off. They report which formats this host can write, warn about a switch that cannot be honoured, and convert with the other format.
* A page view no longer probes the image editor: whether a converted file exists is the only question the read path asks, so serving needs no encoder and a library converted on one host keeps working on another.
* AVIF skip reasons say what they compared against - the WebP when there was one, the original otherwise.

= 1.6.1 =
* A cached page is no longer deleted by the generated-file pruner. With Combine CSS off every stylesheet is its own file, and the pruner kept only the six newest - so a theme with seven local sheets lost the seventh on every render. It now spares the files the current request generated, anything younger than a day, and keeps twelve beyond that.
* A stylesheet with a `media` attribute is no longer folded into the combined bundle. A print sheet ran on screen and a `(min-width: ...)` sheet stopped being conditional; those sheets keep their link and are only minified.
* CSS: `ul :first-child` is no longer minified to `ul:first-child`. The minifier now knows whether a block holds declarations or rules, so a descendant combinator survives.
* JavaScript: a multi-line comment between `return` and its value no longer becomes a line break. That line break ended the statement and made the function return undefined; it is emitted only where it cannot end a statement early.
* HTML: whitespace between two inline elements is kept, so `</a>` followed by `<a>` no longer joins two words. The break is removed only between block-level neighbours.
* Image hints: `set_attribute()` no longer matches `data-loading=` instead of `loading=`, decorative images no longer take one of the inlined slots, and a tiny logo is no longer preloaded as the largest paint.
* The combined stylesheet is no longer re-minified on every render. The per-sheet comments that were joined into the bundle made the "already minified" check fail every time.
* Cache flushes cover comments, comment status and widget changes; deactivation and uninstall clear the warm cron; multisite uninstall walks every site; `Vary` includes `User-Agent` because the cache key does.
* Stored pages are named with a site salt, so a visitor cannot compute the filename of a page they did not store, and every entry is invalidated when the salts are rotated.
* A revalidation with `If-Modified-Since` is answered with `304 Not Modified`, so the page a browser already has is not downloaded again.

= 1.6.0 =
* The conversion log: a run records what it did, not just a total. Settings -> Majestic Turbo shows the last run and what started it, the attachments and files it converted, the bytes saved, the files it skipped or deferred, a table of reasons those files were left alone, and the last 40 file results with their before/after sizes. `wp majestic-turbo images --status` prints the same record.
* `wp majestic-turbo images` prints one line per attachment now - files converted, saved, skipped - followed by the reason breakdown. `--verbose` adds one line per file with its size before and after, or the exact reason it was skipped. `--dry-run` lists the attachments and says nothing will be written.
* `convert_attachment()` returns a `details` list for every file and format, the upload hook records its run too, and an upload whose budget ran out is visible as deferred instead of silently missing.

= 1.5.1 =
* Every card in the first screenful now carries `fetchpriority="high"`, not just the first, and the whole screenful is eager. A grid of equal cards makes the largest contentful paint a tie that lands on whichever card the browser scores, so a hint on one card is a guess: PageSpeed's LCP request discovery insight reported the scored card as lazy, without a priority hint, and not discoverable because a lazy-loaded image is not found by the parser. The window is 12 cards by default (`majestic_turbo_eager_images`), covering six rows of a two-column phone grid through the two rows a five-column desktop shows, and one image on a single page so a poster is not queued behind related cards.

= 1.5.0 =
* The first three thumbnails travel in the document: their converted file is written into the `<source>` as a `data:` URI, so the picture paints with the markup instead of waiting behind the rest of the request queue. A grid of equal cards makes the largest contentful paint whichever card is painted last, and no eager or priority hint wins that tie - taking the request away is what helps. Inlining the first few takes their requests out of the queue the largest paint waits on; three is the default because each extra image grows the document by more than the request it saves. `majestic_turbo_inline_images` changes the count, 0 turns it off, and `majestic_turbo_inline_bytes` sets the largest file that may be carried (32 KB by default).
* Inlining never removes a fallback: the `<img>` keeps its JPEG and the other converted format keeps its own source, so a browser that reads none of the inlined format still shows the picture, and a picture whose bytes are already in the page is no longer preloaded as well.

= 1.4.0 =
* Uploads convert inside the request that stores them, with an 8 second budget (`majestic_turbo_upload_budget`): a post is published with its WebP and AVIF files already on disk, an importer's sideloaded image is converted the same way, and a large upload that runs out of budget keeps its JPEG for the batch pass rather than failing. The hook is connected when either format is on, so an AVIF-only site converts too, and it no longer writes WebP files it would never serve.
* WebP images are now written at the quality the setting names. WordPress resets the editor's quality while saving when the output format differs from the file's, so every file written before this release carried the editor default of 86 instead of 75 - more bytes than the quality asked for. The quality is pinned through `wp_editor_set_quality` for that one save; re-run the conversion pass to shrink an existing library.
* AVIF images, off by default: an AVIF source is printed in front of the WebP one and falls back to WebP and then the JPEG. A file is kept only when it is smaller than the WebP beside it, and a missing or larger AVIF drops that source alone rather than the whole `<picture>`.
* AVIF is held to the WebP beside it: a file is kept only when it is smaller, and the quality is a starting point that `majestic_turbo_avif_quality` moves.
* The conversion pass counts each format separately: `wp majestic-turbo images` reports how many of the converted files were AVIF, and the settings notice and the saving add up to what the site stops downloading.
* A crop on disk that the attachment metadata does not list - the theme's own card size, added after the library was uploaded - is converted too. Those are the files the pages actually paint.

= 1.3.0 =
* The first six images are eager instead of one, and only the first keeps `fetchpriority="high"`. On a two-column card grid the largest contentful paint is rarely the first card, and an eager sixth card paints the grid sooner on a two-column layout. `majestic_turbo_eager_images` changes the count.
* WebP images: a smaller WebP copy is written beside each thumbnail and offered to the browser through a `<picture>` source, with the JPEG as the fallback. The preload in the head names the same WebP file, so the largest contentful paint is not downloaded twice.
* Settings -> Majestic Turbo converts an existing media library in batches of 15 attachments, with a resume button, for a site whose images were uploaded before the plugin was installed. `wp majestic-turbo images` does the same from the command line.
* An image with no srcset of its own now still gets its WebP offered. Previously the `<source>` was printed empty, which browsers ignore, so those images kept downloading the JPEG while the head preloaded a WebP nothing used.

= 1.2.0 =
* A responsive first image is now preloaded through `imagesrcset` and `imagesizes`, the same candidate list its `<img>` carries, so the largest contentful paint request is discoverable when the image has a srcset. Previously only an image without a srcset was preloaded.

= 1.1.0 =
* A publish now warms the page cache for the home page and the new permalink, in the background, so the first visit after publishing is a cache hit.
* `wp majestic-turbo warm` primes the home page, the newest posts, given URLs or given post IDs, and reports what the cache did for each.
* `wp majestic-turbo purge` deletes every generated file from the command line.
* New filters: `majestic_turbo_warm_urls`, `majestic_turbo_warm_delay`, `majestic_turbo_warm_timeout`.

= 1.0.0 =
* First release: CSS minifier, combiner and unused-rule reducer, critical CSS, JavaScript minifier and defer, HTML minifier, block CSS removal, image hints, full-page cache, settings screen and purge.
