# GrapOut, merged

One folder holding the whole of grapout.com, laid out the way the server's
document root is laid out:

```
Grapout merged/
  index / *.php …      the grapout.com site        (from GRAPOUT ORIGINAL)
  trade/               GrapOut 2.0, built           (the SPA)
  trade/apibase/       …and its Laravel backend
  crm/                 the CRM                      (was grapme)
  _run/                the only thing that is not the site: how to run it
```

That mirrors production: on the server, `/home/grapme/public_html/grapout.com/`
holds the site with `trade/` inside it, and `trade/apibase` is Laravel's public
directory. `crm/` is where the CRM joins them.

## Running it

```powershell
powershell -ExecutionPolicy Bypass -File _run\start.ps1
```

Then <http://localhost:8080>:

| | |
|---|---|
| `/` | the site — consulting, Contact Us, the old Grap pages |
| `/trade` | GrapOut 2.0 |
| `/crm` | the CRM |

**You do not type anything to sign in.** `/trade`'s form arrives filled, so the
button is the whole of it — you land in as Rahul Sharma, an ordinary member.

**`/crm` does not ask at all.** Clicking CRM in GrapOut's sidebar and being
asked to sign in again is the seam this whole exercise is about removing, so
the session is fetched in the background and you land on its dashboard. On the
server that would be single sign-on between the two apps; here it is the same
effect by the cheap route.

It fills the form; it does not bypass it. The password is still checked and the
session is still issued — the gateway is just typing for you, and the gateway
only ever runs on a laptop, so none of this can reach the real deployment. Other
seeded accounts still work if you type them: `superadmin@grapout.test`,
`admin@grapout.test` and the rest all use `GrapOut@123`; the CRM has
`admin@grapme.local` and `subadmin@grapme.local` on `Password123!`.

`/trade` signs in as a member rather than the super admin on purpose: a staff
account lands in the team view, which has no sidebar until a business page is
picked, so the merged menu — the thing worth looking at — never appears.

Stop it with `_run\stop.ps1`.

The five services run out of sight; their output goes to `_run/logs`, one
file each. Look there when something misbehaves.

## Why there is anything in `_run` at all

Apache serves a folder. Two of these three are programs, not folders: GrapOut
2.0 needs PHP running Laravel, and the CRM is Node. So something has to start
them and put them on one port, which on the server is Apache's vhost plus PHP-FPM
and a reverse proxy. Locally that is:

| File | What it is |
|---|---|
| `gateway.mjs` | The one port. Node, no dependencies — the local stand-in for the Apache vhost. |
| `legacy-router.php` | Reads the site's real `.htaccess` and applies its rewrite rules, so `/sign-in` reaches `login.php` here for the same reason it does live. |
| `legacy-nodb.php` | A database that answers "nothing" (see below). |
| `start.ps1` / `stop.ps1` | Start and stop the five processes. |

Routing, and the one order that matters:

```
/crm/api/…      → the CRM's API        (:4000, /crm taken off)
/crm…           → the CRM's web app    (:3000, prefix kept — it is built for it)
/trade/api/…    → Laravel              (:8000-8002, it takes /trade off itself)
/trade…         → trade/ on disk
everything else → the site             (:8081)
```

`/crm/api` has to be matched **before** `/crm`, or the CRM's web app answers its
own API's requests and every call 404s. Same rule, same reason, in the real
Apache include at `crm/deploy/grapout-crm/grapout-crm.conf`.

## What is true here, and what is not

**True to production:**

- One address, the same path split the server will use.
- The CRM is genuinely built for `/crm` — every asset and link carries the
  prefix. Ask for `/login` without it and you get a 404, exactly as you would live.
- GrapOut 2.0 is the real built front end on a real Laravel and the real local
  database (`grapout2`, 331 tables).
- The site's own rewrite rules, read from its own `.htaccess`.

**Not true to production:**

- **The site has no database.** It connects to a live MySQL server whose
  credentials are in its source, and a local copy has no business opening that.
  `legacy-nodb.php` stands in and answers every query with nothing, so pages
  whose content is in the template render exactly as they will, and pages that
  list rows come out empty. **You cannot sign in to the old site here** — the
  form has nothing to check against.
- **Its links are rewritten on the way out.** The site writes its own address
  into its pages, so without this, clicking Login would take you to the live
  site. The gateway swaps `grapout.com` for this address in what it serves.
- **Plain HTTP on one port.** No certificates, no https redirect.
- **Three copies of Laravel**, because PHP's built-in server answers one request
  at a time and Windows has no worker setting for it.
- **The CRM runs in dev mode**, so first paint is slower than the built image.
- **The CRM's marketing site is not wired up.** It still belongs to grapme.com,
  and whether it moves is a separate decision.

## This is a copy

The three projects were copied in as they stood. Editing anything here does not
touch `GRAPOUT ORIGINAL`, `GRAPOUT 2.0` or `grapme`, and changes made there do
not appear here. It is a snapshot to look at and click through, not the place to
develop.
