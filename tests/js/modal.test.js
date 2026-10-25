import { describe, it, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { boot, job, fail, later, noNativeDialogs } from './helpers.js'

afterEach(noNativeDialogs)

async function history(routes = {}) {
  const env = boot({
    data: { view: 'history' },
    routes: {
      jobs: { jobs: [job({ status: 'complete', stats: { processed: 15, created: 12, skipped: 3 }, created_total: 12 })] },
      exports: { exports: [] },
      ...routes,
    },
  })
  await env.flush()
  env.click(env.$('tbody .migrateplus-link-danger'))
  return env
}

describe('ask()', () => {
  it('uses the trash icon for a destructive action', async () => {
    const { modal, document } = await history()
    assert.equal(modal.title(), 'Delete this import?')
    assert.match(modal.message(), /Imported content is not deleted/)
    assert.equal(modal.status(), 'danger')
    assert.match(modal.icon(), /dashicons-trash/)
    assert.deepEqual(modal.labels(), ['Cancel', 'Delete'])
    assert.equal(document.activeElement, modal.primary())
  })

  it('uses the question icon otherwise', async () => {
    const env = boot({
      data: { view: 'replace', tables: [{ name: 'wp_posts', rows: 1200, size: 4194304 }] },
      routes: { replace_history: { runs: [] } },
    })
    await env.flush()
    env.type(env.$('#migrateplus-replace-search'), 'http://old.test')
    env.check(env.$('#migrateplus-replace-dry_run'), false)
    env.submit(env.$('form.migrateplus-replace'))
    assert.equal(env.modal.title(), 'Replace in the database?')
    assert.equal(env.modal.status(), 'question')
    assert.match(env.modal.icon(), /dashicons-editor-help/)
    assert.ok(!env.modal.primary().classList.contains('migrateplus-danger'))
    assert.equal(env.sent('replace_start').length, 0)
  })

  it('Cancel closes without calling back', async () => {
    const env = await history({ delete: {} })
    env.click(env.modal.button('Cancel'))
    await env.flush()
    assert.equal(env.modal.isOpen(), false)
    assert.equal(env.sent('delete').length, 0)
  })

  it('disables the primary on click and only then spins', async () => {
    const env = await history({ delete: {} })
    assert.notEqual(env.modal.status(), 'working')
    env.click(env.modal.primary())

    assert.equal(env.modal.status(), 'working')
    assert.deepEqual(env.modal.labels(), ['Delete…'])
    // The button is rebuilt in place, so a quick second click lands on the disabled copy.
    const busy = env.modal.primary()
    assert.equal(busy.disabled, true)
    busy.click()
    await env.flush()
    assert.equal(env.sent('delete').length, 1)
    assert.equal(env.modal.isOpen(), false)
  })
})

describe('modalError()', () => {
  it('shows the server message', async () => {
    const env = await history({ delete: fail('That import is still running.') })
    env.click(env.modal.primary())
    await env.flush()
    assert.equal(env.modal.title(), 'Could not delete')
    assert.equal(env.modal.note(), 'That import is still running.')
    assert.equal(env.modal.status(), 'error')
    assert.deepEqual(env.modal.labels(), ['Close'])
  })

  it('reports a non-JSON answer from admin-ajax', async () => {
    const env = await history()
    env.window.fetch = () => Promise.resolve({ status: 500, text: () => Promise.resolve('<h1>Critical error</h1>') })
    env.click(env.modal.primary())
    await env.flush()
    assert.equal(env.modal.note(), 'Unexpected server response (HTTP 500). Critical error')
  })
})

describe('modal focus', () => {
  const tab = (env, node) => !node.dispatchEvent(new env.window.KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }))

  it('focuses the dialog when its only button is disabled, and keeps Tab inside', async () => {
    const env = await history({ delete: later().promise })
    env.click(env.modal.primary())
    const dialog = env.$('.migrateplus-modal')
    assert.equal(env.document.activeElement, dialog)
    assert.equal(tab(env, dialog), true)
  })

  it('closes on Escape after focus has left the dialog', async () => {
    const env = await history()
    env.document.activeElement.blur()
    assert.equal(env.document.activeElement, env.document.body)
    env.document.body.dispatchEvent(new env.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))
    assert.equal(env.modal.isOpen(), false)
  })

  it('brings Tab from the page behind back into the dialog', async () => {
    const env = await history()
    env.document.activeElement.blur()
    assert.equal(tab(env, env.document.body), true)
    assert.equal(env.document.activeElement, env.$('.migrateplus-modal-close'))
  })

  it('announces the title', async () => {
    const env = await history()
    assert.equal(env.$('.migrateplus-modal-title').getAttribute('aria-live'), 'polite')
  })

  const bulkDelete = async (focus) => {
    const env = boot({
      data: { view: 'history' },
      routes: {
        jobs: { jobs: [job({ status: 'complete' }), job({ id: 'authors', status: 'complete' })] },
        exports: { exports: [] },
        history_delete: { deleted: [{ id: 'books' }], skipped: [] },
      },
    })
    await env.flush()
    env.check(env.$('.migrateplus-history-panel tbody input'))
    const opener = focus(env)
    opener.focus()
    env.click(env.byText('button', 'Delete 1 entry'))
    env.click(env.modal.primary())
    await env.flush()
    env.click(env.modal.button('Close'))
    return { env, opener }
  }

  it('gives focus back to the control that opened it', async () => {
    const { env, opener } = await bulkDelete(env => env.$('#migrateplus-history-search'))
    assert.equal(env.document.activeElement, opener)
  })

  it('falls back to the page heading when that control is gone', async () => {
    const { env } = await bulkDelete(env => env.byText('button', 'Delete 1 entry'))
    assert.equal(env.document.activeElement, env.$('h1'))
  })
})
