import { describe, it, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { boot, json, later, noNativeDialogs } from './helpers.js'

afterEach(noNativeDialogs)

const exportScreen = (data = {}) =>
  boot({
    data: { view: 'export', ...data },
    routes: {
      export_count: { count: 52, counts: { post: 40, page: 12 } },
      export_start: { id: 'exp1', total: 52 },
      export_run: { complete: true, done: 52, total: 52, size: '120 KB', filename: 'books-2026-10-24.json', url: 'https://example.test/dl/exp1' },
    },
  })

describe('Export', () => {
  it('sends every ticked post type', async () => {
    const env = exportScreen()
    env.check(env.$('#migrateplus-export-type-page'))
    // The count is debounced by 250 ms.
    env.clock.tick(250)
    await env.flush()
    assert.deepEqual(json(env.sent('export_count').at(-1), 'args').post_types, ['post', 'page'])
    assert.equal(env.$('.migrateplus-export-count').textContent, '52 items (40 posts, 12 pages) will be exported')

    env.submit(env.$('form.migrateplus-export'))
    await env.flush()
    assert.deepEqual(json(env.sent('export_start')[0], 'args').post_types, ['post', 'page'])
    assert.equal(env.modal.title(), 'Export ready')
    assert.equal(env.modal.primary().getAttribute('href'), 'https://example.test/dl/exp1')
  })

  it('sends one count per change', async () => {
    const env = exportScreen()
    env.check(env.$('#migrateplus-export-type-page'))
    env.clock.tick(250)
    await env.flush()
    assert.equal(env.sent('export_count').length, 1)
  })

  for (const [flag, id] of [['zlib', 'gzip']]) {
    it('shows the ' + id + ' toggle only when ' + flag + ' is true', () => {
      assert.ok(exportScreen({ [flag]: true }).$('#migrateplus-export-' + id))
      assert.equal(exportScreen({ [flag]: false }).$('#migrateplus-export-' + id), null)
    })
  }

  it('ignores a count that arrives after a newer one', async () => {
    const first = later()
    const env = boot({
      data: { view: 'export' },
      routes: { export_count: (fields, calls) => (calls ? { count: 52, counts: { post: 40, page: 12 } } : first.promise) },
    })
    env.clock.tick(250)
    await env.flush()
    env.check(env.$('#migrateplus-export-status-draft'))
    env.clock.tick(250)
    await env.flush()
    first.resolve({ count: 0, counts: { post: 0 }, menus: 0 })
    await env.flush()
    assert.equal(env.$('.migrateplus-export-count').textContent, '52 items (40 posts, 12 pages) will be exported')
    assert.equal(env.$('form.migrateplus-export [type=submit]').disabled, false)
  })

  it('offers only the types the server says can be exported', () => {
    const env = exportScreen({
      postTypes: [
        { name: 'post', label: 'Post', plural: 'Posts', taxonomies: [], export: true },
        { name: 'acf-field-group', label: 'Field Group', plural: 'Field Groups', taxonomies: [], export: false },
      ],
    })
    assert.ok(env.$('#migrateplus-export-type-post'))
    assert.equal(env.$('#migrateplus-export-type-acf-field-group'), null)
  })

  it('warns before leaving the page while the export runs', async () => {
    const run = later()
    const env = boot({
      data: { view: 'export' },
      routes: { export_count: { count: 52, counts: { post: 52 } }, export_start: { id: 'exp1', total: 52 }, export_run: run.promise },
    })
    env.submit(env.$('form.migrateplus-export'))
    await env.flush()
    assert.equal(env.leaveWarns(), true)
    run.resolve({ complete: true, done: 52, total: 52, size: '120 KB', filename: 'posts.json', url: 'https://example.test/dl/exp1' })
    await env.flush()
    assert.equal(env.modal.title(), 'Export ready')
    assert.equal(env.leaveWarns(), false)
  })
})

describe('Export custom post types', () => {
  const cpt = { name: 'book', label: 'Book', plural: 'Books', taxonomies: [] }
  const withCpt = () =>
    boot({
      data: { view: 'export', postTypes: [
        { name: 'post', label: 'Post', plural: 'Posts', taxonomies: [] },
        { name: 'page', label: 'Page', plural: 'Pages', taxonomies: [] },
        { name: 'attachment', label: 'Media', plural: 'Media', taxonomies: [] },
        cpt,
      ] },
      routes: { export_count: { count: 3, counts: { post: 3 } } },
    })

  const titles = env => env.$$('.migrateplus-export-card-title').map(n => n.textContent)

  it('get a card of their own, closed until it is ticked', async () => {
    const env = withCpt()
    assert.deepEqual(titles(env), ['Posts, pages and media', 'Navigation', 'Custom post types', 'Users', 'Site settings'])
    assert.equal(env.$('#migrateplus-export-type-book'), null)

    env.check(env.$('#migrateplus-export-feature-cpt'))
    env.clock.tick(250)
    await env.flush()
    assert.ok(env.$('#migrateplus-export-type-book').checked)
    assert.ok(env.$('#migrateplus-export-feature-cpt').closest('.migrateplus-export-card').classList.contains('is-on'))
    assert.deepEqual(json(env.sent('export_count').at(-1), 'args').post_types, ['post', 'book'])
  })

  it('shows how much of a card is ticked, and keeps the status filter in the content card', async () => {
    const env = withCpt()
    const content = env.$('#migrateplus-export-feature-content')
    const count = () => content.closest('.migrateplus-export-card').querySelector('.migrateplus-export-card-count').textContent
    assert.equal(content.checked, true)
    assert.equal(count(), '1 of 3')
    assert.ok(content.closest('.migrateplus-export-card').querySelector('#migrateplus-export-status-draft'))
    env.check(env.$('#migrateplus-export-type-page'))
    await env.flush()
    assert.equal(count(), '2 of 3')
  })

  it('has no Custom post types card on a site without them', () => {
    assert.deepEqual(titles(exportScreen()), ['Posts, pages and media', 'Navigation', 'Users', 'Site settings'])
  })

  it('puts classic menus on the Navigation card, not among the Include toggles', async () => {
    const env = exportScreen()
    assert.equal(env.$('#migrateplus-export-include_menus'), null)
    env.check(env.$('#migrateplus-export-feature-navigation'))
    env.clock.tick(250)
    await env.flush()
    assert.equal(json(env.sent('export_count').at(-1), 'args').include_menus, 1)
    assert.equal(exportScreen({ menus: false }).$('#migrateplus-export-feature-navigation'), null)
  })

  it('shows the Include toggles only with the cards they belong to', async () => {
    const env = exportScreen()
    assert.ok(env.$('#migrateplus-export-include_terms'))
    env.check(env.$('#migrateplus-export-feature-content'), false)
    await env.flush()
    assert.equal(env.$('#migrateplus-export-include_terms'), null)
    assert.ok(env.$('#migrateplus-export-gzip'))
  })

  it('lets every card be unticked, then says so and disables Export', async () => {
    const env = exportScreen()
    env.check(env.$('#migrateplus-export-feature-content'), false)
    env.clock.tick(250)
    await env.flush()
    assert.equal(env.$('#migrateplus-export-feature-content').checked, false)
    assert.equal(env.$('.migrateplus-export-count').textContent, 'Nothing to export yet. Select at least one card to export.')
    assert.equal(env.$('form.migrateplus-export [type=submit]').disabled, true)
    assert.equal(env.sent('export_count').length, 0)
  })
})

describe('Export WooCommerce, users and site settings', () => {
  const types = [
    { name: 'post', label: 'Post', plural: 'Posts', taxonomies: [] },
    { name: 'product', label: 'Product', plural: 'Products', taxonomies: [] },
    { name: 'shop_coupon', label: 'Coupon', plural: 'Coupons', taxonomies: [] },
  ]
  const shop = () =>
    boot({
      data: { view: 'export', postTypes: types, woo: { active: true }, menus: false, sides: { users: true, site: true, orders: true, yoast: false } },
      routes: { export_count: { count: 3, counts: { post: 3 }, sides: { users: 7, orders: 2 } } },
    })

  it('puts products, coupons and orders on the WooCommerce card', async () => {
    const env = shop()
    const card = env.$('#migrateplus-export-feature-woo').closest('.migrateplus-export-card')
    assert.equal(card.querySelector('.migrateplus-export-card-count').textContent, '3 types')
    assert.equal(env.byText('.migrateplus-export-card-title', 'Custom post types'), undefined)

    env.check(env.$('#migrateplus-export-feature-woo'))
    env.clock.tick(250)
    await env.flush()
    assert.deepEqual([...card.querySelectorAll('.migrateplus-check-chip span')].map(n => n.textContent), ['Products', 'Coupons', 'Orders'])
    let args = json(env.sent('export_count').at(-1), 'args')
    assert.equal(args.include_orders, 1)
    assert.deepEqual(args.post_types, ['post', 'product', 'shop_coupon'])
    assert.equal(env.$('.migrateplus-export-count').textContent, '3 items, with 7 users and 2 orders will be exported')
    assert.ok(env.$('#migrateplus-export-include_woo'))

    // A part can be dropped on its own.
    env.check(env.$('#migrateplus-export-type-shop_coupon'), false)
    env.clock.tick(250)
    await env.flush()
    args = json(env.sent('export_count').at(-1), 'args')
    assert.deepEqual(args.post_types, ['post', 'product'])
    assert.equal(card.querySelector('.migrateplus-export-card-count').textContent, '2 of 3')
  })

  it('exports users alone when they are the only thing ticked', async () => {
    const env = boot({
      data: { view: 'export', postTypes: types, menus: false, sides: { users: true, site: false, orders: false, yoast: false } },
      routes: { export_count: (fields) => (JSON.parse(fields.args).post_types.length ? { count: 3, counts: { post: 3 } } : { count: 0, counts: {}, sides: { users: 7 } }) },
    })
    env.check(env.$('#migrateplus-export-feature-users'))
    env.check(env.$('#migrateplus-export-feature-content'), false)
    env.clock.tick(250)
    await env.flush()
    const args = json(env.sent('export_count').at(-1), 'args')
    assert.deepEqual(args.post_types, [])
    assert.equal(args.include_users, 1)
    assert.equal(env.$('.migrateplus-export-count').textContent, '7 users will be exported')
    assert.equal(env.$('form.migrateplus-export [type=submit]').disabled, false)
  })

  it('opens the sections of the site settings once that card is ticked', async () => {
    const env = boot({
      data: { view: 'export', postTypes: types, menus: false, sides: { users: false, site: true, orders: false, yoast: false }, siteSections: [
        { key: 'general', label: 'General', help: 'Title and tagline' },
        { key: 'reading', label: 'Reading', help: 'Front page' },
      ] },
      routes: { export_count: { count: 3, counts: { post: 3 } } },
    })
    assert.equal(env.$('#migrateplus-export-site-general'), null)
    env.check(env.$('#migrateplus-export-feature-site'))
    await env.flush()
    assert.ok(env.$('#migrateplus-export-site-general').checked)
    assert.equal(env.$('.migrateplus-export-parts .migrateplus-table-tools .migrateplus-help').textContent, '2 of 2 sections')

    env.check(env.$('#migrateplus-export-site-reading'), false)
    env.clock.tick(250)
    await env.flush()
    const args = json(env.sent('export_count').at(-1), 'args')
    assert.equal(args.include_site_settings, 1)
    assert.deepEqual(args.site_sections, ['general'])
    assert.equal(env.$('.migrateplus-export-parts .migrateplus-table-tools .migrateplus-help').textContent, '1 of 2 sections')
  })
})
