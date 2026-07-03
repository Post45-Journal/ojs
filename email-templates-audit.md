# Email Templates Audit — post45Editorial Plugin

**Plugin lineage:** Originally `submissionsOnly` (Oct 2025). Forked into `post45Editorial` (June 2026) when Post45 expanded OJS's scope to include copy editing AND post-copyedit proof coordination.

**Scope as of June 2026:** Submit → Peer Review → Accept → Copy Edit → Proof Coordination → Mark Published on WordPress. All four OJS stages (1, 3, 4, 5) are in scope. Stage 5 is repurposed as proof coordination (not OJS-side typesetting). The terminal action is "Mark Published on WordPress" — custom decision added by the post45Editorial plugin.

## Currently HIDDEN (16 templates):

### OJS Publication Mechanics (4)
Post45 marks published externally via the post45Editorial action. OJS's native versioning/issue-publish/OA flip mechanisms are unused.

1. ✅ EDITOR_DECISION_NEW_ROUND ("New Version Created") — no versioning
2. ✅ VERSION_CREATED — no versioning
3. ✅ ISSUE_PUBLISH_NOTIFY — no issues (continuous publication on WordPress)
4. ✅ OPEN_ACCESS_NOTIFY — Post45 is OA from day one; no embargo flip

### Broadcast / Announcement (1)
5. ✅ ANNOUNCEMENT — Post45 uses Mailchimp/Mailerlite for journal-wide announcements

### Payment / Subscription (9)
Post45 is free and open — no payment or subscription flows.

6. ✅ PAYMENT_REQUEST_NOTIFICATION
7. ✅ SUBSCRIPTION_PURCHASE_INDL
8. ✅ SUBSCRIPTION_PURCHASE_INSTL
9. ✅ SUBSCRIPTION_RENEW_INDL
10. ✅ SUBSCRIPTION_RENEW_INSTL
11. ✅ SUBSCRIPTION_NOTIFY
12. ✅ SUBSCRIPTION_BEFORE_EXPIRY
13. ✅ SUBSCRIPTION_AFTER_EXPIRY
14. ✅ SUBSCRIPTION_AFTER_EXPIRY_LAST

### ORCID (3)
Post45 doesn't use ORCID integration.

15. ✅ ORCID_COLLECT_AUTHOR_ID
16. ✅ ORCID_REQUEST_AUTHOR_AUTHORIZATION
17. ✅ ORCID_REQUEST_UPDATE_SCOPE

### Masthead (1, added in OJS 3.5.0-4)
18. ✅ USER_ROLE_MASTHEAD_UPDATE — Post45 doesn't expose a public masthead

(Numbering above is 1–18, not 1–16; the count "16" reflects the trimmed list now that copy editing + production templates are back in scope.)

### Group Filters (Manage Emails sidebar)
- **None excluded** — all four workflow-stage group filters (Submission / Review / Copyediting / Production) are in scope as of June 2026

## Changes from previous version (Oct 2025 → June 2026):

**Now SHOWN (previously hidden):**
- `DISCUSSION_NOTIFICATION_COPYEDITING` — copy editing in scope
- `DISCUSSION_NOTIFICATION_PRODUCTION` — Stage 5 used for proof coordination
- `EDITOR_DECISION_SEND_TO_PRODUCTION` — used to move accepted+copyedited submission into proof coordination
- `EDITOR_DECISION_BACK_FROM_PRODUCTION` — used to return to copy editing when proof review surfaces issues
- "Copyediting" group filter
- "Production" group filter

---

## Currently SHOWN (key categories):

### ✅ KEEP — Submission Stage
- SUBMISSION_ACK
- SUBMISSION_ACK_NOT_USER
- SUBMISSION_NEEDS_EDITOR
- SUBMISSION_SAVED_FOR_LATER
- DISCUSSION_NOTIFICATION_SUBMISSION

### ✅ KEEP — Review Stage
- REVIEW_REQUEST, REVIEW_REQUEST_SUBSEQUENT, REVIEW_RESEND_REQUEST
- REVIEW_CONFIRM, REVIEW_DECLINE, REVIEW_CANCEL, REVIEW_REINSTATE
- REVIEW_REMIND, REVIEW_REMIND_AUTO, REVIEW_RESPONSE_OVERDUE_AUTO
- REVIEW_ACK, REVIEW_COMPLETE, REVIEW_EDIT
- DISCUSSION_NOTIFICATION_REVIEW

### ✅ KEEP — Editorial Decisions
- EDITOR_ASSIGN
- EDITOR_DECISION_ACCEPT (moves to Copy Editing)
- EDITOR_DECISION_BACK_FROM_COPYEDITING ("Cancel Acceptance" — back to Review)
- EDITOR_DECISION_DECLINE
- EDITOR_DECISION_INITIAL_DECLINE
- EDITOR_DECISION_REVERT_DECLINE
- EDITOR_DECISION_REVERT_INITIAL_DECLINE
- EDITOR_DECISION_REVISIONS, EDITOR_DECISION_RESUBMIT
- EDITOR_DECISION_SEND_TO_EXTERNAL, EDITOR_DECISION_SKIP_REVIEW
- EDITOR_DECISION_CANCEL_REVIEW_ROUND

### ✅ KEEP — Copy Editing
- DISCUSSION_NOTIFICATION_COPYEDITING

### ✅ KEEP — Proof Coordination (Production stage, repurposed)
- DISCUSSION_NOTIFICATION_PRODUCTION
- EDITOR_DECISION_SEND_TO_PRODUCTION (rebranded by Post45's draft: "Sending Article to Proofs")
- EDITOR_DECISION_BACK_FROM_PRODUCTION

### ✅ KEEP — Editor/Reviewer Management
- EDITOR_RECOMMENDATION, EDITORIAL_REMINDER
- REVIEWER_REGISTER, EDITOR_DECISION_NOTIFY_REVIEWERS

### ✅ KEEP — User Management
- USER_REGISTER, USER_VALIDATE_CONTEXT, USER_VALIDATE_SITE
- USER_ROLE_ASSIGNMENT_INVITATION, USER_ROLE_END
- CHANGE_EMAIL

### ✅ KEEP — Password/Security
- PASSWORD_RESET_CONFIRM

### ✅ KEEP — Author Notifications
- EDITOR_DECISION_NOTIFY_OTHER_AUTHORS
- REVISED_VERSION_NOTIFY

### 🆕 NEW (added by post45Editorial)
- `EDITOR_DECISION_MARK_PUBLISHED` — "Your article is now live" — sent by the Mark Published on WordPress action with `{$publicationUrl}` as a custom variable

### ❓ REVIEW — Potentially Hide (1)
- STATISTICS_REPORT_NOTIFICATION — open question: do you use OJS's automated statistics reports?

---

## Summary

- **Currently Hidden**: 16 templates (down from 22 in the submissionsOnly era)
- **New in post45Editorial**: 1 custom mailable (`EDITOR_DECISION_MARK_PUBLISHED`)
- **Open question**: STATISTICS_REPORT_NOTIFICATION — hide if Post45 doesn't use it
