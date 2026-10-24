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

async function mappingStep({ savedMapping = '', routes = {}, prompt } = {}) {
  const env = boot({
    data: { mappings: [booksFeed] },
    prompt,
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
    assert.equal(env.$('.migrateplus-options select').value, 'page')

    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    const req = env.sent('save')[0]
    assert.deepEqual(json(req, 'mapping').fields, { post_title: 'title', post_name: 'slug' })
    assert.deepEqual(json(req, 'mapping').meta, [{ key: 'isbn', source: 'isbn' }])
    assert.equal(json(req, 'options').default_status, 'draft')
  })

  it('shows the error when saving at the cap', async () => {
    const cap = 'There are already 50 saved mappings, the most MigratePlus keeps. Delete one you no longer use, or save over an existing one.'
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

  it('renames through window.prompt', async () => {
    const env = await mappingStep({
      savedMapping: 'Books feed',
      prompt: ' Novels ',
      routes: { mapping_rename: { name: 'Novels', mappings: [{ ...booksFeed, name: 'Novels' }] } },
    })
    env.click(button(env, 'Rename'))
    await env.flush()
    assert.deepEqual(env.prompts, [['New name for “Books feed”:', 'Books feed']])
    assert.equal(env.sent('mapping_rename')[0].fields.new_name, 'Novels')
    assert.equal(notice(env).textContent, 'Renamed to “Novels”.')
  })
})
