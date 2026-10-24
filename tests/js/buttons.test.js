import { describe, it, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { boot, job, noNativeDialogs } from './helpers.js'

afterEach(noNativeDialogs)

const isPrimary = node => node.classList.contains('button-primary')
const isDanger = node => node.classList.contains('migrateplus-danger')

async function history() {
  const env = boot({
    data: { view: 'history' },
    routes: {
      jobs: { jobs: [job({ status: 'complete', stats: { processed: 15, created: 12, skipped: 3 }, created_total: 12 })] },
      exports: {
        exports: [{ id: 'exp1', label: 'Posts export', status: 'complete', done: 40, total: 40, size: '96 KB', url: 'https://example.test/dl/exp1', created: 0 }],
      },
    },
  })
  await env.flush()
  return env
}

async function mappingStep() {
  const env = boot({
    data: { mappings: [{ name: 'Books feed', mapping: {}, options: {} }] },
    routes: { preview: { job: job(), records: [{ title: 'Dune' }], fields: ['title'], savedMapping: 'Books feed' } },
  })
  env.chooseFile(env.$('#migrateplus-import-file'), env.file('books.json'))
  await env.flush()
  return env
}

async function replaceRuns() {
  const env = boot({
    data: { view: 'replace' },
    routes: {
      replace_history: {
        runs: [{ id: 'run1', search: 'http://old.test', replace: 'https://new.test', status: 'complete', can_undo: true, changes: 340, rows: 120, created: 0 }],
      },
    },
  })
  await env.flush()
  return env
}

describe('buttons', () => {
  it('marks the main action on each screen as primary', async () => {
    const imp = await mappingStep()
    assert.ok(isPrimary(imp.byText('button', 'Start import')))
    assert.ok(!isPrimary(imp.byText('button', 'Dry run')))
    assert.ok(isPrimary(boot().byText('button', 'Choose a JSON file')))
    assert.ok(isPrimary(boot({ data: { view: 'export' } }).$('form.migrateplus-export [type=submit]')))
    const his = await history()
    assert.ok(isPrimary(his.byText('a', 'Import')))
    assert.ok(isPrimary(his.byText('.migrateplus-exports-panel a', 'Download')))
  })

  const destructive = [
    ['deleting an import', history, env => env.click(env.$('.migrateplus-history-panel tbody .migrateplus-link-danger'))],
    ['deleting an export', history, env => env.click(env.$('.migrateplus-exports-panel tbody .migrateplus-link-danger'))],
    ['rolling back an import', history, env => env.click(env.byText('tbody button', 'Roll back'))],
    ['bulk deleting', history, env => {
      env.check(env.$('.migrateplus-history-panel tbody input'))
      env.click(env.byText('button', 'Delete 1 entry'))
    }],
    ['deleting a saved mapping', mappingStep, env => env.click(env.byText('.migrateplus-saved button', 'Delete'))],
    ['rolling back a replacement', replaceRuns, env => env.click(env.byText('.migrateplus-replace-runs button', 'Roll back'))],
    ['deleting a replacement run', replaceRuns, env => env.click(env.byText('.migrateplus-replace-runs button', 'Delete'))],
  ]
  for (const [name, setup, open] of destructive) {
    it('styles the confirm button as danger when ' + name, async () => {
      const env = await setup()
      open(env)
      assert.ok(isPrimary(env.modal.primary()) && isDanger(env.modal.primary()))
      assert.ok(!isPrimary(env.modal.button('Cancel')))
    })
  }
})

describe('Past replacements', () => {
  it('says why a run can no longer be rolled back', async () => {
    const note = 'Can no longer be rolled back: its rollback file is gone. Rollback files are deleted after 30 days.'
    const env = boot({
      data: { view: 'replace' },
      routes: {
        replace_history: {
          runs: [{ id: 'run1', search: 'http://old.test', replace: 'https://new.test', status: 'complete', can_undo: false, undo_note: note, changes: 340, rows: 120, created: 0 }],
        },
      },
    })
    await env.flush()
    assert.equal(env.byText('.migrateplus-replace-runs button', 'Roll back'), undefined)
    assert.equal(env.$('.migrateplus-replace-runs .migrateplus-undo-note').textContent, note)
  })
})
