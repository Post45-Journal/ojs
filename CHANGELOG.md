# Post45 OJS — Development History

Reverse-chronological log of significant changes, decisions, and resolved-issue post-mortems.
Moved out of `CLAUDE.md` (which now holds only active/durable content) so session narrative
doesn't dilute the always-loaded instructions. For durable technical reference see
`OJS-DEV-NOTES.md`; for current status see `CLAUDE.md`.

---

## 2026-08-10 — Editorial state was readable *and writable* by authors (launch blocker)

Found while starting the author-visibility sweep (backlog item B). The Editorial
State panel — Owner, copyediting/production substatus, publication-agreement
status, URL and dates — rendered in the author's own workflow view. Confirmed in
the browser, then traced to three independent gaps, all now closed.

**Why it happened.** `injectAdminAssets()` gated on `getRequestedPage()` being in
a backend-page allowlist. That is not a role check: `submission` and `dashboard`
are author pages too, so all 14 injected scripts were served to authors and
reviewers. The panel itself has no role logic — it keys only on stage — and the
Vue extender it uses wraps `getSecondaryItems` on the *shared* workflow config,
which the author view (`workflowConfigAuthorOJS`) goes through as well.

**The worse half: it was writable.** The panel saves with `PUT /submissions/{id}`.
That route lists `ROLE_ID_AUTHOR` among its permitted roles, and
`PKPSubmissionController::edit()` filters incoming properties only by the
schema's `writeDisabledInApi` flag — it applies no per-role property rules. None
of the six Post45 properties set that flag. So an author could set their own
Owner and, notably, mark their own publication agreement signed. Those writes
also fire `SubmissionEditEventsHook`, which is what post45NotionSync mirrors onto
the board — so an author write would have moved the editorial board.

**The fix, in three layers** (the first two are the real ones; hiding a panel
does nothing about a hand-crafted request):

- **Read** — `Post45SubmissionSchemaMap` overrides `mapByProperties()`, the single
  funnel every read path uses, and strips the six properties for non-editorial
  users. Deliberately *not* done by making the schema role-conditional: the
  schema also drives persistence, so a request that omitted the properties could
  round-trip a submission and drop the stored values.
- **Write** — `Post45SubmissionRepository::validate()` rejects the properties with
  a named error. It fails loudly rather than silently stripping, so nothing
  believes a write succeeded when it didn't. Both ride one container binding,
  the same pattern as `Post45SubmissionFileRepository`.
- **Assets** — `injectAdminAssets()` now splits its 14 scripts into shared vs
  editorial-only behind `EditorialRoleGate`. The split is not "editor vs author":
  the scripts that *hide* native OJS machinery stay shared, because withholding
  those would show authors **more** than before.

**Who counts as editorial** (decided with the team): journal managers,
journal/section/guest editors, copyeditors — `ROLE_ID_MANAGER`, `SUB_EDITOR`,
`ASSISTANT`, plus site admin. Not authors, not reviewers. Copyeditors are in
deliberately: the substatus tracks their own work.

**Also fixed in passing:** `production-url-field.js` and
`author-facing-decisions.js` were reaching authors too — an editable WordPress
URL field and a set of editor decision buttons.

**Verified** by breaking each guard and watching the check fail, then restoring:
role truth table, read gate (author/reviewer 0 of 6 properties, editor 6 of 6),
write gate (author and reviewer rejected, manager and CLI allowed). The CLI
fail-open is deliberate — sync jobs and dev tools have no request user.

**Still open:** `publicationUrl` lives on the *publication* schema and was not in
scope here; whether authors can read it has not been established.

## 2026-08-10 — Dev fixture seeds a submission in every state

`tools/dev/createTestSubmissions.php --suite` seeds six submissions: fresh Stage
1, Stage 1 after Request Desk Revision, two Stage 3 variants (reviewers invited;
one reviewer accepted), Stage 4 and Stage 5.

Each state is reached by **recording the real decision** through
`Repo::decision()->add()` rather than setting `stageId` — so review rounds,
decision history and stage assignments are all genuine. It deliberately seeds
**no files and no completed reviews**: fabricating those would create states the
UI cannot produce, which is precisely what hides UI bugs from a fixture.

That principle immediately earned its keep. The first working version produced
review assignments the dashboard rendered as `null` bubbles, because
`EditorAction::setDueDates()` writes whatever it is handed and has no fallback
to the journal defaults — and `addReviewer()` alone does not set `dateNotified`,
without which no reviewer status can be computed. Both were caught by looking at
the seeded rows in the real UI rather than by trusting the seed. Written up in
`OJS-DEV-NOTES.md`.

---

## 2026-07-06 — "Mark Published on WordPress" frontend built & browser-tested

Completed the Stage-5 UI end-to-end (all in the plugin monorepo). Full technical record +
the durable extender contract are in `OJS-DEV-NOTES.md`; live status in the
`post45-editorial-state` memory.

- **Redesigned to metadata-first (Option A).** OJS builds all decision steps up front, so a URL
  typed inside the decision can't reach its own author-email step. The WordPress URL is now
  recorded on the publication *before* the decision, via a **"WordPress URL" field in the
  Production stage panel** (`js/production-url-field.js`, saved with a plain-fetch PUT). The
  decision dropped its URL form-step and now just validates the URL exists + notifies. Removed
  the redundant `PublicationFormHook`.
- **Fixed the button that never rendered:** the workflow extender wraps the top-level getters,
  whose args carry `selectedMenuState`, not `selectedStageId` (the value the old code read was
  always `undefined`). Button is now a custom `Post45MarkPublishedButton`, **disabled with a
  hover hint until a URL is saved**, gated on active-Production + not-published.
