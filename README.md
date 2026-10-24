# PressPort

A WordPress plugin for moving content into WordPress from CSV files, JSON feeds, RSS/Atom feeds, WordPress export files, any XML/JSON/CSV URL, or another CMS (WordPress, Drupal), and for exporting it again in the same formats.

You map the source fields to WordPress fields, do a dry run, and then import in batches. Every import is logged and can be rolled back.

## Features

- **Five sources**: CSV (upload or remote URL), JSON feed or file, any URL or feed (RSS/Atom, WordPress export, XML, JSON, CSV), WordPress (REST API), Drupal 8+ (JSON:API). You can add more with a filter.
- **Field mapping with a preview** of real records. Map title, content, excerpt, date, slug, status, author, categories, tags, featured image, custom fields, custom taxonomies and **ACF fields**.
- **Batched imports** over AJAX, so large imports don't hit PHP timeouts. Includes a progress window with live counts and time remaining, pause/resume and a live log.
- **Dry runs** that show what would be created or updated without saving anything.
- **Safe re-runs**: records are tracked by their source ID, so running an import again can skip or update existing posts instead of duplicating them.
- **Media import**: downloads featured images and, optionally, images inside content, then rewrites the URLs. Each image is downloaded only once.
- **Rollback**: removes the posts and media an import created and leaves updated posts untouched.
- **ACF support**: every field type is converted properly. Image, file and gallery URLs are downloaded; relationships, post objects, taxonomy and user fields are looked up; dates, choices, links and maps are normalised; repeaters, groups and flexible content are rebuilt from nested data or JSON.
- **WooCommerce support**: export and re-import products through WooCommerce's own API, including prices, stock, tax and shipping settings, attributes, downloads and the variations of variable products. Galleries travel as URLs and related products travel as SKUs.
- **Export** posts, pages or any post type as JSON, CSV or XML (WordPress WXR) with terms, featured images, custom fields, ACF fields and WooCommerce product data. JSON exports carry their own mapping, so importing them on another site needs no setup.
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
| **PressPort → Import** | Choose a source, map fields, set options, dry run, import. |
| **PressPort → Export** | Export content as JSON, CSV or XML, with terms, featured images, custom fields, ACF fields and WooCommerce product data. |
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
| CSV | Upload a file or import it from a URL. Header row required. Delimiter is auto-detected. Multi-line cells work. Google Sheets, Google Drive, Dropbox and GitHub share links are turned into direct downloads; the file is re-downloaded when you run the import again. Remote files are limited by **Remote file size limit** in Settings (default 100 MB). |
| JSON | URL or upload. The record list is found automatically, or set a dot path like `data.posts`. Supports custom request headers. Handles [JSON Feed](https://jsonfeed.org) as-is. |
| URL or feed | Any URL (or uploaded file): RSS 2.0, RSS 1.0 and Atom feeds, WordPress export (WXR) files, any XML, JSON or CSV file or API endpoint. The format is detected automatically. Feeds are normalized to `id`, `title`, `content`, `excerpt`, `date`, `author`, `categories` and `image`, so the mapping is filled in for you. WXR files also bring slugs, statuses, tags, featured images (`image`), custom taxonomies (`terms.<taxonomy>`) and post meta (`meta.<key>`); choose which post types to include. For other XML, set an element path such as `catalog.product` or let PressPort find the repeating element. Supports request headers for APIs that need a token. |
| WordPress | Any site with the REST API. Add a username and [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) for drafts, private posts and raw block markup. Use the REST base for pages or custom post types. |
| Drupal 8+ | Requires the core JSON:API module. Images, media, terms and authors are resolved from `included`. |

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

# Everything from one file (see examples/migration-config.json)
wp pressport import --config=migration.json

# Manage imports
wp pressport jobs
wp pressport resume <job-id>
wp pressport rollback <job-id> --yes

# Export (json, csv or xml)
wp pressport export page --format=json --status=publish,draft --output=pages.json
wp pressport export product --format=csv --after=2024-01-01 --no-meta
```

Options: `--post_type`, `--status`, `--author`, `--duplicate=skip|update|create`, `--batch_size`, `--[no-]featured-images`, `--content-images`, `--create-authors`, `--dry-run`. Any option you leave out uses the value from PressPort → Settings.

JSON feeds use `--feed` because `--url` is reserved by WP-CLI. For CSV and the URL source, `--file` accepts a local path or an `http(s)://` URL, e.g. `wp pressport import csv --file=https://example.com/posts.csv` or `wp pressport import remote --file=https://blog.example.com/feed/`.

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
  "taxonomies": [{ "taxonomy": "genre", "source": "genres" }],
  "acf": { "hero_image": "image_url", "ingredients": "recipe.ingredients" }
}
```

ACF fields are keyed by field name or key (`field_…`). A source can point at nested data (`recipe.ingredients` collects `recipe.ingredients.0.name`, `recipe.ingredients.1.name`, …) for repeaters, groups, galleries and relationships.

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

// Rewrite a remote file URL before it's downloaded (e.g. sign it or add a token)
add_filter( 'pressport_remote_file_url', function ( $direct_url, $original_url ) {
	return $direct_url;
}, 10, 2 );

// Custom sources get "Upload file / From URL" on any 'file' setting automatically.
// Opt out with 'remote' => false in the settings schema.
```

