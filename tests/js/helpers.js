// Loads assets/admin.js, unchanged, into a fresh jsdom window. fetch and
// XMLHttpRequest answer from `routes`, keyed by the action without its
// "migrateplus_" prefix. A route may answer with a promise, to hold the
// response back, or with `offline` or `hang`. The window's timers are fake:
// admin.js debounces the export count, retries a busy run and times requests
// out, and those only fire on clock.tick().
//
// WordPress gives admin.js its React as wp.element. The tests build that from
// React 18's UMD builds, the last React with them; the development builds
// print React's warnings. wp.i18n has no translations loaded, so strings come
// back as written.
import { readFileSync } from 'node:fs'
import { JSDOM } from 'jsdom'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
const source = read('../../assets/admin.js')
// By path: the UMD builds are not in React's package exports.
const react = read('../../node_modules/react/umd/react.development.js')
const reactDom = read('../../node_modules/react-dom/umd/react-dom.development.js')
const site = 'https://example.test'

const defaults = {
  post_type: 'post',
  default_status: 'publish',
  force_status: '',
  author: 0,
  // Empty PHP arrays, as wp_localize_script() sends them.
  authors: [],
  author_map: [],
  duplicate: 'skip',
  skip_existing: 0,
  update_ids: [],
  replace_ids: [],
  copy_ids: [],
  skip_ids: [],
  duplicate_rest: '',
  batch_size: 10,
  import_featured_image: 1,
  import_content_images: 0,
  create_terms: 1,
  rewrite_links: 1,
  import_comments: 1,
  import_menus: 1,
  import_term_data: 1,
  import_acf_definitions: 1,
  import_acf_options: 1,
  import_site_settings: 1,
  import_orders: 1,
  import_redirects: 1,
  dry_run: 0,
}

// The shape of MIGRATEPLUS_DATA from MigratePlus_Admin::bootstrap_data().
const bootstrap = () => ({
  ajaxUrl: site + '/wp-admin/admin-ajax.php',
  nonce: 'abc123',
  adminUrl: site + '/wp-admin/',
  view: 'new',
  importUrl: site + '/wp-admin/admin.php?page=migrateplus',
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
    include_acf_definitions: 1,
    include_acf_options: 1,
    include_site_settings: 0,
    site_sections: ['general', 'reading'],
    include_orders: 0,
    include_yoast: 1,
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
  authors: [
    { id: 1, name: 'Kenny', login: 'kenny' },
    { id: 2, name: 'Ada', login: 'ada' },
  ],
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
  sides: { site: true, orders: false, yoast: false },
  siteSections: [
    { key: 'general', label: 'General', help: 'Site title, tagline, time zone' },
    { key: 'reading', label: 'Reading', help: 'Front page and posts page' },
  ],
  zlib: true,
  mappings: [],
  maxRemote: '64 MB',
  tables: [],
})

// code is the WP_Error code MigratePlus_Admin::fail() sends with the message.
export const fail = (message, code) => ({ failMessage: message, failCode: code })
// fetch rejects, as it does when the connection drops.
export const offline = { offline: true }
// fetch never answers, until admin.js aborts it.
export const hang = { hang: true }

// A route answer that arrives when the test calls resolve().
export function later() {
  let resolve
  const promise = new Promise(r => (resolve = r))
  return { promise, resolve }
}

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

// wp-i18n's three functions, as they behave with no translations loaded.
const i18n = {
  __: text => text,
  _n: (single, plural, n) => (n === 1 ? single : plural),
  sprintf: (format, ...args) => {
    let next = 0
    return format.replace(/%(?:(\d+)\$)?[sd]/g, (m, pos) => String(args[pos ? pos - 1 : next++]))
  },
}

let booted = []

// For afterEach: no flow may fall back to window.confirm, alert or prompt.
export function noNativeDialogs() {
  const calls = booted.flatMap(env => env.nativeDialogs)
  booted = []
  if (calls.length) throw new Error('native dialog used: ' + calls.join(', '))
}

