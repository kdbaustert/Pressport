import { describe, it, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { boot, json, later, fail, offline, noNativeDialogs } from './helpers.js'

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

  it('keeps Counting… when an older count lands while the next one waits out its delay', async () => {
    const first = later()
    const env = boot({
      data: { view: 'export' },
      routes: { export_count: (fields, calls) => (calls ? { count: 52, counts: { post: 40, page: 12 } } : first.promise) },
    })
    env.clock.tick(250)
    await env.flush()
    env.check(env.$('#migrateplus-export-status-draft'))
    first.resolve({ count: 0, counts: { post: 0 }, menus: 0 })
    await env.flush()
    assert.equal(env.$('.migrateplus-export-count').textContent, 'Counting…')
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

  it('enables Export again when a count fails after one that found nothing', async () => {
    const env = boot({
      data: { view: 'export' },
      routes: { export_count: (fields, calls) => (calls ? fail('Error establishing a database connection') : { count: 0, counts: { post: 0 } }) },
    })
    env.clock.tick(250)
    await env.flush()
    const submit = env.$('form.migrateplus-export [type=submit]')
    assert.equal(submit.disabled, true)
    env.check(env.$('#migrateplus-export-status-draft'))
    env.clock.tick(250)
    await env.flush()
    assert.equal(env.$('.migrateplus-export-count').textContent, 'Error establishing a database connection')
    assert.equal(submit.disabled, false)
  })

  it('retries a batch after a network failure, then offers Try again', async () => {
    const env = boot({
      data: { view: 'export' },
      routes: {
        export_count: { count: 52, counts: { post: 52 } },
        export_start: { id: 'exp1', total: 52 },
        export_run: (fields, calls) => (calls < 4 ? offline : { complete: true, done: 52, total: 52, size: '120 KB', filename: 'posts.json', url: 'https://example.test/dl/exp1' }),
      },
    })
    env.submit(env.$('form.migrateplus-export'))
    await env.flush()
    for (const wait of [2000, 4000, 8000]) {
      env.clock.tick(wait)
      await env.flush()
    }
    assert.equal(env.sent('export_run').length, 4)
    assert.equal(env.modal.title(), 'Export stopped')
    env.click(env.modal.button('Try again'))
    await env.flush()
    assert.equal(env.sent('export_run').length, 5)
    assert.equal(env.modal.title(), 'Export ready')
  })

  it('disables Export while Site settings has no section picked', async () => {
    const env = exportScreen()
    env.check(env.$('#migrateplus-export-feature-site'))
    const submit = env.$('form.migrateplus-export [type=submit]')
    assert.equal(submit.disabled, false)
    env.click(env.byText('.migrateplus-table-tools button', 'None'))
    assert.equal(submit.disabled, true)
    env.clock.tick(250)
    await env.flush()
    assert.equal(json(env.sent('export_count').at(-1), 'args').site_sections, '')
  })

  it('sends a toggle only while its card shows it', async () => {
    const env = exportScreen({ acf: { active: true, fields: {} } })
    assert.equal(env.$('#migrateplus-export-include_acf_options'), null)
    env.check(env.$('#migrateplus-export-include_term_data'))
    env.submit(env.$('form.migrateplus-export'))
    await env.flush()
    let args = json(env.sent('export_start')[0], 'args')
    assert.equal(args.include_acf_options, 0)
    assert.equal(args.include_acf_definitions, 1)
    assert.equal(args.include_term_data, 1)

    // Site settings alone: the post field toggles are gone, the options pages toggle is there.
    env.check(env.$('#migrateplus-export-feature-site'))
    env.check(env.$('#migrateplus-export-feature-content'), false)
    assert.equal(env.$('#migrateplus-export-include_term_data'), null)
    env.submit(env.$('form.migrateplus-export'))
    await env.flush()
    args = json(env.sent('export_start')[1], 'args')
    assert.equal(args.include_term_data, 0)
    assert.equal(args.include_acf_definitions, 0)
    assert.equal(args.include_meta, 0)
    assert.equal(args.include_acf_options, 1)
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

  const needle = { id: 7, title: 'Needle', type: 'page', label: 'Page', status: 'Draft', date: 'Oct 25, 2026' }
  const withPicks = () =>
    boot({
      data: { view: 'export' },
      routes: {
        export_posts: { posts: [needle] },
        export_count: { count: 1, counts: { page: 1 } },
        export_start: { id: 'exp1', total: 1 },
        export_run: { complete: true, done: 1, total: 1, size: '1 KB', filename: 'picks.json', url: 'https://example.test/dl/exp1' },
      },
    })

  it('keeps Searching… when an older search lands while the next one waits out its delay', async () => {
    const first = later()
    const env = boot({
      data: { view: 'export' },
      routes: { export_posts: (fields, calls) => (calls ? { posts: [needle] } : first.promise), export_count: { count: 1, counts: { page: 1 } } },
    })
    env.check(env.$('#migrateplus-export-feature-picks'))
    env.type(env.$('#migrateplus-export-pick-search'), 'hay')
    env.clock.tick(300)
    await env.flush()
    env.type(env.$('#migrateplus-export-pick-search'), 'needle')
    first.resolve({ posts: [{ ...needle, id: 8, title: 'Hay' }] })
    await env.flush()
    assert.equal(env.$('.migrateplus-pick-results .migrateplus-pick-title'), null)
    assert.ok(env.byText('p', 'Searching…'))
  })

  it('hand-picks posts by search and sends their ids', async () => {
    const env = withPicks()
    env.check(env.$('#migrateplus-export-feature-picks'))
    env.type(env.$('#migrateplus-export-pick-search'), 'needle')
    // The search is debounced by 300 ms.
    env.clock.tick(300)
    await env.flush()
    assert.equal(env.sent('export_posts').at(-1).fields.search, 'needle')
    assert.equal(env.$('.migrateplus-pick-results .migrateplus-pick-title').textContent, 'Needle')

    env.click(env.byText('.migrateplus-pick-results button', 'Add'))
    env.clock.tick(250)
    await env.flush()
    assert.equal(env.$('.migrateplus-pick-list .migrateplus-pick-title').textContent, 'Needle')
    const args = json(env.sent('export_count').at(-1), 'args')
    assert.deepEqual(args.post_ids, [7])
    assert.deepEqual(args.post_types, [])

    env.submit(env.$('form.migrateplus-export'))
    await env.flush()
    assert.deepEqual(json(env.sent('export_start')[0], 'args').post_ids, [7])
    assert.equal(env.modal.title(), 'Export ready')
  })

  it('clears the picks when a whole type is ticked, and the types when picking starts', async () => {
    const env = withPicks()
    env.check(env.$('#migrateplus-export-feature-picks'))
    env.type(env.$('#migrateplus-export-pick-search'), 'needle')
    env.clock.tick(300)
    await env.flush()
    env.click(env.byText('.migrateplus-pick-results button', 'Add'))
    env.clock.tick(250)
    await env.flush()
    assert.deepEqual(json(env.sent('export_count').at(-1), 'args').post_types, [])

    // Hand-picking closed the content card, so the user ticks the whole card again.
    env.check(env.$('#migrateplus-export-feature-content'))
    env.clock.tick(250)
    await env.flush()
    assert.equal(env.$('.migrateplus-pick-list'), null)
    const args = json(env.sent('export_count').at(-1), 'args')
    assert.deepEqual(args.post_ids, [])
    assert.deepEqual(args.post_types, ['post', 'page', 'attachment'])
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
    assert.deepEqual(titles(env), ['Hand-picked content', 'Posts, pages and media', 'Navigation', 'Custom post types', 'Site settings'])
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
    assert.deepEqual(titles(exportScreen()), ['Hand-picked content', 'Posts, pages and media', 'Navigation', 'Site settings'])
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

  it('offers only the toggles the ticked types can use', async () => {
    const env = boot({
      data: { view: 'export', postTypes: [
        { name: 'post', label: 'Post', plural: 'Posts', taxonomies: [{ name: 'category', label: 'Category' }], supports: { thumbnail: true, comments: true } },
        { name: 'book', label: 'Book', plural: 'Books', taxonomies: [], supports: { thumbnail: false, comments: false } },
      ] },
      routes: { export_count: { count: 3, counts: { post: 3 } } },
    })
    assert.ok(env.$('#migrateplus-export-include_terms'))
    assert.ok(env.$('#migrateplus-export-include_image'))
    assert.ok(env.$('#migrateplus-export-include_comments'))
    assert.equal(env.$('#migrateplus-export-include_acf'), null) // ACF is not active.

    // Books alone: no taxonomies, no featured images, no comments. The custom fields toggle stays.
    env.check(env.$('#migrateplus-export-feature-cpt'))
    env.check(env.$('#migrateplus-export-feature-content'), false)
    await env.flush()
    const card = env.$('#migrateplus-export-feature-cpt').closest('.migrateplus-export-card')
    assert.ok(card.querySelector('#migrateplus-export-include_meta'))
    assert.equal(env.$('#migrateplus-export-include_terms'), null)
    assert.equal(env.$('#migrateplus-export-include_image'), null)
    assert.equal(env.$('#migrateplus-export-include_comments'), null)
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

describe('Export WooCommerce and site settings', () => {
  const types = [
    { name: 'post', label: 'Post', plural: 'Posts', taxonomies: [] },
    { name: 'product', label: 'Product', plural: 'Products', taxonomies: [] },
    { name: 'shop_coupon', label: 'Coupon', plural: 'Coupons', taxonomies: [] },
  ]
  const shop = () =>
    boot({
      data: { view: 'export', postTypes: types, woo: { active: true }, menus: false, sides: { site: true, orders: true, yoast: false } },
      routes: { export_count: { count: 3, counts: { post: 3 }, sides: { orders: 2 } } },
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
    assert.equal(env.$('.migrateplus-export-count').textContent, '3 items, with 2 orders will be exported')
    assert.ok(env.$('#migrateplus-export-include_woo'))

    // A part can be dropped on its own.
    env.check(env.$('#migrateplus-export-type-shop_coupon'), false)
    env.clock.tick(250)
    await env.flush()
    args = json(env.sent('export_count').at(-1), 'args')
    assert.deepEqual(args.post_types, ['post', 'product'])
    assert.equal(card.querySelector('.migrateplus-export-card-count').textContent, '2 of 3')
  })

  it('exports the site settings alone when they are the only thing ticked', async () => {
    const env = boot({
      data: { view: 'export', postTypes: types, menus: false, sides: { site: true, orders: false, yoast: false } },
      routes: { export_count: (fields) => (JSON.parse(fields.args).post_types.length ? { count: 3, counts: { post: 3 } } : { count: 0, counts: {}, sides: { site_settings: 1 } }) },
    })
    env.check(env.$('#migrateplus-export-feature-site'))
    env.check(env.$('#migrateplus-export-feature-content'), false)
    env.clock.tick(250)
    await env.flush()
    const args = json(env.sent('export_count').at(-1), 'args')
    assert.deepEqual(args.post_types, [])
    assert.equal(args.include_site_settings, 1)
    assert.equal(env.$('.migrateplus-export-count').textContent, 'the site settings will be exported')
    assert.equal(env.$('form.migrateplus-export [type=submit]').disabled, false)
  })

  it('opens the sections of the site settings once that card is ticked', async () => {
    const env = boot({
      data: { view: 'export', postTypes: types, menus: false, sides: { site: true, orders: false, yoast: false }, siteSections: [
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

describe('Export date pickers', () => {
  const calendars = []
  const withPicker = () =>
    boot({
      data: { view: 'export' },
      routes: { export_count: { count: 52, counts: { post: 52 } } },
      setup(window) {
        calendars.length = 0
        window.VanillaCalendarPro = {
          Calendar: class {
            constructor(input, options) {
              this.input = input
              this.options = options
              this.context = { selectedDates: [] }
              this.sets = []
              calendars.push(this)
            }
            init() {
              this.inited = true
            }
            set(options) {
              this.sets.push(options)
            }
            hide() {
              this.hidden = true
            }
            destroy() {}
          },
        }
      },
    })

  it('binds a calendar to the From and To inputs', () => {
    const env = withPicker()
    assert.equal(calendars.length, 2)
    assert.equal(calendars[0].input, env.$('input[aria-label="From"]'))
    assert.equal(calendars[1].input, env.$('input[aria-label="To"]'))
    assert.ok(calendars.every(c => c.inited && c.options.inputMode === true))
  })

  it('a picked day shows as m/d/Y and reaches the count query as YYYY-MM-DD', async () => {
    const env = withPicker()
    const from = calendars[0]
    from.context.selectedDates = ['2024-01-05']
    from.options.onChangeToInput(from)
    env.clock.tick(250)
    await env.flush()
    assert.equal(env.$('input[aria-label="From"]').value, '01/05/2024')
    assert.equal(json(env.sent('export_count').at(-1), 'args').after, '2024-01-05')
    assert.ok(from.hidden)
    assert.equal(from.sets.at(-1).selectedDates.join(), '2024-01-05')
  })

  it('typing an m/d/Y date works without the calendar library', async () => {
    const env = exportScreen()
    const from = env.$('input[aria-label="From"]')
    env.type(from, '1/15/2024')
    env.clock.tick(250)
    await env.flush()
    assert.equal(json(env.sent('export_count').at(-1), 'args').after, '2024-01-15')

    env.type(from, '1/15/202')
    env.clock.tick(250)
    await env.flush()
    assert.equal(from.value, '1/15/202')
    assert.equal(json(env.sent('export_count').at(-1), 'args').after, '2024-01-15')
  })
})
