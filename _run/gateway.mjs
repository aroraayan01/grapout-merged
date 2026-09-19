/**
 * grapout.com, locally, in one address.
 *
 * On the real server this job belongs to Apache: one domain, and rules that
 * decide which of three applications answers each path. This is the same set
 * of rules in about a hundred lines of Node, so the merged site can be looked
 * at on a laptop without cPanel, Docker or a second machine.
 *
 *   /            the legacy PHP site        (php -S on 8081)
 *   /trade       GrapOut 2.0                (SPA from disk + Laravel on 8000)
 *   /crm         the CRM, formerly grapme   (Next.js on 3000, Nest on 4000)
 *
 * Order matters the same way it does in Apache: /crm/api has to be tried
 * before /crm, or the Next.js app answers the API's requests and every call
 * 404s. The comment lives in deploy/grapout-crm/grapout-crm.conf too, because
 * getting it wrong looks like a broken app rather than a routing mistake.
 */

import { createServer, request as httpRequest } from 'node:http'
import { createReadStream, promises as fs } from 'node:fs'
import { extname, join, normalize, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = fileURLToPath(new URL('.', import.meta.url))
const PORT = Number(process.env.PORT ?? 8080)

/** Where each piece lives while this is running. */
const UP = {
  legacy: { host: '127.0.0.1', port: 8081 },
  trade: { host: '127.0.0.1', port: 8000 },
  crmWeb: { host: '127.0.0.1', port: 3000 },
  crmApi: { host: '127.0.0.1', port: 4000 },
}

/*
 * PHP's built-in server answers one request at a time, and on Windows there is
 * no worker setting to change that. The app opens a page by firing half a
 * dozen calls at once, so one slow query holds up everything behind it and the
 * screen looks hung. start.ps1 runs three copies; requests go round them in
 * turn. Apache on the real server has a pool of its own, which is why this
 * only matters here.
 */
const TRADE_POOL = [8000, 8001, 8002]
let nextWorker = 0
function tradeWorker() {
  const ports = TRADE_POOL.filter((p) => !deadWorkers.has(p))
  const pool = ports.length ? ports : [TRADE_POOL[0]]
  const port = pool[nextWorker++ % pool.length]

  return { host: '127.0.0.1', port }
}
/** Ports that refused a connection, so the pool stops sending work there. */
const deadWorkers = new Set()

/** The merged site itself: this folder is the document root. */
const SITE = resolve(HERE, '..')
/** The built GrapOut 2.0 front end, served straight off disk as Apache does. */
const SPA = resolve(SITE, 'trade')
/** Uploads, which live in Laravel's storage and are served as /trade/storage/*. */
const TRADE_STORAGE = resolve(SITE, 'trade', 'apibase', 'storage', 'app', 'public')

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.webmanifest': 'application/manifest+json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.map': 'application/json; charset=utf-8',
  '.txt': 'text/plain; charset=utf-8',
  '.pdf': 'application/pdf',
}

/** Decide who answers, and on what path. Returns a proxy or a file plan. */
function route(url) {
  const path = url.split('?')[0]

  // --- The CRM -------------------------------------------------------------
  // Its API first, exactly as in the Apache include. The app is BUILT with
  // basePath=/crm, so its own path is passed through untouched; the API knows
  // nothing about the prefix, so /crm is taken off before it is forwarded.
  if (path === '/crm/api' || path.startsWith('/crm/api/')) {
    return { kind: 'proxy', to: UP.crmApi, url: url.slice('/crm'.length) }
  }
  if (path === '/crm' || path.startsWith('/crm/')) {
    return { kind: 'proxy', to: UP.crmWeb, url }
  }

  // --- GrapOut 2.0 ---------------------------------------------------------
  // Laravel takes /trade off itself (APP_URL_PREFIX), so it gets the full path.
  if (/^\/trade\/(api|broadcasting)(\/|$)/.test(path)) {
    return { kind: 'proxy', to: tradeWorker(), url }
  }
  if (path.startsWith('/trade/storage/')) {
    return { kind: 'file', root: TRADE_STORAGE, rel: path.slice('/trade/storage/'.length) }
  }
  if (path === '/trade' || path.startsWith('/trade/')) {
    const rel = path.slice('/trade'.length).replace(/^\/+/, '')
    // apibase is Laravel's own public dir, sitting inside the same folder the
    // SPA is served from. It is reached through the /trade/api rule above and
    // must never be readable as plain files.
    if (rel === 'apibase' || rel.startsWith('apibase/')) return { kind: 'file', root: SPA, rel: '__no__' }
    // A missing build asset is a 404, never the app shell — a stale index.html
    // asking for a chunk that is not there should say so, not render a page.
    return { kind: 'file', root: SPA, rel, spa: !rel.startsWith('assets/') }
  }

  // --- Everything else is the site that was there first ---------------------
  return { kind: 'proxy', to: UP.legacy, url }
}

