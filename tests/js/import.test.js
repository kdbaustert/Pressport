import { describe, it, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { boot, job, json, fail, offline, hang, later, noNativeDialogs } from './helpers.js'

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
    assert.deepEqual(env.modal.labels(), ['Cancel', 'Import'])

    env.click(env.modal.primary())
    await env.flush()
    assert.deepEqual(env.requests.map(r => r.action), ['preview', 'duplicates', 'save', 'run'])
    assert.equal(json(env.sent('save')[0], 'options').author, 0)
    assert.equal(env.modal.isOpen(), false)
    assert.equal(env.$('.migrateplus-run h2').textContent, 'Import complete')
  })

  it('imports a file of several post types with the ACF fields of every type', async () => {
    const multiPreview = {
      job: job({
        total: 15,
        settings: { file_name: 'site.json', migrateplus_site: 'https://old.test', migrateplus_post_types: 'attachment,page,post' },
        mapping: { fields: { post_title: 'title', post_type: 'post_type' }, acf: { page_field: 'a', post_field: 'b' } },
        sections: [
          { type: 'attachment', label: 'Media', done: 0, total: 5 },
          { type: 'page', label: 'Pages', done: 0, total: 4 },
          { type: 'post', label: 'Posts', done: 0, total: 6 },
        ],
      }),
      records: [{ title: 'Dune', post_type: 'post' }],
      fields: ['title', 'post_type'],
    }
    const env = boot({ routes: { preview: multiPreview, duplicates: { count: 0, total: 15 }, save: saved, run: done(true) } })
    upload(env, 'site.json')
    await env.flush()
    assert.match(env.modal.message(), /Media, Pages and Posts are imported in that order/)
    assert.deepEqual(env.modal.labels(), ['Cancel', 'Import'])

    env.click(env.modal.primary())
    await env.flush()
    const req = json(env.sent('save')[0], 'mapping')
    assert.deepEqual(req.fields, { post_title: 'title', post_type: 'post_type' })
    assert.deepEqual(req.acf, { page_field: 'a', post_field: 'b' })
  })

  it('asks for no author in that confirmation, leaving the posts to the person importing', async () => {
    const env = boot({ routes: { preview: exportPreview, duplicates: { count: 0, total: 15 }, save: saved, run: done(false) } })
    upload(env)
    await env.flush()
    assert.equal(env.$('.migrateplus-modal select'), null)

    env.click(env.modal.primary())
    await env.flush()
    assert.equal(json(env.sent('save')[0], 'options').author, 0)
  })

  it('runs without an author picked, leaving the posts to the person importing', async () => {
    const env = await mappingStep({ run: done(true) })
    const picker = env.$('#migrateplus-opt-author')
    assert.equal(env.$('label[for="migrateplus-opt-author"]').textContent, 'Author for all posts and pages')
    assert.equal(picker.value, '')
    assert.equal(picker.options[0].textContent, 'You (the person importing)')
    assert.equal(env.$('.migrateplus-actionbar-status').textContent, '1 of 3 fields mapped')
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    assert.equal(json(env.sent('save')[0], 'options').author, 0)
  })

  it('sends the author picked for all', async () => {
    const env = await mappingStep({ run: done(true) })
    env.choose(env.$('#migrateplus-opt-author'), '2')
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    assert.equal(json(env.sent('save')[0], 'options').author, 2)
  })

  it('Dry run skips existing items without asking', async () => {
    const env = await mappingStep({ run: done(true) })
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    assert.equal(env.sent('duplicates').length, 0)
    const opts = json(env.sent('save')[0], 'options')
    assert.equal(opts.dry_run, 1)
    assert.equal(opts.skip_existing, 1)
    assert.equal(env.modal.isOpen(), false)
    assert.equal(env.$('.migrateplus-run h2').textContent, 'Dry run complete. Nothing was saved.')
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
      run: (fields, calls) => (calls ? done(true) : fail('A batch for this job is already running.', 'migrateplus_locked')),
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

  it('stops on an error that only mentions "already running" in its message', async () => {
    const env = await mappingStep({ run: (fields, calls) => (calls ? done(true) : fail('Upload failed: already running out of disk space.')) })
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    env.clock.tick(3000)
    await env.flush()
    assert.equal(env.sent('run').length, 1)
    assert.equal(env.modal.title(), 'Import stopped')
  })

  it('retries a run after a network failure', async () => {
    const env = await mappingStep({ run: (fields, calls) => (calls ? done(true) : offline) })
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    env.clock.tick(1999)
    await env.flush()
    assert.equal(env.sent('run').length, 1)
    env.clock.tick(1)
    await env.flush()
    assert.equal(env.sent('run').length, 2)
    assert.equal(env.modal.isOpen(), false)
    assert.equal(env.$('.migrateplus-run h2').textContent, 'Dry run complete. Nothing was saved.')
  })

  it('stops after three retries and says why', async () => {
    const env = await mappingStep({ run: offline })
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    for (const wait of [2000, 4000, 8000]) {
      env.clock.tick(wait)
      await env.flush()
    }
    assert.equal(env.sent('run').length, 4)
    env.clock.tick(60000)
    await env.flush()
    assert.equal(env.sent('run').length, 4)
    assert.equal(env.modal.title(), 'Import stopped')
    assert.equal(env.modal.note(), 'Network error. Check your connection and try again.')
    assert.equal(env.byText('button', 'Resume').disabled, false)
  })

  it('times out a run the server never answers, then retries it', async () => {
    const env = await mappingStep({ run: (fields, calls) => (calls ? done(true) : hang) })
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    env.clock.tick(6 * 60 * 1000 - 1)
    await env.flush()
    assert.equal(env.modal.title(), 'Importing content…')
    env.clock.tick(1)
    await env.flush()
    env.clock.tick(2000)
    await env.flush()
    assert.equal(env.sent('run').length, 2)
    assert.equal(env.modal.isOpen(), false)
    assert.equal(env.$('.migrateplus-run h2').textContent, 'Dry run complete. Nothing was saved.')
  })

  it('ends the server message with a full stop before the advice', async () => {
    const env = await mappingStep({ run: fail('Error establishing a database connection') })
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    assert.equal(
      env.$('.notice-error p').textContent,
      'Error establishing a database connection. Fix the problem, then press Resume to pick up where it stopped.'
    )
  })

  it('says it is waiting for another tab, and stops once a batch could have finished', async () => {
    const env = await mappingStep({ run: fail('A batch for this job is already running.', 'migrateplus_locked') })
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    assert.equal(env.modal.note(), 'Waiting for another tab…')
    // A batch may run for 6 minutes, so 120 tries 3 seconds apart.
    for (let i = 0; i < 120; i++) {
      env.clock.tick(3000)
      await env.flush()
    }
    assert.equal(env.sent('run').length, 121)
    assert.equal(env.modal.title(), 'Import stopped')
  })

  it('ignores the second click of a double click on Resume', async () => {
    const batch = later()
    const env = await mappingStep({ run: (fields, calls) => (calls ? later().promise : batch.promise) })
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    env.click(env.modal.button('Hide'))
    const toggle = () => env.$('#migrateplus-run-toggle')
    env.click(toggle())
    batch.resolve({ done: false, job: job({ status: 'running' }) })
    await env.flush()
    assert.equal(toggle().textContent, 'Resume')
    env.click(toggle())
    env.click(toggle())
    assert.equal(toggle().textContent, 'Pause')
    assert.equal(env.sent('run').length, 2)
  })

  it('filters the log to skipped records', async () => {
    const finished = done(true)
    finished.job.log = [
      { t: 1700000000, l: 'success', m: 'Created "Dune" (#7).' },
      { t: 1700000001, l: 'skipped', m: 'Not updated: "Emma" (#8) is unchanged.' },
    ]
    const env = await mappingStep({ run: finished })
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    env.click(env.$('#migrateplus-log-filter-skipped'))
    const lines = env.$$('.migrateplus-log li')
    assert.equal(lines.length, 1)
    assert.match(lines[0].textContent, /Not updated: "Emma"/)
    assert.equal(env.$('#migrateplus-log-filter-skipped .migrateplus-chip-count').textContent, '1')
  })

  it('starts the next import with the log filters cleared', async () => {
    const env = await mappingStep({ run: done(true) })
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    env.click(env.$('#migrateplus-log-filter-error'))
    env.type(env.$('#migrateplus-log-search'), 'Dune')
    env.click(env.byText('button', 'Import'))
    upload(env)
    await env.flush()
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    assert.equal(env.$('#migrateplus-log-filter-all').getAttribute('aria-pressed'), 'true')
    assert.equal(env.$('#migrateplus-log-search').value, '')
  })

  it('keeps focus on Pause and the log filters while batches arrive', async () => {
    const env = await mappingStep({
      run: (fields, calls) => (calls % 2 ? fail('A batch for this job is already running.', 'migrateplus_locked') : { done: false, job: job({ status: 'running' }) }),
    })
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    env.click(env.modal.button('Hide'))
    for (const id of ['migrateplus-run-toggle', 'migrateplus-log-filter-error']) {
      const before = env.$('#' + id)
      const runs = env.sent('run').length
      before.focus()
      env.clock.tick(3000)
      await env.flush()
      // The retry gets a batch, and the next request finds the lock again.
      assert.equal(env.sent('run').length, runs + 2)
      assert.equal(env.$('#' + id), before)
      assert.equal(env.document.activeElement, before)
    }
  })
})

