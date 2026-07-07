# OJS 3.5 Development Notes (Post45)

On-demand deep reference for working on OJS internals — plugins, the Vue workflow UI,
decisions, hooks, and theming. `CLAUDE.md` stays lean and points here; **read the relevant
section below before modifying the corresponding subsystem** (understand-first rule).

Companion docs:
- `PLUGIN-IMPLEMENTATION-REFERENCE.md` — the submissionsOnly implementation patterns
  (store extension, custom Vue components, `getActionItems` button injection, decision-page
  navigation), directly reusable by `post45Editorial`.
- `CHANGELOG.md` — dated session history and resolved-issue post-mortems.

> **TODO (reconcile):** merge the user's personal "how to work with OJS" guide into this
> file once it syncs from the Dropbox/desktop archive (`D:\Dropbox\wsl-backup\AWang-AWare\dev`,
> may not reach WSL until back at the desktop). Watch for duplicate/contradictory guidance.

---

## Authoritative reference map (the answer keys)

Do **not** reverse-engineer these from source — read the reference and cite it.

- **Backend hooks + exact parameter signatures:** `docs/dev/guide/hooks.rst` — auto-generated
  list of EVERY hook with its exact signature. Regenerate with `php lib/pkp/tools/getHooks.php -r`.
- **Frontend / Vue admin UI guides:** `lib/ui-library/src/docs/guide/` —
  `PageArchitecture.mdx`, `PiniaStores.mdx`, `Plugins/Plugins.mdx` (storeExtend /
  registerComponent / extender), `CompositionAPI.mdx`, `APIInteractions.mdx`,
  `Translation/Translation.mdx`, `DesignSystem/`.
- **Live workflow source to mirror:** `lib/ui-library/src/pages/workflow/` — `workflowStore.js`,
  `composables/useWorkflowConfig/workflowConfigEditorialOJS.js` (per-stage getPrimaryItems /
  getSecondaryItems / getActionItems), `composables/useExtender.js`,
  `components/action/WorkflowActionButton.vue`.
- **Prior plugin patterns:** `PLUGIN-IMPLEMENTATION-REFERENCE.md` (repo root).
  - CAVEAT: it uses `args.selectedMenuState?.stageId`; the 3.5.0-4 `getActionItems` in
    ui-library uses `args.selectedStageId` — verify arg shapes against current source.

---

## Testing OJS changes headlessly (backend)

- **CLI harness:** `require '<repo>/tools/bootstrap.php';` then extend
  `\PKP\cliTool\CommandLineTool`.
- **Generic plugins register against the CURRENT context; CLI has none, so re-register:**
  ```php
  PluginRegistry::getPlugin('generic', '<name>plugin')
      ->register('generic', $plugin->getPluginPath(), <ctxId>);
  ```
  Do this BEFORE any `getDecisionTypes()` / schema lookup memoizes.
- **Code calling `$request->getContext()`** (e.g. decision notifications) needs it injected:
  ```php
  Application::get()->getRequest()->getRouter()->_context = $context;
  ```
- **DB access:** `mysql -h127.0.0.1 --protocol=TCP -uroot -p"…" ojs` (the socket default
  fails; use TCP).
- **Local mail + logs:** local `[email] default = log` ⇒ emails go to the server log + the
  submission email log; none are actually sent. Local `php -S` logs → `/tmp/ojs-server.log`
  (confirm the path via `/proc/<pid>/fd/2`).

---

## Hard-won gotchas (OJS 3.5.0-4)

- **Hook arg conventions differ by dispatch mechanism.** `Hook::call` passes args as an ARRAY
  (`$args[0]`, `$args[1]`, …). `Hook::run` SPREADS them, so the callback receives them
  directly — e.g. `Form::config::before` is dispatched via `Hook::run`, so the callback
  signature is `(string $hookName, FormComponent $form)`, NOT `($hookName, $args)`.
- **Plugin JS using `pkp.registry` must load AFTER OJS's Vue bundle.** `pkpApp` (`/js/build.js`)
  loads at `STYLE_SEQUENCE_LATE` (15); `addJavaScript` defaults to `NORMAL` (10) → runs first →
  `pkp.registry` is undefined. Register plugin backend JS at `STYLE_SEQUENCE_LAST` (20).
