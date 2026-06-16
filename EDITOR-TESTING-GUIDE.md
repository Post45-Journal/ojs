# OJS Submissions Platform Testing Guide
## For Editorial Team

**Purpose**: Familiarize yourself with the OJS submissions workflow from all three perspectives (Author, Reviewer, Editor) and verify that email notifications are working correctly.

**Time Required**: ~30-45 minutes

---

## Prerequisites

- You will receive an **Editor account** (credentials provided separately)
- You need your **@post45.org Google Workspace email** for testing
- You will create **two additional test accounts** using Gmail's `+` aliasing feature

---

## Part 0: Understanding Gmail Aliasing & Email Organization

### What is Gmail Aliasing?

Gmail ignores anything after a `+` in your email address, so all these deliver to the same inbox:
- `arthur@post45.org`
- `arthur+test-author@post45.org`
- `arthur+test-reviewer@post45.org`

But OJS treats them as **different email addresses**, allowing you to create separate accounts while receiving all emails in one inbox.

### Setting Up Gmail Filters (Recommended)

To distinguish which emails are for which role, create Gmail filters:

1. **In Gmail**, click the search box, then click "Show search options" (dropdown arrow)
2. **For Author emails**:
   - In "To" field: `yourname+test-author@post45.org`
   - Click "Create filter"
   - Check "Apply the label" → Create new label "OJS - Author"
   - Check "Also apply filter to matching conversations"
   - Click "Create filter"

3. **For Reviewer emails**:
   - Repeat with `yourname+test-reviewer@post45.org`
   - Create label "OJS - Reviewer"

4. **For Editor emails** (optional):
   - In "To" field: `yourname@post45.org`
   - Create label "OJS - Editor"

Now all emails will be automatically labeled by role!

---

## Part 1: Create Your Test Accounts

### 1.1 Create Author Account

1. Go to the OJS site: **https://submissions.post45.org**
2. Click **"Register"** (top right)
3. Fill in registration form:
   - **Username**: `yourname-test-author` (e.g., `arthur-test-author`)
   - **Email**: `yourname+test-author@post45.org`
   - **Given Name**: Your First Name
   - **Family Name**: Your Last Name + "(Test Author)"
   - **Country**: United States
   - **Affiliation**: (optional - can leave blank)
   - **I agree to have my data collected**: ✓ Check this box
   - **Register as**: Check **"Author"** ONLY
   - **DO NOT CHECK** "Send me email notifications" boxes
4. Click **"Register"**
5. **Check your @post45.org inbox** - you should receive a welcome email
   - ✅ **Verify**: Email arrives in your inbox (should have "OJS - Author" label if you set up filters)

### 1.2 Create Reviewer Account

1. In the **same browser**, log out of the Author account (or use an incognito window)
2. Click **"Register"** again
3. Fill in registration form:
   - **Username**: `yourname-test-reviewer` (e.g., `arthur-test-reviewer`)
   - **Email**: `yourname+test-reviewer@post45.org`
   - **Given Name**: Your First Name
   - **Family Name**: Your Last Name + "(Test Reviewer)"
   - **Country**: United States
   - **Affiliation**: (optional)
   - **I agree to have my data collected**: ✓ Check this box
   - **Register as**: Check **"Reviewer"** ONLY
   - **DO NOT CHECK** "Send me email notifications" boxes
4. Click **"Register"**
5. **Check your inbox** - you should receive another welcome email
   - ✅ **Verify**: Email has "OJS - Reviewer" label

**Pro Tip**: Keep three browser profiles/windows open:
- Regular window: Editor account
- Incognito window 1: Author account
- Incognito window 2: Reviewer account

---

## Part 2: Author Workflow - Submit a Test Manuscript

**Log in as**: Author account (`yourname+test-author@post45.org`)

### 2.1 Start a New Submission

1. Click **"New Submission"** button
2. **Submission Requirements**:
   - Read the checklist
   - Check all boxes
   - Click **"Save and Continue"**

### 2.2 Upload Submission

1. **Upload File**:
   - Click **"Upload File"**
   - Select a test document (any Word/PDF file)
   - Click **"Continue"**
   - Click **"Complete"**
2. Click **"Save and Continue"**

### 2.3 Enter Metadata

1. **Article Type**: Select "General Submission" (or any section)
2. **Title**: "Test Submission - [Your Name] - [Today's Date]"
3. **Abstract**: Write a brief test abstract (2-3 sentences)
4. **Contributors**:
   - You should already be listed as author
   - If not, click "Add Contributor" and add yourself
5. Click **"Save and Continue"**

### 2.4 Confirmation

1. Review your submission
2. Click **"Finish Submission"**
3. ✅ **Verify**: You see a success message
4. ✅ **Check your inbox**: You should receive a "Submission Confirmation" email (labeled "OJS - Author")

**What the email should contain**:
- Submission title
- Submission ID number
- Link to view submission
- Next steps information

---

## Part 3: Editor Workflow - Review & Assign

**Switch to**: Editor account (`yourname@post45.org`)

### 3.1 Find the Submission

1. Log in with your Editor credentials
2. You should see the submission in the **"Unassigned"** view on your dashboard
3. Click on the submission title to open it

