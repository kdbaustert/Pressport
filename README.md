# PressPort

A WordPress plugin for moving content into WordPress from CSV files, JSON feeds, or another CMS (WordPress, Drupal, Ghost).

You map the source fields to WordPress fields, do a dry run, and then import in batches. Every import is logged and can be rolled back.

## Features

- **Five sources**: CSV upload, JSON feed or file, WordPress (REST API), Drupal 8+ (JSON:API), Ghost (Content API). You can add more with a filter.
- **Field mapping with a preview** of real records. Map title, content, excerpt, date, slug, status, author, categories, tags, featured image, custom fields and custom taxonomies.
- **Batched imports** over AJAX, so large imports don't hit PHP timeouts. Includes pause/resume and a live log.
- **Dry runs** that show what would be created or updated without saving anything.
- **Safe re-runs**: records are tracked by their source ID, so running an import again can skip or update existing posts instead of duplicating them.
- **Media import**: downloads featured images and, optionally, images inside content, then rewrites the URLs. Each image is downloaded only once.
- **Rollback**: removes the posts and media an import created and leaves updated posts untouched.
- **WP-CLI** commands for big or scripted migrations.
- **Settings page** with default import options, access control, connection options and history cleanup.

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

Clone or download into `wp-content/plugins/`:

```bash
cd wp-content/plugins
git clone https://github.com/kdbaustert/pressport.git
```

Then activate **PressPort** under Plugins. A new **PressPort** menu appears in wp-admin.

## Admin pages

| Page | What it's for |
| --- | --- |
| **PressPort → New Import** | Choose a source, map fields, set options, dry run, import. |
| **PressPort → History** | All past imports: view logs, resume, run again, roll back, or delete the record. |
| **PressPort → Settings** | Defaults and plugin-wide options (administrators only). |

### Settings

| Section | Options |
| --- | --- |
| Import defaults | Post type, status, author, handling of previously imported records, batch size, image downloads, creating missing terms and authors |
| Access | Administrators only, or Editors and Administrators |
| Connections | Request timeout; allow local/private network sources and non-standard ports (for migrating from a local dev site) |
| Housekeeping | Delete history after N days; remove tracking meta from imported posts on uninstall |

The settings page also includes a quick server check: upload folder, upload limit, PHP time and memory limits, and image support.

## Sources

| Source | Notes |
| --- | --- |
| CSV | Header row required. Delimiter is auto-detected. Multi-line cells work. |
| JSON | URL or upload. The record list is found automatically, or set a dot path like `data.posts`. Supports custom request headers. Handles [JSON Feed](https://jsonfeed.org) as-is. |
| WordPress | Any site with the REST API. Add a username and [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) for drafts, private posts and raw block markup. Use the REST base for pages or custom post types. |
| Drupal 8+ | Requires the core JSON:API module. Images, media, terms and authors are resolved from `included`. |
| Ghost | Requires a Content API key. Published content only. |

Nested fields are flattened with dots, for example `authors.0.name` or `body.value`.

## WP-CLI

```bash
# See fields and the suggested mapping
wp pressport preview csv --file=posts.csv

# Import
wp pressport import csv --file=posts.csv --post_type=page --status=draft
wp pressport import json --feed=https://example.com/feed.json --map=mapping.json --content-images
wp pressport import wordpress --site_url=https://old.example.com --username=me --app_password="xxxx xxxx" --duplicate=update
wp pressport import drupal --site_url=https://drupal.example.com --bundle=article
wp pressport import ghost --site_url=https://blog.example.com --api_key=KEY

# Everything from one file (see examples/migration-config.json)
wp pressport import --config=migration.json

# Manage imports
wp pressport jobs
wp pressport resume <job-id>
wp pressport rollback <job-id> --yes
```

Options: `--post_type`, `--status`, `--author`, `--duplicate=skip|update|create`, `--batch_size`, `--[no-]featured-images`, `--content-images`, `--create-authors`, `--dry-run`. Any option you leave out uses the value from PressPort → Settings.

JSON feeds use `--feed` because `--url` is reserved by WP-CLI.

### Mapping file

```json
{
  "fields": {
    "source_id": "id",
    "post_title": "title",
    "post_content": "content_html",
    "post_date": "date_published",
    "tags": "tags",
    "featured_image": "image"
  },
  "meta": [{ "key": "original_url", "source": "url" }],
  "taxonomies": [{ "taxonomy": "genre", "source": "genres" }]
}
```

Available fields: `source_id`, `post_title`, `post_content`, `post_excerpt`, `post_date`, `post_name`, `post_status`, `post_author`, `menu_order`, `comment_status`, `categories`, `tags`, `featured_image`, `featured_image_alt`.

## Hooks

```php
// Add a source (implement PressPort_Source or extend PressPort_Source_Base)
add_filter( 'pressport_sources', function ( $sources ) {
	$sources['my_cms'] = 'My_CMS_Source';
	return $sources;
} );

// Change or skip a record before it's imported
add_filter( 'pressport_record', function ( $record, $job ) {
	if ( empty( $record['title'] ) ) {
		return false;
	}
	return $record;
}, 10, 2 );

// Adjust the wp_insert_post() / wp_update_post() args
add_filter( 'pressport_post_data', function ( $postarr, $record, $job ) {
	return $postarr;
}, 10, 3 );

// After each post is saved ($action is "created" or "updated")
add_action( 'pressport_after_import_record', function ( $post_id, $record, $job, $action ) {}, 10, 4 );

// Override the capability needed to run imports
add_filter( 'pressport_capability', fn () => 'edit_pages' );
```

Imported posts are tagged with `_pressport_job_id`, `_pressport_source_id` and `_pressport_source_uid`.

## Notes

- To migrate from a local or LAN site, turn on **Local sources** in Settings. WordPress blocks those addresses by default.
- The WordPress REST API doesn't include category parents, so category hierarchy isn't recreated for that source.
- Uploaded and cached source files live in `wp-content/uploads/pressport/` (web access denied). They are removed when their history entry is deleted or the plugin is uninstalled.
- Uninstalling removes settings, history and cached files. Imported content is always kept.

## License

GPL-2.0-or-later