/*
 * The legacy site writes its own address into its pages — SITE_WS_PATH, and a
 * fair number of hand-written https://www.grapout.com/... links. Left alone,
 * clicking Login on a local preview takes you to the live site, and its fonts
 * are fetched across origins and blocked.
 *
 * So anything it says about itself is rewritten on the way out. Only for the
 * legacy site, only for text, and only here: the files on disk are untouched.
 */
const SELF = /https?:\/\/(?:www\.)?grapout\.com/g
const REWRITABLE = /^(?:text\/html|text\/css|application\/javascript|text\/javascript)/i

function localise(body, origin) {
  return body.replace(SELF, origin)
}

/*
 * Sign-in, without typing anything.
 *
 * This is a copy of the site for looking at, and asking someone to remember two
 * sets of demo credentials to click through it is friction with no purpose. So
 * the sign-in form arrives already filled and you press the button.
 *
 * It fills a form; it does not bypass one. The password is still checked, the
 * session is still issued, and the accounts are the ordinary seeded ones. That
 * matters: nothing here weakens the apps themselves, so none of it can escape
 * into the real deployment — it lives in this gateway, which only ever runs on
 * a laptop.
 *
 * Only on a page with exactly one password box, so a change-password or
 * registration form is left alone.
 */
const DEMO = `<script>(function(){
  // An ordinary member for GrapOut 2.0, not the super admin: staff accounts
  // land in the team view, which has no sidebar until a business page is
  // picked — so the merged menu, the thing worth looking at, never appears.
  // superadmin@grapout.test with the same password still works if you type it.
  var creds = location.pathname.indexOf('/crm') === 0
    ? { id: 'admin@grapme.local', pw: 'Password123!' }
    : { id: 'rahul@grapout.test', pw: 'GrapOut@123' };
  function set(el, v) {
    // React keeps its own copy of the value, so a plain assignment is ignored
    // the moment it re-renders. The native setter plus an input event is what
    // a keystroke looks like from the outside.
    var proto = Object.getPrototypeOf(el);
    var setter = Object.getOwnPropertyDescriptor(proto, 'value').set;
    setter.call(el, v);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }
  function fill() {
    var pws = document.querySelectorAll('input[type=password]');
    if (pws.length !== 1) return;
    var pw = pws[0];
    if (pw.value) return;
    var scope = pw.form || document;
    var id = scope.querySelector('input[type=email], input[type=tel], input[type=text], input:not([type])');
    if (id && !id.value) set(id, creds.id);
    set(pw, creds.pw);
  }
  // No "already done" flag: React throws the server's markup away when it
  // hydrates, which emptied the boxes the first attempt had filled. Filling
  // only ever when a box is empty is both the retry and the guard.
  var watch = new MutationObserver(fill);
  function start() {
    watch.observe(document.body, { childList: true, subtree: true });
    [0, 200, 800, 2000].forEach(function (ms) { setTimeout(fill, ms); });
    // Then stop, so a box cleared on purpose stays cleared.
    setTimeout(function () { watch.disconnect(); }, 6000);
  }
  if (document.body) start();
  else document.addEventListener('DOMContentLoaded', start);

  /*
   * The CRM does not ask at all.
   *
   * Signing in once should be enough: arriving at /crm from GrapOut's own
   * sidebar and being asked again is the seam this whole exercise is about
   * removing. On the server that is a proper single sign-on between the two
   * apps; here it is the same thing done the cheap way — the session is
   * fetched once, in the background, and kept where the app looks for it.
   *
   * Still a real sign-in: the API checks the password and issues the tokens.
   */
  if (location.pathname.indexOf('/crm') === 0) {
    var TOKEN = 'aeo_access_token', REFRESH = 'aeo_refresh_token';
    var tried = 'crm_auto_signin_failed';
    if (!localStorage.getItem(TOKEN) && !sessionStorage.getItem(tried)) {
      fetch('/crm/api/v1/auth/login', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ email: creds.id, password: creds.pw }),
      })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !d.accessToken) {
            // Remember the failure, or a bad password becomes a reload loop.
            sessionStorage.setItem(tried, '1');

            return;
          }
          localStorage.setItem(TOKEN, d.accessToken);
          localStorage.setItem(REFRESH, d.refreshToken);
          location.replace(/\\/crm\\/?(login|client)?$/.test(location.pathname) ? '/crm/dashboard' : location.href);
        })
        .catch(function () { sessionStorage.setItem(tried, '1'); });
    }
  }
})();</script>`

