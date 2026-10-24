=== PressPort ===
Contributors: cncresource
Tags: import, export, migration, json, woocommerce
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: trunk
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Move content between WordPress sites as JSON, with ACF and WooCommerce support, dry runs and rollback.

== Description ==

**Not ready for use yet.** PressPort is still in development and hasn't had a stable release. Don't run it on a live site until one is published. If you want to try it, use a test site or a copy you can throw away.

PressPort adds its own **PressPort** menu to wp-admin with four pages:

* **Import**: drop a JSON file onto the page, or choose one.
    * *A PressPort export* is recognised, and after one confirmation it imports straight away with the field mapping and post type saved in the file. "Review mapping" opens the mapping step first.
    * *Any other JSON file* goes through *Map & options*: preview real records and map fields to title, content, excerpt, date, slug, status, author, categories, tags, featured image, post type, custom fields, custom taxonomies and ACF fields.
    * *Before importing*, PressPort looks for items already on the site: imported before, or the same slug (products: SKU, media: file name). It asks whether to skip them or import them anyway. Posts and pages that differ from the site's version can be reviewed one by one, with what would change, and only the ones you approve are updated. Duplicate media is shown side by side with the copy on the site, and each file can be skipped, have its details updated, be replaced, or be added as a new item beside it ("Keep both").
    * Imports run in small batches so large ones don't time out, with a progress window (live counts and time remaining), a live log, pause/resume and a dry-run mode.
* **Export**: export posts, pages or any post type as a JSON file with terms, featured images, custom fields, ACF fields and WooCommerce product data. The file maps itself when imported with PressPort on another site.
    * *Media* exports the Media Library: each file as a URL, with its title, caption, description and alt text. Importing it downloads the files into the other site's Media Library. Files sold as WooCommerce downloads are left out; they travel with the product export.
* **History**: every import you have run. View the log, resume an import that stopped, run it again, or roll it back. Exports are listed too, each with a log of what went into the file.
* **Find & Replace**: replace text across the database, for example an old domain after a migration. Serialized data stays valid, and web addresses only match whole, so http://old.test leaves http://old.test.example.com alone. Starts as a dry run with sample changes per table. Real runs can be rolled back from Past replacements; values edited since the replacement are left alone. Administrators only.
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
* WooCommerce: export and re-import products with their prices, stock, tax and shipping settings, attributes, downloadable files and variations. Rebuilt through WooCommerce's own API on the destination; galleries travel as URLs, and related products are linked by their ID on the old site or their SKU, even when they arrive in a later import.
* Full WP-CLI support for large or scripted migrations, including export.

= Importing other JSON =

Any JSON file holding a list of records can be imported: a top-level array, or a list under a key such as "items", "data", "posts" or "results". Map its fields on the second step. Nested fields are flattened with dots, for example "authors.0.name" or "body.value".

== Settings ==

PressPort → Settings (administrators only):

* **Import defaults**: post type, status, author, what to do with records imported before, batch size, whether to download images, create missing terms and create missing authors, and whether links to the old site should point at this one (PressPort exports only). These are starting values; each import can still change them.
* **Export defaults**: which statuses to export and what to include, plus custom field keys to always leave out (end a key with * to match a prefix).
* **Access**: let Editors run imports as well as Administrators. Only administrators can change settings.
* **Connections**: request timeout, size limits for remote source files and for downloaded images and media, and whether downloads may reach localhost, private network addresses and non-standard ports.
* **Housekeeping**: automatically delete history entries after a number of days, and choose whether uninstalling also removes PressPort's tracking data from imported posts.

The settings page also shows a short server check (upload folder, upload size, PHP time and memory limits, image support).

== Installation ==

1. Upload the `pressport` folder to `/wp-content/plugins/`, or upload the zip under Plugins → Add New → Upload Plugin.
2. Activate PressPort.
3. Go to PressPort → Settings to set your defaults, then PressPort → Import.

== Frequently Asked Questions ==

= My export imported as the wrong post type =

A PressPort export imports into the post type it was exported from when the importing site has it. If that type is missing (for example WooCommerce is not active), it falls back to "Import as". Check that the plugin providing the type is active, then import again.

