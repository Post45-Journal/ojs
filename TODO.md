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

## Pending Tasks ⏳

### Immediate Priority: Git Restructuring (Post45 Fork)

**Goal:** End state where both local and prod track `Post45-Journal/ojs` (a fork of `pkp/ojs`) on its `main` branch. Future workflow: edit locally → push to fork's main → pull on prod.

Currently:
- Local is on `stable-3_5_0` with custom commits (the wrong shape — customizations shouldn't live on an upstream branch)
- Prod has no remote tracking at all — just a one-shot "Initial Commit" snapshot
- Custom `mailgun` plugin lives directly in `/var/www/html/plugins/generic/mailgun/` with its own `.git`, separate from the monorepo

**Plan (see `temp/prod-upgrade-checklist.md` Phase 2 for the full procedure):**
1. Create `Post45-Journal/ojs` fork on GitHub
2. Move mailgun plugin into the post45-ojs-plugins-monorepo (consistency with colorPalettes/submissionsOnly/pragmaSubmissions)
3. Restructure local to `main` branch tracking the fork
4. Migrate prod's `.git` to track the fork
5. Future upgrades: merge `upstream/stable-3_5_0` into fork's main, push, pull on prod

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

**Tackle this AFTER the git restructuring above.**

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
- `/plugins/generic/submissionsOnly/` - Admin interface modifications
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
