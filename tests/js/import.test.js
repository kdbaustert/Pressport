import { describe, it, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { boot, job, json, fail, noNativeDialogs } from './helpers.js'

afterEach(noNativeDialogs)

// A MigratePlus export carries its own mapping and goes straight to a confirmation.
const exportPreview = {
  job: job({
    total: 15,
    settings: { file_name: 'books.json', migrateplus_site: 'https://old-books.test', migrateplus_post_type: 'post' },
    mapping: { fields: { post_title: 'title', post_content: 'content' } },
  }),
  records: [{ title: 'Dune', content: 'Arrakis…' }],
  fields: ['title', 'content'],
}

// Any other JSON goes to the mapping step.
const feedPreview = {
  job: job(),
  records: [
    { title: 'Dune', blurb: 'Arrakis…', isbn: '9780441013593' },
    { title: 'Emma', blurb: 'Highbury…', isbn: '9780141439587' },
  ],
  fields: ['title', 'blurb', 'isbn'],
}

const saved = { job: job({ status: 'running' }) }
const done = dry => ({
  done: true,
  job: job({
    status: 'complete',
    stats: { processed: 15, created: 12, updated: 0, skipped: 3, failed: 0 },
    created_total: 12,
    options: { ...job().options, dry_run: dry ? 1 : 0 },
  }),
})

const upload = (env, name = 'books.json') => env.chooseFile(env.$('#migrateplus-import-file'), env.file(name))

async function mappingStep(routes) {
  const env = boot({ routes: { preview: feedPreview, save: saved, ...routes } })
  upload(env)
  await env.flush()
  return env
}

describe('Import', () => {
  it('posts a chosen file to preview', async () => {
    const env = boot({ routes: { preview: feedPreview } })
    upload(env)
    assert.equal(env.modal.title(), 'Uploading books.json')
    await env.flush()
    const [req] = env.sent('preview')
    assert.equal(req.transport, 'xhr')
    assert.equal(req.fields.source, 'json')
    assert.equal(req.fields.nonce, 'abc123')
    assert.equal(req.body.get('file_file').name, 'books.json')
    assert.ok(env.$('.migrateplus-map-table'))
  })

  it('posts a dropped file to preview', async () => {
    const env = boot({ routes: { preview: feedPreview } })
    env.drop(env.$('.migrateplus-import-drop'), env.file('books.json'))
    await env.flush()
    assert.equal(env.sent('preview')[0].body.get('file_file').name, 'books.json')
  })

  const refused = [
    ['books.csv', {}, 'This file can’t be imported'],
    ['books.json', { maxUploadBytes: 1 }, 'This file is too large'],
  ]
  for (const [name, data, title] of refused) {
    it('refuses ' + name + ' with "' + title + '"', () => {
      const env = boot({ data })
      upload(env, name)
      assert.equal(env.modal.title(), title)
      assert.equal(env.requests.length, 0)
    })
  }

  it('confirms a MigratePlus export once, then imports it', async () => {
    const env = boot({
      routes: { preview: exportPreview, duplicates: { count: 0, total: 15 }, save: saved, run: done(false) },
    })
    upload(env)
    await env.flush()
    assert.equal(env.modal.title(), 'Import 15 items?')
    assert.match(env.modal.message(), /^Exported from old-books\.test\./)
    assert.deepEqual(env.modal.labels(), ['Review mapping', 'Cancel', 'Import'])

    env.click(env.modal.primary())
    await env.flush()
    assert.deepEqual(env.requests.map(r => r.action), ['preview', 'duplicates', 'save', 'run'])
    assert.equal(env.modal.title(), 'Import complete')
  })

  it('puts the duplicates choice into that one confirmation', async () => {
    const env = boot({
      routes: {
        preview: exportPreview,
        duplicates: { count: 3, total: 15, before: 3, existing: 0, examples: [{ title: 'Dune', post_id: 41 }] },
        save: saved,
        run: done(false),
      },
    })
    upload(env)
    await env.flush()
    assert.match(env.modal.message(), /3 of them are already on this site/)
    assert.match(env.modal.note(), /“Dune” \(#41\) and 2 more/)
    assert.deepEqual(env.modal.labels(), ['Import anyway', 'Review mapping', 'Cancel', 'Skip duplicates'])

    env.click(env.modal.button('Skip duplicates'))
    await env.flush()
    assert.equal(json(env.sent('save')[0], 'options').skip_existing, 1)
    assert.equal(env.sent('duplicates').length, 1)
  })

  it('Dry run skips existing items without asking', async () => {
    const env = await mappingStep({ run: done(true) })
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    assert.equal(env.sent('duplicates').length, 0)
    const opts = json(env.sent('save')[0], 'options')
    assert.equal(opts.dry_run, 1)
    assert.equal(opts.skip_existing, 1)
    assert.equal(env.modal.title(), 'Dry run complete')
  })

  it('Start import asks about duplicates the server reports', async () => {
    const env = await mappingStep({
      duplicates: { count: 1, total: 2, before: 1, existing: 0, examples: [{ title: 'Dune', post_id: 41 }] },
      run: done(false),
    })
    env.click(env.byText('button', 'Start import'))
    await env.flush()
    assert.equal(env.modal.title(), 'Some items are already here')
    assert.match(env.modal.message(), /^1 of 2 records is already on this site/)
    assert.equal(env.sent('save').length, 0)

    env.click(env.modal.button('Import anyway'))
    await env.flush()
    const opts = json(env.sent('save')[0], 'options')
    assert.equal(opts.skip_existing, 0)
    assert.equal(opts.dry_run, 0)
  })

  it('offers to import anyway when the duplicates check fails', async () => {
    const env = await mappingStep({ duplicates: fail('Error establishing a database connection'), run: done(false) })
    env.click(env.byText('button', 'Start import'))
    await env.flush()
    assert.equal(env.modal.title(), 'Could not check for duplicates')
    env.click(env.modal.button('Import anyway'))
    await env.flush()
    assert.equal(env.sent('save').length, 1)
  })

  it('retries a run that is busy in another tab after 3 seconds', async () => {
    const env = await mappingStep({
      run: (fields, calls) => (calls ? done(true) : fail('This import is already running in another tab.')),
    })
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    env.clock.tick(2999)
    await env.flush()
    assert.equal(env.sent('run').length, 1)
    env.clock.tick(1)
    await env.flush()
    assert.equal(env.sent('run').length, 2)
  })
})
