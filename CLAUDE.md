# Post45 OJS — Submissions & Editorial Platform

## Project Goal
Transform OJS 3.5 into a submissions-and-editorial management system: a Pragma child theme
provides a submissions-focused public site, and a plugin runs the editorial pipeline through
"published on WordPress" while hiding OJS's own publishing machinery.

## 🔑 Development References — READ FIRST

**Before changing OJS behavior, use the platform's own mechanism — don't invent one.** This is
OJS 3.5 (the PKP stack: Smarty frontend, Vue/Pinia admin, a PHP hook system). Its extension
points are specific and *not* reliably guessable from general web-dev experience — inferring
them wastes time and produces hacky code that then bloats the rest of the session.

**Anti-patterns — do NOT reach for these to alter OJS behavior:**
- Hiding or changing things via injected **CSS/JS as a first resort**.
- Reverse-engineering hook signatures or APIs **from source** instead of the reference.

If you catch yourself doing either, **stop** — it's a signal you haven't found the native
mechanism yet. Go back to steps 1–2. The same applies to workaround chains: if fixing one hack
needs another (inject a button → race condition → add polling → add retries…), the first step
was wrong — don't keep patching, go back and find the native path.

**User pushback is a hard stop, not a speed bump.** When the user says something sounds hacky,
over-complex, or unnecessary ("does it really need that?", "that sounds weird"), treat it as a
signal the approach is wrong. Stop, don't add another workaround to defend it, and go back to
the reference / existing pattern.

**Instead, in this order:**
1. **Read the authoritative reference** for the subsystem and cite it (map below).
2. **Find and mirror the nearest existing implementation** — another plugin or core OJS already
   doing something similar. Borrow the pattern; don't invent one.
3. **Use native extension points:** backend hooks, custom `Decision`s, theme template overrides,
   the Vue workflow `extender`.

**Task → read first:**
| If you're… | Read |
|---|---|
| Adding/changing an editorial **decision** or workflow action | existing `post45Editorial` decisions + `PLUGIN-IMPLEMENTATION-REFERENCE.md` + `OJS-DEV-NOTES.md` |
| Adding/altering a **workflow button / admin UI** | `lib/ui-library/src/pages/workflow/` (live source) + `PLUGIN-IMPLEMENTATION-REFERENCE.md` |
| Needing an exact **hook name/signature** | `docs/dev/guide/hooks.rst` (regen: `php lib/pkp/tools/getHooks.php -r`) — the answer key |
| Working on **theme / templates / CSS** | the Pragma parent theme + existing `.tpl` overrides in `pragmaSubmissions` |
| Hitting a **gotcha / needing the headless-test recipe** | `OJS-DEV-NOTES.md` |
| Wanting **past decisions / history** | `CHANGELOG.md` |

## Deployment & Upgrade Workflow

**Repo architecture:**
- Source-of-truth: `Post45-Journal/ojs` on GitHub (fork of `pkp/ojs`). Default branch `main`
  = stable-3_5_0 + Post45 customizations.
- Local: `~/dev/submissions-ojs` tracks `origin/main` (origin = fork). `upstream` remote →
  `pkp/ojs` for pulling future stable-branch updates.
- Prod (`submissions.post45.org`, Ubuntu 24.04, 1GB DO droplet): `/var/www/html` is a real git
  checkout tracking `origin/main`. Files dir `/var/www/ojs-files` (outside web root). Plugin
  monorepo at `/var/www/ojs-plugins-monorepo`.

**Day-to-day edits (local → prod):**
```bash
# Local
cd ~/dev/submissions-ojs
# ...make changes...
git push origin main

# Prod
ssh submissions.post45.org
cd /var/www/html
git pull origin main
# If front-end assets changed (rare for theme/doc-only edits):
NODE_OPTIONS=--max-old-space-size=1536 npm run build
```

**Major OJS upgrade (merge upstream stable-3_5_0 → main):**
```bash
# Local
cd ~/dev/submissions-ojs
git fetch upstream
git merge upstream/stable-3_5_0     # resolve conflicts in Post45 customizations
git push origin main

# Prod
cd /var/www/html
git pull origin main
git submodule update --init --recursive
cd lib/pkp && composer install --no-dev --optimize-autoloader && cd ../..
cd plugins/paymethod/paypal && composer install --no-dev --optimize-autoloader && cd ../../..
npm install
NODE_OPTIONS=--max-old-space-size=1536 npm run build
./scripts/post-update-assets.sh     # restores TinyMCE asset symlinks
sudo systemctl restart apache2
```

