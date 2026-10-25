import { describe, it, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { boot, job, json, noNativeDialogs } from './helpers.js'

afterEach(noNativeDialogs)

const titles = ['Hello world', 'About', 'Contact']

// The records endpoint over a file of 120 records, pages among them.
const records = fields => {
  const all = Array.from({ length: 120 }, (v, i) => ({ index: i, title: titles[i] || 'Post ' + (i + 1), type: i === 1 || i === 2 ? 'page' : 'post' }))
  const q = (fields.q || '').toLowerCase()
  const found = q ? all.filter(r => r.title.toLowerCase().includes(q)) : all
  const offset = Number(fields.offset)
  return { records: found.slice(offset, offset + Number(fields.limit)), total: 120, matched: found.length, truncated: false }
}

async function mappingStep() {
  const env = boot({
    routes: {
      preview: { job: job({ total: 120 }), records: [{ title: 'Hello world' }], fields: ['title'] },
      records,
      save: { job: job({ status: 'running' }) },
    },
  })
  env.chooseFile(env.$('#migrateplus-import-file'), env.file('posts.json'))
  await env.flush()
  return env
}

async function expand(env) {
  env.click(env.$('.migrateplus-authors-toggle'))
  await env.flush()
}

const rowTitles = env => env.$$('.migrateplus-authors-title').map(n => n.textContent)
const paging = env => env.$('.migrateplus-authors-paging span').textContent

describe('An author for each post or page', () => {
  it('stays collapsed until opened, then loads the first page of titles', async () => {
    const env = await mappingStep()
    const toggle = env.$('.migrateplus-authors-toggle')
    assert.equal(toggle.textContent, 'Choose an author for each post or page')
    assert.equal(toggle.getAttribute('aria-expanded'), 'false')
    assert.equal(env.sent('records').length, 0)

    await expand(env)
    assert.equal(toggle.getAttribute('aria-expanded'), 'true')
    const [req] = env.sent('records')
    assert.equal(req.fields.offset, '0')
    assert.equal(req.fields.limit, '50')
    assert.equal(req.fields.q, '')
    assert.deepEqual(json(req, 'mapping').fields, { post_title: 'title' })
    assert.deepEqual(rowTitles(env).slice(0, 3), titles)
    assert.deepEqual(env.$$('.migrateplus-authors-table tbody tr').slice(0, 3).map(tr => tr.children[1].textContent), ['Post', 'Page', 'Page'])
    assert.equal(paging(env), '1–50 of 120')
  })

  it('labels each row’s select with its title and starts it at Same as above', async () => {
    const env = await mappingStep()
    await expand(env)
    const select = env.$('#migrateplus-author-0')
    assert.equal(env.$('label[for="migrateplus-author-0"]').textContent, 'Author of “Hello world”')
    assert.equal(env.$('label[for="migrateplus-author-0"]').className, 'screen-reader-text')
    assert.equal(select.value, '')
    assert.equal(select.options[0].textContent, 'Same as above')
  })

  it('sends the authors chosen for single rows, by their position, beside the author for all', async () => {
    const env = await mappingStep()
    env.choose(env.$('#migrateplus-opt-author'), '1')
    await expand(env)
    env.choose(env.$('#migrateplus-author-0'), '2')
    env.choose(env.$('#migrateplus-author-2'), '1')
    assert.equal(env.$('.migrateplus-authors-count').textContent, '2 posts have their own author.')
    env.choose(env.$('#migrateplus-author-2'), '')
    assert.equal(env.$('.migrateplus-authors-count').textContent, '1 post has its own author.')

    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    const options = json(env.sent('save')[0], 'options')
    assert.equal(options.author, 1)
    assert.deepEqual(options.authors, { 0: 2 })
  })

  it('Reset all clears the rows’ authors', async () => {
    const env = await mappingStep()
    await expand(env)
    const reset = env.byText('button', 'Reset all to the author above')
    assert.equal(reset.disabled, true)
    env.choose(env.$('#migrateplus-author-0'), '2')
    env.choose(env.$('#migrateplus-author-1'), '2')
    env.click(reset)
    assert.equal(env.$('#migrateplus-author-0').value, '')
    assert.equal(env.$('#migrateplus-author-1').value, '')
    assert.equal(env.$('.migrateplus-authors-count'), null)

    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    assert.deepEqual(json(env.sent('save')[0], 'options').authors, {})
  })

  it('pages with Previous and Next, keeping the choices of other pages', async () => {
    const env = await mappingStep()
    await expand(env)
    env.choose(env.$('#migrateplus-author-0'), '2')
    const previous = env.byText('.migrateplus-authors-paging button', 'Previous')
    assert.equal(previous.disabled, true)

    env.click(env.byText('.migrateplus-authors-paging button', 'Next'))
    await env.flush()
    assert.equal(env.sent('records')[1].fields.offset, '50')
    assert.equal(rowTitles(env)[0], 'Post 51')
    env.click(env.byText('.migrateplus-authors-paging button', 'Next'))
    await env.flush()
    assert.equal(env.sent('records')[2].fields.offset, '100')
    assert.equal(paging(env), '101–120 of 120')
    assert.equal(env.byText('.migrateplus-authors-paging button', 'Next').disabled, true)

    env.click(env.byText('.migrateplus-authors-paging button', 'Previous'))
    await env.flush()
    assert.equal(env.sent('records')[3].fields.offset, '50')

    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    assert.deepEqual(json(env.sent('save')[0], 'options').authors, { 0: 2 })
  })

  it('searches titles 300 ms after typing stops, from the first match', async () => {
    const env = await mappingStep()
    await expand(env)
    env.click(env.byText('.migrateplus-authors-paging button', 'Next'))
    await env.flush()
    const box = env.$('#migrateplus-authors-search')
    env.type(box, 'abo')
    env.type(box, 'about')
    env.clock.tick(299)
    await env.flush()
    assert.equal(env.sent('records').length, 2)
    env.clock.tick(1)
    await env.flush()
    const [, , req] = env.sent('records')
    assert.equal(req.fields.q, 'about')
    assert.equal(req.fields.offset, '0')
    assert.deepEqual(rowTitles(env), ['About'])
    assert.equal(paging(env), '1–1 of 1')
  })

  it('starts a new file with no rows’ authors', async () => {
    const env = boot({
      routes: {
        preview: { job: job({ total: 120, options: { ...job().options, authors: [2] } }), records: [{ title: 'Hello world' }], fields: ['title'] },
        records,
        save: { job: job({ status: 'running' }) },
      },
    })
    env.chooseFile(env.$('#migrateplus-import-file'), env.file('posts.json'))
    await env.flush()
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    assert.deepEqual(json(env.sent('save')[0], 'options').authors, {})
  })
})