// What the duplicates check answers for a feed of 6 records, 4 of them already on the site.
const dupItems = () => [
  {
    index: 0, title: 'Dune', existing_title: 'Dune (1965)', post_id: 41, type: 'Post', kind: 'before', label: '', changed: true,
    changes: { post_content: { label: 'Content', old: 'Arrakis\nspice', new: 'Arrakis\nsand' } },
    view_url: 'https://example.test/dune/', edit_url: 'https://example.test/wp-admin/post.php?post=41&action=edit', media: null,
  },
  { index: 1, title: 'Emma', existing_title: 'Emma', post_id: 42, type: 'Post', kind: 'existing', label: 'slug', changed: false, changes: {}, view_url: 'https://example.test/emma/', edit_url: '', media: null },
  {
    index: 3, title: 'Kindred', existing_title: 'Kindred', post_id: 43, type: 'Post', kind: 'before', label: '', changed: true,
    changes: { post_title: { label: 'Title', old: 'Kindred', new: 'Kindred.' } }, view_url: '', edit_url: '', media: null,
  },
  {
    index: 4, title: 'cover.jpg', existing_title: 'cover', post_id: 50, type: 'Media', kind: 'existing', label: 'file', changed: false, changes: {},
    view_url: 'https://example.test/cover/', edit_url: '',
    media: { current: 'https://example.test/cover-150.jpg', current_full: 'https://example.test/cover.jpg', imported: 'https://old.test/cover.jpg', is_image: true },
  },
]
const dups = over => ({ count: 4, total: 6, before: 2, existing: 2, examples: [], items: dupItems(), items_truncated: false, ...over })

