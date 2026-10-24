// Loads assets/admin.js, unchanged, into a fresh jsdom window. fetch and
// XMLHttpRequest answer from `routes`, keyed by the action without its
// "migrateplus_" prefix. The window's timers are fake: admin.js debounces the
// export count and retries a busy run, and those only fire on clock.tick().
import { readFileSync } from 'node:fs'
import { JSDOM } from 'jsdom'

const source = readFileSync(new URL('../../assets/admin.js', import.meta.url), 'utf8')
const site = 'https://example.test'

const defaults = {
  post_type: 'post',
  default_status: 'publish',
  force_status: '',
  default_author: 1,
  duplicate: 'skip',
  skip_existing: 0,
  update_ids: [],
  replace_ids: [],
  copy_ids: [],
  batch_size: 10,
  import_featured_image: 1,
  import_content_images: 0,
  create_terms: 1,
  create_authors: 0,
  rewrite_links: 1,
  import_comments: 1,
  import_menus: 1,
  dry_run: 0,
}

// The shape of MIGRATEPLUS_DATA from MigratePlus_Admin::bootstrap_data().
const bootstrap = () => ({
  ajaxUrl: site + '/wp-admin/admin-ajax.php',
  nonce: 'abc123',
  adminUrl: site + '/wp-admin/',
  view: 'new',
  importUrl: site + '/wp-admin/admin.php?page=migrateplus',
  historyUrl: site + '/wp-admin/admin.php?page=migrateplus-history',
  exportUrl: site + '/wp-admin/admin.php?page=migrateplus-export',
  exportDefaults: {
    post_types: ['post'],
    statuses: ['publish'],
    after: '',
    before: '',
    include_acf: 0,
    include_meta: 1,
    include_terms: 1,
    include_image: 1,
    include_woo: 0,
    include_comments: 0,
    include_menus: 0,
    exclude_meta: [],
    gzip: 0,
  },
  settingsUrl: '',
  openJob: '',
  resume: false,
  sources: [
    {
      id: 'json',
      label: 'JSON',
      schema: [
        {
          key: 'file',
          label: 'JSON file',
          type: 'file',
          accept: '.json,.gz,application/json,application/gzip',
          remote: false,
          required: true,
        },
      ],
    },
  ],
  postTypes: [
    {
      name: 'post',
      label: 'Post',
      plural: 'Posts',
      taxonomies: [
        { name: 'category', label: 'Category' },
        { name: 'post_tag', label: 'Tag' },
      ],
    },
    { name: 'page', label: 'Page', plural: 'Pages', taxonomies: [] },
    { name: 'attachment', label: 'Media', plural: 'Media', taxonomies: [] },
  ],
  users: [{ id: 1, name: 'Kenny' }],
  targets: [
    { key: 'post_title', label: 'Title', help: '' },
    { key: 'post_content', label: 'Content', help: '' },
    { key: 'post_name', label: 'Slug', help: '' },
  ],
  defaults,
  maxUpload: '8 MB',
  maxUploadBytes: 8 * 1024 * 1024,
  acf: { active: false, fields: {} },
  woo: { active: false },
  menus: true,
  zlib: true,
  mappings: [],
  maxRemote: '64 MB',
  tables: [],
})

export const fail = message => ({ failMessage: message })

// Every job admin.js renders needs stats, options and settings; tests
// override what they care about.
export const job = over => ({
  id: 'books',
  source: 'json',
  label: 'JSON',
  status: 'draft',
  total: 15,
  created: Math.floor(Date.now() / 1000) - 3600,
  settings: { file_name: 'books.json' },
  mapping: { fields: { post_title: 'title' } },
  options: { ...defaults },
  stats: { processed: 0, created: 0, updated: 0, skipped: 0, failed: 0 },
  log: [],
  ...over,
})

function fakeClock() {
  const timers = new Map()
  let now = 0
  let seq = 0
  return {
    setTimeout: (fn, ms = 0) => {
      timers.set(++seq, { fn, at: now + ms })
      return seq
    },
    clearTimeout: id => timers.delete(id),
    tick(ms) {
      const end = now + ms
      for (;;) {
        const due = [...timers].filter(([, t]) => t.at <= end).sort((a, b) => a[1].at - b[1].at)[0]
        if (!due) break
        timers.delete(due[0])
        now = due[1].at
        due[1].fn()
      }
      now = end
    },
  }
}

let booted = []

// For afterEach: no flow may fall back to window.confirm, alert or prompt.
// The saved-mapping rename is the one deliberate prompt; a test that expects
// it passes the answer as `prompt`, which allows it.
export function noNativeDialogs() {
  const calls = booted.flatMap(env => env.nativeDialogs)
  booted = []
  if (calls.length) throw new Error('native dialog used: ' + calls.join(', '))
}

