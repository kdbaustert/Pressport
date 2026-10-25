import { describe, it, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { boot, job, json, fail, noNativeDialogs } from './helpers.js'

afterEach(noNativeDialogs)

const booksFeed = {
  name: 'Books feed',
  mapping: {
    fields: { post_title: 'title', post_content: 'description', post_name: 'slug' },
    meta: [{ key: 'isbn', source: 'isbn' }],
  },
  options: { post_type: 'page', default_status: 'draft' },
}

async function mappingStep({ savedMapping = '', routes = {} } = {}) {
  const env = boot({
    data: { mappings: [booksFeed] },
    routes: {
      preview: {
        job: job({ mapping: { fields: {} } }),
        records: [{ title: 'Dune', slug: 'dune', isbn: '9780441013593' }],
        fields: ['title', 'slug', 'isbn'],
        savedMapping,
      },
      save: { job: job({ status: 'running' }) },
      ...routes,
    },
  })
  env.chooseFile(env.$('#migrateplus-import-file'), env.file('books.json'))
  await env.flush()
  return env
}

const notice = env => env.$('.migrateplus-saved .notice')
const button = (env, label) => env.byText('.migrateplus-saved button', label)

describe('Saved mappings', () => {
  it('preselects the mapping the server matched', async () => {
    const env = await mappingStep({ savedMapping: 'Books feed' })
    assert.equal(env.$('#migrateplus-saved-select').value, 'Books feed')
    assert.match(notice(env).textContent, /same fields as the saved mapping “Books feed”/)
  })

  it('Apply copies the mapping and options and lists dropped fields', async () => {
    const env = await mappingStep({ savedMapping: 'Books feed' })
    env.click(button(env, 'Apply'))
    assert.equal(notice(env).textContent, 'Applied “Books feed”. Not in this file, so left unmapped: description.')
    assert.equal(env.$('#migrateplus-opt-row-1').value, 'page')

    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    const req = env.sent('save')[0]
    assert.deepEqual(json(req, 'mapping').fields, { post_title: 'title', post_name: 'slug' })
    assert.deepEqual(json(req, 'mapping').meta, [{ key: 'isbn', source: 'isbn' }])
    assert.equal(json(req, 'options').default_status, 'draft')
  })

  it('neither saves nor applies the authors', async () => {
    const env = boot({
      // Saved by an older version, which kept them.
      data: { mappings: [{ ...booksFeed, options: { ...booksFeed.options, author: 2, authors: { 0: 2 } } }] },
      routes: {
        preview: { job: job({ mapping: { fields: {} } }), records: [{ title: 'Dune', slug: 'dune', isbn: '9780441013593' }], fields: ['title', 'slug', 'isbn'], savedMapping: 'Books feed' },
        records: { records: [{ index: 0, title: 'Dune', type: 'post' }], total: 1, matched: 1, truncated: false },
        mapping_save: { name: 'Library', mappings: [booksFeed] },
        save: { job: job({ status: 'running' }) },
      },
    })
    env.chooseFile(env.$('#migrateplus-import-file'), env.file('books.json'))
    await env.flush()
    env.choose(env.$('#migrateplus-opt-author'), '1')
    env.click(env.$('.migrateplus-authors-toggle'))
    await env.flush()
    env.choose(env.$('#migrateplus-author-0'), '1')
    env.click(button(env, 'Apply'))
    assert.equal(env.$('#migrateplus-opt-author').value, '1')
    assert.equal(env.$('#migrateplus-author-0').value, '1')

    env.type(env.$('#migrateplus-saved-name'), 'Library')
    env.click(button(env, 'Save mapping'))
    await env.flush()
    const kept = json(env.sent('mapping_save')[0], 'options')
    assert.equal('author' in kept, false)
    assert.equal('authors' in kept, false)

    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    const sent = json(env.sent('save')[0], 'options')
    assert.equal(sent.author, 1)
    assert.deepEqual(sent.authors, { 0: 1 })
  })

  it('shows the error when saving at the cap', async () => {
    const cap = 'There are already 50 saved mappings, the most Migrate Plus keeps. Delete one you no longer use, or save over an existing one.'
    const env = await mappingStep({ routes: { mapping_save: fail(cap) } })
    env.type(env.$('#migrateplus-saved-name'), 'Library')
    env.click(button(env, 'Save mapping'))
    await env.flush()
    assert.equal(env.sent('mapping_save')[0].fields.name, 'Library')
    assert.match(notice(env).className, /notice-error/)
    assert.equal(notice(env).textContent, cap)
  })

  it('asks before saving over an existing name', async () => {
    const env = await mappingStep({ routes: { mapping_save: { name: 'Books feed', mappings: [booksFeed] } } })
    env.type(env.$('#migrateplus-saved-name'), 'Books feed')
    env.click(button(env, 'Save mapping'))
    assert.equal(env.modal.title(), 'Replace saved mapping?')
    assert.equal(env.sent('mapping_save').length, 0)
    env.click(env.modal.primary())
    await env.flush()
    assert.equal(env.sent('mapping_save').length, 1)
  })

  it('renames in the modal', async () => {
    const env = await mappingStep({
      savedMapping: 'Books feed',
      routes: { mapping_rename: { name: 'Novels', mappings: [{ ...booksFeed, name: 'Novels' }] } },
    })
    env.click(button(env, 'Rename'))
    assert.equal(env.modal.message(), 'New name for “Books feed”:')
    const field = env.$('#migrateplus-modal-input')
    assert.equal(field.value, 'Books feed')
    assert.equal(env.document.activeElement, field)
    env.type(field, ' Novels ')
    env.click(env.modal.primary())
    await env.flush()
    assert.equal(env.sent('mapping_rename')[0].fields.new_name, 'Novels')
    assert.equal(notice(env).textContent, 'Renamed to “Novels”.')
  })

  it('leaves a removed custom field out after Import as changes', async () => {
    const env = boot({
      routes: {
        preview: { job: job({ mapping: { fields: { post_title: 'title' } } }), records: [{ title: 'Dune', 'meta.isbn': '9780441013593' }], fields: ['title', 'meta.isbn'] },
      },
    })
    env.chooseFile(env.$('#migrateplus-import-file'), env.file('books.json'))
    await env.flush()
    assert.equal(env.$$('.migrateplus-pair').length, 1)
    env.click(env.$('[aria-label="Remove custom field"]'))
    const importAs = env.$('#migrateplus-opt-row-1')
    env.choose(importAs, 'page')
    assert.equal(importAs.value, 'page')
    assert.equal(env.$$('.migrateplus-pair').length, 0)
  })

  it('shows a field name with markup in it as text', async () => {
    const hostile = '<img src=x onerror=alert(1)>'
    const env = boot({ routes: { preview: { job: job({ mapping: { fields: {} } }), records: [{ [hostile]: hostile }], fields: [hostile] } } })
    env.chooseFile(env.$('#migrateplus-import-file'), env.file('books.json'))
    await env.flush()
    assert.ok(env.byText('.migrateplus-preview th', hostile))
    assert.ok(env.byText('.migrateplus-map-table option', hostile))
    assert.equal(env.$$('img').length, 0)
  })

  it('fills the field lists of a very wide file when one is focused', async () => {
    const fields = Array.from({ length: 301 }, (v, i) => 'f' + i)
    const env = boot({
      routes: { preview: { job: job({ mapping: { fields: { post_title: 'f5' } } }), records: [{ f5: 'Dune' }], fields } },
    })
    env.chooseFile(env.$('#migrateplus-import-file'), env.file('wide.json'))
    await env.flush()
    const title = env.$('.migrateplus-map-table select')
    assert.deepEqual([...title.options].map(o => o.value), ['', 'f5'])
    assert.equal(title.value, 'f5')
    title.focus()
    assert.equal(title.options.length, 302)
    assert.equal(title.value, 'f5')
  })
})