async function duplicatesScreen(routes) {
  const env = await mappingStep({ duplicates: dups(), run: done(false), ...routes })
  env.click(env.byText('button', 'Start import'))
  await env.flush()
  return env
}

const choice = (env, index) => env.$('#migrateplus-dup-' + index)
const choices = env => [0, 1, 3, 4].map(i => choice(env, i).value)
const chips = env => env.$$('.migrateplus-dups-chips li').map(li => li.querySelector('span').textContent + ': ' + li.querySelector('strong').textContent)
const plan = env => env.$('.migrateplus-dups-plan').textContent
const rowTitles = env => env.$$('.migrateplus-dups-title').map(n => n.textContent)
const rows = env => env.$$('.migrateplus-dups-table tbody tr')
const dupButton = (env, label) => env.byText('.migrateplus-dups-bulk button, .migrateplus-actionbar button', label)

describe('Duplicates', () => {
  it('goes straight to the duplicates screen after the check, with nothing saved yet', async () => {
    const env = await duplicatesScreen()
    assert.equal(env.modal.isOpen(), false)
    assert.equal(env.sent('save').length, 0)
    assert.equal(env.$('.migrateplus-dups-head h2').textContent, 'Items already on this site')
    assert.equal(env.$('.migrateplus-dups-head p').textContent, '4 of 6 records are already on this site. Choose what to do with each.')
    assert.equal(env.$('.migrateplus-dups-table caption').textContent, 'Items already on this site')
    assert.deepEqual(env.$$('.migrateplus-dups-table thead th').map(th => th.textContent), ['In the file', 'On this site', 'Why it matched', 'Status', 'Author', 'What to do'])
    assert.deepEqual(env.$$('.migrateplus-dups-table thead th').map(th => th.getAttribute('scope')), ['col', 'col', 'col', 'col', 'col', 'col'])
    assert.deepEqual(rowTitles(env), ['Dune', 'Emma', 'Kindred', 'cover.jpg'])

    const [view, edit] = rows(env)[0].children[1].querySelectorAll('a')
    assert.equal(view.textContent, 'Dune (1965)')
    assert.equal(view.getAttribute('href'), 'https://example.test/dune/')
    assert.equal(view.getAttribute('target'), '_blank')
    assert.equal(view.getAttribute('rel'), 'noopener')
    assert.equal(edit.textContent, 'Edit')
    assert.equal(edit.getAttribute('href'), 'https://example.test/wp-admin/post.php?post=41&action=edit')
    assert.equal(rows(env)[1].children[1].querySelectorAll('a').length, 1)
    assert.equal(rows(env)[2].children[1].querySelector('a'), null)
    assert.deepEqual(rows(env).map(tr => tr.children[2].textContent), ['imported before', 'same slug', 'imported before', 'same file'])
    assert.deepEqual(env.$$('.migrateplus-dups-table .migrateplus-badge').map(n => n.textContent), ['Differs', 'Unchanged', 'Differs', 'Unchanged'])

    assert.equal(env.$('label[for="migrateplus-dup-0"]').textContent, 'What to do with “Dune”')
    assert.deepEqual([...choice(env, 0).options].map(o => o.textContent), ['Update', 'Skip', 'Import as copy'])
    assert.deepEqual([...choice(env, 1).options].map(o => o.textContent), ['Skip', 'Import as copy'])
    assert.deepEqual([...choice(env, 4).options].map(o => o.textContent), ['Update details', 'Replace file', 'Skip'])
    assert.equal(env.$('.migrateplus-stepper'), null)
  })

  it('keeps Update and shows no badge on a row the check cannot compare, like a product', async () => {
    const items = dupItems()
    items[1] = { ...items[1], compared: false }
    const env = await duplicatesScreen({ duplicates: dups({ items }) })
    assert.deepEqual([...choice(env, 1).options].map(o => o.textContent), ['Update', 'Skip', 'Import as copy'])
    assert.equal(rows(env)[1].querySelector('.migrateplus-badge'), null)
    assert.deepEqual(env.$$('.migrateplus-dups-table .migrateplus-badge').map(n => n.textContent), ['Differs', 'Differs', 'Unchanged'])
  })

  it('starts the import at once when nothing is already here', async () => {
    const env = await mappingStep({ duplicates: { count: 0, total: 2, before: 0, existing: 0, examples: [], items: [], items_truncated: false }, run: done(false) })
    env.click(env.byText('button', 'Start import'))
    await env.flush()
    assert.deepEqual(env.requests.map(r => r.action), ['preview', 'duplicates', 'save', 'run'])
    assert.equal(env.$('.migrateplus-dups-table'), null)
    assert.equal(env.modal.isOpen(), false)
    assert.equal(env.$('.migrateplus-run h2').textContent, 'Import complete')
  })

  it('starts every row at Skip, unless records imported before are updated, which updates the ones that differ', async () => {
    const skipping = await duplicatesScreen()
    assert.deepEqual(choices(skipping), ['skip', 'skip', 'skip', 'skip'])

    const env = await mappingStep({ duplicates: dups(), run: done(false) })
    env.choose(env.$('#migrateplus-opt-row-5'), 'update')
    env.click(env.byText('button', 'Start import'))
    await env.flush()
    assert.deepEqual(choices(env), ['update', 'skip', 'update', 'skip'])

    env.click(dupButton(env, 'Import'))
    await env.flush()
    const opts = json(env.sent('save')[0], 'options')
    assert.equal(opts.duplicate, 'update')
    assert.deepEqual(opts.update_ids, [41, 43])
  })

  it('gives two records that match the same post one choice, as the server reads it', async () => {
    const items = dupItems()
    items.splice(2, 0, { ...items[1], index: 2 })
    const env = await duplicatesScreen({ duplicates: dups({ count: 5, items }) })
    env.choose(choice(env, 2), 'copy')
    assert.equal(choice(env, 1).value, 'copy')
    assert.deepEqual(chips(env), ['New: 1', 'Will update: 0', 'Skipped: 3', 'Imported as copies: 2', 'Update details: 0', 'Replace file: 0'])

    env.click(dupButton(env, 'Import'))
    await env.flush()
    assert.deepEqual(json(env.sent('save')[0], 'options').copy_ids, [42])
  })

  it('counts each choice in the chips and the summary as it changes', async () => {
    const env = await duplicatesScreen()
    assert.deepEqual(chips(env), ['New: 2', 'Will update: 0', 'Skipped: 4', 'Imported as copies: 0', 'Update details: 0', 'Replace file: 0'])
    assert.equal(plan(env), 'Import 2 new items, skip 4.')
    assert.equal(env.$('.migrateplus-dups-plan').getAttribute('aria-live'), 'polite')

    env.choose(choice(env, 0), 'update')
    env.choose(choice(env, 1), 'copy')
    env.choose(choice(env, 4), 'replace')
    assert.deepEqual(chips(env), ['New: 2', 'Will update: 1', 'Skipped: 1', 'Imported as copies: 1', 'Update details: 0', 'Replace file: 1'])
    assert.equal(plan(env), 'Import 2 new items, update 1, import 1 as a copy, replace 1 file, skip 1.')
    assert.equal(env.$('.migrateplus-actionbar-status').textContent, plan(env))
  })

  it('lists each record with its status under the cards and follows the choices', async () => {
    const env = await duplicatesScreen()
    const roll = () => env.$$('.migrateplus-dups-roll li').map(li => [...li.children].map(n => n.textContent).join(' — '))
    assert.deepEqual(roll(), ['Dune — Skipped', 'Emma — Skipped', 'Kindred — Skipped', 'cover.jpg — Skipped'])

    env.choose(choice(env, 0), 'update')
    env.choose(choice(env, 1), 'copy')
    env.choose(choice(env, 4), 'update')
    assert.deepEqual(roll(), ['Dune — Will update', 'Emma — Imported as copy', 'Kindred — Skipped', 'cover.jpg — Update details'])
    assert.equal(env.$$('.migrateplus-dups-roll li .migrateplus-dups-roll-status.is-updated').length, 2)
  })

  it('applies the bulk buttons to the rows the filter and search show, and no others', async () => {
    const env = await duplicatesScreen()
    env.choose(env.$('#migrateplus-dups-filter'), 'before')
    assert.deepEqual(rowTitles(env), ['Dune', 'Kindred'])
    assert.equal(env.$('.migrateplus-dups-bulk span').textContent, 'Applies to the 2 rows shown:')
    env.click(dupButton(env, 'Import all as copies'))

    env.choose(env.$('#migrateplus-dups-filter'), 'all')
    env.type(env.$('#migrateplus-dups-search'), 'EMM')
    assert.deepEqual(rowTitles(env), ['Emma'])
    assert.equal(dupButton(env, 'Update all that differ').disabled, true)
    assert.equal(dupButton(env, 'Replace all files'), undefined)

    env.type(env.$('#migrateplus-dups-search'), '')
    env.choose(env.$('#migrateplus-dups-filter'), 'media')
    assert.deepEqual(rowTitles(env), ['cover.jpg'])
    env.click(dupButton(env, 'Replace all files'))

    env.choose(env.$('#migrateplus-dups-filter'), 'all')
    assert.deepEqual(choices(env), ['copy', 'skip', 'copy', 'replace'])
  })

  it('filters by what differs and how each row matched', async () => {
    const env = await duplicatesScreen()
    const shown = filter => {
      env.choose(env.$('#migrateplus-dups-filter'), filter)
      return rowTitles(env)
    }
    assert.deepEqual(shown('differs'), ['Dune', 'Kindred'])
    assert.deepEqual(shown('unchanged'), ['Emma', 'cover.jpg'])
    assert.deepEqual(shown('existing'), ['Emma', 'cover.jpg'])
    env.type(env.$('#migrateplus-dups-search'), 'nothing like it')
    assert.equal(env.$('.migrateplus-dups-table'), null)
    assert.ok(env.byText('.migrateplus-dups p', 'No duplicates match.'))
  })

  it('pages 50 rows at a time', async () => {
    const items = Array.from({ length: 60 }, (v, i) => ({ ...dupItems()[1], index: i, post_id: 100 + i, title: 'Book ' + (i + 1) }))
    const env = await duplicatesScreen({ duplicates: dups({ count: 60, total: 60, items }) })
    assert.equal(rows(env).length, 50)
    assert.equal(env.$('.migrateplus-authors-paging span').textContent, '1–50 of 60')
    env.click(env.byText('.migrateplus-authors-paging button', 'Next'))
    assert.equal(rowTitles(env)[0], 'Book 51')
    assert.equal(env.$('.migrateplus-authors-paging span').textContent, '51–60 of 60')
  })

  it('shows the changes of a row on request, with removed and added lines named for screen readers', async () => {
    const env = await duplicatesScreen()
    const toggle = rows(env)[0].querySelector('.migrateplus-dups-toggle')
    assert.equal(toggle.tagName, 'BUTTON')
    assert.equal(toggle.textContent, 'Show changes')
    assert.equal(toggle.getAttribute('aria-expanded'), 'false')
    assert.equal(toggle.getAttribute('aria-controls'), 'migrateplus-dup-0-changes')
    assert.equal(env.$('#migrateplus-dup-0-changes'), null)
    assert.equal(rows(env)[1].querySelector('.migrateplus-dups-toggle'), null)

    env.click(toggle)
    assert.equal(toggle.getAttribute('aria-expanded'), 'true')
    assert.deepEqual(env.$$('#migrateplus-dup-0-changes .migrateplus-diff .screen-reader-text').map(n => n.textContent), ['Removed:', 'Added:'])
    assert.ok(env.$$('.migrateplus-diff-sign').every(n => n.getAttribute('aria-hidden') === 'true'))

    env.click(toggle)
    assert.equal(env.$('#migrateplus-dup-0-changes'), null)

    env.click(env.byText('.migrateplus-dups-toggle', 'Compare files'))
    assert.deepEqual(env.$$('#migrateplus-dup-4-changes img').map(n => n.getAttribute('src')), ['https://example.test/cover-150.jpg', 'https://old.test/cover.jpg'])
  })

  it('imports with the ids and flags of the choices', async () => {
    const env = await duplicatesScreen()
    env.choose(choice(env, 0), 'update')
    env.choose(choice(env, 1), 'copy')
    env.choose(choice(env, 4), 'replace')
    env.click(dupButton(env, 'Import'))
    await env.flush()
    const opts = json(env.sent('save')[0], 'options')
    assert.equal(opts.dry_run, 0)
    assert.equal(opts.skip_existing, 1)
    assert.equal(opts.duplicate, 'skip')
    assert.deepEqual(opts.update_ids, [41])
    assert.deepEqual(opts.replace_ids, [50])
    assert.deepEqual(opts.copy_ids, [42])
    assert.deepEqual(opts.skip_ids, [])
    assert.equal(opts.duplicate_rest, '')
    assert.equal(env.modal.isOpen(), false)
    assert.equal(env.$('.migrateplus-run h2').textContent, 'Import complete')
  })

  it('picks an author for each post before what to do with it, but none for media', async () => {
    const env = await duplicatesScreen({ save: saved })
    assert.equal(env.$('label[for="migrateplus-dup-0-author"]').textContent, 'Author of “Dune”')
    assert.equal(env.$('#migrateplus-dup-0-author').options[0].textContent, 'You (the person importing)')
    assert.equal(rows(env)[0].children[4].querySelector('select').id, 'migrateplus-dup-0-author')
    assert.equal(env.$('#migrateplus-dup-4-author'), null)

    env.choose(env.$('#migrateplus-dup-3-author'), '2')
    env.choose(choice(env, 3), 'update')
    env.click(dupButton(env, 'Import'))
    await env.flush()
    assert.deepEqual(json(env.sent('save')[0], 'options').authors, { 3: 2 })
  })

  it('names the author picked for all on the mapping step as each row’s default', async () => {
    const env = await mappingStep({ duplicates: dups() })
    env.choose(env.$('#migrateplus-opt-author'), '1')
    env.click(env.byText('button', 'Start import'))
    await env.flush()
    assert.equal(env.$('#migrateplus-dup-0-author').options[0].textContent, 'Author for all')
  })

  it('asks what to do with the duplicates past a list cut short, and sends it with the skipped ids', async () => {
    const env = await duplicatesScreen({ duplicates: dups({ count: 6, total: 8, items_truncated: true }) })
    assert.ok(env.byText('.migrateplus-dups-rest p', 'Showing the first 4 of 6.'))
    assert.equal(env.$('label[for="migrateplus-dups-rest"]').textContent, 'For the other 2 duplicates:')
    assert.deepEqual(chips(env).slice(0, 3), ['New: 2', 'Will update: 0', 'Skipped: 6'])

    env.choose(env.$('#migrateplus-dups-rest'), 'update')
    env.choose(choice(env, 0), 'update')
    assert.deepEqual(chips(env).slice(0, 3), ['New: 2', 'Will update: 3', 'Skipped: 3'])
    env.click(dupButton(env, 'Import'))
    await env.flush()
    const opts = json(env.sent('save')[0], 'options')
    assert.equal(opts.duplicate, 'skip')
    assert.deepEqual(opts.update_ids, [41])
    assert.deepEqual(opts.skip_ids, [42, 43, 50])
    assert.equal(opts.duplicate_rest, 'update')
  })

  it('reaches the screen from the confirmation of a dropped export, with an author picked on a row', async () => {
    const env = boot({ routes: { preview: exportPreview, duplicates: dups({ total: 15 }), save: saved, run: done(false) } })
    upload(env)
    await env.flush()
    assert.equal(env.modal.message(), 'Exported from old-books.test. Everything is imported as Post, with the field mapping saved in the file. 4 of them are already on this site. Next, choose which to update, skip or import as copies.')
    assert.deepEqual(env.modal.labels(), ['Cancel', 'Choose what to do with them'])
    env.click(env.modal.primary())
    assert.equal(env.modal.isOpen(), false)
    assert.deepEqual(rowTitles(env), ['Dune', 'Emma', 'Kindred', 'cover.jpg'])
    assert.ok(dupButton(env, 'Cancel'))
    assert.equal(dupButton(env, 'Dry run'), undefined)

    assert.equal(env.byText('label', 'Author for all posts and pages'), undefined)
    assert.equal(env.$('#migrateplus-dup-0-author').options[0].textContent, 'You (the person importing)')
    env.choose(choice(env, 0), 'update')
    env.choose(env.$('#migrateplus-dup-0-author'), '2')
    env.click(dupButton(env, 'Import'))
    await env.flush()
    assert.equal(env.sent('duplicates').length, 1)
    const opts = json(env.sent('save')[0], 'options')
    assert.equal(opts.author, 0)
    assert.deepEqual(opts.authors, { 0: 2 })
    assert.equal(opts.skip_existing, 1)
    assert.deepEqual(opts.update_ids, [41])
  })

  it('Cancel goes back from there to the upload screen without importing', async () => {
    const env = boot({ routes: { preview: exportPreview, duplicates: dups({ total: 15 }) } })
    upload(env)
    await env.flush()
    env.click(env.modal.primary())
    env.click(dupButton(env, 'Cancel'))
    assert.equal(env.$('.migrateplus-dups-table'), null)
    assert.ok(env.$('.migrateplus-import-drop'))
    assert.equal(env.sent('save').length, 0)
  })

  it('Back to mapping keeps the choices for the next check', async () => {
    const env = await duplicatesScreen()
    assert.ok(dupButton(env, 'Dry run'))
    env.choose(choice(env, 0), 'update')
    env.choose(choice(env, 1), 'copy')
    env.click(dupButton(env, 'Back to mapping'))
    assert.ok(env.$('.migrateplus-map-table'))
    assert.equal(env.sent('save').length, 0)

    env.click(env.byText('button', 'Start import'))
    await env.flush()
    assert.equal(env.sent('duplicates').length, 2)
    assert.deepEqual(choices(env), ['update', 'copy', 'skip', 'skip'])
  })
})

