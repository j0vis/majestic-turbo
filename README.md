# Majestic Turbo

A performance plugin for any WordPress theme. It rewrites the finished page on
its way out: minifies and combines CSS, reduces unused rules, minifies and
defers JavaScript, minifies HTML, hints the largest image for the first paint,
converts images to WebP and/or AVIF and serves cached pages.

It was built as the companion to the Majestic Tube theme, and it can be used on
any theme: no template, CSS class or theme function is named anywhere in the
pipeline. Block and classic themes work without configuration; the two switches
that read theme output - **Reduce unused CSS** and **Defer JavaScript** - are
the ones worth checking on a new install, and both are explained under
[Things to know](#things-to-know-before-switching-it-on). Majestic Tube needs no
special build: the theme's player prefixes are added to the CSS safelist only
while it is the active template, and every other theme starts without them.

Every rewrite is switchable, every generated file lives in
`uploads/majestic-turbo`, apart from the one `advanced-cache.php` drop-in the
early cache may write, and deleting the plugin leaves the media library, the
theme and the database untouched apart from its own two option rows.

## Requirements

- WordPress 6.5 or newer.
- PHP 7.4 or newer.
- A writable uploads directory for the stylesheets, scripts and stored pages.
  Without one nothing is written: the CSS and JavaScript passes leave the
  page's own files in place, while the HTML and image passes still run.
- A writable `wp-content` **if** you want the early drop-in, plus
  `define( 'WP_CACHE', true );` in `wp-config.php`. Without either, the page
  cache still answers, just after WordPress has loaded.
- WebP and AVIF encoding depend on the host: GD has written WebP since PHP 5.5,
  AVIF since PHP 8.1; Imagick needs libwebp / libheif in its build. The two
  formats are independent - the plugin generates and offers whichever ones the
  host can write, so an AVIF-only build works without a WebP file anywhere on
  disk, and a WebP-only build works without AVIF. The settings screen says which
  of the two this host can write.

## What each switch does

| Switch | Effect |
| --- | --- |
| Minify HTML | Drops template indentation and comments, and minifies inline styles and scripts. Whitespace that renders a space is kept. |
| Minify CSS | Strips comments and dead whitespace from every local stylesheet. |
| Combine CSS | Serves every local stylesheet with no media attribute as one content-addressed file. |
| Reduce unused CSS | Drops rules no element on the rendered page can match, keeping the safelist. Reads the combined sheet. |
| Inline critical CSS | Prints the used rules inline and loads the remainder without blocking the first paint. Reads the combined sheet. |
| Minify JavaScript | Removes comments and indentation from local scripts. Nothing is renamed or rewritten. |
| Defer JavaScript | Adds `defer` to local theme and plugin scripts that name no strategy. Core scripts are left parser-blocking. |
| Image hints | Marks the first screenful eager and high priority, carries the first three thumbnails in the document, lazy-loads the rest. |
| WebP images | Writes a smaller WebP sibling beside each JPEG or PNG and offers it in a `<picture>`. Skipped when the host cannot write WebP. |
| AVIF images | Writes an AVIF sibling in front of the WebP, kept only when it is smaller than the WebP - or than the original when there is no WebP. Off by default, skipped when the host cannot write AVIF. |
| Drop unused block CSS | Skips the block library and global styles when the page contains no blocks. |
| Cache pages | Stores the finished HTML of anonymous visits and answers the next one from the stored document. |
| Serve before WordPress | Writes the `advanced-cache.php` drop-in, so a stored page is answered before core, the plugins and the database load. Requires `define( 'WP_CACHE', true );` in wp-config.php. |
| Cache lifetime | How long a stored page is served, in minutes. A publish, edit, comment, menu, widget or site-setting change flushes the whole cache. |

The two CSS switches that reduce or split a sheet need **Combine CSS**, because
they work on the combined file; with combining off the page keeps its own
stylesheets and those two switches do nothing.

Four settings take text rather than a switch: the **CSS safelist**,
**Excluded stylesheets**, **Excluded scripts** and **Preconnect hosts**. The
first three decide what the CSS and JavaScript passes may touch; the last is
announced in the head so the DNS and TLS handshake to a video CDN, an ad server
or an analytics endpoint overlaps the first paint.

## Generated files

```
uploads/majestic-turbo/
├── .htaccess        optional Apache rules: deny stored pages, long expiry on css/js
├── index.php        silence
├── css/             combined and minified stylesheets, content-addressed
├── js/              minified scripts, content-addressed
└── page-cache/      <hash>.html.gz plus a small <hash>.json of metadata
```

Filenames carry a hash of their contents, so a change produces a new file and a
browser or CDN never needs revalidating one. Stylesheets and scripts are pruned
once they are a day old, keeping the twelve newest and never removing one the
current page still names; a stored page is dropped when it is twice its
lifetime old.

The store is plain files, so every server that can run WordPress can use it:
no rewrite rule, no `location` block, no module and no control-panel setting.
Where the server reads `.htaccess` (Apache, LiteSpeed) the plugin also writes
one, which denies the stored pages and gives the generated css/js a one-year
expiry. Where it does not - nginx, Caddy, IIS, a host that hardens
uploads - nothing is missing: the plugin never asks the server to interpret a
request, a stored page has a salted hash for a name and a gzipped body, and each
stylesheet or script carries a hash of its own contents in its filename, so a
browser that revalidates one is answered `304` instead of downloading it again.
No server configuration is documented or required, because none is needed.

Stored pages are named with a hash of the site's salt (`NONCE_SALT` in
`wp-config.php`), not only with the URL, so a visitor cannot compute the
filename of a page they did not store - and rotating the salts retires the
whole cache. A page that is already open in a browser is
revalidated with `If-Modified-Since` and answered with `304 Not Modified`, which
is a header line instead of the document.