- **The Vue workflow HARD-CODES decision buttons** (`workflowConfigEditorialOJS.js`); it does
  NOT render arbitrary decisions returned by the `Workflow::Decisions` backend hook. Custom
  decision buttons must be injected via
  `storeExtend('workflow') → extender.extendFn('getActionItems', …)`.
- **`Repo::publication()->publish()` fatals on a null issueId in 3.5.** `publish()` →
  `setStatusOnPublish()` dereferences `Repo::issue()->get($issueId)` on its first line, and
  `get()` requires a non-null int — so a null `issueId` (every Post45 publication) fatals
  before the "no issue" branch. Publish no-issue publications manually: set `STATUS_PUBLISHED`
  + `datePublished`, then `Repo::submission()->updateStatus()`.
- **Local `[email] default = log` triggers a container gap:**
  `Illuminate\Contracts\Log\ContextLogProcessor` is unbound → emergency logger → undefined
  `PKPContainer::storagePath()` → 500 on any email-bearing decision page. FIXED by binding it
  in `classes/core/AppServiceProvider.php`. Prod (smtp/mailgun) is unaffected.
- **Symlinked (monorepo) plugins live outside `BASE_SYS_DIR/plugins`,** so `Hook::run`'s
  "plugin exceptions don't crash the app" resilience does NOT apply — an uncaught throw in a
  post45Editorial hook 500s the page (an intentional `NotFoundHttpException` still renders as
  a proper 404).

---

## OJS 3.5 architecture insights

### Frontend vs admin interface
- **Frontend:** controlled by themes (Smarty templates), easily customizable. Themes inherit
  **Bootstrap 5.2.3** from parent themes (not TailwindCSS) + custom LESS.
- **Admin interface:** built with Vue.js 3 + Composition API + Pinia stores; more complex to
  modify. Uses **TailwindCSS** for styling.
- **Separation:** CSS hiding works reasonably for admin; template overrides are better for
  frontend.

### UI-library guidelines (`lib/ui-library/src/docs/guide/`)
- **Plugin styling:** use scoped CSS with CSS variables (NOT TailwindCSS classes) to avoid
  conflicts. Available vars: colors `var(--color-stage-in-review)`, `var(--text-color-heading)`;
  typography `var(--font-3xl-bold)`; spacing `var(--spacing-8)`.
- **Modern Vue plugin architecture (3.5+):** Vite build step; Single File Components with
  Composition API; component prefixes to avoid conflicts; ui-library components available
  globally with the `pkp` prefix (`<PkpButton>`); composables via `pkp.modules.useLocalize`,
  `pkp.modules.useFetch`, etc.
- **Registration / extension:**
  ```javascript
  pkp.registry.registerComponent('MyComponent', MyComponent);
  pkp.registry.storeExtend('fileManager_SUBMISSION_FILES', (piniaContext) => { /* … */ });
  ```
- **Extensibility patterns:** PHP hooks for Smarty template pages (legacy); JS hooks via Pinia
  stores for Vue pages (modern); `store.extender` to extend existing functionality. Reference
  example plugin: `github.com/jardakotesovec/backend-ui-example-plugin`.

### Plugin architecture patterns
- **Themes** (frontend): extend `ThemePlugin`; `setParent('parenttheme')` for inheritance;
  overrides in `templates/`; CSS/JS via `addStyle()` / `addScript()`.
- **Generic plugins** (admin): extend `GenericPlugin`; hook `TemplateManager::display` for
  CSS/JS injection; page detection via `$request->getRequestedPage()`.

### Workflow UI structure (Vue-based)
- Key source: `lib/ui-library/src/pages/workflow/` — `WorkflowPage.vue`,
  `useWorkflowNavigationConfigOJS.js` (menu structure/labels),
  `workflowConfigAuthorOJS.js` / `workflowConfigEditorialOJS.js` (stage configs).
- Translations: locale keys in `/locale/en/submission.po` (`submission.publication`,
  `publication.status.unscheduled`). `getPublicationTitle()` generates the
  "Publication: Title & Abstract" headers.
- **Comprehensive workflow renaming** (Publication → Article Metadata, etc.) requires
  overriding the Vue navigation config functions, custom locale overrides, form-template
  overrides, and status-logic changes. Complexity: moderate-to-high.

