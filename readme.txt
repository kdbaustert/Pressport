=== PressPort ===
Contributors: cncresource
Tags: import, migration, csv, json, woocommerce
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import content into WordPress from CSV, JSON, RSS, WordPress exports or another CMS, with ACF support, dry runs and rollback. Export it the same way.

== Description ==

PressPort adds its own **PressPort** menu to wp-admin with four pages:

* **Import**: a three-step importer.
    1. *Source*: upload a CSV or import one from a URL, point at a JSON feed or file, import from any URL or feed (RSS, Atom, WordPress export, XML, JSON, CSV), or connect to a WordPress or Drupal site.
    2. *Map & options*: preview real records and map source fields to title, content, excerpt, date, slug, status, author, categories, tags, featured image, custom fields, custom taxonomies and ACF fields. A mapping is suggested automatically.
    3. *Import*: runs in small batches so large imports don't time out, with a progress window (live counts and time remaining), a live log, pause/resume and a dry-run mode.
* **Export**: export posts, pages or any post type as JSON, CSV or XML (WordPress export format) with terms, featured images, custom fields, ACF fields and WooCommerce product data. JSON exports map themselves when imported with PressPort on another site.
* **History**: every import you have run. View the log, resume an import that stopped, run it again to pick up new content, or roll it back.
* **Settings**: default import options, who can use the importer, connection options and housekeeping.

= Features =

* Re-runs are safe: records are matched by their source ID, so you can skip or update anything already imported instead of duplicating it.
* Downloads featured images and, optionally, images inside post content into the Media Library and rewrites the URLs. An image is only downloaded once.
* Hierarchical categories using "Parent > Child". Lists can be arrays or "a, b", "a|b" or "a;b" strings.
* Authors are matched by email, login, slug or display name, and can be created if missing.
* Dates in any strtotime() format, ISO 8601 with offsets, or Unix timestamps (seconds or milliseconds).
* Status values such as published, draft, true/false and 1/0 are understood.
* Rollback deletes every post and media file an import created. Posts it only updated are left alone.
* Advanced Custom Fields (free and Pro): every field type is converted, including images, galleries, relationships, taxonomy and user fields, dates, choices, repeaters, groups and flexible content.
* WooCommerce: export and re-import products with their prices, stock, tax and shipping settings, attributes, downloads and variations. Rebuilt through WooCommerce's own API on the destination; galleries travel as URLs and related products as SKUs.
* Full WP-CLI support for large or scripted migrations, including export.

= Sources =

* **CSV**: upload a file or import it from a URL (Google Sheets, Google Drive, Dropbox and GitHub share links work). Header row required, delimiter detected automatically, multi-line cells supported. Remote files are re-downloaded when you run the import again.
* **JSON feed or file**: the list of records is found automatically (items, data, posts, results...) or you can give a dot path such as "data.posts". Optional request headers for APIs that need a token. JSON Feed (jsonfeed.org) works out of the box.
* **URL or feed**: any URL or uploaded file. RSS and Atom feeds, WordPress export (WXR) files, and any XML, JSON or CSV file or API endpoint. The format is detected automatically and feed fields are mapped for you. WordPress exports include slugs, statuses, tags, featured images, custom taxonomies and post meta. Optional request headers and records path.
* **WordPress**: any site with the REST API. Add a username and Application Password to bring over drafts, private posts and raw block markup. Set the REST base to import pages or custom post types.
* **Drupal 8+**: needs the core JSON:API module. Images, media, taxonomy terms and authors are resolved automatically.

Nested fields are flattened with dots, for example "authors.0.name" or "body.value".

== Settings ==

PressPort → Settings (administrators only):

* **Import defaults**: post type, status, author, what to do with records imported before, batch size, and whether to download images, create missing terms and create missing authors. These are starting values; each import can still change them.
* **Access**: let Editors run imports as well as Administrators. Only administrators can change settings.
* **Connections**: request timeout, and an option to allow importing from localhost, private network addresses and non-standard ports (for migrating from a local dev site).
* **Housekeeping**: automatically delete history entries after a number of days, and choose whether uninstalling also removes PressPort's tracking data from imported posts.

The settings page also shows a short server check (upload folder, upload size, PHP time and memory limits, image support).

== Installation ==

