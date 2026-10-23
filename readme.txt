=== PressPort ===
Contributors: cncresource
Tags: import, migration, csv, json, drupal, ghost
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Port your content into WordPress from CSV files, JSON feeds, or another CMS (WordPress, Drupal, Ghost).

== Description ==

Tools → PressPort walks you through three steps:

1. **Source**: upload a CSV, point at a JSON feed/file, or connect to a WordPress (REST API), Drupal (JSON:API) or Ghost (Content API) site.
2. **Map & options**: preview real records, map source fields to title, content, excerpt, date, slug, status, author, categories, tags, featured image, any post meta, and custom taxonomies. Sensible mappings are suggested automatically.
3. **Import**: runs in AJAX batches (no timeouts), with a live log, pause/resume, and a dry-run mode.

Features

* De-duplication by source ID: re-run an import to skip or update records already imported.
* Downloads featured images and (optionally) images inside content into the Media Library, rewriting URLs. Images are de-duplicated by source URL.
* Hierarchical categories via "Parent > Child". Lists can be arrays or "a, b" / "a|b" / "a;b" strings.
* Authors matched by email, login, slug or display name; optionally created.
* Dates in any strtotime() format, ISO 8601 with offsets, or Unix timestamps (seconds or ms).
* Status values like published/draft/true/false/1/0 are understood.
* One-click rollback deletes every post and media item a job created (updated posts are left alone).
* Full WP-CLI support.

== Sources ==

* **CSV**: header row required; delimiter auto-detected; multi-line cells supported.
* **JSON**: URL or upload; records path auto-detected (items, data, posts, results...) or set as a dot path such as "data.posts". Optional request headers for auth. JSON Feed (jsonfeed.org) works out of the box.
* **WordPress**: any site with the REST API. Add a username + Application Password to bring over drafts/private posts and raw block markup. Set the REST base for pages or custom post types.
* **Drupal 8+**: requires the core JSON:API module. Files, media images, taxonomy terms and authors are resolved automatically.
* **Ghost**: requires a Content API key (published content only).

Nested fields are flattened with dots (e.g. "authors.0.name", "body.value").

== WP-CLI ==

    wp pressport preview csv --file=posts.csv
    wp pressport import csv --file=posts.csv --post_type=page --status=draft
    wp pressport import json --url=https://example.com/feed.json --map=mapping.json --content-images
    wp pressport import wordpress --site_url=https://old.example.com --username=me --app_password="xxxx xxxx" --duplicate=update
    wp pressport import drupal --site_url=https://drupal.example.com --bundle=article
    wp pressport import ghost --site_url=https://blog.example.com --api_key=KEY
    wp pressport import --config=examples/migration-config.json
    wp pressport jobs
    wp pressport resume <job-id>
    wp pressport rollback <job-id> --yes

Options: --post_type, --status, --author, --duplicate=skip|update|create, --batch_size, --[no-]featured-images, --content-images, --create-authors, --dry-run.

== Developer hooks ==

* `pressport_sources` (filter): register a custom source class implementing `PressPort_Source` (extend `PressPort_Source_Base`).
* `pressport_record` (filter): modify a record before import, or return false to skip it.
* `pressport_post_data` (filter): adjust the array passed to wp_insert_post()/wp_update_post().
* `pressport_after_import_record` (action): runs after each post is saved: ($post_id, $record, $job, $action).
* `pressport_capability` (filter): capability required to use the tool (default manage_options).

Imported posts carry the meta keys `_pressport_job_id`, `_pressport_source_id` and `_pressport_source_uid`.

== Notes ==

* Remote requests use wp_safe_remote_get(), which blocks local/private hosts and ports other than 80, 443 and 8080. To migrate from a local dev site, allow it with the `http_request_host_is_external` and `http_allowed_safe_ports` filters.
* The WordPress REST API returns category names without hierarchy, so parent/child relationships between categories are not recreated for that source.
* Uploaded and cached source files are stored in wp-content/uploads/pressport/ (web access denied) and removed when a job record is deleted or the plugin is uninstalled. Imported content is never removed on uninstall.

== Changelog ==

= 1.0.0 =
* Initial release.
