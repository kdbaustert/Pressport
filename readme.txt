=== Migrate Plus – Content Migration for WordPress ===
Contributors: cncresource
Tags: import, export, migration, json, woocommerce
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: trunk
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Move content between WordPress sites as JSON, with ACF and WooCommerce support, dry runs and rollback.

== Description ==

**Not ready for use yet.** Migrate Plus is still in development and hasn't had a stable release. Don't run it on a live site until one is published. If you want to try it, use a test site or a copy you can throw away.

Migrate Plus adds its own **Migrate Plus** menu to wp-admin with five pages:

* **Import**: drop a JSON file (.json, or .json.gz compressed) onto the page, choose one, or pull it straight from the old site.
    * *Pull from another site* fetches the export itself over HTTP. Connect with a WordPress application password (needs HTTPS) or with a pull key generated on the old site under Migrate Plus → Settings → Access, which also works over plain http:// for local and staging sites. Credentials go along with each request and are stored nowhere on the pulling site.
    * *A Migrate Plus export* is recognised, and after one confirmation it imports straight away with the field mapping and post type saved in the file. "Review mapping" opens the mapping step first. An export with several post types imports in one go too: Media first, then the other types, then its menus, with progress shown per part.
    * *Any other JSON file* goes through *Map & options*: preview real records and map fields to title, content, excerpt, date, slug, status, author, categories, tags, featured image, post type, custom fields, custom taxonomies and ACF fields. Save the mapping and its import options under a name to apply them to the next file; a file with the same top-level fields gets it selected for you, to apply with one click.
    * *Before importing*, Migrate Plus looks for items already on the site: imported before, or the same slug (products: SKU, media: file name). It asks whether to skip them or import them anyway. Posts and pages that differ from the site's version can be reviewed one by one, with what would change, and only the ones you approve are updated. Duplicate media is shown side by side with the copy on the site, and each file can be skipped, have its details updated, be replaced, or be added as a new item beside it ("Keep both").
    * Imports run in small batches so large ones don't time out, with a progress window (live counts and time remaining), a live log, pause/resume and a dry-run mode.
* **Export**: export posts, pages or any post type as a JSON file with terms, featured images, custom fields, ACF fields and WooCommerce product data. Tick several content types to put them in one file; Media always goes first, so the import downloads each file once and the posts after it reuse it. Navigation menus can go in the file too, also on their own. The file can be compressed (.json.gz) for a site with a low upload limit. It maps itself when imported with Migrate Plus on another site. Or tick **Hand-picked content** to export exactly the posts you choose: search by title or paste an ID, whatever the type; the status and date filters don't apply to picks.
    * *Media* exports the Media Library: each file as a URL, with its title, caption, description and alt text. Importing it downloads the files into the other site's Media Library. Files sold as WooCommerce downloads are left out; they travel with the product export.
* **Find & Replace**: replace text across the database, for example an old domain after a migration. Serialized data stays valid, and web addresses only match whole, so http://old.test leaves http://old.test.example.com alone. Starts as a dry run with sample changes per table. Real runs can be rolled back from Past replacements; values edited since the replacement are left alone. Administrators only.
* **History**: every import you have run. View the log, resume an import that stopped, run it again, or roll it back. Exports are listed too, each with a log of what went into the file. Tick several entries to delete them in one go; running ones are skipped.
* **Settings**: default import options, who can use the importer, download limits and housekeeping.

= Features =

