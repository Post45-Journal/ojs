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

## ⚠️ After "Request Desk Revision", the author has a real upload button (v1.0.18.0)

**📝 To verify in the browser — behaviour built in v1.0.18.0, not yet walked
end-to-end on prod.**

Request Desk Revision sends a Stage 1 submission back to the author before it
ever reaches reviewers. Post45 gives the author a real Upload path for this,
which is not something stock OJS provides.

**What the author sees:** the moment you record the Request Desk Revision
decision, the author's Submission Files panel gets a single **Upload revised
manuscript** button at the top. It's only there while the ask is
outstanding — as soon as you take any other decision (Send for Review,
Accept, Decline, another Request Desk Revision), the button disappears and
the panel goes back to read-only.

**What that button does:** the author's upload lands in Submission Files
already tagged as their revision, and the Notion `Review Status` for the
submission flips to `Received` on the next sync. No moving files around by
hand.

**So write the decision email around that button.** Tell them to open the
submission and use **Upload revised manuscript** in Submission Files, not
"reply in this discussion with your file attached". The discussion thread
the decision creates is for questions and back-and-forth about the ask, not
for the file itself.

**Deliberately just one button.** Copyediting's version of this pattern has
a matching "Upload other file" for supporting material; Stage 1 does not.
The window is narrow — respond to a specific ask — and giving a second
"anything else" button would blur what you asked for. If the author needs
to send you something alongside the revision, they can attach it to the
decision's discussion thread.

## ⚠️ The "just-submitted, spotted a typo" case still needs your help

The upload button above only appears when *you* have asked for a revision.
If an author submits, notices something wrong 20 minutes later, and wants
to send you a corrected file, they still have no Upload path — the same
situation as before v1.0.18.0.

**What they'll do instead:** open a discussion on their submission and
attach the corrected file to their first message. Discussion attachments
are always available to a participant.

**What you do about it:** download the corrected file from the discussion
attachment, then **Upload File** it into Submission Files yourself. Delete
the old version (or keep it as history — your call). Same as regularising
any file that arrived by email.

Not a first-class action yet — a one-click "promote from discussion to
Submission Files" is buildable if this comes up often enough to matter. Say
so if you find yourself doing this weekly.

---

## ⚠️ The two file panels in Copyediting — who can see what

**Check this in the browser before relying on it.** The behaviour is built and
tested, but nobody has walked it on screen yet.

Copyediting has two file panels, and Post45 uses them differently from stock
OJS. The difference that matters is **who can see them**:

| Panel | Authors see it? | What belongs there |
|---|---|---|
| **Draft Files** | **No — never** | Your working copies. The manuscript carried over from review, your first and second edits, anything still in progress. |
| **Manuscript & Copyedits** (core calls it Copyedited Files) | **Yes** | Anything the author sends you or you send them: their copyediting-ready manuscript, the copyedited document you send out, the version they return. |

This split exists because OJS can only hide a whole panel, not individual files
in it. There is no "hide this one file from the author" setting — if a file is
in Manuscript & Copyedits, the author can see it and download it. So **put
drafts in progress in Draft Files**, and move something across only when you mean the
author to have it.

The panel descriptions in OJS have been rewritten to say this, so you don't
have to remember it from here.

---

## ⚠️ The status now follows the files — you shouldn't have to set it

**📝 To verify in the browser — behaviour built in v1.0.14.0, not yet walked
end-to-end on prod.**

The Copyediting status used to be a dropdown you had to remember to change.
Now most of it moves on its own, driven by what people **say** about the files
they upload:

| What happens | Status becomes |
|---|---|
| Author uses **Upload copyedit-ready manuscript** in Copyedited Files | Manuscript received |
| You run **Send Copyedits to Author** | Edits with author |
| Author uses **Upload approved copyedits** in Copyedited Files | Copyedits approved by author |

The author's view of the Copyedited Files panel has three upload buttons — one
for each of those two tagged uploads, plus a plain "Upload other file" for
supporting material (abstracts, images, anything else). They pick the button
that says what they're sending. Only the two tagged ones move the status.

**When the button was the wrong one, you can fix it.** On any file in
Copyedited Files, the row menu has **Mark as copyedit-ready manuscript** /
**Mark as approved copyedits** / **Remove tag**. Marking a file has the same
effect as the author using the matching upload button. The file's current tag
shows as a badge in the Tag column so you can see at a glance which file is
what — untagged files show a blank badge.

