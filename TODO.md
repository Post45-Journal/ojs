# OJS Submissions-Only System - TODO List

## Completed Tasks ✅

1. **Identify Outlook Safe Links as root cause**
   - Issue: Email security scanners (Outlook Safe Links, Gmail, etc.) pre-visit one-click invitation links
   - This auto-accepts invitations before reviewers can actually click them
   - Results in 404 errors when reviewers try to use the link

2. **Research solutions for email crawler protection**
   - Option 1: Disable one-click reviewer access (recommended)
   - Option 2: Make invitations reusable for a short window (requires core code changes)
   - Option 3: Add bot detection to invitation handler (not foolproof)
   - Option 4: Use two-step verification (adds friction)
   - **Decision**: Disable one-click access due to incompatibility with email scanners

3. **OJS upgrade 3.5.0-1 → 3.5.0-4 (June 2026)**
   - Local upgraded via git rebase (with several gotchas resolved along the way)
   - Prod upgraded via PKP tarball-overlay method (prod's git was a one-shot snapshot, not upstream-tracking)
   - Migrations applied: I10962 (contextAcronym→journalAcronym rename), I11603 (missing UserRoleAssignment email), I11673 (missing reviewer suggestion approval), I12357 (decision constants fix — recovered any lost editorial decisions), I11800 (new USER_ROLE_MASTHEAD_UPDATE email template)
   - submissionsOnly plugin updated to hide the new USER_ROLE_MASTHEAD_UPDATE template
   - Detailed checklist preserved at `temp/prod-upgrade-checklist.md`

4. **Special Issue Proposal Guidelines page** (added as a Custom Navigation Menu Item, editable via OJS admin)

5. **Git restructuring: Post45 fork (June 2026)**
   - `Post45-Journal/ojs` fork created on GitHub; both local and prod now track it on `main`
   - `mailgun` plugin moved into `post45-ojs-plugins-monorepo` (consistent with other custom plugins, no more "git inside git")
   - Prod fully fresh-cloned from the fork (replaced tarball install) with build pipeline: `npm install && npm run build` produces JS/CSS, `composer install` in `lib/pkp` populates PHP deps
   - Future workflow: edit locally → push to fork main → `git pull` on prod
   - Detailed migration record at `temp/prod-upgrade-checklist.md`. See CLAUDE.md "Deployment & Upgrade Workflow" for the ongoing flow.

## Pending Tasks ⏳

### post45Editorial plugin: "Mark Published on WordPress" — ✅ BUILT & TESTED (July 2026)

The custom Stage 5 decision + supporting pieces are implemented and verified.
Programmatic end-to-end test (`scratchpad/` during the session; logic lives in the
plugin) passed 18/18: decision registration, publish flow, double-run guard,
post-publish URL edit field, mailable variable, and the public-view 404 blocker.

Two real bugs were caught by *running* it (both fixed):
1. **Issue-less publish crashed.** `Repo::publication()->publish()` →
   `setStatusOnPublish()` calls `Repo::issue()->get($issueId)` as its first line;
   `get()` requires a non-null int, so a null `issueId` fatals before the "no
   issue" branch. `MarkPublished` now publishes manually (sets STATUS_PUBLISHED +
   datePublished, recomputes submission status) instead of calling `publish()`.
2. **`Form::config::before` hook signature.** That hook is dispatched via
   `Hook::run` (spreads args), so the form arrives as the 2nd param directly, not
   in an array. `PublicationFormHook` signature corrected.

Also note: OJS core already blocks any decision on an already-published submission
at the controller (`api.decisions.403.alreadyPublished`), so our `validate()`
guard is belt-and-suspenders (friendlier message + covers non-API paths).

**Frontend built & browser-tested (2026-07-06), committed.** Redesigned to metadata-first
(URL entered in the Production panel before the decision; see `OJS-DEV-NOTES.md` +
`CHANGELOG.md`). Button renders/disables correctly, URL saves + repopulates, nav trimmed,
notification box gone. `PublicationFormHook` removed (URL single-homed in Production).

**Remaining before fully shipped:**
- **Hide the Title & Abstract machinery** — Unpublish, Create New Version, the red
  "version has been published" warning (find the PublicationConfig source before touching).
