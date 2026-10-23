# Editorial Events Sync — Design Plan

**Status:** post-launch design. Not launch-blocking — OJS's `edit_decisions`
and `review_assignments` capture the underlying data natively at cutover.
This design concerns how to surface that data in Notion as a query surface
for editorial timing analysis.

**Supersedes:** unreviewed `docs/decision-history-sync-plan.md` (deleted
2026-08-26).

## Motivation

Post45 wants cross-article timing queries — weekly-meeting reads ("what's
sitting?") and periodic metric reports ("time to desk review", "time to
decision", "reviewer report return time", "ratio of invitations to secured
reviewers", "time between accept and publish"). Some of these are also
intended for public reporting.

OJS captures every underlying event at row level (`edit_decisions`,
`review_assignments`, submission files / publications). Notion is where the
editorial team actually queries. The gap: the current sync collapses OJS
decision history into a single "latest verdict" pair of Notion columns.

## Core design decisions

### 1. Unified "Editorial Events" DB

One Notion DB holds every dated editorial event — not two (Decision Log +
Invitations), not three (also splitting author-side dates onto Article).

Reasons:
- Multi-round articles break property-per-event on Article as soon as
  anything repeats. `Latest Revision Returned` on Article doesn't tell you
  round 1 vs round 2 vs round 3; adding `First Revision Returned` /
  `Second Revision Returned` etc. explodes the schema.
- Every metric named above needs date arithmetic across event types; one
  flat table is trivially pandas-friendly and consistent-shape enough for
  Notion formulas.
- Adding a new event type is a select value, not a schema change.
- Absorbs the "declined invitations" placement question. Reader's Reports
  DB is semantically about reports (with content); an invitation the
  reviewer declined has no report and belongs elsewhere. Declined
  invitations become Event rows with `Event Type = Review Invitation
  Declined`.

### 2. Row shape

Every row carries the same properties:

- `Article` — relation
- `Event Type` — select (values enumerated at implementation time)
- `Date` — date
- `Actor` — person (nullable; may fall back to free-text for actors not
  registered in OJS, e.g. non-user reviewers we invited via email)
- `Round` — number (nullable — only meaningful for review-round-scoped
  events)
- `Payload` — rich_text (nullable; event-specific — WordPress URL for
  MarkPublished, decline reason for a review decline, etc.)
- `Reverted` — boolean (default false — set true when a subsequent
  Revert/Back-From decision undoes this one)

### 3. Event families and sources

Three OJS source tables map to Events. Complete event-type inventory is
deferred to the implementation session.

- **Editor decisions** — from `edit_decisions`. Includes Post45 custom
  decisions (RequestDeskRevision, AssignFirstEdit, AssignSecondEdit,
  MarkPublished, MarkProofsApproved, SendCopyeditsToAuthor,
  SendProofsToAuthor) and native pkp-lib decisions used by Post45
  (PENDING_REVISIONS, ACCEPT, DECLINE, EXTERNAL_REVIEW,
  SEND_TO_PRODUCTION). Events that the current "latest verdict" sync
  ignores because they don't move a summary state — notably
  EXTERNAL_REVIEW and SEND_TO_PRODUCTION — become visible as timeline
  events, because they're distinct milestones editors care about.
- **Reviewer lifecycle** — from `review_assignments`. Includes invitation
  sent, invitation accepted, invitation declined, assignment cancelled,
  report submitted, reminder sent.
- **Author events** — from submission files / publications. Includes
  manuscript submitted, revision returned, proofs returned. These
  currently live as date properties on the Article page; they move to
  Events under this design.

### 4. Article DB changes

Delete these plugin-authored Notion columns (see
[ArticleSchema.php:55-88](../ojs-plugins-monorepo/plugins/post45NotionSync/classes/mapping/ArticleSchema.php#L55-L88)):

- `ArticleSchema::DECISION`
- `ArticleSchema::DECISION_DATE`
- `ArticleSchema::DECISION_HISTORY`

None of these are OJS-native constructs — OJS itself just has
`edit_decisions`. They're plugin-authored Notion surface, so deleting
them costs nothing in OJS.

Replace with rollups off Events. Selection criteria for which rollups
survive: **"which dates would an editor name at a weekly editorial
meeting?"** — the mental model is "how long has this been sitting
without movement." Specific rollup list deferred to implementation.

Author-side date properties currently on Article DB retire in favor of
Events + rollups on the same criteria.

### 5. Reader's Reports adjustments

Reader's Reports DB keeps its role — container for submitted reports
with content (uploaded report files, notes, reviewer commentary). What
changes:

- Existing lifecycle date fields (notably `Date Requested`) become
  relation → rollup off the Events DB row for the corresponding
  `Review Invitation Sent` or `Review Report Submitted` event.
- A `Related Event` relation on each Reader's Reports row points at
  the matching `Report Submitted` event.
- No content shape changes to the DB otherwise.

### 6. Metric computation

Not baked into schema. Two paths, either sufficient:

- Notion formulas / rollups on Events or Article
- CSV export → pandas

Deliberate: this means we don't need to design specific rollups per
metric ahead of time. A flat table with consistent shape covers every
question the team wants to ask now, and every question they think of
later. Adding a metric is a query change, not a schema change.

### 7. Reverts

Mark decisions as `Reverted = true` on the Event row rather than
deleting the row. Rationale: metrics need to distinguish "genuinely
accepted" from "accepted then reverted" (the timeline is different,
and the reverted decision is still editorially real — it happened).

### 8. Backfill (post-launch, nice-to-have)

Post-cutover articles arrive with full timelines natively — no
backfill needed for them. Legacy pre-cutover articles have partial or
no history in OJS (populated in-progress articles get whatever G3b
records; archived pre-cutover articles stay in LEGACY Notion).

If we later want to backfill legacy data from email history, the
highest-value + lowest-complexity first target is the
**invitation-count** metric (the historically-observed pandemic-era
shift from 2.4 → 3.2 reviewers-asked-per-2-secured is the pattern the
user wants to be able to continue tracking).

Why invitations-first:

- Only needs COUNTS of distinct invitees per article per round — not
  per-reviewer identity, not reply content.
- Editor sent-mail is queryable via Google Takeout mbox export (local
  processing, nothing goes to third parties beyond Anthropic during
  extraction).
- Review-invitation subject lines are template-generated and
  regex-matchable, so LLM extraction only needs subject line +
  recipient email + date, minimizing content exposure.

Other backfill targets (decision-return dates, full timelines) have
higher extraction complexity and lower per-article yield. Prioritize
based on what metrics editors actually reach for post-launch.

Privacy posture assumed for any backfill:

- Local mbox processing on user's laptop; no third-party upload.
- Reviewer identities never sent to the LLM if only counts are needed.
- Structured CSV output for user review before any Notion write.
- Explicit article match by title + date window; no LLM-guessed IDs.

## Deferred to implementation session

- Complete event-type inventory (which OJS decisions / states map to
  which Event Types; payload content per type).
- Article rollup selection — specific rollups off Events that
  preserve the at-a-glance meeting-prep read.
- Sync architecture: per-source synchronizers (Decision, ReviewLifecycle,
  AuthorEvent) all targeting the same Events DB; likely follows the
  SyncReviewJob one-job-per-entity pattern.
- Reader's Reports migration: retire old lifecycle date properties, add
  `Related Event` relation + rollups back to Reader's Reports.
- Populated-article partial-history handling: whether to record
  intermediate decisions during populate (the deferred
  `g3b-decision` TODO), or accept partial history for these ~30-40
  articles and let editors know via EDITOR-NOTES.

## Non-goals

- Baking specific metric queries into the DB schema.
- Notes column per Event (editable annotations) in v1.
- Notion-side editing of Events — the whole DB is OJS-authoritative.
- Retaining the retired "Decision History (DRAFT)" DB shape.
- Backfill of legacy article decision timelines as a pre-launch or
  early-post-launch requirement.

## Related

- Existing sync architecture: `project_ojs_notion_sync_plan` memory.
- Post-launch position in the plan: `project_sequenced_backlog` memory.
- Load-bearing principle: `project_ojs_operational_notion_historical`
  memory — OJS is the operational surface, Notion retains history.
  Under this design, Notion's role as the query surface is exactly
  the "retains history" side; OJS remains the source of truth via
  `edit_decisions` + `review_assignments`.