If you mark a file from the row menu, the substatus in the Editorial State
panel updates on the server but the dropdown doesn't refresh live — reload the
page to see the new value. (Not a blocker: the dropdown is on its way out.)

**You are told when they upload.** Every author upload into Copyedited Files
sends you an email regardless of which button they used, so you know something
landed even if the status did not move.

**First edit and Second edit are driven by assignment (v1.0.15.0).** Two
per-participant actions replace the manual dropdown for these two states.
They live on the **"…" menu** of each row in the **Participants** panel on
the Copyediting stage:

- **Assign as first edit** — clicks through to a composer pre-scoped to
  that participant. Sending sets the Copyediting status to **First edit in
  progress**, makes that participant the submission's current owner,
  emails them, and creates a stage-4 discussion thread for the handoff.
- **Assign as second edit** — same shape for the second round.

Menu items appear only when the participant's user_group has the
**Copyediting** checkbox ticked in Users & Roles → Roles (that's Section
Editor, Managing Editor, Assistant Editor, Copyeditor, and any other
editorial role currently authorized on Copyediting) — minus the author,
who can never be assigned their own edit. Un-ticking the Copyediting box
for a role removes the menu items for participants in that role on the
next page load, no code change.

**To assign a copyeditor who isn't yet a participant:** use the native
**Assign** button on the Participants panel to add them (this is the
same modal you'd use on any other stage). Once they're on the list,
their "…" menu carries the Assign-as-First/Second-edit items. Two
clicks total. If you don't want to send the standard Request Copyedit
email while adding them, leave the message body empty in the Assign
modal — OJS suppresses the email when the body is blank.

They are deliberately *not* driven by uploading to Draft Files, and never
will be: uploading means that edit is **finished**, so treating it as "in
progress" would say the opposite of what happened.

**What OJS doesn't automate (yet) — the copyeditor's upload.** When the
person doing the edit uploads their draft to Draft Files, OJS won't
notify you and the Copyediting status stays at *First / Second edit in
progress*. You'll need to check Draft Files periodically, or the
copyeditor will need to ping you (Slack, email). This is fine if the
team is already coordinating out-of-band, and Notion mirrors the same
truth (status doesn't move until you take the next editorial action).
If you'd rather have OJS notify you + move the status when a draft
lands, that's a clean addition — flag it and we'll wire a hook that
watches Draft Files uploads by the current owner.

**The dropdown no longer offers First / Second edit.** As of v1.0.15.0, the
Editorial State panel dropdown for Stage 4 lists only *Manuscript received*,
*Copyedits with author*, and *Copyedits approved by author* — the three
states that either arrive from tagged uploads or from Send Copyedits /
Send To Production. A submission that already carries First / Second edit
in progress from before still shows the value in the dropdown (nothing is
lost); you just can't freshly select it there — use the decision instead.

Two things the whole substatus system deliberately will not do: it never
moves the status *backwards* (if you've already pushed a submission forward,
a late file or a late tag won't rewind it), and a third or fourth edit stays
at "Second edit in progress".

---

## ⚠️ A file that arrives the wrong way needs one extra step

**Check this in the browser before relying on it.**

Authors will email you manuscripts, and they'll attach files to discussion
threads instead of using the upload button. Neither of those puts the file in
the file panels — so as far as the rest of the workflow is concerned, and as
far as the Notion board is concerned, **it hasn't arrived**.

The fix is the same in both cases and takes a few seconds:

1. Go to the right panel — **Copyedited Files** for anything to or from the
   author, **Draft Files** for your own working copy.
2. Click **Upload/Select Files**.
3. If the file came by email, upload it. If it's on a discussion thread, it's
   already in the list — just tick it and add it.

Doing this is what makes the file count. The status moves and the Notion board
updates off that step, so skipping it leaves the board saying the submission is
still waiting on the author.

An emailed manuscript you add this way counts exactly as if the author had
uploaded it themselves — that's deliberate, since you're putting it where it
should have gone.

---

## ⚠️ Send To Production is blocked until something is in Copyedited Files

**Check this in the browser before relying on it.**

Stock OJS lets you run **Send To Production** with both file lists showing "No
items found", which leaves the submission in Production with nothing to produce
from. Post45 refuses that: if **Copyedited Files** is empty, the decision won't
record and you'll get a message saying so.

An empty Copyedited Files panel means the round-trip never happened — the
author was never sent edits and never returned anything, because both of those
put a file there. If you're sure copyediting is done and the file is elsewhere
(a discussion thread, Draft Files, your inbox), use the step above to move it
across, then run the decision.

---

## ✅ The "Status" box appears on every stage except the one you're on

Not a bug, and not something Post45 changed. That grey **Status** box at the top
of a stage — "The submission advanced to the next review round, was accepted, and
is currently in the Copyediting stage" — exists only to tell you **you are not
looking at the stage the submission is actually in**.

So it shows on every stage *except* the live one, which makes the live stage look
like it is missing something. It isn't: there is nothing to orient you away from
when you are already in the right place.

It also appears when you open a stage the submission has not reached yet, and
when you look back at an earlier review round.

If you want to confirm it, move a submission on a stage — the box disappears from
the new stage and appears on the old one.

---

## ⚠️ Send Copyedits to Author: upload the file *in* the decision

**Check this in the browser before relying on it.**

You do not need to upload the copyedited document first and then send it. The
natural order is the other order:

1. Finish the copyedit on your own machine.
2. Click **Send Copyedits to Author**.
3. On the **Attach Files** step, **Upload File** — that's the only option, on
   purpose.

That one upload does three things: attaches the file to the email, files it in
**Manuscript & Copyedits** so the author can find it in OJS, and puts a copy on
the discussion thread. There is no separate "attach a file already in the panel"
option, because anything already in that panel is visible to the author anyway.

Because the file lands in Manuscript & Copyedits, this also satisfies the check
that blocks **Send To Production** when nothing has been copyedited.

---

## 📝 To write up

Topics we know are worth an entry. Add detail when someone next walks the path.

- **When an author emails you a revision instead of using OJS.** Some will, and
  you can put it into OJS on their behalf: on the review round, the **Upload**
  button beside *Revisions Uploaded* is available to editors, not just authors.
  Worth doing rather than leaving it in your inbox — a file uploaded there is
  what the rest of the workflow (and the Notion board, once sync is on) can
  actually see. Write up the exact steps and which file type to pick.
- **The two legitimate ways an author sends a revision.** They can use the
  **Upload** button beside *Revisions Uploaded*, or attach the file to a **Review
  Discussion** message — the latter is normal when they want to explain what they
  changed, not a mistake to correct. Both are fine; note what each looks like from
  the editor's side so nobody goes hunting for a revision that is sitting on a
  discussion thread.
- **Publication agreement.** Nothing happens automatically on Accept — requesting
  the agreement is always an explicit action. The "N days ago" note on the
  Editorial State panel is the *only* reminder in the system; there is no email
  or digest. Also covers backdating a request or signature you didn't record on
  the day.
- **Editorial State panel.** Owner, stage status, publication agreement — three
  sections, each with its own Save button. Which fields appear depends on the
  stage you're on.
- **Production statuses.** What each Stage 5 value means and when to set it. The
  Copyediting half is covered above; the proofs states are still hand-set.
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
- 📝 **Reassign `Assigned to` when you take a decision.** When you record a
  decision that puts the ball in someone else's court (Request Revisions -> the
  author, Send Copyedits to Author -> the author, Send to Production -> a
  copyeditor, and so on), also reassign the submission's owner in OJS to
  whoever needs to act next. That's the value the Notion board's `Assigned to`
  column reflects. Write up which decisions imply which handoffs; consider a
  post-decision UI prompt.
- 📝 **Returning a pre-review revision to the editors** (post-`RequestDeskRevision`).
  The stage-1 tagged-upload path is still being built (Chunk B of Session 3D).
  Until it lands, authors have no file-grid upload channel at stage 1 — they
  respond through the decision's discussion thread. When you get one that way,
  regularise the attachment into the Submission Files bucket via Upload/Select
  so it reaches reviewers when you advance the round.

---

## Adding to this file

When you hit something in the workflow that took more figuring out than it
should have, add a note — even a rough one. A three-line stub under
**To write up** is worth more than a polished entry written six months later
from memory.