- **View button → the WordPress URL** for published articles; hide **Preview** pre-publish.
- **Assign-participant email templates** (Assign Editor / Galleys Complete / Ready for
  Production) reference OJS galleys + "Schedule for Publication" — revise or hide.
- **`NotifyProofsReady`** — 2nd Production decision + its own email template (not started;
  may warrant a fresh session).
- **Open design Q:** published submissions now land on the Production tab — revisit if there's
  a better default.
- **Deploy to prod:** commit is done in both repos; still need to `git pull` on prod, install
  the plugin version + enable for the context
  (`php lib/pkp/tools/installPluginVersion.php plugins/generic/post45Editorial/version.xml`),
  and rebuild if needed. NOT yet done.

### Scope Expansion: Extend OJS to Cover Copy Editing

**Decision (June 2026):** OJS will now handle submissions, peer review, AND copy editing. Only proofs and publication move to WordPress. Previously OJS stopped at acceptance and post-acceptance work happened in Notion.

**What changes:**
- CLAUDE.md's "Submit → Review → Accept/Decline → Done" framing is now obsolete. Update to "Submit → Review → Accept → Copy Edit → Final Approval → (then WordPress for proofs/publication)".
- The **Copyeditor role** was deleted at the DB level (per CLAUDE.md's role cleanup SQL). Needs to be restored. Easiest path: re-add via the OJS admin UI (Users & Roles → Roles → add "Copyeditor"). Don't re-run the role install migration — it would re-add all 12 deleted roles, including the publishing/subscription ones we still want gone.
- The **submissionsOnly plugin's hidden email templates list** (`EmailTemplateHook.php`) needs revision. Currently hides `DISCUSSION_NOTIFICATION_COPYEDITING`, `EDITOR_DECISION_SEND_TO_PRODUCTION`, `EDITOR_DECISION_BACK_FROM_PRODUCTION`, `EDITOR_DECISION_NEW_ROUND` as part of "Publishing Workflow." With the scope expansion:
  - **Unhide**: `DISCUSSION_NOTIFICATION_COPYEDITING` (we need this for copyediting discussions)
  - **Keep hidden**: `EDITOR_DECISION_SEND_TO_PRODUCTION`, `EDITOR_DECISION_BACK_FROM_PRODUCTION`, `EDITOR_DECISION_NEW_ROUND`, `ISSUE_PUBLISH_NOTIFY`, `OPEN_ACCESS_NOTIFY`, `VERSION_CREATED` — these are all post-copyediting (production/publication), which stays out of OJS
  - Update `email-templates-audit.md` accordingly
- **Stage 4 email drafts** (Copy Editing stage): add Post45-voiced templates for `COPYEDIT_REQUEST` and related copy-editing emails, in the same format as Stages 1-3 in `temp/stage-1-email-drafts.md` etc.
- **Stage 3 acceptance template revision**: the EDITOR_DECISION_ACCEPT drafts currently end with "Our editorial team will be in touch shortly with next steps for preparing the article for publication." This stays accurate, but could now reference the copy editing process more specifically since it happens in OJS.

**Possible plugin rename / refactor:** "submissionsOnly" is becoming a misnomer if copy editing is now in scope. Two options:
- (a) Rename the plugin to something more accurate (e.g. `post45Customizations` or `editorialScopeFilter`)
- (b) Generalize it: add per-area toggle settings (hide copyediting / hide production / hide publication). Post45's install would toggle off "production" and "publication" but leave "copyediting" on. This makes the plugin reusable by other journals with different scope choices.
- Decision deferred — pick when actually doing the refactor.

### Other Immediate Priorities

3. **Disable one-click reviewer access in production**
   - Go to Settings → Workflow → Review
   - Uncheck "Enable one-click reviewer access"
   - Save settings
   - Test new reviewer invitation to confirm it uses traditional login flow

4. **Customize reviewer registration and invitation email templates to reduce confusion**
   - **Issue**: New reviewers get two emails:
     - Email 1: "Reviewer Registration" - says they haven't been assigned anything yet
     - Email 2: "Review Invitation" - immediately assigns them a review
   - **Solutions to consider**:
     - Option A: Pre-create reviewer accounts separately from assignments
     - Option B: Customize "Reviewer Registration" template to mention upcoming assignment
     - Option C: Suppress registration email when creating+assigning in one step
   - **Action**: Edit email templates at Settings → Workflow → Emails → REVIEWER_REGISTER

### Email Enhancement Tasks

5. **Add journal name prefix to email subject lines**
   - **Issue**: Emails from OJS don't clearly indicate they're from Post45
   - **Goal**: Prefix subjects with "[Post45]" or similar for easy filtering/recognition
   - **Action**: Customize email templates to add subject prefix
   - **Templates to update**: All outgoing emails (review invitations, submission notifications, etc.)

6. **Fix author comments email to include proper context**
   - **Issue**: When authors submit comments for editors, the notification email has zero context
     - No article title
     - No submission URL
     - No indication it's from OJS
     - Just the raw comment text
   - **Goal**: Add metadata header to these emails:
     - Article title
     - Submission ID
     - Link to submission in OJS
     - Clear indication it's from Post45 submissions system
   - **Action**: Find and customize the author comments email template
   - **Template to find**: SUBMISSION_COMMENT or similar

7. **Set up Mailgun email templates**
   - Configure Mailgun templates for professional email styling
   - Research OJS Mailgun integration options
   - Document template variables needed for OJS emails

8. **Tweak Pragma Submissions theme to use Mailgun templates for styled emails**
   - Integrate Mailgun templates with OJS email system
   - Test with common email types (review invitations, submission confirmations, etc.)
   - Ensure branding consistency with Pragma Submissions theme

## Notes & Context

### Root Cause Analysis: Broken Reviewer Links

**Original Issue**: Reviewer invitation links returning 404 in production

**Investigation Path**:
1. Initially suspected missing `/index.php` in URLs (restful_urls issue)
2. Checked `.htaccess` configuration - confirmed correct
3. Tested invitation route - confirmed routing works
4. Found invitation ID 27 had status=ACCEPTED (already used)
5. Discovered email security scanners auto-visit links for malware scanning
6. **Conclusion**: One-click access incompatible with modern email security

**Production Setup**:
- Server: Apache 2.4.58 on Ubuntu
- OJS version: 3.5
- Domain: https://submissions.post45.org
- Journal path: `/journal`
- Restful URLs: Enabled
- One-click reviewer access: Currently enabled (needs to be disabled)

**Local Dev vs Production Differences**:
- Local: `restful_urls = Off`, uses `/index.php` in URLs
- Production: `restful_urls = On`, clean URLs without `/index.php`
- Both: Invitation system works correctly when not pre-visited by crawlers

### Test User Management Best Practices

**Don't delete users - disable them instead**:
- User IDs are linked to submissions, reviews, editorial decisions
- Deleting breaks data integrity and audit trails
- Safe approach:
  1. Remove from all roles (Users & Roles)
  2. Disable account (Edit user → Uncheck "Enable this account")
  3. Rename to "TEST - Delete Me" for clarity

**Safe to delete only if**:
- Never submitted anything
- Never reviewed anything
- Never made editorial decisions
- Just registered with no activity

### Related Files & Plugins

**Plugins (maintained in monorepo)**:
- `/plugins/themes/pragmaSubmissions/` - Frontend theme
- `/plugins/generic/post45Editorial/` - Active editorial-scope plugin (email
  filtering, "Mark Published on WordPress" decision, public-view blocker). Forked
  from submissionsOnly (June 2026).
- `/plugins/generic/submissionsOnly/` - Predecessor, kept disabled as backup
- `/plugins/generic/colorPalettes/` - Color picker for themes

**Config**:
- `/config.inc.php` - Main OJS configuration
- `.htaccess` - Apache rewrite rules for clean URLs

**Invitation System Code**:
- `/lib/pkp/classes/invitation/invitations/reviewerAccess/ReviewerAccessInvite.php`
- `/lib/pkp/classes/mail/variables/ReviewAssignmentEmailVariable.php`
- `/lib/pkp/classes/mail/traits/OneClickReviewerAccess.php`

## Next Session

When resuming work:
1. Review this TODO list
2. Disable one-click reviewer access in production (task #3)
3. Test with fresh reviewer invitation to confirm fix
4. Work on email template customization (task #4)
5. Consider Mailgun integration for better email styling (tasks #5-6)