---

## OJS 3.5 plugin compatibility & conventions

### Hook registration syntax (BREAKING vs 3.4)
```php
// ❌ Old — causes plugin registration failures in 3.5
Hook::add('TemplateManager::display', [$this, 'methodName']);
// ✅ New — required in 3.5 (first-class callable)
Hook::add('TemplateManager::display', $this->methodName(...));
```

### Plugin discovery — essential files
1. `PluginNamePlugin.php` — main class (exact case-sensitive naming)
2. `version.xml` — must include `<class>PluginNamePlugin</class>` and `<lazy-load>1</lazy-load>`
3. `settings.xml` — required for admin-interface visibility
4. `locale/en/locale.po` — translation strings
5. `index.php` — **NOT required** (3.5 uses autoloading)

### Naming conventions (strict)
- Directory: `camelCase` (e.g. `colorPalettes`)
- Class: `PascalCase` + `Plugin` (e.g. `ColorpalettesPlugin`)
- File: matches class exactly (`ColorpalettesPlugin.php`)
- Namespace: `\APP\plugins\{category}\{directoryName}\`
- Version format: 4-part semantic versioning `1.0.0.0` (not `1.0.0`)
- PHP 8.1+ (first-class callables, modern autoloading, enhanced type checking).

### Debugging plugin issues
1. `php -l PluginFile.php` (syntax)
2. Verify file permissions are readable
3. Clear the OJS cache (plugin changes may require it)
4. Check error logs in `/files/scheduledTaskLogs/`
5. Test a bare-bones plugin to confirm registration works

### Prefer native mechanisms over stack-agnostic hacks
**Recurring failure mode (observed across multiple sessions):** when OJS behavior needs
changing, the tempting move is a stack-agnostic hack — hide it with injected CSS, inject some
JS — because those work on any website and OJS/PKP is niche enough that the *native* path feels
low-confidence. Resist it. The CSS/JS-injection reflex is almost always a sign you haven't found
the real extension point yet. In order: (1) read the authoritative reference, (2) find and
mirror an existing plugin / core OJS doing the same kind of thing, (3) use the native mechanism
— a hook, a custom `Decision`, a theme template override, or the Vue workflow `extender`.
Example: hide/alter a Vue-rendered workflow control via the `extender` (filtering
`getActionItems`), NOT guessed admin CSS.

### Never assume HTML structure or CSS selectors
When you *do* legitimately need a selector, verify the actual DOM first — OJS admin is
Vue-generated with dynamic classes (e.g. color inputs are the Vue Chrome picker `.vc-chrome`,
NOT `input[type="color"]`). Inspect the element, test `document.querySelectorAll()` in the
console first, and ask for the real markup when debugging.

---

## Color system architecture

### Theme-level semantic variables (6)
| Variable | Role | Default |
|---|---|---|
| `@theme-primary` | Links, button backgrounds, key interactive elements | `#006798` |
| `@theme-secondary` | Header, footers, structural sections | `#01354F` |
| `@theme-text` | Main body text | `#222222` |
| `@theme-text-accent` | Headings, secondary text (hierarchy) | `#01354F` |
| `@theme-background` | Page / content backgrounds | `#FFFFFF` |
| `@theme-background-accent` | Content cards, contrast sections | `#EAEDEE` |

### Palette plugin (300+ curated colors)
Standalone repo `github.com/Post45-Journal/ojs-color-palettes` (deployed to
`plugins/generic/colorPalettes`). Flexoki (8 families, 50–950 scale) + Albers (15 artistic
studies, ROYGBIV-organized), all lightness-sorted. Auto-detects Vue Chrome color pickers and
adds palette-selection buttons; selecting a swatch populates the hex field; the theme applies
it via CSS custom properties. Works with any OJS theme that exposes color options.

---

## post45Editorial: "Mark Published on WordPress" implementation notes (July 2026)

The terminal Stage-5 action of the Post45 editorial pipeline. Frontend built + browser-tested
2026-07-06. This section is the durable technical record; live status is in the
`post45-editorial-state` memory.

**Architecture is metadata-first** (the pivotal design decision): OJS builds every decision step
up front in `getSteps()`, before any is filled — so a value typed in one step **cannot** reach a
later email step (proven: URL entered in a step-1 form never populated the step-2 email's
`{$publicationUrl}`). Therefore the WordPress URL is recorded on the publication *before* the
decision runs, and the decision reads it.

