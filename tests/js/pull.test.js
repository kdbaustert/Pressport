import { describe, it, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { boot, job, fail, noNativeDialogs } from './helpers.js'

afterEach(noNativeDialogs)

// What the old site's /site route answers through ajax_pull_check.
const oldSite = {
  name: 'Old Site',
  url: 'https://old.test',
  version: '0.1.0',
  types: [
    { type: 'post', label: 'Posts', count: 12 },
    { type: 'page', label: 'Pages', count: 0 },
  ],
}

// pull_fetch answers like preview does: the downloaded file's first records.
const fetched = {
  job: job({
    total: 12,
    settings: { file_name: 'pull.json', migrateplus_site: 'https://old.test', migrateplus_post_type: 'post' },
    mapping: { fields: { post_title: 'title' } },
  }),
  records: [{ title: 'Dune' }],
  fields: ['title'],
}

const pullForm = env => env.$('.migrateplus-pull form')

async function connect(env, values) {
  for (const [key, value] of Object.entries(values)) {
    env.type(env.$('#migrateplus-pull-' + key), value)
  }
  env.submit(pullForm(env))
  // Twice: with an earlier test's window still around, React's scheduler needs
  // more macrotask turns than one flush gives before this window re-renders.
  await env.flush()
  await env.flush()
}

describe('Pull', () => {
  it('connects with an application password and pulls into the import confirmation', async () => {
    const env = boot({
      routes: {
        pull_check: oldSite,
        pull_start: { export: 'exp123', total: 12 },
        pull_run: (fields, calls) =>
          calls === 0 ? { done: 6, total: 12, complete: false } : { done: 12, total: 12, complete: true, filename: 'pull.json' },
        pull_fetch: fetched,
        duplicates: { count: 0, total: 12 },
      },
    })

    await connect(env, { url: 'https://old.test', user: 'kenny', pass: 'abcd efgh ijkl' })
    const check = env.sent('pull_check')[0].fields
    assert.equal(check.url, 'https://old.test')
    assert.equal(check.user, 'kenny')
    assert.equal(check.pass, 'abcd efgh ijkl')
    assert.equal(check.key, undefined)
    assert.match(env.$('.migrateplus-pull-site').textContent, /^Connected to Old Site \(old\.test\)\./)

    // Posts preselected, the empty Pages chip disabled.
    assert.equal(env.$('#migrateplus-pull-type-post').checked, true)
    assert.equal(env.$('#migrateplus-pull-type-page').disabled, true)

    env.click(env.byText('button', 'Pull 12 items'))
    await env.flush()

    assert.deepEqual(
      env.requests.map(r => r.action).filter(a => a.startsWith('pull_')),
      ['pull_check', 'pull_start', 'pull_run', 'pull_run', 'pull_fetch']
    )
    const start = env.sent('pull_start')[0].fields
    assert.equal(start.post_types, 'post')
    assert.equal(start.user, 'kenny')
    assert.equal(start.pass, 'abcd efgh ijkl')
    assert.equal(env.sent('pull_fetch')[0].fields.filename, 'pull.json')
    assert.equal(env.modal.title(), 'Import 12 items?')
  })

  it('connects with the pull key instead, sending no username or password', async () => {
    const env = boot({ routes: { pull_check: oldSite } })

    env.check(env.$('#migrateplus-pull-method-key'))
    assert.equal(env.$('#migrateplus-pull-user'), null)
    assert.equal(env.$('#migrateplus-pull-pass'), null)

    await connect(env, { url: 'https://old.test', key: 'k3y-from-the-old-site' })
    const check = env.sent('pull_check')[0].fields
    assert.equal(check.key, 'k3y-from-the-old-site')
    assert.equal(check.user, undefined)
    assert.equal(check.pass, undefined)
    assert.match(env.$('.migrateplus-pull-site').textContent, /^Connected to Old Site/)
  })

  it('switching back to the password method brings its fields back', () => {
    const env = boot()
    env.check(env.$('#migrateplus-pull-method-key'))
    env.check(env.$('#migrateplus-pull-method-password'))
    assert.ok(env.$('#migrateplus-pull-user'))
    assert.ok(env.$('#migrateplus-pull-pass'))
    assert.equal(env.$('#migrateplus-pull-key'), null)
  })

  it('shows what the old site said when the pull fails', async () => {
    const env = boot({
      routes: {
        pull_check: oldSite,
        pull_start: fail('The old site said: This needs a user allowed to run Migrate Plus.'),
      },
    })

    await connect(env, { url: 'https://old.test', user: 'kenny', pass: 'abcd efgh ijkl' })
    env.click(env.byText('button', 'Pull 12 items'))
    await env.flush()

    assert.equal(env.modal.title(), 'Could not pull from the old site')
    assert.equal(env.modal.note(), 'The old site said: This needs a user allowed to run Migrate Plus.')
    env.click(env.modal.button('Close'))
    assert.equal(env.modal.isOpen(), false)
  })

  it('refuses to connect when the check fails, without opening a modal', async () => {
    const env = boot({ routes: { pull_check: fail('Could not reach the old site: cURL error 6') } })

    await connect(env, { url: 'https://old.test', user: 'kenny', pass: 'abcd efgh ijkl' })

    assert.equal(env.modal.isOpen(), false)
    assert.match(env.$('.migrateplus-pull .notice-error').textContent, /Could not reach the old site/)
    assert.ok(pullForm(env), 'still disconnected')
  })
})