* Re-runs are safe: records are matched by their source ID, so you can skip or update anything already imported instead of duplicating it.
* Downloads featured images and, optionally, images inside post content into the Media Library and rewrites the URLs. An image is only downloaded once.
* Hierarchical categories using "Parent > Child". Lists can be arrays or "a, b", "a|b" or "a;b" strings.
* Authors: user accounts differ between sites, so authors aren't taken from the file. Each post goes to the author picked for it (on Review mapping or the duplicates screen), else the author for all posts and pages picked on Review mapping (--author on the command line), else whoever runs the import. The old site's author login and name are kept with the post as a note.
* "Status for every item" overrides each record's status, also for posts that are updated, for example to import everything as drafts on staging. Published posts with a future date are scheduled.
* Comments: an export can carry each post's approved comments (pending, spam and trash stay behind). The import adds them with replies threaded, links commenters to users by email, and doesn't add them twice when the same file is imported again.
* Dates in any strtotime() format, ISO 8601 with offsets, or Unix timestamps (seconds or milliseconds).
* Status values such as published, draft, true/false and 1/0 are understood.
* Navigation menus: an export can carry every menu with its items and theme locations. The import rebuilds them after the posts, pointing each item at the imported post or term (or one with the same slug), skipping with a warning what it can't find, pointing custom links to the old site at this one, and assigning theme locations the active theme has. A menu with the same slug is filled rather than copied, and importing the file again adds no item twice.
* Rollback deletes every post, media file, comment and menu an import created, and gives theme locations it changed their previous menu back. Posts it only updated are kept, without the comments it added to them.
* When an import finishes, the permalink rules are rebuilt once (.htaccess is not touched).
* Advanced Custom Fields (free and Pro): every field type is converted, including images, galleries, relationships, taxonomy and user fields, dates, choices, repeaters, groups and flexible content.
* WooCommerce: export and re-import products with their prices, stock, tax and shipping settings, attributes, downloadable files and variations. Rebuilt through WooCommerce's own API on the destination; galleries travel as URLs, and related products are linked by their ID on the old site or their SKU, even when they arrive in a later import.
* Full WP-CLI support for large or scripted migrations, including export.

= Importing other JSON =

Any JSON file holding a list of records can be imported: a top-level array, or a list under a key such as "items", "data", "posts" or "results". Map its fields on the second step. Nested fields are flattened with dots, for example "authors.0.name" or "body.value".

== Settings ==

Migrate Plus → Settings (administrators only):

* **Import defaults**: post type, default status, a status for every item, what to do with records imported before, batch size, whether to download images, whether to create missing terms, whether links to the old site should point at this one (Migrate Plus exports only), whether to import comments, and whether to import menus. These are starting values; each import can still change them.
* **Export defaults**: which statuses to export and what to include (comments and menus are off unless you turn them on), whether to compress the file (.json.gz, off by default), plus custom field keys to always leave out (end a key with * to match a prefix).
* **Access**: let Editors run imports as well as Administrators, and generate a pull key other sites can use to pull exports from this site. The key is shown here to copy, can be revoked or replaced, and pulls made with it run as the user who generated it. Only administrators can change settings.
* **Downloads**: limits for the files an import downloads from the old site (featured images, images in content, Media Library files, WooCommerce download files): the timeout per file, the largest image or media file, and whether downloads may reach localhost, private network addresses and non-standard ports. The remote file size limit only applies to custom sources that read their file from a URL.
* **Housekeeping**: automatically delete history entries after a number of days, and choose whether uninstalling also removes Migrate Plus's tracking data from imported posts.

The settings page also shows a short server check (upload folder, upload size, PHP time and memory limits, image support, compression).

== Installation ==

1. Upload the `migrateplus` folder to `/wp-content/plugins/`, or upload the zip under Plugins → Add New → Upload Plugin.
2. Activate Migrate Plus.
3. Go to Migrate Plus → Settings to set your defaults, then Migrate Plus → Import.

== Frequently Asked Questions ==

= My export imported as the wrong post type =

A Migrate Plus export imports into the post type it was exported from when the importing site has it. If that type is missing (for example WooCommerce is not active), it falls back to "Import as". Check that the plugin providing the type is active, then import again.

= My import times out or my file is too big to upload =

Export it again with "Compress the file (.json.gz)" on, which usually makes it several times smaller. Or lower the batch size in Settings, or run the import with WP-CLI, which has no upload limit or browser timeout.

A .json.gz may unpack to no more than an eighth of PHP's memory limit (1 GB when there is none), since the import reads the whole file into memory and decoding it takes several times its size. The `migrateplus_max_json_size` filter changes that.

= Can I undo an import? =

Yes. Use "Roll back" on the History page (or `wp migrateplus rollback <job-id>`). It deletes the posts, media, comments and menus that import created, and gives theme locations it changed their previous menu back. If you have deleted the history entry, the import can no longer be rolled back.

= Will uninstalling delete my imported posts? =

No. Uninstalling removes Migrate Plus's settings, history and cached files. Imported content always stays.