- **URL entry — `js/production-url-field.js`.** A "WordPress URL" card injected into the
  Production primary column via the `getPrimaryItems` extender. Saves `publicationUrl` (schema
  field via `Schema::get::publication`) with a **plain `fetch` PUT**, mirroring ui-library's
  `useFetch`: send as `POST` with `X-Http-Method-Override: PUT` (pkp/pkp-lib#5981) + `X-Csrf-Token`
  from `pkp.currentUser.csrfToken`, JSON body. Done with plain fetch because **only a subset of
  composables is reliably on `pkp.modules`** (`vue` yes; `useUrl`/`useFetch` no). Build the API
  URL as `pkp.context.apiBaseUrl + 'submissions/{id}/publications/{pubId}'`. The field watches
  `props.currentUrl` because the store finishes loading `selectedPublication` a tick after the
  component mounts. Same file filters out the native Production galley/scheduling notification.
- **Decision constant `999` (`MarkPublished`),** registered via `Decision::types` + surfaced in
  Stage 5 via `Workflow::Decisions`. Single **notify-author** email step (`getSteps` reads the
  stored URL so `{$publicationUrl}` resolves in preview AND send). `validate()` **requires a URL
  is already stored** (friendly error routing the editor to the Production field) and keeps the
  already-published guard. `getAllowedAttachmentFileStages()` is **required** by any decision with
  a NotifyAuthors email step (else `validateNotifyAuthorsAction` fatals on the undefined method) —
  mirrors `BackFromProduction` (`SUBMISSION_FILE_PRODUCTION_READY`). Completed message no longer
  claims "author notified" (the email is skippable).
- **Publishes manually, NOT via `Repo::publication()->publish()`** — null-issueId gotcha above.
  Sets `STATUS_PUBLISHED` + `datePublished`, recomputes submission status. `ArticleViewHook` 404s
  the public article view/download when `publicationUrl` is set.
- **Button — `js/mark-published.js`.** Injected via the `getActionItems` extender as a custom
  `Post45MarkPublishedButton` (native `WorkflowActionButton` has no disabled state; `PkpButton`
  supports `is-disabled`, and a wrapping `<span title="…">` carries the hover hint). **Disabled
  until a URL is saved**, gated on active-Production + not-published. Also filters out the native
  "Schedule For Publication" button (a plain `navigateToMenu`→`publication_titleAbstract` item —
  no id, so unmatchable by CSS; filter by action target). Defines
  `store.markPublishedOnWordpress`.
- **Nav — `js/publication-nav.js`.** Trims the Publication nav group to Title & Abstract /
  Contributors / Metadata via the `getMenuItems` extender (replaced a guessed-CSS hack that hid
  the whole group and made those tabs unreachable), and redirects the post-publish landing from
  `publication_titleAbstract` to the Production stage via `getInitialSelectionItemKey`.

**Extender contract (verified against live ui-library — the answer key for future work):** the
workflow store does `extender.addFns(useWorkflowConfig(...))` and `extender.addFns(navConfig)`, so
plugins extend the **TOP-LEVEL** getters (`getActionItems`, `getPrimaryItems`, `getMenuItems`,
`getInitialSelectionItemKey`). `extendFn(name, cb)` calls `cb(originalResult, ...sameArgs)`. Those
top-level args carry **`selectedMenuState`** (read stage via `selectedMenuState.stageId`) — **NOT**
`selectedStageId`, which only exists one layer deeper in the per-stage configs. (Reading
`selectedStageId` at the extender level is always `undefined` — this was the "button never
rendered" bug.) `getActiveStage(submission) = submission.stages.find(s => s.isActiveStage)` gives
the real active stage vs. the browsed tab. Custom components: `pkp.registry.registerComponent(name,
def)` then reference by name string in an item's `component`.

- **Symlink caveat:** an unexpected hook throw 500s the page (symlinked-plugin gotcha above);
  `ArticleViewHook`'s intentional `NotFoundHttpException` still renders as a proper 404.
- **Removed** `PublicationFormHook` (post-publish URL correction) — the Production field is the
  single URL home (it shows post-publish too, since the active stage stays Production).
