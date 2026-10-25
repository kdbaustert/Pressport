import { describe, it, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { boot, job, json, later, noNativeDialogs } from './helpers.js'

afterEach(noNativeDialogs)

const jobs = () => [
  job({ id: 'books', status: 'complete', settings: { file_name: 'books.json' }, stats: { processed: 15, created: 12, skipped: 3 }, created_total: 12 }),
  job({ id: 'authors', status: 'complete', settings: { file_name: 'authors.json' }, stats: { processed: 4, created: 4 }, created_total: 4 }),
  job({ id: 'reviews', status: 'failed', settings: { file_name: 'reviews.json' }, stats: { processed: 30, created: 28, failed: 2 } }),
]

const exportsList = () => [
  { id: 'exp1', label: 'Posts export', status: 'complete', done: 40, total: 40, size: '96 KB', url: 'https://example.test/dl/exp1', created: 0 },
  { id: 'exp2', label: 'Pages export', status: 'stopped', done: 3, total: 9, created: 0 },
]

async function history(routes = {}) {
  const env = boot({
    data: { view: 'history' },
    routes: { jobs: { jobs: jobs() }, exports: { exports: exportsList() }, ...routes },
  })
  await env.flush()
  return env
}

const imports = env => env.$('.migrateplus-history-panel:not(.migrateplus-exports-panel)')
const exportsPanel = env => env.$('.migrateplus-exports-panel')
const boxes = panel => [...panel.querySelectorAll('tbody input[type=checkbox]')]
const selectAll = panel => panel.querySelector('thead input')
const bulk = panel => panel.querySelector('.migrateplus-history-head .migrateplus-danger')
const names = panel => [...panel.querySelectorAll('tbody strong')].map(n => n.textContent)

describe('History', () => {
  it('lists imports and exports', async () => {
    const env = await history()
    assert.deepEqual(names(imports(env)), ['books.json', 'authors.json', 'reviews.json'])
    assert.match(imports(env).textContent, /12 created, 3 skipped/)
    assert.deepEqual(names(exportsPanel(env)), ['Posts export', 'Pages export'])
  })

  it('counts the ticked rows on the delete button', async () => {
    const env = await history()
    const [books, , reviews] = boxes(imports(env))
    assert.equal(bulk(imports(env)).disabled, true)
    env.check(books)
    assert.equal(bulk(imports(env)).textContent, 'Delete 1 entry')
    env.check(reviews)
    assert.equal(bulk(imports(env)).textContent, 'Delete 2 entries')
    env.check(books, false)
    env.check(reviews, false)
    assert.equal(bulk(imports(env)).textContent, 'Delete selected')
    assert.equal(bulk(imports(env)).disabled, true)
  })

  it('select-all ticks every row and goes indeterminate on a partial selection', async () => {
    const env = await history()
    const all = selectAll(imports(env))
    env.check(all)
    assert.ok(boxes(imports(env)).every(b => b.checked))
    assert.equal(bulk(imports(env)).textContent, 'Delete 3 entries')

    env.check(boxes(imports(env))[1], false)
    assert.equal(all.checked, false)
    assert.equal(all.indeterminate, true)

    env.check(boxes(imports(env))[1])
    assert.equal(all.checked, true)
    assert.equal(all.indeterminate, false)
  })

  it('search hides rows and drops them from the selection', async () => {
    const env = await history({ history_delete: { deleted: [{ id: 'reviews' }], skipped: [] } })
    env.check(selectAll(imports(env)))
    env.type(env.$('#migrateplus-history-search'), 'failed')
    assert.deepEqual(names(imports(env)), ['reviews.json'])
    assert.equal(bulk(imports(env)).textContent, 'Delete 1 entry')

    env.click(bulk(imports(env)))
    env.click(env.modal.primary())
    await env.flush()
    assert.deepEqual(json(env.sent('history_delete')[0], 'items'), [{ kind: 'job', id: 'reviews' }])
  })

  it('search finds a row by its file name', async () => {
    const env = await history()
    env.type(env.$('#migrateplus-history-search'), 'authors')
    assert.deepEqual(names(imports(env)), ['authors.json'])
  })

  it('bulk delete sends one request with the ticked rows', async () => {
    const env = await history({
      history_delete: { deleted: [{ id: 'books' }], skipped: [{ id: 'reviews', reason: 'still running' }] },
    })
    env.check(boxes(imports(env))[0])
    env.check(boxes(imports(env))[2])
    env.click(bulk(imports(env)))
    assert.equal(env.modal.title(), 'Delete 2 entries?')
    env.click(env.modal.primary())
    await env.flush()

    assert.equal(env.sent('history_delete').length, 1)
    assert.deepEqual(json(env.sent('history_delete')[0], 'items'), [
      { kind: 'job', id: 'books' },
      { kind: 'job', id: 'reviews' },
    ])
    assert.equal(env.modal.title(), 'Deleted 1, skipped 1 (still running).')
    assert.match(env.modal.note(), /“reviews\.json” \(still running\)/)
    assert.deepEqual(names(imports(env)), ['authors.json', 'reviews.json'])
  })

  const summaries = [
    [{ deleted: [{ id: 'books' }, { id: 'authors' }, { id: 'reviews' }], skipped: [] }, 'Deleted 3.'],
    [
      {
        deleted: [{ id: 'books' }],
        skipped: [
          { id: 'authors', reason: 'still running' },
          { id: 'reviews', reason: 'not found' },
        ],
      },
      'Deleted 1, skipped 2 (1 still running, 1 not found).',
    ],
  ]
  for (const [answer, title] of summaries) {
    it('summarises the result: ' + title, async () => {
      const env = await history({ history_delete: answer })
      env.check(selectAll(imports(env)))
      env.click(bulk(imports(env)))
      env.click(env.modal.primary())
      await env.flush()
      assert.equal(env.modal.title(), title)
    })
  }

  it('shows the success icon on the summary', async () => {
    const env = await history({ history_delete: { deleted: [{ id: 'books' }], skipped: [] } })
    env.check(boxes(imports(env))[0])
    env.click(bulk(imports(env)))
    env.click(env.modal.primary())
    await env.flush()
    assert.equal(env.modal.status(), 'success')
  })

  it('bulk deletes exports as kind export', async () => {
    const env = await history({ history_delete: { deleted: [{ id: 'exp2' }], skipped: [] } })
    env.check(boxes(exportsPanel(env))[1])
    env.click(bulk(exportsPanel(env)))
    env.click(env.modal.primary())
    await env.flush()
    assert.deepEqual(json(env.sent('history_delete')[0], 'items'), [{ kind: 'export', id: 'exp2' }])
    assert.deepEqual(names(exportsPanel(env)), ['Posts export'])
  })

  it('deletes a single import after confirming in the modal', async () => {
    const env = await history({ delete: {} })
    env.click(imports(env).querySelectorAll('tbody .migrateplus-link-danger')[1])
    assert.equal(env.modal.title(), 'Delete this import?')
    assert.equal(env.sent('delete').length, 0)
    env.click(env.modal.primary())
    await env.flush()
    assert.equal(env.sent('delete')[0].fields.job, 'authors')
    assert.deepEqual(names(imports(env)), ['books.json', 'reviews.json'])
  })

  it('keeps the other ticked rows when one row is deleted', async () => {
    const env = await history({ delete: {} })
    const [books, , reviews] = boxes(imports(env))
    env.check(books)
    env.check(reviews)
    env.click(imports(env).querySelectorAll('tbody .migrateplus-link-danger')[1])
    env.click(env.modal.primary())
    await env.flush()
    assert.equal(bulk(imports(env)).textContent, 'Delete 2 entries')
  })

  it('offers Roll back for an import left running, in History and on its run screen', async () => {
    const left = job({ status: 'running', stats: { processed: 5, created: 5 }, created_total: 5 })
    const env = await history({ jobs: { jobs: [left] } })
    assert.ok(env.byText('tbody button', 'Roll back'))

    const run = boot({ data: { openJob: 'books' }, routes: { job: { job: left } } })
    await run.flush()
    assert.equal(run.byText('button', 'Resume').disabled, false)
    assert.ok(run.byText('button', 'Roll back this import'))
  })

  it('shows a file name with markup in it as text', async () => {
    const hostile = '<img src=x onerror=alert(1)>'
    const env = await history({ jobs: { jobs: [job({ settings: { file_name: hostile } })] } })
    assert.deepEqual(names(imports(env)), [hostile])
    assert.equal(env.$$('img').length, 0)
  })

  for (const url of ['javascript:alert(document.cookie)', ' java\tscript:alert(1)', 'data:text/html,<script>alert(1)</script>']) {
    it('leaves the href off a download link to ' + JSON.stringify(url), async () => {
      const env = await history({ exports: { exports: [{ ...exportsList()[0], url }] } })
      assert.equal(env.byText('.migrateplus-exports-panel a', 'Download').getAttribute('href'), null)
    })
  }

  it('warns before leaving the page while a rollback runs', async () => {
    const step = later()
    const env = await history({ rollback: step.promise })
    env.click(env.byText('tbody button', 'Roll back'))
    env.click(env.modal.primary())
    await env.flush()
    assert.equal(env.leaveWarns(), true)
    step.resolve({ deleted: 12, remaining: 0, done: true, error: '', job: job({ status: 'rolled_back' }) })
    await env.flush()
    assert.equal(env.modal.title(), 'Rollback complete')
    assert.equal(env.leaveWarns(), false)
  })
})