function prefill(html) {
  return html.includes('</body>') ? html.replace('</body>', DEMO + '</body>') : html + DEMO
}

function proxy(req, res, to, url) {
  /*
   * The visitor's Host is passed through untouched — Apache's
   * ProxyPreserveHost On, and for the same reason: the site builds its
   * canonical link and other absolute URLs from HTTP_HOST, so rewriting it to
   * the internal port makes the page advertise 127.0.0.1 to the world.
   */
  const headers = { ...req.headers }
  headers['x-forwarded-host'] = req.headers.host ?? `localhost:${PORT}`
  // Pages are edited on the way out, so ask for them uncompressed rather than
  // carry a gzip decoder around for a preview.
  delete headers['accept-encoding']
  headers['x-forwarded-proto'] = 'http'
  headers['x-forwarded-for'] = req.socket.remoteAddress ?? ''

  const upstream = httpRequest({ host: to.host, port: to.port, method: req.method, path: url, headers }, (up) => {
    const origin = `http://${req.headers.host ?? `localhost:${PORT}`}`
    const out = { ...up.headers }

    // A redirect that names the live site would leave the preview entirely.
    if (typeof out.location === 'string') out.location = localise(out.location, origin)

    const type = String(out['content-type'] ?? '')
    const fromSite = to.port === UP.legacy.port
    const isHtml = /^text\/html/i.test(type)
    const rewrite = isHtml || (fromSite && REWRITABLE.test(type))
    if (!rewrite) {
      res.writeHead(up.statusCode ?? 502, out)

      return up.pipe(res)
    }

    // Held whole, because the address can straddle a chunk boundary, and the
    // length changes with every replacement.
    const chunks = []
    up.on('data', (c) => chunks.push(c))
    up.on('end', () => {
      let body = Buffer.concat(chunks).toString('utf8')
      if (fromSite) body = localise(body, origin)
      if (isHtml) body = prefill(body)
      delete out['content-length']
      res.writeHead(up.statusCode ?? 502, { ...out, 'content-length': Buffer.byteLength(body) })
      res.end(body)
    })
  })

  upstream.on('error', (err) => {
    // A worker that is not there is taken out of the rotation rather than
    // failing every third request for the rest of the session.
    if (err.code === 'ECONNREFUSED' && TRADE_POOL.includes(to.port) && to.port !== TRADE_POOL[0]) {
      deadWorkers.add(to.port)
    }
    if (res.headersSent) return res.destroy()
    res.writeHead(502, { 'content-type': 'text/html; charset=utf-8' })
    res.end(offline(to, err))
  })

  req.pipe(upstream)
}