== WP-CLI ==

    wp migrateplus preview json --file=migrateplus-product.json
    wp migrateplus import json --file=migrateplus-product.json --duplicate=update
    wp migrateplus import json --file=feed.json --map=mapping.json --post_type=page
    wp migrateplus import --config=examples/migration-config.json
    wp migrateplus jobs
    wp migrateplus resume <job-id>
    wp migrateplus rollback <job-id> --yes
    wp migrateplus export page --output=pages.json
    wp migrateplus export attachment,page,post --menus --output=site.json
    wp migrateplus export product --gzip
    wp migrateplus export --posts=12,34,56
    wp migrateplus import json --file=feed.json --mapping="Books feed"
    wp migrateplus mapping list
    wp migrateplus mapping export "Books feed" --output=books.json
    wp migrateplus mapping import books.json
    wp migrateplus mapping delete "Books feed"

Options: --post_type, --status, --force-status=publish|draft|pending|private|keep, --author, --duplicate=skip|update|create, --duplicates=skip|import|update (what to do with items already on the site; asks when left out, unless you pass --yes or run a dry run, when it skips them, or imports copies with --duplicate=create), --batch_size, --[no-]featured-images, --content-images, --[no-]create-terms, --[no-]rewrite-links, --[no-]comments, --[no-]menus, --dry-run, --mapping=<name> (a saved mapping). --file takes .json or .json.gz. `wp migrateplus export` takes one post type or several separated by commas, plus --posts=<ids> (export exactly these posts by ID, whatever their type; the type list and the status and date filters don't apply), --[no-]comments, --[no-]menus and --[no-]gzip. `wp migrateplus mapping export` writes a file for `wp migrateplus import --config`. Anything you leave out uses the values from Migrate Plus → Settings, except that a Migrate Plus export imports into the post type it came from unless you pass --post_type.

On/off options also take yes, no, true, false, 1 or 0 (`--comments=no`). An import with failed records ends with an error and a non-zero exit status, after printing the summary.

== Developer hooks ==

* `migrateplus_sources` (filter): register a custom source class implementing `MigratePlus_Source` (extend `MigratePlus_Source_Base`).
* `migrateplus_record` (filter): change a record before import, or return false to skip it.
* `migrateplus_post_data` (filter): adjust the array passed to wp_insert_post() / wp_update_post().
* `migrateplus_after_import_record` (action): runs after each post is saved, with ($post_id, $record, $job, $action).
* `migrateplus_capability` (filter): override the capability needed to run imports.
* `migrateplus_acf_value` (filter): adjust a converted ACF value before it is saved, with ($value, $field, $raw, $post_id, $job).
* `migrateplus_export_item` (filter): change an exported item, with ($item, $post, $args).
* `migrateplus_max_json_size` (filter): the most JSON, in bytes, a .json.gz upload may unpack to.
* `migrateplus_remote_file_url` (filter): rewrite a remote file URL before a custom source downloads it, with ($direct_url, $original_url).

Imported posts and menu items carry the meta keys `_migrateplus_job_id`, `_migrateplus_source_id` and `_migrateplus_source_uid`. Imported comments carry `_migrateplus_job_id` and `_migrateplus_source_comment_uid`. Menus an import created carry the term meta `_migrateplus_job_id`.

== Notes ==

* Saved mappings are stored in the `migrateplus_mappings` option (not autoloaded) and removed on uninstall. A site keeps up to 50.
* Uploaded files and cached records are kept in wp-content/uploads/migrateplus/ (web access denied) and removed when their history entry is deleted or the plugin is uninstalled.
* WooCommerce downloadable files are copied into the importing site's protected woocommerce_uploads folder, so they pass WooCommerce's approved download directories check. Because that folder refuses direct requests on the old site too, the export carries a private link for each file. It works for 24 hours, so import within a day of exporting.

== Changelog ==

= Unreleased =
* Pull from another site: the Import page can fetch an export straight from the old site over HTTP, connecting with an application password or a pull key generated on the old site under Settings → Access. The key works over plain http:// and is stored nowhere on the pulling site.
* Hand-picked export: tick "Hand-picked content" on the Export page to export exactly the posts you choose, whatever their type, or pass --posts=<ids> on the command line.
* Fix: a number saved by an older version below a setting's new minimum (for example a 30-second download timeout) silently blocked the whole settings form from saving. Such values now render clamped to the allowed range.
* History: select several imports or exports and delete them in one go. Running ones are skipped and the result says which.
* Confirmations and error messages use Migrate Plus's own dialog instead of the browser's. Primary buttons use WordPress's blue; buttons that delete or roll back are red.
* Find & Replace is now above History in the menu.
* Dry runs skip items already on the site, as a real run does when you choose to skip them, so their counts match. Before, they counted them as created.
* Saved mappings: a site keeps up to 50. An option a mapping file leaves out now follows Migrate Plus → Settings instead of being turned off.
* Fixes: a multi-type export no longer stops early when content is added while it runs; Editors' exports no longer fail when menus are on by default; a failed export no longer leaves a broken file; a .json.gz that is too big to import is refused instead of running out of memory.
* Renamed to Migrate Plus. Settings, history, saved mappings, tracking data and the uploads folder of an existing install are moved over once, the first time the renamed plugin loads, and files exported before the rename still import and match what they imported before.
* JSON only: export writes a JSON file, and import takes an uploaded JSON file. Removed the CSV, "URL or feed", WordPress REST API, Ghost and Drupal sources and the CSV and XML export formats.
* New Import screen: drop a JSON file to import it. A Migrate Plus export imports after a single confirmation; other JSON goes through field mapping.
* A Migrate Plus export imports into the post type it was exported from, so a product export becomes WooCommerce products without changing "Import as". New "Post type" mapping field routes each record to its own type.
* ACF support: map ACF fields with type-aware conversion (images, galleries, relationships, taxonomy, users, dates, choices, repeaters, groups, flexible content).
* WooCommerce support: export and re-import products (prices, stock, tax and shipping, attributes, downloadable files and variations) through WooCommerce's own API. Turn it off on the Export page or with `--no-woo`.
* New Export page and `wp migrateplus export`, with terms, featured images, custom fields and ACF fields.
* Export and import the Media Library ("Media" on the Export page, `wp migrateplus export attachment`).
* Imports check for items already on the site and ask whether to skip them; changed posts and pages can be reviewed and approved before they are updated, and duplicate media compared side by side to update, replace or keep both.
* Items imported before are now matched per source site, so the same ID from two different sites is no longer taken for the same item.
* "Status for every item" (`--force-status`) imports every record with one status, also when updating.
* Migrate Plus exports carry the author's login and display name; the import matches by email, then login, and logs a warning when it falls back to the default author.
* Comments: export approved comments (`--comments`) and import them with threading, user links and no duplicates on re-import (`--no-comments` to leave them out). Rollback removes them.
* Permalink rules are rebuilt once when an import finishes.
* Several post types in one export (`wp migrateplus export attachment,page,post`). The file lists its post types under `post_types` and imports as one job: Media first, then the rest, with progress per type and one rollback for all of it. Single-type files are written and imported as before.
* A parent is now found whatever its post type, so a child of another type in the same file links up.
* Navigation menus: export them with "Navigation menus" (`--menus`, off by default) and import them as the last stage of the import ("Import menus", `--no-menus` to leave them out). Rollback removes them.
* The Connections settings are now called Downloads, and say which downloads they limit.
* Refreshed admin design: step indicator, drag-and-drop uploads, outcome-split progress bars, log filters and search, searchable history.
* New progress window for uploads, imports, exports and rollbacks, with live counts, time remaining and pause/resume.
* Compressed exports: "Compress the file (.json.gz)" on the Export page, `--gzip`, or the Export defaults. The import takes .json.gz uploads and `--file`, recognised by their first bytes, and unpacks them a chunk at a time up to an eighth of the memory limit (`migrateplus_max_json_size`). The Server check lists zlib.
* Saved mappings for JSON that isn't a Migrate Plus export: save the mapping and import options on the mapping screen, apply them to a later file, rename or delete them. A file with the same top-level fields gets its mapping selected, not applied. On the command line: `--mapping=<name>` and `wp migrateplus mapping list|export|import|delete`.
* With "Navigation menus" on, an export that matches no content still writes the site's menus, and importing that file goes straight to them.
