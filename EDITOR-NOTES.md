# Editorial Cheat Sheet

## For the Post45 editorial team

**Purpose**: the things OJS does that aren't obvious from looking at the screen —
sequences that take more steps than you'd guess, buttons whose names don't quite
say what they do, and Post45-specific behaviour that differs from stock OJS.

This is a **running collection**, not a finished handbook. Entries get added as
we find them, while the details are fresh. When there's enough here to be worth
navigating, it becomes a Static Pages handbook on the journal site.

**How entries are marked:**

- ✅ **Verified** — traced through the code or confirmed in the browser.
- 📝 **To write up** — we know the topic matters, nobody has written it properly yet.
- ⚠️ **Check in browser** — written from how the code behaves; the exact button
  labels and screen positions still need confirming against the real UI.

---

## ✅ Sending a revision back to the same reviewer

The most common thing our workflow does that OJS doesn't make obvious. There is
no "send this revision back to the reviewer" button — you create a second review
round, and OJS handles the rest for you.

**The sequence**, once the author has uploaded their revision:

1. Go to the **External Review** stage.
2. Under the review-round controls, choose **Create New Review Round**.
3. In the new round, click **Add Reviewer**.
4. Your previous reviewers appear as their own group in the reviewer list —
   OJS pre-selects them for you, so you don't have to hunt through everyone.
5. Pick the reviewer. **The email template switches automatically** to the
   second-round wording:

   > Thank you for your review of *[title]*. The authors have considered the
   > reviewers' feedback and have now submitted a revised version of their work.
   > I'm writing to ask if you would conduct a second round of peer review…

   Edit it as you would any other message and send.

**Why bother, instead of just messaging them?** Three things you get that a
discussion message can't give you:

- The review is a **real record** — due date, recommendation, completion date —
  so it shows up in reports and in the Notion board alongside the first round.
- **OJS chases overdue reviews automatically.** A message has no follow-up.
- The reviewer's second opinion is attached to the submission's review history
  rather than buried in a thread.

**Only reviewers who actually completed a review last round** appear in the
returning-reviewers group. Someone who declined or never responded won't be there.

> Reviewers can answer with **See Comments** rather than a formal recommendation,
> which is usually the right choice for a second look — it's kept on our form
> specifically for responses that don't reduce to a single verdict.

---

## ✅ Why there's only one "Request Revisions" button

Stock OJS has two revision decisions: **Request Revisions** and **Resubmit for
Review**. We hide the second one.

They are mechanically **identical** — neither creates a review round, neither
changes who the reviewers are. The only difference is the wording of the email
the author receives and the status badge shown on the round.

Since we treat both situations as R&R, having two buttons would mean choosing
between them with no real consequence, and getting it wrong occasionally. One
button, and you decide afterwards whether the revision goes back to a reviewer
(see above) or you assess it yourself.

---

## 📝 To write up

Topics we know are worth an entry. Add detail when someone next walks the path.

- **Author's post-acceptance manuscript upload.** After acceptance we ask authors
  for a style-guide-conformed version. They upload it in a specific place at the
  Copyediting stage, and it lands in the editor's **Draft Files** panel. Stock OJS
  gives authors no upload path here at all — this is a Post45 addition, so it
  won't match anything you read in the OJS manual.
- **Publication agreement.** Nothing happens automatically on Accept — requesting
  the agreement is always an explicit action. The "N days ago" note on the
  Editorial State panel is the *only* reminder in the system; there is no email
  or digest. Also covers backdating a request or signature you didn't record on
  the day.
- **Editorial State panel.** Owner, stage status, publication agreement — three
  sections, each with its own Save button. Which fields appear depends on the
  stage you're on.
- **Copyediting and Production statuses.** What each value means and when to set
  it, including which are set for you by a decision and which you flip by hand.
- **Mark Published on WordPress.** Terminal action — what it does, and why the
  WordPress URL has to be saved before you run it.
- **Double-anonymous downloads.** Reviewers see a renamed file rather than the
  author's original filename. Worth knowing so it isn't reported as a bug.
- **What reviewers actually see** at each step, for answering their questions
  without having to log in as one.
- **The Notion board is written by OJS now** (once sync is switched on). Some
  columns are OJS's to fill and get rewritten on the next sync, so editing them
  on the board doesn't stick — status, decision, dates, the agreement fields, the
  links. Others stay yours and OJS never touches them: `Assigned to`, `Keywords`,
  the "needs fixing?" flags, `Archived`, attachments, `Current Task Due`. Write up
  which is which, and where to make a change so it survives (in OJS, for the first
  group). Not live yet — nothing on the board changes until sync is enabled.

---

## Adding to this file

When you hit something in the workflow that took more figuring out than it
should have, add a note — even a rough one. A three-line stub under
**To write up** is worth more than a polished entry written six months later
from memory.