**Prod-specific quirks:**
- The 1GB droplet OOMs during Vite builds without help: 1GB swap at `/swapfile` (persistent via
  `/etc/fstab`) + `NODE_OPTIONS=--max-old-space-size=1536` on every build. Consider a 2GB droplet.
- `config.inc.php` must be `chown ojsadmin:www-data` + `chmod 640` (apache/www-data can read it,
  not world-readable).
- Plugin symlinks (colorPalettes, submissionsOnly, mailgun, pragmaSubmissions, post45Editorial)
  and TinyMCE asset symlinks (js/plugins/, js/skins/) are gitignored — recreated per environment.
- `cache/`, `public/`, and `/var/www/ojs-files` must be `chown www-data:www-data`.
- Migration record: `temp/prod-upgrade-checklist.md` (one-time June 2026 tarball→git move).
  Reference only — don't re-execute.

## Current Architecture

The system is three plugins + a database-level role cleanup. Deep detail for each lives in
`OJS-DEV-NOTES.md`; history of how they got here is in `CHANGELOG.md`.

- **Frontend theme — `pragmaSubmissions`** (`/plugins/themes/pragmaSubmissions/`): child of
  Pragma. Submissions-focused homepage (`indexJournal.tpl`), submission-guidelines page, and
  section-specific CFP pages at `/about?cfp=1&sectionId=X`. Bootstrap 5.2.3 + custom LESS,
  6 semantic color variables.
- **Editorial plugin — `post45Editorial`** (monorepo, symlinked): the active editorial plugin
  (forked from `submissionsOnly`). Hides OJS-native publication mechanics and adds the terminal
  "Mark Published on WordPress" decision. `submissionsOnly` is kept as a **disabled** backup —
  never enable both at once.
- **Color palettes — `colorPalettes`** (standalone repo `Post45-Journal/ojs-color-palettes`):
  reusable palette picker (Flexoki + Albers, 300+ colors) for any OJS theme.
- **Role cleanup:** 12 publishing roles were deleted directly from the DB. Remaining 7:
  Journal Manager, Journal Editor, Section Editor, Guest Editor, Author, Reviewer, Copyeditor.
  Re-add individual roles via the admin UI (Users & Roles → Roles → Add Role) — **never**
  re-run the install migration (it re-adds all 12). Per-environment manual step.

## Active Editorial Scope

Pipeline: **Submit → Peer Review → Accept → Copy Edit → Proof Coordination → Mark Published on
WordPress.** All four OJS workflow stages (1, 3, 4, 5) are in scope. Stage 5 (Production) is
repurposed as proof coordination — typesetting and publication happen on WordPress; OJS
provides the discussion thread + decision tracking. The terminal action "Mark Published on
WordPress" (custom `post45Editorial` decision) sets `STATUS_PUBLISHED`, stores the WordPress
URL, emails the author, and blocks the public OJS article view.

**In-flight (as of July 2026):** the "Mark Published" backend is built + headless-tested; the
Vue frontend button injection and prod install were not yet finished. See `OJS-DEV-NOTES.md`
for the technical record, the `post45-editorial-state` memory for live status, and `temp/` for
the browser-test checklist. Uncommitted work may be present — don't assume the tree is clean.

## Core Coding Rules

- **Understand-first** (see top): read the authoritative reference and cite it before writing.
- **Never guess CSS selectors or DOM structure.** OJS admin is Vue-generated with dynamic
  classes — inspect the real markup / test `querySelectorAll()` first, and prefer the workflow
  extender over guessed admin CSS for Vue-rendered controls. (Full rationale + examples in
  `OJS-DEV-NOTES.md`.)
- **Be honest about sources.** Never claim to have read docs/resources you haven't — use the
  Read/WebFetch tools, and say "No, let me check" when you haven't. Distinguish what you know
  from what you're inferring.
- **OJS 3.5 plugin basics:** modern hook syntax `Hook::add('X', $this->method(...))` (not the
  `[$this, 'method']` array form); strict `camelCase`/`PascalCase` naming; 4-part version
  `1.0.0.0`. Full checklist, gotchas, and the headless-test recipe are in `OJS-DEV-NOTES.md` —
  read it before plugin/workflow/decision work.
