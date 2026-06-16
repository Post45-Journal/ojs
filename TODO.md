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

## Pending Tasks ⏳

### Immediate Priorities

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