export function boot({ data = {}, routes = {}, url = site + '/wp-admin/admin.php?page=migrateplus', setup } = {}) {
  // The page as MigratePlus_Admin::render_shell() prints it.
  const html = '<div class="wrap migrateplus-wrap"><h1 class="wp-heading-inline">Import Content</h1><div id="migrateplus-app"><p>Loading…</p></div></div>'
  const { window } = new JSDOM(html, { url, runScripts: 'outside-only' })
  const doc = window.document
  const requests = []
  const xhrs = []
  const nativeDialogs = []
  const clock = fakeClock()

  // Before the fake timers go in: React's scheduler keeps the setTimeout it finds.
  window.eval(react)
  window.eval(reactDom)
  // What react-dom/client sets, so createRoot doesn't warn that it came from react-dom.
  window.ReactDOM.__SECRET_INTERNALS_DO_NOT_USE_OR_YOU_WILL_BE_FIRED.usingClientEntryPoint = true
  window.wp = { element: { ...window.React, ...window.ReactDOM }, i18n }

  window.MIGRATEPLUS_DATA = { ...bootstrap(), ...data }
  window.confirm = () => nativeDialogs.push('confirm')
  window.alert = () => nativeDialogs.push('alert')
  window.prompt = () => nativeDialogs.push('prompt')
  window.setTimeout = clock.setTimeout
  window.clearTimeout = clock.clearTimeout
  window.scrollTo = () => {}
  window.Element.prototype.scrollIntoView = () => {}

  const answer = (body, transport) => {
    const fields = Object.fromEntries(body.entries())
    const action = fields.action.replace(/^migrateplus_/, '')
    const calls = requests.filter(r => r.action === action).length
    requests.push({ action, fields, body, transport })
    let value = routes[action]
    if (typeof value === 'function') value = value(fields, calls)
    if (value === undefined) value = fail('No mock for ' + action)
    return value
  }
  const encode = value =>
    JSON.stringify(
      value.failMessage !== undefined
        ? { success: false, data: { message: value.failMessage, ...(value.failCode && { code: value.failCode }) } }
        : { success: true, data: value }
    )

  window.fetch = (url, { body, signal }) =>
    Promise.resolve(answer(body, 'fetch')).then(value => {
      if (value === offline) throw new window.TypeError('Failed to fetch')
      if (value === hang) {
        return new Promise((resolve, reject) =>
          signal.addEventListener('abort', () => reject(new window.DOMException('The operation was aborted.', 'AbortError')))
        )
      }
      return { status: 200, text: () => Promise.resolve(encode(value)) }
    })

  // Uploads go through XHR for progress events. The body is sent at once; the
  // answer comes when the route's promise settles, unless abort() came first.
  window.XMLHttpRequest = class {
    listeners = {}
    upload = { listeners: {}, addEventListener: (t, fn) => (this.upload.listeners[t] = fn) }
    open() {}
    addEventListener(t, fn) {
      this.listeners[t] = fn
    }
    send(body) {
      xhrs.push(this)
      const value = answer(body, 'xhr')
      Promise.resolve()
        .then(() => {
          this.upload.listeners.progress?.({ lengthComputable: true, loaded: 1, total: 1 })
          this.upload.listeners.load?.({})
          return value
        })
        .then(value => {
          if (this.aborted) return
          this.status = 200
          this.responseText = encode(value)
          this.listeners.load({})
        })
    }
    abort() {
      this.aborted = true
      this.listeners.abort?.({})
    }
  }

  // A test's chance to plant globals the plugin's other enqueued scripts would have left,
  // such as the calendar library, before admin.js runs and reads them.
  setup?.(window)

  window.eval(source + '\n//# sourceURL=assets/admin.js')

  const event = (node, type, init) => node.dispatchEvent(new window.Event(type, { bubbles: true, cancelable: true, ...init }))
  const click = node => node.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }))
  const actions = () => [...doc.querySelectorAll('.migrateplus-modal-actions > *')]

  const env = {
    window,
    document: doc,
    root: doc.getElementById('migrateplus-app'),
    requests,
    xhrs,
    nativeDialogs,
    clock,
    sent: action => requests.filter(r => r.action === action),
    $: sel => doc.querySelector(sel),
    $$: sel => [...doc.querySelectorAll(sel)],
    byText: (sel, text) => [...doc.querySelectorAll(sel)].find(n => n.textContent.trim() === text),
    // The fakes answer on the microtask queue; a few macrotask turns let every chain settle.
    async flush() {
      for (let i = 0; i < 10; i++) await new Promise(r => setImmediate(r))
    },
    // Flushes until what the test waits for has rendered. Bounded, so a render
    // that never comes still fails the assertion instead of hanging.
    async until(rendered) {
      for (let i = 0; i < 100 && !rendered(); i++) await new Promise(r => setImmediate(r))
    },
    click,
    // Whether leaving the page now would ask first.
    leaveWarns: () => !window.dispatchEvent(new window.Event('beforeunload', { cancelable: true })),
    // As a user does it: React reads a checkbox's change from the click.
    check(box, on = true) {
      if (box.checked !== on) click(box)
    },
    // Through the prototype's setter, as typing does: React ignores a value set
    // on the node itself, which is how it tells its own writes apart.
    type(input, value) {
      Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(input, value)
      event(input, 'input')
    },
    // A select changed by the user: React reads the new value from the change event.
    choose(select, value) {
      select.value = value
      event(select, 'change')
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