### 3.2 Assign Yourself as Editor

1. In the **Submission** workflow tab, click **"Assign"** under "Participants"
2. Search for your editor name
3. Click **"Assign"**
4. Click **"OK"** to confirm

### 3.3 Send to Review

1. Click the **"Review"** tab (top of workflow)
2. Click **"Send to Review"** button
3. Review the email template (this will be sent to the author)
4. Click **"Record Editorial Decision"**
5. ✅ **Check inbox**: The author should receive a "Sent to Review" notification (labeled "OJS - Author")

### 3.4 Assign a Reviewer

1. Still in the **Review** tab
2. Under "Reviewers", click **"Assign Reviewer"**
3. Search for your reviewer account: `yourname-test-reviewer`
4. Click the reviewer name to select them
5. Set review due date (e.g., 2 weeks from today)
6. Review the email template
7. Click **"Assign Reviewer"**
8. ✅ **Check inbox**: You should receive a "Review Request" email (labeled "OJS - Reviewer")

---

## Part 4: Reviewer Workflow - Complete a Review

**Switch to**: Reviewer account (`yourname+test-reviewer@post45.org`)

### 4.1 Accept the Review Request

1. Log in as reviewer
2. You should see the review request in your dashboard
3. Click **"View"** on the review request
4. Click **"Accept Review, Continue to Step #2"**
5. ✅ **Check inbox**: You should receive a "Review Request Accepted" confirmation (labeled "OJS - Reviewer")

### 4.2 Complete the Review

1. Click **"Continue to Step #3"**
2. Download the submission file to review it
3. **Review Recommendation**: Select "Accept Submission"
4. **Reviewer's Comments**:
   - Write a brief test review (2-3 sentences)
   - Example: "This is a test review. The submission demonstrates [positive feedback]. I recommend acceptance."
5. Click **"Submit Review"**
6. ✅ **Check inbox**:
   - You (reviewer) should receive a "Review Submitted" confirmation (labeled "OJS - Reviewer")
   - Editor should receive a "Review Submitted" notification (labeled "OJS - Editor" or no label)

---

## Part 5: Editor Workflow - Accept Submission

**Switch to**: Editor account

### 5.1 Review the Reviewer's Recommendation

1. Go back to the submission (click "Submissions" → find your test submission)
2. In the **Review** tab, you should see the completed review
3. Click **"Confirm"** to read the review details

### 5.2 Make Editorial Decision - Accept

1. Click **"Accept Submission"** button
2. Review the email template (this will be sent to the author)
3. Click **"Record Editorial Decision"**
4. ✅ **Check inbox**: Author should receive "Submission Accepted" email (labeled "OJS - Author")

### 5.3 View in "Accepted" Dashboard

1. Click **"Submissions"** (top navigation)
2. Click **"Accepted"** view (left sidebar)
3. You should see your test submission listed here
4. This is the final stage in our submissions-only workflow!

---

## Part 6: Testing Checklist

Use this checklist to verify everything worked:

### Email Delivery ✓
- [ ] Author received: Welcome email
- [ ] Author received: Submission confirmation
- [ ] Author received: Sent to review notification
- [ ] Author received: Submission accepted notification
- [ ] Reviewer received: Welcome email
- [ ] Reviewer received: Review request
- [ ] Reviewer received: Review accepted confirmation
- [ ] Reviewer received: Review submitted confirmation
- [ ] Editor received: Review submitted notification

### Gmail Organization ✓
- [ ] Author emails have "OJS - Author" label (if filters set up)
- [ ] Reviewer emails have "OJS - Reviewer" label (if filters set up)
- [ ] Editor emails have "OJS - Editor" label (if filters set up)

### Workflow ✓
- [ ] Submission appears in correct dashboard views
- [ ] Review assignment works correctly
- [ ] Review submission works correctly
- [ ] Acceptance moves submission to "Accepted" view
- [ ] All UI elements display correctly

---

## Part 7: Cleanup (Optional)

After testing, you can delete your test accounts:

1. **Contact the site administrator** (Arthur) with:
   - Your test usernames: `yourname-test-author` and `yourname-test-reviewer`
   - Request deletion

2. **Delete Gmail filters** (if you want):
   - Gmail Settings → Filters and Blocked Addresses
   - Delete the "OJS - Author" and "OJS - Reviewer" filters

---

## Troubleshooting

### Emails not arriving?
- Check your spam folder
- Verify you used the correct email format: `yourname+test@post45.org`
- Gmail may take 1-2 minutes to deliver

### Can't see the submission in dashboard?
- Make sure you're logged in with the correct account
- Check the correct view (Unassigned, Assigned, Review, Accepted)

### Forgot which account you're logged in as?
- Check the top-right corner for username
- Or log out and log back in

### Questions or Issues?
Contact Arthur at arthur@post45.org

---

## Notes for Reviewers

- This is a **submissions-only** system - we don't publish through OJS
- After "Accepted", the workflow is complete (we handle publication in Notion)
- The interface hides all publishing-related features (copyediting, production, etc.)
- Focus on the submission → review → accept workflow

---

**Happy Testing!** 🎉
