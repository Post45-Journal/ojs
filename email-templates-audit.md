# Email Templates Audit - Submissions Only Plugin

## Currently HIDDEN (22 templates):

### Publishing Workflow (5)
1. ✅ DISCUSSION_NOTIFICATION_COPYEDITING
2. ✅ DISCUSSION_NOTIFICATION_PRODUCTION
3. ✅ EDITOR_DECISION_SEND_TO_PRODUCTION
4. ✅ EDITOR_DECISION_BACK_FROM_PRODUCTION (Sent Back TO Copyediting from Production)
5. ✅ EDITOR_DECISION_NEW_ROUND (New Version Created)

### Publication Notifications (4)
6. ✅ ISSUE_PUBLISH_NOTIFY
7. ✅ OPEN_ACCESS_NOTIFY
8. ✅ ANNOUNCEMENT
9. ✅ VERSION_CREATED (Publication version created — production workflow)

### Payment/Subscription (9)
10. ✅ PAYMENT_REQUEST_NOTIFICATION
11. ✅ SUBSCRIPTION_PURCHASE_INDL
12. ✅ SUBSCRIPTION_PURCHASE_INSTL
13. ✅ SUBSCRIPTION_RENEW_INDL
14. ✅ SUBSCRIPTION_RENEW_INSTL
15. ✅ SUBSCRIPTION_NOTIFY
16. ✅ SUBSCRIPTION_BEFORE_EXPIRY
17. ✅ SUBSCRIPTION_AFTER_EXPIRY
18. ✅ SUBSCRIPTION_AFTER_EXPIRY_LAST

### ORCID (3)
19. ✅ ORCID_COLLECT_AUTHOR_ID
20. ✅ ORCID_REQUEST_AUTHOR_AUTHORIZATION
21. ✅ ORCID_REQUEST_UPDATE_SCOPE

### Masthead (1, added in OJS 3.5.0-4)
22. ✅ USER_ROLE_MASTHEAD_UPDATE (User Role Masthead Visibility Update Notification — Post45 doesn't expose a public masthead)

---

## Currently SHOWN (should review - 45 templates):

### ✅ KEEP - Submission Stage (5)
- SUBMISSION_ACK - Author receives when submission created
- SUBMISSION_ACK_NOT_USER - Non-user co-author notification
- SUBMISSION_NEEDS_EDITOR - Alert when submission needs editor assigned
- SUBMISSION_SAVED_FOR_LATER - Incomplete submission saved
- DISCUSSION_NOTIFICATION_SUBMISSION - Discussion in submission stage

### ✅ KEEP - Review Stage (14)
- REVIEW_REQUEST - Invite reviewer
- REVIEW_REQUEST_SUBSEQUENT - Invite reviewer for subsequent round
- REVIEW_RESEND_REQUEST - Resend reviewer invitation
- REVIEW_CONFIRM - Reviewer accepts
- REVIEW_DECLINE - Reviewer declines
- REVIEW_CANCEL - Editor cancels review request
- REVIEW_REINSTATE - Reinstate cancelled reviewer
- REVIEW_REMIND - Manual reminder to reviewer
- REVIEW_REMIND_AUTO - Auto reminder to reviewer
- REVIEW_RESPONSE_OVERDUE_AUTO - Auto reminder when overdue
- REVIEW_ACK - Acknowledge completed review
- REVIEW_COMPLETE - Notify author review is complete
- REVIEW_EDIT - Notify reviewer of edited review
- DISCUSSION_NOTIFICATION_REVIEW - Discussion in review stage

### ✅ KEEP - Editorial Decisions (12)
- EDITOR_ASSIGN - Assign editor to submission
- EDITOR_DECISION_ACCEPT - Accept submission (moves to Copyediting = our "Accepted")
- EDITOR_DECISION_BACK_FROM_COPYEDITING - **Sent Back FROM Copyediting to Review (Cancel Acceptance)**
- EDITOR_DECISION_DECLINE - Decline submission
- EDITOR_DECISION_INITIAL_DECLINE - Decline without review
- EDITOR_DECISION_REVERT_DECLINE - Revert decline decision
- EDITOR_DECISION_REVERT_INITIAL_DECLINE - Revert initial decline
- EDITOR_DECISION_REVISIONS - Request revisions
- EDITOR_DECISION_RESUBMIT - Request resubmission
- EDITOR_DECISION_SEND_TO_EXTERNAL - Send to external review
- EDITOR_DECISION_SKIP_REVIEW - Skip review stage
- EDITOR_DECISION_CANCEL_REVIEW_ROUND - Cancel review round

### ✅ KEEP - Editor/Reviewer Management (4)
- EDITOR_RECOMMENDATION - Section editor recommendation to editor
- EDITORIAL_REMINDER - Reminder for editorial tasks
- REVIEWER_REGISTER - Welcome email for new reviewer
- EDITOR_DECISION_NOTIFY_REVIEWERS - Notify reviewers of decision

### ✅ KEEP - User Management (6)
- USER_REGISTER - Welcome new user
- USER_VALIDATE_CONTEXT - Validate user email (journal level)
- USER_VALIDATE_SITE - Validate user email (site level)
- USER_ROLE_ASSIGNMENT_INVITATION - Invite user to role
- USER_ROLE_END - Notify user role ended
- CHANGE_EMAIL - Confirm email change

### ✅ KEEP - Password/Security (1)
- PASSWORD_RESET_CONFIRM - Password reset

### ✅ KEEP - Author Notifications (2)
- EDITOR_DECISION_NOTIFY_OTHER_AUTHORS - Notify co-authors of decision
- REVISED_VERSION_NOTIFY - Notify editors of revised submission

### ❓ REVIEW - Potentially Hide (2)
- STATISTICS_REPORT_NOTIFICATION - Statistics report emails (do you use this?)
- VERSION_CREATED - Version created notification (related to versioning/publication?)

---

## Summary:
- **Currently Hidden**: 22 templates
- **Open question**: STATISTICS_REPORT_NOTIFICATION — do you use automated statistics reports, or handle externally? Hide if not used.
- **Correctly Shown**: ~43 templates (all submission/review workflow related)