- **Hid OJS publishing machinery the native way (extender, not CSS):** removed the native
  "Schedule For Publication" button; trimmed the Publication nav group to Title & Abstract /
  Contributors / Metadata (`js/publication-nav.js`, replacing a guessed-CSS hack that made those
  tabs unreachable); redirected the post-publish landing to the Production stage; filtered out
  the Production galley/scheduling notification box.
- **Fixes found by running it:** added `getAllowedAttachmentFileStages()` (a NotifyAuthors email
  step fatals without it); un-hid the "Published" dashboard view (published submissions were
  vanishing); completed message no longer falsely claims the author was notified when the email
  is skipped.
- **Still open:** hide the Title & Abstract machinery (Unpublish / Create New Version / version
  warning), View→WordPress + hide Preview, assign-participant email templates, and the
  `NotifyProofsReady` decision. Not yet deployed to prod.

## July 2026 — "Mark Published on WordPress" backend (post45Editorial Stage 5)

Built and headless-tested the terminal editorial decision (programmatic end-to-end test
passed 18/18). Decision `999` (`MarkPublished`): persists a `publicationUrl`, publishes the
submission (manually, to dodge the null-issueId fatal), emails the author, and 404s the public
OJS article view. Frontend Vue button injection and prod install remained in flight at the time
of writing (see the 2026-07-06 entry above for completion — note the flow was later redesigned
to metadata-first, so some specifics here are superseded).

---

## June 2026 — Repo restructuring & plugin fork

- **Tarball → git checkout migration** (one-time). Prod moved from a tarball install to a
  proper git checkout tracking the `Post45-Journal/ojs` fork. See
  `temp/prod-upgrade-checklist.md` for the record — kept for reference, not to re-execute.
- **Scope expansion:** copy editing + proof coordination brought in scope. The OJS pipeline
  now runs Submit → Peer Review → Accept → Copy Edit → Proof Coordination → Mark Published on
  WordPress (all four workflow stages). Copyeditor role re-added (7 roles total).
- **Plugin fork:** `submissionsOnly` forked into `post45Editorial`. The original assumed OJS
  stopped at acceptance; the new plugin runs the full pipeline through "marked published on
  WordPress." Original kept on disk as a disabled backup — don't enable both simultaneously.

---

## Oct 5, 2025 — Database role cleanup & plugin cleanup

- **Database-level role cleanup.** Replaced brittle JavaScript/CSS role-hiding with direct
  MySQL deletion of 12 unwanted publishing roles (Production Editor, Copyeditor, Designer,
  Funding Coordinator, Indexer, Layout Editor, Marketing & Sales Coordinator, Proofreader,
  Translator, Reader, Subscription Manager, Editorial Board Member). Kept: Journal Manager,
  Journal Editor, Section Editor, Guest Editor, Author, Reviewer. (Copyeditor later re-added
  June 2026 — see above.) The old `submissionsOnly` generic plugin was deleted at this point.
  SQL used:
  ```sql
  DELETE FROM user_user_groups   WHERE user_group_id IN (4,7,8,9,10,11,12,13,15,17,18,19);
  DELETE FROM user_group_stage   WHERE user_group_id IN (4,7,8,9,10,11,12,13,15,17,18,19);
  DELETE FROM user_group_settings WHERE user_group_id IN (4,7,8,9,10,11,12,13,15,17,18,19);
  DELETE FROM user_groups        WHERE user_group_id IN (4,7,8,9,10,11,12,13,15,17,18,19);
  ```
  > NB: do NOT re-run the install migration to re-add a single role — it re-adds all 12.
  > Re-add individual roles via Users & Roles → Roles → "Add Role" (per-environment manual step).
- **Section Metadata plugin REMOVED** (`/plugins/generic/sectionMetadata/`). JS field injection
  was unreliable and caused 500 errors; functionality (custom CFP deadline/editor fields) was
  non-essential. Section editors are still shown on CFP cards via native `SubEditorsDAO`;
  deadlines can live in section policy text. Removing it also required stripping now-dangling
  `cfpDueDate` references from `indexJournal.tpl` and `submissionGuidelines.tpl` (they were
  causing 500s) and clearing the cache.
- **Reviewed** `lib/ui-library/src/docs/guide/Plugins/Plugins.mdx`; confirmed database deletion
  was simpler/more robust than hook-based role filtering.

---

## Oct 3, 2025 — Header nav, CFP system, homepage streamlining

- **Header navigation:** right-aligned the "Return to Journal" external link; improved home-link
  vertical alignment and sizing (1.5rem); fixed admin-dropdown white-rectangle/invisible-link
  issues.
- **CFP (Call for Papers) system:** dedicated section-specific CFP pages at
  `/about?cfp=1&sectionId=X` via OJS 3.5-compatible template-display hooks (HANDLER_CLASS is
  deprecated). Added a flag to prevent recursive template-display calls (infinite loop).
- **Homepage streamlining:** single "Begin a Submission" button for general submissions;
  special-issue CFPs in a grid with "View Full CFP" links; removed section pre-selection.
- **CSS architecture confirmed:** themes inherit Bootstrap 5.2.3 (TailwindCSS is admin-only);
  kept the semantic LESS-variable color approach.

### Known insights captured this era
- External-link ordering and nav-text ("About" → "Submission Guidelines") are controlled via
  the admin Settings → Website → Navigation UI, not CSS/theme.
- The CFP system works well with the template-override approach, avoiding deprecated handler
  patterns.
- Admin nav and main nav live in separate containers, so cross-alignment is limited.

### Long-term possibilities (not yet done)
- Locale overrides for submission-focused terminology.
- Vue component overrides for comprehensive workflow renaming.
- Submissions-focused statistics/reporting dashboard.
- Enhanced author-facing submission status tracking.