1. Upload the `pressport` folder to `/wp-content/plugins/`, or upload the zip under Plugins → Add New → Upload Plugin.
2. Activate PressPort.
3. Go to PressPort → Settings to set your defaults, then PressPort → Import.

== Frequently Asked Questions ==

= Can I import from a site running on my computer? =

Yes. Turn on "Local sources" under PressPort → Settings → Connections. WordPress blocks requests to localhost, private IP ranges and unusual ports by default; this setting relaxes that rule for PressPort's own requests only.

= My import times out or my CSV is too big to upload =

Lower the batch size in Settings, or run the import with WP-CLI, which has no upload limit or browser timeout.

= Can I undo an import? =

Yes. Use "Roll back" on the History page (or `wp pressport rollback <job-id>`). It deletes the posts and media that import created. If you have deleted the history entry, the import can no longer be rolled back.

= Will uninstalling delete my imported posts? =

No. Uninstalling removes PressPort's settings, history and cached files. Imported content always stays.

== WP-CLI ==

    wp pressport preview csv --file=posts.csv
    wp pressport import csv --file=posts.csv --post_type=page --status=draft
    wp pressport import json --feed=https://example.com/feed.json --map=mapping.json --content-images
    wp pressport import wordpress --site_url=https://old.example.com --username=me --app_password="xxxx xxxx" --duplicate=update
    wp pressport import drupal --site_url=https://drupal.example.com --bundle=article
    wp pressport import --config=examples/migration-config.json
    wp pressport jobs
    wp pressport resume <job-id>
    wp pressport rollback <job-id> --yes
    wp pressport export page --format=json --output=pages.json

Options: --post_type, --status, --author, --duplicate=skip|update|create, --batch_size, --[no-]featured-images, --content-images, --create-authors, --dry-run. Anything you leave out uses the values from PressPort → Settings.

JSON feeds use `--feed` because `--url` is reserved by WP-CLI. For CSV and the URL source, `--file` also accepts an http(s):// URL, e.g. `wp pressport import remote --file=https://blog.example.com/feed/`.

== Developer hooks ==

* `pressport_sources` (filter): register a custom source class implementing `PressPort_Source` (extend `PressPort_Source_Base`).
* `pressport_record` (filter): change a record before import, or return false to skip it.
* `pressport_post_data` (filter): adjust the array passed to wp_insert_post() / wp_update_post().
* `pressport_after_import_record` (action): runs after each post is saved, with ($post_id, $record, $job, $action).
* `pressport_capability` (filter): override the capability needed to run imports.
* `pressport_acf_value` (filter): adjust a converted ACF value before it is saved, with ($value, $field, $raw, $post_id, $job).
* `pressport_export_item` (filter): change an exported item, with ($item, $post, $args).
* `pressport_remote_file_url` (filter): rewrite a remote file URL before it is downloaded, with ($direct_url, $original_url).

Imported posts carry the meta keys `_pressport_job_id`, `_pressport_source_id` and `_pressport_source_uid`.

== Notes ==

* The WordPress REST API returns category names without their parents, so category hierarchy isn't recreated for that source.
* Uploaded and cached source files are kept in wp-content/uploads/pressport/ (web access denied) and removed when their history entry is deleted or the plugin is uninstalled.

== Changelog ==

= Unreleased =
* ACF support: map ACF fields with type-aware conversion (images, galleries, relationships, taxonomy, users, dates, choices, repeaters, groups, flexible content).
* WooCommerce support: export and re-import products (prices, stock, tax and shipping, attributes, downloads and variations) through WooCommerce's own API. Turn it off on the Export page or with `--no-woo`.
* New Export page and `wp pressport export`: JSON (self-mapping), CSV and XML (WordPress WXR), with terms, featured images, custom fields and ACF fields.
* Refreshed admin design: step indicator, drag-and-drop uploads, outcome-split progress bars, log filters and search, searchable history.
* Removed the Ghost source.
* New "URL or feed" source: import from any URL, including RSS/Atom feeds, WordPress export (WXR) files, XML, JSON and CSV, with automatic format detection.
* CSV files can be imported from a remote URL, with automatic conversion of Google Sheets, Google Drive, Dropbox and GitHub share links, and a new "Remote file size limit" setting.
* "Run again" re-downloads remote CSV files.
* New progress window for uploads, downloads, imports and rollbacks, with live counts, time remaining and pause/resume.

= 1.0.0 =
* Initial release.
