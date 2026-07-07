# Post45 OJS — Development History

Reverse-chronological log of significant changes, decisions, and resolved-issue post-mortems.
Moved out of `CLAUDE.md` (which now holds only active/durable content) so session narrative
doesn't dilute the always-loaded instructions. For durable technical reference see
`OJS-DEV-NOTES.md`; for current status see `CLAUDE.md`.

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
