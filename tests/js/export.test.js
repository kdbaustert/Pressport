import { describe, it, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { boot, json, noNativeDialogs } from './helpers.js'

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

  for (const [flag, id] of [['zlib', 'gzip'], ['menus', 'include_menus']]) {
    it('shows the ' + id + ' toggle only when ' + flag + ' is true', () => {
      assert.ok(exportScreen({ [flag]: true }).$('#migrateplus-export-' + id))
      assert.equal(exportScreen({ [flag]: false }).$('#migrateplus-export-' + id), null)
    })
  }
})