## ACF fields

When Advanced Custom Fields (free or Pro) is active, the mapping step lists the ACF fields assigned to the chosen post type, grouped by field group, with suggestions for `acf.<name>`, `meta.<name>` or `<name>` source fields. Values are converted per field type:

| Field type | Accepts |
| --- | --- |
| Image, File | URL (downloaded into the Media Library), a local attachment ID, or `{ "url": … }` |
| Gallery | A list of URLs or IDs, or `"a.jpg\|b.jpg"` |
| Relationship, Post object, Page link | IDs, slugs, titles, URLs, or source IDs of posts imported earlier with PressPort |
| Taxonomy | Term names (`Parent > Child`) or IDs; missing terms are created when allowed |
| User | Email, login or ID |
| Select, Checkbox, Radio, Button group | Choice values or labels |
| True / false | `1`, `true`, `yes`, `on` |
| Date, Date time, Time | Any date format |
| Link | URL or `{ url, title, target }` |
| Google Map | `"lat, lng"` or an object |
| Repeater, Group, Flexible content | Nested data or JSON; rows use sub field names (flexible content rows need `acf_fc_layout`) |

A custom field (post meta) row whose key matches an ACF field is saved through ACF too, so the field reference is set correctly. Adjust any value with the `pressport_acf_value` filter.

## Export

**PressPort → Export** writes posts, pages or any post type to a file you can import with PressPort on another site:

- **JSON** (recommended) carries a field mapping, so the import maps everything automatically.
- **CSV** opens in a spreadsheet. Lists are joined with `|`; nested ACF data is stored as JSON in the cell.
- **XML** is a standard WordPress export (WXR 1.2). WordPress's own importer reads it too; PressPort also reads its extra `pressport:` elements (ACF values and full term paths).

Each item includes the core fields, the author's email, terms as `Parent > Child` paths, the featured image URL and alt text, public custom fields, and ACF values in a portable form: images as URLs, related posts as `{ id, slug, title }` references, terms as names and users as emails. Because the original IDs become source IDs, import related content first (or run the import twice) and relationships link up. Exports run in batches with a progress window and the download link stays valid for 24 hours. Filter items with `pressport_export_item`.

Imported posts are tagged with `_pressport_job_id`, `_pressport_source_id` and `_pressport_source_uid`.

## WooCommerce

When WooCommerce is active, exporting the **product** type also carries the fields WooCommerce keeps in protected meta, which a plain export leaves out: SKU, regular and sale price (with sale dates), tax status and class, stock, weight and dimensions, shipping class, the virtual, downloadable, featured and catalog-visibility settings, downloadable files, and up-sells, cross-sells and grouped children (as SKUs). Attributes travel with their term names, and a variable product brings its variations, each with its own SKU, price, stock and attribute values. A **WooCommerce product data** switch on the Export page turns this off; on the command line it is `--no-woo`.

Import the file on another site as the **Products** type, with WooCommerce active, and the products are rebuilt through WooCommerce's own API, so prices, stock and attributes end up where WooCommerce expects them. Galleries and variation images travel as URLs and are downloaded when image import is on. Related products are matched by SKU, so importing the catalogue a second time links up anything that was missing the first time. Missing global attributes and their terms are created when **Create missing terms** is on; a variation that already exists (by SKU, or by its attribute combination) is updated rather than duplicated. If WooCommerce is not active on the destination, the product fields are skipped with a warning.

## Notes

- To migrate from a local or LAN site, turn on **Local sources** in Settings. WordPress blocks those addresses by default.
- The WordPress REST API doesn't include category parents, so category hierarchy isn't recreated for that source.
- Uploaded and cached source files live in `wp-content/uploads/pressport/` (web access denied). They are removed when their history entry is deleted or the plugin is uninstalled.
- Uninstalling removes settings, history and cached files. Imported content is always kept.

## License

GPL-2.0-or-later