## Serving before WordPress

A page cache that runs on `plugins_loaded` has already paid for core, every
active plugin, the database connection and the theme. That work is small on a
fast local machine and usually the largest part of time to first byte on shared
hosting. The optional drop-in moves the answer in front of all of it.

With **Serve before WordPress** on and `define( 'WP_CACHE', true );` in
`wp-config.php`, the plugin keeps `wp-content/advanced-cache.php` in place.
WordPress includes that file before `wp-settings.php` finishes, so the early
class gets the request with nothing but its own code loaded: it rebuilds the
cache key from the salts and `$_SERVER`, checks the cookie, the method and the
path rules, reads the stored entry and prints it. On a miss it returns and
WordPress continues normally -- the in-plugin cache still runs, so nothing is
served worse than before.

The drop-in embeds everything it needs (store path, host, lifetime, charset,
theme and plugin versions) and is rewritten when one of those changes. It also
bakes in the modification time of the plugin's main file and of the theme's
`style.css`, and compares them before serving: after an update the first request
is an ordinary one, WordPress loads, and the drop-in is rewritten with the new
stamps instead of serving entries stored under the old version. It is removed
when either switch goes off, on deactivation and on uninstall. It never
overwrites another caching plugin's drop-in, and it checks that its two class
files still exist, so a plugin folder deleted by FTP degrades to an ordinary
uncached request instead of a fatal.

The body is byte-identical either way; what changes is how much work happens
before the first byte. A request with a `wordpress_logged_in_*` cookie and a
POST both bypass the early path, and an `If-Modified-Since` request is answered
`304` from the drop-in.

## Command line

```bash
# The home page and the five newest posts.
wp majestic-turbo warm

# Prime one post, and any page on this site.
wp majestic-turbo warm --post=482 /category/amateur/

# Convert up to 200 attachments to WebP.
wp majestic-turbo images

# Convert everything, then read back what the run decided file by file.
wp majestic-turbo images --limit=0
wp majestic-turbo images --status

# Delete every generated file.
wp majestic-turbo purge
```

## Filters

`majestic_turbo_page_cacheable`, `majestic_turbo_cache_ttl`,
`majestic_turbo_defer_script`, `majestic_turbo_page_has_blocks`,
`majestic_turbo_inline_css_limit`, `majestic_turbo_fold_bytes`,
`majestic_turbo_eager_images`, `majestic_turbo_inline_images`,
`majestic_turbo_inline_bytes`, `majestic_turbo_upload_budget`,
`majestic_turbo_image_batch`, `majestic_turbo_webp_quality`,
`majestic_turbo_avif_quality`, `majestic_turbo_warm_urls`,
`majestic_turbo_warm_delay`, `majestic_turbo_warm_timeout` and
`majestic_turbo_option_definitions`.

## Things to know before switching it on

- **The page cache stores HTML for anonymous visitors**, which includes any
  nonce a theme printed. A panel that relies on a nonce being fresh should be
  fetched over the REST API rather than embedded in a cached page. The default
  lifetime is an hour for this reason: the longest a nonce can be stale is the
  longest a page is stored.
- **Cookie-based language switching is part of the key.** WPML and Polylang
  can serve a different language at the same URL with a cookie. The plugin
  reads the common language cookies (`wp-wpml_current_language`,
  `_icl_current_language`, `pll_language`, `wpml_language`) and keys each
  language separately, so one language never replaces another in the cache.
- **The cache key carries the mobile/desktop split**, and the response says so
  with `Vary: User-Agent`. A CDN that ignores `Vary` can hand the phone variant
  to a desktop, or the reverse; on such a CDN either add a device-aware cache
  rule or leave **Cache pages** off.
- **A class a script adds after load cannot be found in the markup.** If a
  component is built client-side, its selector prefix belongs in the CSS
  safelist, or the reducer will drop its rules. The shipped default covers
  generic state classes (`.is-`, `.has-`, `.js-`) and common UI libraries
  (Swiper, Slick, Magnific, Fancybox, Select2, Chosen, WooCommerce). Majestic
  Tube's player prefixes are appended only while that theme is the active
  template, so another theme's install starts without selectors it will never
  print; your own theme's prefixes are added to the same box.
- **Combining moves stylesheets into one file at the end of the queue.** A
  sheet that was deliberately loaded before another to win a specificity tie
  can lose it; exclude either handle if that matters.
- **A converted image is wrapped in `<picture>`.** That is what makes the
  fallback possible, but it puts one element between the image and its parent,
  so a theme rule written as `div > img` no longer matches. Write
  `div > picture > img`, or use the `picture` rule the plugin prints.
- **Deferring changes execution order.** A theme script that must run before
  inline markup depends on it belongs in `exclude_js`.
- **A `<script>` or `<style>` whose content contains its own closing tag** ends
  the scanner early. That is a limitation of every regex-based HTML rewriter;
  keep such a document out of the buffer with `DONOTCACHEPAGE` if it matters.
- **Multisite settings are per site.** The generated files and the stored pages
  are per site as well; uninstalling walks every site.