= My import times out or my file is too big to upload =

Lower the batch size in Settings, or run the import with WP-CLI, which has no upload limit or browser timeout.

= Can I undo an import? =

Yes. Use "Roll back" on the History page (or `wp pressport rollback <job-id>`). It deletes the posts and media that import created. If you have deleted the history entry, the import can no longer be rolled back.

= Will uninstalling delete my imported posts? =

No. Uninstalling removes PressPort's settings, history and cached files. Imported content always stays.

== WP-CLI ==

    wp pressport preview json --file=pressport-product.json
    wp pressport import json --file=pressport-product.json --duplicate=update
    wp pressport import json --file=feed.json --map=mapping.json --post_type=page
    wp pressport import --config=examples/migration-config.json
    wp pressport jobs
    wp pressport resume <job-id>
    wp pressport rollback <job-id> --yes
    wp pressport export page --output=pages.json

Options: --post_type, --status, --author, --duplicate=skip|update|create, --duplicates=skip|import|update (what to do with items already on the site; asks when left out), --batch_size, --[no-]featured-images, --content-images, --create-authors, --[no-]create-terms, --[no-]rewrite-links, --dry-run. Anything you leave out uses the values from PressPort → Settings, except that a PressPort export imports into the post type it came from unless you pass --post_type.

== Developer hooks ==

* `pressport_sources` (filter): register a custom source class implementing `PressPort_Source` (extend `PressPort_Source_Base`).
* `pressport_record` (filter): change a record before import, or return false to skip it.
* `pressport_post_data` (filter): adjust the array passed to wp_insert_post() / wp_update_post().
* `pressport_after_import_record` (action): runs after each post is saved, with ($post_id, $record, $job, $action).
* `pressport_capability` (filter): override the capability needed to run imports.
* `pressport_acf_value` (filter): adjust a converted ACF value before it is saved, with ($value, $field, $raw, $post_id, $job).
* `pressport_export_item` (filter): change an exported item, with ($item, $post, $args).
* `pressport_remote_file_url` (filter): rewrite a remote file URL before a custom source downloads it, with ($direct_url, $original_url).

Imported posts carry the meta keys `_pressport_job_id`, `_pressport_source_id` and `_pressport_source_uid`.

== Notes ==

* Uploaded files and cached records are kept in wp-content/uploads/pressport/ (web access denied) and removed when their history entry is deleted or the plugin is uninstalled.
* WooCommerce downloadable files are copied into the importing site's protected woocommerce_uploads folder, so they pass WooCommerce's approved download directories check. Because that folder refuses direct requests on the old site too, the export carries a private link for each file. It works for 24 hours, so import within a day of exporting.
== Changelog ==

= Unreleased =
* JSON only: export writes a JSON file, and import takes an uploaded JSON file. Removed the CSV, "URL or feed", WordPress REST API, Ghost and Drupal sources and the CSV and XML export formats.
* New Import screen: drop a JSON file to import it. A PressPort export imports after a single confirmation; other JSON goes through field mapping.
* A PressPort export imports into the post type it was exported from, so a product export becomes WooCommerce products without changing "Import as". New "Post type" mapping field routes each record to its own type.
* ACF support: map ACF fields with type-aware conversion (images, galleries, relationships, taxonomy, users, dates, choices, repeaters, groups, flexible content).
* WooCommerce support: export and re-import products (prices, stock, tax and shipping, attributes, downloadable files and variations) through WooCommerce's own API. Turn it off on the Export page or with `--no-woo`.
* New Export page and `wp pressport export`, with terms, featured images, custom fields and ACF fields.
* Export and import the Media Library ("Media" on the Export page, `wp pressport export attachment`).
* Imports check for items already on the site and ask whether to skip them; changed posts and pages can be reviewed and approved before they are updated, and duplicate media compared side by side to update, replace or keep both.
* Items imported before are now matched per source site, so the same ID from two different sites is no longer taken for the same item.
* Refreshed admin design: step indicator, drag-and-drop uploads, outcome-split progress bars, log filters and search, searchable history.
* New progress window for uploads, imports, exports and rollbacks, with live counts, time remaining and pause/resume.
