# Serving this app as grapout.com/crm

GrapOut is the product being advertised, so the CRM moves behind that name.
Nothing is rewritten: the same containers, the same Postgres volume, the same
clients and the same running campaigns — reached through grapout.com instead of
grapme.com.

Apache holds the visitor's connection and fetches from `127.0.0.1:3000` /
`:4000` on the same box. The address bar says `grapout.com/crm` throughout; it
is a proxy, not a redirect, and nobody ever sees grapme.com.

## What moves

The whole app, every page of it, under `/crm`: the admin side, the client
portal (`/crm/client`), sign-in, password reset, email verification. The links
the API puts in its own mail are built by pasting a path onto `WEB_PUBLIC_URL`,
so they follow automatically — **as long as that value has no trailing slash.**

The public marketing site (`apps/marketing`, port 3200) does **not** move. It
keeps serving grapme.com exactly as it does today; putting it on grapout.com is
a separate, content-shaped decision.

Two places used to leave the app for it, and only one still should:

- **Signing out as a client** went to the marketing site. It now goes to
  `/crm/client`, the client's own sign-in screen — a client should not be
  walked off the domain the app is served from just to come back in.
- **"View" on a published blog post** still opens the marketing site, because
  that is where the post actually is. `NEXT_PUBLIC_MARKETING_URL` points there,
  and changes the day those pages move.

## Read this first: the one thing that must not happen

**grapme.com has to keep resolving, permanently.** Every email a client has
already sent has `https://api.grapme.com/api/v1/t/click/…` wrapped around each
link and a `/t/open/….png` pixel at the bottom (`apps/api/src/sending/
tracking.util.ts`). Retire that hostname and the links in mail already sitting
in prospects' inboxes go dead. The existing grapme.com vhosts stay exactly as
they are — this runbook adds a second front door, it does not close the first.

Two more, while we are here:

- `CREDENTIAL_ENCRYPTION_KEY` in `/opt/aeo/.env` decrypts every client's
  mailbox. Back it up somewhere other than that file before touching anything.
- Never `docker compose down -v`. The `-v` is the database.

## What the clients will notice

**They get signed out once.** Sessions live in `localStorage`, which belongs to
the origin that set it, so a move from `app.grapme.com` to `grapout.com` leaves
them behind. Accounts, data and campaigns are untouched — it is one extra login.
Worth an email the day before.

If any LinkedIn seat is mid-connection (status PENDING), finish or re-issue it
after the cutover: Unipile calls back to `APP_PUBLIC_URL`, which changes here.

---

## 0. Look before touching (read-only)

```bash
cd /opt/aeo
docker compose -f docker-compose.prod.yml ps
grep -E '^(APP_IMAGE|WEB_PUBLIC_URL|APP_PUBLIC_URL|NEXT_PUBLIC_API_URL|BIND_HOST)=' .env
ls /etc/apache2/conf.d/userdata/std/2_4/grapme/ 2>/dev/null
apachectl -M 2>/dev/null | grep -E 'proxy_module|proxy_http_module'
```

Write down the current `APP_IMAGE` line (or `ghcr.io/himanshu13485-pixel/
grapme:latest` if there isn't one). That is the rollback.

`proxy_module` and `proxy_http_module` must both be listed. If they are not,
enable them in WHM → EasyApache 4 before going further.

## 1. Build the image on this server

The browser bundle bakes `NEXT_PUBLIC_API_URL` and `NEXT_PUBLIC_BASE_PATH` at
build time, so an image built for grapme.com cannot serve grapout.com/crm. This
builds here, which also drops the dependency on someone else's CI:

```bash
cd /opt/aeo
git fetch origin && git checkout feat/grapout-crm-basepath
bash deploy/grapout-crm/build-image.sh
```

Takes a few minutes. Nothing goes live — the running containers are untouched.

## 2. Put the proxy in place

```bash
install -D -m 644 /opt/aeo/deploy/grapout-crm/grapout-crm.conf \
  /etc/apache2/conf.d/userdata/std/2_4/grapme/grapout.com/crm.conf
install -D -m 644 /opt/aeo/deploy/grapout-crm/grapout-crm.conf \
  /etc/apache2/conf.d/userdata/ssl/2_4/grapme/grapout.com/crm.conf
/scripts/ensure_vhost_includes --user=grapme
apachectl configtest && /scripts/restartsrv_httpd
```

`/crm` will 503 until step 3 — the rule is there, the app behind it is not.
The rest of grapout.com (the site, `/trade`, `/office`) is unaffected: this
include only claims `/crm`.

## 3. Point the stack at the new image

In `/opt/aeo/.env`:

```
APP_IMAGE=grapme:grapout-crm
WEB_PUBLIC_URL=https://www.grapout.com/crm
APP_PUBLIC_URL=https://www.grapout.com/crm
NEXT_PUBLIC_API_URL=https://www.grapout.com/crm/api/v1
```

`APP_PUBLIC_URL` has no `/api` on the end — the code appends `/api/v1` itself
when it builds tracking and webhook URLs.

```bash
cd /opt/aeo
docker compose -f docker-compose.prod.yml up -d
```

Only the containers whose settings changed are recreated. Postgres and Redis
are not among them; their volumes are never touched.

## 3b. Point the old app address at the new one

Do this in the same sitting, not later. From the moment step 3 lands, the
container answers only under `/crm`, so every bookmark of
`app.grapme.com/<page>` is a 404 until this is in place.

```bash
install -D -m 644 /opt/aeo/deploy/grapout-crm/app-grapme-redirect.conf \
  /etc/apache2/conf.d/userdata/std/2_4/grapme/app.grapme.com/redirect.conf
install -D -m 644 /opt/aeo/deploy/grapout-crm/app-grapme-redirect.conf \
  /etc/apache2/conf.d/userdata/ssl/2_4/grapme/app.grapme.com/redirect.conf
/scripts/ensure_vhost_includes --user=grapme
apachectl configtest && /scripts/restartsrv_httpd
```

`api.grapme.com` is deliberately not touched by this. It stays a silent proxy
to `:4000` forever — the tracking links in mail already delivered point at it.

## 4. Check

```bash
curl -sI https://www.grapout.com/crm | head -3            # 200
curl -s  https://www.grapout.com/crm/api/v1/health        # the API answers
curl -sI https://api.grapme.com/api/v1/health | head -1   # STILL 200 — old links
```

Then in a browser: sign in at `https://www.grapout.com/crm`, open a campaign,
and confirm a client can reach the portal.

## Rolling back

```bash
cd /opt/aeo
# restore the four .env lines to their old values, then:
docker compose -f docker-compose.prod.yml up -d
rm -f /etc/apache2/conf.d/userdata/{std,ssl}/2_4/grapme/grapout.com/crm.conf
rm -f /etc/apache2/conf.d/userdata/{std,ssl}/2_4/grapme/app.grapme.com/redirect.conf
/scripts/ensure_vhost_includes --user=grapme && /scripts/restartsrv_httpd
```

The redirect has to come out too — with the old image back, `app.grapme.com`
serves the app again and must stop bouncing people to a `/crm` that is no
longer there.

The old image is still on the box, so this is a restart, not a rebuild.
Campaigns that ran in the meantime keep their data either way.

## Afterwards

Nothing outstanding for the cutover itself. The remaining grapme.com pieces are
a separate decision: the marketing site on `:3200` still serves grapme.com, and
whether that becomes a page of grapout.com is a content question, not this one.