export function boot({ data = {}, routes = {}, prompt } = {}) {
  const { window } = new JSDOM('<div id="migrateplus-app"><p>Loading…</p></div>', {
    url: site + '/wp-admin/admin.php?page=migrateplus',
    runScripts: 'outside-only',
  })
  const doc = window.document
  const requests = []
  const nativeDialogs = []
  const prompts = []
  const clock = fakeClock()

  window.MIGRATEPLUS_DATA = { ...bootstrap(), ...data }
  window.confirm = () => nativeDialogs.push('confirm')
  window.alert = () => nativeDialogs.push('alert')
  window.prompt = (...args) => {
    if (prompt === undefined) nativeDialogs.push('prompt')
    prompts.push(args)
    return prompt ?? null
  }
  window.setTimeout = clock.setTimeout
  window.clearTimeout = clock.clearTimeout
  window.scrollTo = () => {}
  window.Element.prototype.scrollIntoView = () => {}

  const respond = (body, transport) => {
    const fields = Object.fromEntries(body.entries())
    const action = fields.action.replace(/^migrateplus_/, '')
    const calls = requests.filter(r => r.action === action).length
    requests.push({ action, fields, body, transport })
    let value = routes[action]
    if (typeof value === 'function') value = value(fields, calls)
    if (value === undefined) value = fail('No mock for ' + action)
    return JSON.stringify(
      value.failMessage !== undefined
        ? { success: false, data: { message: value.failMessage } }
        : { success: true, data: value }
    )
  }

  window.fetch = (url, { body }) => {
    const text = respond(body, 'fetch')
    return Promise.resolve({ status: 200, text: () => Promise.resolve(text) })
  }

  // Uploads go through XHR for progress events.
  window.XMLHttpRequest = class {
    listeners = {}
    upload = { listeners: {}, addEventListener: (t, fn) => (this.upload.listeners[t] = fn) }
    open() {}
    addEventListener(t, fn) {
      this.listeners[t] = fn
    }
    send(body) {
      const text = respond(body, 'xhr')
      Promise.resolve().then(() => {
        this.upload.listeners.progress?.({ lengthComputable: true, loaded: 1, total: 1 })
        this.upload.listeners.load?.({})
        this.status = 200
        this.responseText = text
        this.listeners.load({})
      })
    }
  }

  window.eval(source + '\n//# sourceURL=assets/admin.js')

  const event = (node, type, init) => node.dispatchEvent(new window.Event(type, { bubbles: true, cancelable: true, ...init }))
  const actions = () => [...doc.querySelectorAll('.migrateplus-modal-actions > *')]

  const env = {
    window,
    document: doc,
    root: doc.getElementById('migrateplus-app'),
    requests,
    nativeDialogs,
    prompts,
    clock,
    sent: action => requests.filter(r => r.action === action),
    $: sel => doc.querySelector(sel),
    $$: sel => [...doc.querySelectorAll(sel)],
    byText: (sel, text) => [...doc.querySelectorAll(sel)].find(n => n.textContent.trim() === text),
    // The fakes answer on the microtask queue; a few macrotask turns let every chain settle.
    async flush() {
      for (let i = 0; i < 10; i++) await new Promise(r => setImmediate(r))
    },
    click: node => node.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true })),
    check(box, on = true) {
      box.checked = on
      event(box, 'change')
    },
    type(input, value) {
      input.value = value
      event(input, 'input')
    },
    submit: form => event(form, 'submit'),
    file: (name, content = '[]') => new window.File([content], name, { type: 'application/json' }),
    // jsdom's input.files is read-only and it has no DataTransfer, so both are set by hand.
    chooseFile(input, file) {
      Object.defineProperty(input, 'files', { value: [file] })
      event(input, 'change')
    },
    drop(target, file) {
      const e = new window.Event('drop', { bubbles: true, cancelable: true })
      Object.defineProperty(e, 'dataTransfer', { value: { files: [file] } })
      target.dispatchEvent(e)
    },
    modal: {
      isOpen: () => !!doc.querySelector('.migrateplus-modal'),
      status: () => doc.querySelector('.migrateplus-modal').dataset.status,
      title: () => doc.querySelector('.migrateplus-modal-title').textContent,
      message: () => doc.querySelector('.migrateplus-modal-message').textContent,
      note: () => doc.querySelector('.migrateplus-modal-note').textContent,
      icon: () => doc.querySelector('.migrateplus-modal-icon .dashicons').className,
      labels: () => actions().map(b => b.textContent),
      button: label => actions().find(b => b.textContent === label),
      primary: () => doc.querySelector('.migrateplus-modal-actions .button-primary'),
    },
  }
  booted.push(env)
  return env
}

// apiBody JSON-encodes objects and arrays.
export const json = (req, key) => JSON.parse(req.fields[key])
