# GrapOut, merged

GrapOut is becoming one product on one domain. This is the work that puts it
there: the CRM built to live inside grapout.com rather than beside it, the
Trade front end it sits next to, and the small amount of plumbing that serves
both from a single address.

```
crm/     the CRM (was grapme.com) — built to be served at /crm
trade/   GrapOut 2.0, built, with its Laravel backend in trade/apibase
_run/    how to run all of it locally on one port
```

## Running it

```powershell
powershell -ExecutionPolicy Bypass -File _run\start.ps1
```

Then <http://localhost:8080> — `/trade` and `/crm` behind one address, which is
the arrangement the server will use. `_run/README.md` has the detail: what is
faithful to production, what isn't, and why there is a Node gateway standing in
for Apache.

Stop it with `_run\stop.ps1`.

## What the change to the CRM actually is

It used to live at `app.grapme.com` and answer at the root. It now answers under
`/crm`, which is a build-time property in Next.js — every asset URL and every
link carries the prefix — so it is a rebuild, not a setting. Along with it:

- the four places that navigated by raw URL now carry the prefix, and the
  "Client portal" link on the sign-in page, which would have 404'd;
- signing out as a client lands on the client sign-in page instead of walking
  them off the domain they just used;
- Unipile's reply webhook now derives its address from `APP_PUBLIC_URL` on boot
  instead of being whatever was typed into a dashboard once, so moving the app
  re-points it rather than stranding it — and it refuses any address the
  internet cannot reach, so a laptop cannot hijack the live one.

Setting `NEXT_PUBLIC_BASE_PATH` empty builds exactly what ran before, so none of
this is a one-way door.

## What is deliberately not here

**The legacy grapout.com PHP site.** It is the third part of the merged tree on
disk and it is not in this repository, because it keeps production credentials —
the live MySQL password, the SMTP login, API tokens — in ordinary source files,
copied across more of them than can be audited by reading. A git history cannot
be edited afterwards, so it stays out. The `.gitignore` here is written the
other way round from the usual: nothing is tracked unless it is named.

**Dependencies and data.** `node_modules/`, `vendor/`, and the local Postgres
data directory, which is a copy of real client records.

**Every `.env`.** The `.env.example` files show what is needed.

## Where the pieces come from

`crm/` and `trade/` are copies taken from their own repositories, so changes
made here do not flow back to them and vice versa. This repository is the merged
product; those remain the homes of the two halves.
