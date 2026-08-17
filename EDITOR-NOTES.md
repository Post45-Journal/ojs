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

## ✅ After "Request Desk Revision", the author replies in the discussion

Request Desk Revision sends a Stage 1 submission back to the author before it
ever reaches reviewers. Worth knowing before you use it: **it does not give the
author an Upload button.**

Stock OJS only opens an author's upload path at the *review* stages, and only
once an R&R decision exists in the current round. Stage 1 has no review rounds,
so there is nothing for that rule to key on. The author's own submission files
become read-only to them the moment they finish submitting.

**What the author actually does:** the decision creates a discussion thread with
them, and they reply to it with the revised manuscript attached. Attaching a
file to a discussion reply is always available to a participant, which is why
this route works when the file panels don't.

**So when you write the decision email, tell them to reply in that thread** —
don't ask them to "upload a revised version", because they will go looking for a
button that isn't there.

**Then move the file where it belongs.** A file attached to a discussion stays in
the discussion; it does not appear in the submission's file list. Once the author
replies, upload their file into the submission files yourself, the same way you
would handle a revision that arrived by email (see the note below).

If this becomes common enough to be annoying, say so — giving authors a real
upload button at Stage 1 is buildable, it just hasn't been built.

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

**First edit / second edit are set by hand for now — this is temporary.** They
are meant to be driven by *assigning* the edit: an editor picks who does the
first edit, that person gets an email, and the status follows from the
assignment. That action doesn't exist yet, so until it does you set these two
yourself.

They are deliberately *not* driven by uploading to Draft Files, and won't be:
uploading means that edit is **finished**, so treating it as "in progress" would
say the opposite of what happened.

The dropdown is still there and still works, but it is **on its way out**. It
exists right now only to correct a state that ended up wrong for a reason the
row menu cannot fix. If you reach for it, that is worth reporting: each time it
is needed marks a real action the system failed to notice, and the fix is to
make that action register rather than to keep marking it by hand.

Two things it deliberately will not do: it never moves the status *backwards*
(if you've already pushed a submission forward, a late file or a late tag won't
rewind it), and a third or fourth edit stays at "Second edit in progress".

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

---

## Adding to this file

When you hit something in the workflow that took more figuring out than it
should have, add a note — even a rough one. A three-line stub under
**To write up** is worth more than a polished entry written six months later
from memory.