describe('Upload', () => {
  it('cancels an upload from the progress window', async () => {
    const answer = later()
    const env = boot({ routes: { preview: answer.promise } })
    upload(env)
    const [xhr] = env.xhrs
    assert.equal(xhr.timeout, 6 * 60 * 1000)
    env.click(env.modal.button('Cancel'))
    assert.equal(xhr.aborted, true)
    assert.equal(env.modal.isOpen(), false)
    answer.resolve(feedPreview)
    await env.flush()
    assert.equal(env.$('.migrateplus-map-table'), null)
  })

  it('warns before leaving the page while a file uploads', async () => {
    const answer = later()
    const env = boot({ routes: { preview: answer.promise } })
    upload(env)
    assert.equal(env.leaveWarns(), true)
    answer.resolve(feedPreview)
    await env.flush()
    assert.equal(env.leaveWarns(), false)
  })

  it('keeps a file dropped beside the drop zone from opening in the tab', () => {
    const env = boot()
    const drop = new env.window.Event('drop', { bubbles: true, cancelable: true })
    Object.defineProperty(drop, 'dataTransfer', { value: { types: ['Files'], files: [] } })
    env.document.body.dispatchEvent(drop)
    assert.equal(drop.defaultPrevented, true)
  })

  it('shows the steps for a plain file dropped after a cancelled export', async () => {
    const env = boot({ routes: { preview: (fields, calls) => (calls ? feedPreview : exportPreview), duplicates: { count: 0, total: 15 }, delete: {} } })
    upload(env)
    await env.flush()
    env.click(env.modal.button('Cancel'))
    env.drop(env.$('.migrateplus-import-drop'), env.file('feed.json'))
    await env.flush()
    assert.ok(env.$('.migrateplus-map-table'))
    assert.ok(env.$('.migrateplus-route'))
  })
})