async function file(res, plan) {
  const rel = normalize(plan.rel).replace(/^([.][.][/\\])+/, '')
  let full = join(plan.root, rel)
  if (!full.startsWith(plan.root)) return notFound(res, plan.rel)

  let stat = await fs.stat(full).catch(() => null)
  if (stat?.isDirectory()) {
    full = join(full, 'index.html')
    stat = await fs.stat(full).catch(() => null)
  }
  if (!stat) {
    // Any path the app owns falls back to its shell, the way the SPA rule does.
    if (!plan.spa) return notFound(res, plan.rel)
    full = join(plan.root, 'index.html')
    stat = await fs.stat(full).catch(() => null)
    if (!stat) return notFound(res, plan.rel)
  }

  const type = TYPES[extname(full).toLowerCase()] ?? 'application/octet-stream'

  // The app's shell gets the same treatment a proxied page does, so its
  // sign-in form is filled too.
  if (type.startsWith('text/html')) {
    const body = prefill(await fs.readFile(full, 'utf8'))
    res.writeHead(200, { 'content-type': type, 'content-length': Buffer.byteLength(body), 'cache-control': 'no-cache' })

    return res.end(body)
  }

  res.writeHead(200, { 'content-type': type, 'content-length': stat.size, 'cache-control': 'no-cache' })
  createReadStream(full).pipe(res)
}

function notFound(res, what) {
  res.writeHead(404, { 'content-type': 'text/html; charset=utf-8' })
  res.end(`<!doctype html><meta charset=utf-8><title>404</title>
<body style="font:15px/1.6 system-ui;padding:48px;max-width:40rem">
<h1 style="font-size:1.4rem">404</h1>
<p>Nothing at <code>${what}</code>.</p>`)
}

function offline(to, err) {
  const who = Object.entries(UP).find(([, v]) => v.port === to.port)?.[0] ?? 'that service'
  return `<!doctype html><meta charset=utf-8><title>Not running</title>
<body style="font:15px/1.6 system-ui;padding:48px;max-width:42rem">
<h1 style="font-size:1.4rem">${who} is not running</h1>
<p>The gateway is up, but nothing is listening on port ${to.port}.</p>
<p style="color:#666">Start everything with <code>start.ps1</code>, or see README.md
for the one command per piece. (${err.code ?? err.message})</p>`
}

const server = createServer((req, res) => {
  const plan = route(req.url ?? '/')
  if (plan.kind === 'proxy') return proxy(req, res, plan.to, plan.url)
  file(res, plan).catch(() => notFound(res, req.url))
})

// Next.js keeps a websocket open in dev for hot reload; without this the CRM
// works but reloads nothing, which looks like a broken dev server.
server.on('upgrade', (req, socket, head) => {
  const plan = route(req.url ?? '/')
  if (plan.kind !== 'proxy') return socket.destroy()
  const headers = { ...req.headers, host: `${plan.to.host}:${plan.to.port}` }
  const up = httpRequest({ host: plan.to.host, port: plan.to.port, method: req.method, path: plan.url, headers })
  up.on('upgrade', (upRes, upSocket, upHead) => {
    const lines = Object.entries(upRes.headers).map(([k, v]) => `${k}: ${v}`)
    socket.write(`HTTP/1.1 101 Switching Protocols\r\n${lines.join('\r\n')}\r\n\r\n`)
    if (upHead?.length) socket.unshift(upHead)
    upSocket.pipe(socket).pipe(upSocket)
  })
  up.on('error', () => socket.destroy())
  if (head?.length) up.write(head)
  up.end()
})

server.listen(PORT, () => {
  console.log(`\n  GrapOut, merged — http://localhost:${PORT}\n`)
  console.log(`    /            the site          -> :${UP.legacy.port}`)
  console.log(`    /trade       GrapOut 2.0       -> SPA on disk + :${UP.trade.port}`)
  console.log(`    /crm         the CRM           -> :${UP.crmWeb.port} + :${UP.crmApi.port}\n`)
})
