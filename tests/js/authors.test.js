import { describe, it, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { boot, job, json, fail, later, noNativeDialogs } from './helpers.js'

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

// The authors the old site's file names, for an author per old author.
const sourceAuthors = {
  authors: [
    { key: 'maya', login: 'maya', name: 'Maya Lindqvist', count: 80, match: { id: 6, name: 'Maya Lindqvist', login: 'maya' } },
    { key: 'Guest Writer', login: '', name: 'Guest Writer', count: 40, match: null },
  ],
  truncated: false,
}

async function exportMappingStep(routes) {
  const env = boot({
    routes: {
      preview: {
        job: job({ total: 120, settings: { file_name: 'site.json', migrateplus_site: 'https://old.test', migrateplus_post_type: 'post' }, mapping: { fields: { post_title: 'title' } } }),
        records: [{ title: 'Hello world' }],
        fields: ['title'],
      },
      duplicates: { count: 0, total: 120 },
      source_authors: sourceAuthors,
      records,
      save: { job: job({ status: 'running' }) },
      ...routes,
    },
  })
  env.chooseFile(env.$('#migrateplus-import-file'), env.file('site.json'))
  await env.flush()
  env.click(env.byText('button', 'Review mapping'))
  await env.flush()
  env.click(env.byText('button', 'Choose an author for each author on the old site'))
  await env.flush()
  return env
}

describe('An author for each author on the old site', () => {
  it('lists the file’s authors with their posts, most first, only on Review mapping of an export', async () => {
    const env = await exportMappingStep()
    const rows = env.$$('#migrateplus-authors-source tbody tr')
    assert.deepEqual(rows.map(tr => tr.children[0].textContent), ['Maya Lindqvist (maya)', 'Guest Writer'])
    assert.deepEqual(rows.map(tr => tr.children[1].textContent), ['80', '40'])
    assert.equal(env.$('#migrateplus-source-author-0').value, '')
    assert.deepEqual(json(env.sent('source_authors')[0], 'mapping').fields, { post_title: 'title' })

    const feed = await mappingStep()
    assert.equal(feed.byText('button', 'Choose an author for each author on the old site'), undefined)
  })

  it('sends the authors picked for old authors, keyed by login or name', async () => {
    const env = await exportMappingStep()
    env.choose(env.$('#migrateplus-source-author-1'), '2')
    assert.equal(env.$$('.migrateplus-authors-count')[0].textContent, '1 old author has an author here.')
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    assert.deepEqual(json(env.sent('save')[0], 'options').author_map, { 'Guest Writer': 2 })
  })

  it('takes every account with the same login in one click', async () => {
    const env = await exportMappingStep()
    assert.ok(env.byText('#migrateplus-authors-source span', '1 of them has an account here with the same login.'))
    env.click(env.byText('button', 'Use the accounts with the same login'))
    assert.equal(env.$('#migrateplus-source-author-0').value, '6')
    assert.equal(env.byText('button', 'Use the accounts with the same login').disabled, true)
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    assert.deepEqual(json(env.sent('save')[0], 'options').author_map, { maya: 6 })
  })

  it('drops the error once a reopened list loads', async () => {
    const env = await exportMappingStep({
      source_authors: (fields, calls) => (calls ? sourceAuthors : fail('The file could not be read.')),
    })
    assert.equal(env.$('#migrateplus-authors-source .notice-error').textContent, 'The file could not be read.')

    const toggle = env.byText('button', 'Choose an author for each author on the old site')
    env.click(toggle)
    env.click(toggle)
    await env.flush()
    assert.equal(env.sent('source_authors').length, 2)
    assert.equal(env.$('#migrateplus-authors-source .notice-error'), null)
    assert.equal(env.$$('#migrateplus-authors-source tbody tr').length, 2)
  })

  it('starts a new file with no old authors mapped, whatever the job options say', async () => {
    const env = boot({
      routes: {
        preview: { job: job({ total: 120, options: { ...job().options, author_map: { maya: 2 } } }), records: [{ title: 'Hello world' }], fields: ['title'] },
        records,
        save: { job: job({ status: 'running' }) },
      },
    })
    env.chooseFile(env.$('#migrateplus-import-file'), env.file('posts.json'))
    await env.flush()
    env.click(env.byText('button', 'Dry run'))
    await env.flush()
    assert.deepEqual(json(env.sent('save')[0], 'options').author_map, {})
  })
})

// With the site's first 200 users in D.authors, the picker searches the rest.
const manyAuthors = Array.from({ length: 200 }, (v, i) => ({ id: i + 1, name: 'User ' + (i + 1), login: 'user' + (i + 1) }))

async function searchStep(users) {
  const env = boot({
    data: { authors: manyAuthors },
    routes: {
      preview: { job: job({ total: 120 }), records: [{ title: 'Hello world' }], fields: ['title'] },
      users,
    },
  })
  env.chooseFile(env.$('#migrateplus-import-file'), env.file('posts.json'))
  await env.flush()
  return env
}

const searchBox = env => env.$('.migrateplus-author-search')
const optionCount = env => env.$('#migrateplus-opt-author').options.length

describe('Searching users for an author', () => {
  it('ignores the answer to a search the box was cleared after', async () => {
    const answer = later()
    const env = await searchStep(() => answer.promise)
    env.type(searchBox(env), 'ab')
    env.clock.tick(300)
    await env.flush()
    env.type(searchBox(env), '')
    env.clock.tick(300)
    await env.flush()

    answer.resolve([{ id: 900, name: 'Abbie', login: 'abbie' }])
    await env.flush()
    assert.equal(optionCount(env), 201)
  })

  it('says why a search failed instead of listing no one', async () => {
    const env = await searchStep(fail('Sorry, you are not allowed to list users.'))
    env.type(searchBox(env), 'ab')
    env.clock.tick(300)
    await env.flush()
    assert.equal(env.$('.migrateplus-author .notice-error').textContent, 'Sorry, you are not allowed to list users.')
    assert.equal(optionCount(env), 201)
  })
})