describe('Opening an import from History', () => {
  it('keeps the job in the address but takes out the resume flag, so a reload reopens without resuming', async () => {
    const env = boot({
      url: 'https://example.test/wp-admin/admin.php?page=migrateplus&job=books&resume=1',
      data: { openJob: 'books', resume: true },
      routes: { job: { job: job({ status: 'running' }) }, run: done(false) },
    })
    await env.flush()
    assert.equal(env.window.location.search, '?page=migrateplus&job=books')
    assert.equal(env.modal.isOpen(), false)
    assert.equal(env.$('.migrateplus-run h2').textContent, 'Import complete')
  })

  it('opens a draft at the mapping step, and deletes it when another file is chosen', async () => {
    const env = boot({
      data: { openJob: 'books' },
      routes: { job: { job: job({ fields: ['title'], records: [{ title: 'Dune' }] }) }, delete: {} },
    })
    await env.flush()
    assert.ok(env.$('.migrateplus-map-table'))
    assert.equal(env.window.location.search, '?page=migrateplus&job=books')
    env.click(env.byText('button', 'Choose another file'))
    await env.flush()
    assert.equal(env.sent('delete')[0].fields.job, 'books')
    assert.ok(env.$('.migrateplus-import-drop'))
    assert.equal(env.window.location.search, '?page=migrateplus')
  })

  it('puts the job of a fresh upload in the address, so a refresh comes back to its step', async () => {
    const env = await mappingStep()
    assert.equal(env.window.location.search, '?page=migrateplus&job=books')
  })
})
