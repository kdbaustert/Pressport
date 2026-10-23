=== PressPort ===
Contributors: cncresource
Tags: import, migration, csv, json, drupal
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import content into WordPress from CSV files, JSON feeds, or another CMS (WordPress, Drupal, Ghost), with field mapping, dry runs and rollback.

== Description ==

PressPort adds its own **PressPort** menu to wp-admin with three pages:

* **New Import**: a three-step importer.
    1. *Source*: upload a CSV, point at a JSON feed or file, or connect to a WordPress, Drupal or Ghost site.
    2. *Map & options*: preview real records and map source fields to title, content, excerpt, date, slug, status, author, categories, tags, featured image, custom fields and custom taxonomies. A mapping is suggested automatically.
    3. *Import*: runs in small batches so large imports don't time out, with a live log, pause/resume and a dry-run mode.
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
* Full WP-CLI support for large or scripted migrations.

= Sources =

* **CSV**: header row required, delimiter detected automatically, multi-line cells supported.
* **JSON feed or file**: the list of records is found automatically (items, data, posts, results...) or you can give a dot path such as "data.posts". Optional request headers for APIs that need a token. JSON Feed (jsonfeed.org) works out of the box.
* **WordPress**: any site with the REST API. Add a username and Application Password to bring over drafts, private posts and raw block markup. Set the REST base to import pages or custom post types.
* **Drupal 8+**: needs the core JSON:API module. Images, media, taxonomy terms and authors are resolved automatically.
* **Ghost**: needs a Content API key. Published content only.

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
3. Go to PressPort → Settings to set your defaults, then PressPort → New Import.

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
    wp pressport import ghost --site_url=https://blog.example.com --api_key=KEY
    wp pressport import --config=examples/migration-config.json
    wp pressport jobs
    wp pressport resume <job-id>
    wp pressport rollback <job-id> --yes

Options: --post_type, --status, --author, --duplicate=skip|update|create, --batch_size, --[no-]featured-images, --content-images, --create-authors, --dry-run. Anything you leave out uses the values from PressPort → Settings.

JSON feeds use `--feed` because `--url` is reserved by WP-CLI.

== Developer hooks ==

* `pressport_sources` (filter): register a custom source class implementing `PressPort_Source` (extend `PressPort_Source_Base`).
* `pressport_record` (filter): change a record before import, or return false to skip it.
* `pressport_post_data` (filter): adjust the array passed to wp_insert_post() / wp_update_post().
* `pressport_after_import_record` (action): runs after each post is saved, with ($post_id, $record, $job, $action).
* `pressport_capability` (filter): override the capability needed to run imports.

Imported posts carry the meta keys `_pressport_job_id`, `_pressport_source_id` and `_pressport_source_uid`.

== Notes ==

* The WordPress REST API returns category names without their parents, so category hierarchy isn't recreated for that source.
* Uploaded and cached source files are kept in wp-content/uploads/pressport/ (web access denied) and removed when their history entry is deleted or the plugin is uninstalled.

== Changelog ==

= 1.0.0 =
* Initial release.
