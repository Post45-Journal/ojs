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
- 📸 **Scribe candidate** — the entry describes multi-step UI clicks that would
  read faster as a screen-captured walkthrough. Reach for Scribe when you next
  do this action for real, and drop the recording into the entry.

---

## Launch: what's migrating from Notion, what's not

**Migrating in**: in-progress articles (roughly 60), plus the people currently
active on them (authors, still-open reviewers, assigned copyeditors) and the
files each article actively needs to continue — current manuscript, decision
letter (as an OJS Discussion), reader's reports.

**NOT migrating in**: published articles (already on WordPress), rejected /
withdrawn articles (legacy Notion is the record), and historical files (prior
revision rounds, cover letters, superseded drafts).

**Pre-cutover reviewers whose work is complete are migrated silently**:
they get an OJS account (with a random password, no notifications sent),
and their completed review appears in the article's review list with
`Date Completed` set from Notion. Their report file lives in Review
Attachments on that assignment, exactly where an active reviewer's report
would. If a completed reviewer later interacts with OJS (submits an
article, gets invited for a new review, does a password reset), the
existing silent account absorbs the interaction — no admin step needed.
See the Review-stage section for the returning-reviewer flow.

**File upload dates on migrated reports read as the populate run date, not
the original submission date.** The reviewer's actual submission date is
correctly recorded on the review assignment itself (Date Completed) —
that's the number to trust for editorial-history purposes. The file's
"date uploaded" column is when populate put the file into OJS storage,
which is technically accurate but not what an editor is usually asking
when they scan the file panel.

**Why this scope**: **OJS is the operational surface** — it holds only what's
needed to keep the workflow moving from where each article stands today.
**Notion remains the historical record** — full editorial history, past
decisions, completed reviewer work all stay there. The two systems have
intentionally complementary scopes; OJS won't work as a stats-and-history
tool, and Notion won't be the day-to-day workspace anymore.

**Migration-window exclusions worth naming**:
- **Schantz** — decision pending as of migration; held out until we know
  whether it's a rejection (no migration needed) or R&R (migrate).
- **Colleen Harris** — reclassified as desk rejection.
- **Angela Allan** — proofs stage, publication imminent; handled outside OJS.
- CE-stage articles nearing publication may also be handled outside OJS on a
  case-by-case basis (pending managing-editor input).

---

## Contents

**Submission stage**
- [After Request Desk Revision, the author has a real upload button](#after-request-desk-revision-the-author-has-a-real-upload-button)
- [The "just-submitted, spotted a typo" case still needs your help](#the-just-submitted-spotted-a-typo-case-still-needs-your-help)

**Review stage**
- [Sending a revision back to the same reviewer](#sending-a-revision-back-to-the-same-reviewer)
- [Reader's reports on a decision — plain text vs formatted doc](#readers-reports-on-a-decision--plain-text-vs-formatted-doc)

**Copyediting stage**
- [The two file panels — who can see what](#the-two-file-panels--who-can-see-what)
- [Copyediting status is automatic — driven by uploads and decisions](#copyediting-status-is-automatic--driven-by-uploads-and-decisions)
- [Assign as First Edit / Second Edit (participant menu)](#assign-as-first-edit--second-edit-participant-menu)
- [A file that arrives the wrong way needs one extra step](#a-file-that-arrives-the-wrong-way-needs-one-extra-step)
- [Send Copyedits to Author — upload the file *in* the decision](#send-copyedits-to-author--upload-the-file-in-the-decision)
- [Send To Production is blocked until Copyedited Files has something](#send-to-production-is-blocked-until-copyedited-files-has-something)

**Production stage**
- [Sending proofs and marking them approved](#sending-proofs-and-marking-them-approved)

**Cross-cutting UI**
- [The "Status" box appears on every stage except the one you're on](#the-status-box-appears-on-every-stage-except-the-one-youre-on)

**Journal management**
- [Creating a new special issue](#creating-a-new-special-issue)

**[📝 To write up](#-to-write-up)** — topics we know matter, not yet written.

---

## Submission stage

### 📸 ⚠️ After Request Desk Revision, the author has a real upload button

**To verify in the browser — behaviour built but not yet walked end-to-end on prod.**

Request Desk Revision sends a Stage 1 submission back to the author before it
ever reaches reviewers. Post45 gives the author a real Upload path for this.

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

**If the author needs to send supporting material** alongside the revision,
have them attach it to the discussion thread — Submission Files only shows
the one revision-upload button. (Copyediting is different: three upload
buttons for different tagged file types.)

---

### ⚠️ The "just-submitted, spotted a typo" case still needs your help

The upload button above only appears when *you* have asked for a revision.
If an author submits, notices something wrong 20 minutes later, and wants
to send you a corrected file, they still have no Upload path — no revision
was ever requested.

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

## Review stage

### 📸 ✅ Sending a revision back to the same reviewer

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

### 📸 ⚠️ Reader's reports on a decision — plain text vs formatted doc

When you record a decision that carries reviewer feedback (**Request Revisions**,
**Accept** — anything that emails the author with reviewer input), OJS's
default composer appends each reader's report as plain text at the bottom of
the email body. What that means in practice:

- Bold, italics, bullets, section headers — all flattened
- The reader's own conventions (Reader 1: / Reader 2: headers, indented block
  quotes, paragraph breaks used for emphasis) are lost or mangled
- Long reports dominate the email; the editor's own framing gets buried

Post45's convention is to send **one nicely-formatted document** with both
reports combined, keeping the reader's own formatting intact. Instead of
letting the composer paste the reports as text, you attach that document.

**How to do it in the decision composer:**

1. Prepare the formatted doc separately (usually a Google Doc combining both
   R.R.s with headers like "Reader 1" / "Reader 2", then File → Download →
   Word/PDF).
2. In the decision composer, remove the auto-appended plain-text reader's
   report block from the email body (or shorten it to a summary line).
3. Use the composer's attachment button to attach the pre-formatted document.
4. Send.

Marking this ⚠️ — the composer buttons haven't been walked through in the
browser yet. Verify against the real UI before promoting to ✅.

---

## Copyediting stage

### 📸 ⚠️ The two file panels — who can see what

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

### ⚠️ Copyediting status is automatic — driven by uploads and decisions

**To verify in the browser — behaviour built but not yet walked end-to-end on prod.**

The Copyediting status moves on its own, driven by what people **say** about
the files they upload:

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

If you mark a file from the row menu, the status shown in the Editorial State
panel updates on the server, but the panel doesn't refresh live — reload the
page to see the new value.

**You are told when they upload.** Every author upload into Copyedited Files
sends you an email regardless of which button they used, so you know something
landed even if the status did not move.

The Editorial State panel shows the copyediting status as a short read-only
sentence — no dropdown to flip. Every value has an action that writes it
(tagged uploads, Assign First / Second Edit, Send Copyedits to Author). If
the status looks wrong, take the action that would correct it (retag a file,
run the right decision) rather than trying to edit the status directly.

Two things the status system will not do: it never moves *backwards* (if
you've already pushed a submission forward, a late file or late tag won't
rewind it), and a third or fourth edit stays at "Second edit in progress".

**What OJS doesn't automate (yet) — the copyeditor's upload.** When the
person doing the edit uploads their draft to Draft Files, OJS won't notify
you and the Copyediting status stays at *First / Second edit in progress*.
You'll need to check Draft Files periodically, or the copyeditor will need
to ping you (Slack, email). This is fine if the team is already coordinating
out-of-band, and Notion mirrors the same truth (status doesn't move until
you take the next editorial action). If you'd rather have OJS notify you +
move the status when a draft lands, that's a clean addition — flag it and
we'll wire a hook that watches Draft Files uploads by the current owner.

---

### 📸 ⚠️ Assign as First Edit / Second Edit (participant menu)

**To verify in the browser — behaviour built but not yet walked end-to-end on prod.**

First edit and Second edit are driven by assignment, not by a dropdown. Two
per-participant actions handle them, on the **"…" menu** of each row in the
**Participants** panel on the Copyediting stage:

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

Uploading a draft to Draft Files does **not** move the status to "in
progress" — uploading means the edit is finished, so treating it as
"in-progress" would say the opposite of what happened. Use the Assign
menu item to signal "starting the edit"; use the upload to save the
work you did.

---

### 📸 ⚠️ A file that arrives the wrong way needs one extra step

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

### 📸 ⚠️ Send Copyedits to Author — upload the file *in* the decision

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

### ⚠️ Send To Production is blocked until Copyedited Files has something

**Check this in the browser before relying on it.**

If **Copyedited Files** is empty when you click **Send To Production**, the
decision won't record — you'll get a message saying so.

An empty Copyedited Files panel means the round-trip never happened — the
author was never sent edits and never returned anything, because both of those
put a file there. If you're sure copyediting is done and the file is elsewhere
(a discussion thread, Draft Files, your inbox), use the step above to move it
across, then run the decision.

---

## Production stage

### 📸 ⚠️ Sending proofs and marking them approved

**To verify in the browser — new behaviour, not yet walked end-to-end on prod.**

Proofs live on **WordPress**, not in OJS. The author previews the piece on the
WordPress site and replies to the discussion thread with their approval. OJS
carries the discussion + records the transitions; it does not carry the proofs.

The three Production statuses each have a real driver now:

| What happens | Status becomes |
|---|---|
| You run **Send To Production** (the last thing you do in Copyediting) | Preparing proofs |
| You run **Send Proofs to Author** at Stage 5 | Proofs with author |
| You run **Mark Proofs Approved** at Stage 5 | Proofs approved by author |

**Mark Proofs Approved is a one-click decision.** No email step, no composer —
click it, confirm, done. The point is to record what already happened outside
OJS (the author replied to the discussion saying the proofs look good), so
adding a second email would just repeat the acknowledgement. On the Notion
board this is the **Ready for Publication** state — the "everything is ready
to go live" signal you can look at without opening the submission.

If you skip **Send Proofs to Author** and record the approval directly
(you ran the proof round out-of-band and just want to note the outcome),
Mark Proofs Approved still works — the status jumps straight from
*Preparing proofs* to *Proofs approved by author*. It won't rewind, and
you can't run it after **Mark Published on WordPress**.

**`ready_for_publication` is not a separate state any more.** Proofs
approved by the author IS the "ready to publish" moment; the previous
distinction was extra ceremony without extra information. The Notion
mapping collapses both onto the same **Ready for Publication** option, so
the board reads the same either way.

---

## Cross-cutting UI

### ✅ The "Status" box appears on every stage except the one you're on

Not a bug. That grey **Status** box at the top of a stage — "The submission
advanced to the next review round, was accepted, and is currently in the
Copyediting stage" — exists only to tell you **you are not looking at the
stage the submission is actually in**.

So it shows on every stage *except* the live one, which makes the live stage look
like it is missing something. It isn't: there is nothing to orient you away from
when you are already in the right place.

It also appears when you open a stage the submission has not reached yet, and
when you look back at an earlier review round.

If you want to confirm it, move a submission on a stage — the box disappears from
the new stage and appears on the old one.

---

## Journal management

### ⚠️ Creating a new special issue

**Journal manager task, not an editor task.** Special issues live in three
places at once — OJS, Notion, and the plugin settings that link them — and
Post45 keeps that link by hand rather than automating it. There are only a
handful of live SIs at any time and they're rare to create, so hand-curation
is the design (see the `specialIssuePairings` plugin setting docblock for the
reasoning).

**Do all four steps, or the SI won't route submissions correctly:**

1. **Create the OJS section.** Journal Settings → Sections → *Create Section*.
   Give it the SI's title. That section is where every submission belonging to
   this SI will land.

2. **Assign the guest editor(s) to the section.** Two-step in OJS:
   - Users & Roles → *Users* → find (or create) the guest editor → give them
     the **Guest Editor** role.
   - Journal Settings → *Sections* → *Edit* on the new section → under
     **Editorial Assignments**, check *Assign X as Guest editor* for each
     one. That's what triggers auto-assignment on new submissions to this
     section and shows them on the CFP page as the SI's editors.

3. **Create (or already have) the matching Notion Special Issues page.** On
   the Notion Special Issues database, add the page for this SI. Copy its page
   ID from the URL (the 32-character hex chunk).

4. **Add the pairing.** Website Settings → Plugins → post45NotionSync →
   Settings → **Notion Special Issue → OJS section pairings**. Add one JSON
   entry mapping the Notion page ID to the OJS section ID:
   ```json
   {
     "existing-notion-page-id": 7,
     "new-notion-page-id": 12
   }
   ```
   Save. If the JSON is malformed or the entry is nonsensical (empty key, or
   the value isn't a positive integer section ID), the form refuses the save
   and tells you why — nothing is stored silently.

5. **If this SI uses single-reviewer rounds**, set **Reviews per
   submission** to `1` on the section edit form (Journal Settings →
   Sections → *Edit*). Leave the field **blank** on anything that follows
   the standard two-reviewer flow — a blank field means "inherit the
   journal-wide value" (Website Settings → Workflow → Review → *Reviews
   per Submission*). This threshold governs several editor-facing
   surfaces at once:
    - The dashboard alert *"Minimum required number of reviews have been
      confirmed. A decision is needed."*
    - The workflow-page status callout.
    - The *"Proceed without minimum reviews?"* warning modal shown before
      a decision is recorded.
    - The Notion `Review Status` column's transition to *Out for Review*
      / *Reports Received*.

   A single-reviewer section left at the default (inheriting `2`) stalls
   on `Finding Reviewers` and never fires the dashboard alert even after
   the reviewer has accepted and submitted.

   ⚠️ **One caveat**: recording a decision on an SI section with fewer
   reviews than the *journal-wide* setting requires clicking through a
   "Proceed without minimum reviews?" modal. That's an OJS-native
   warning, and OJS's plugin API doesn't currently let us swap the
   threshold it reads — so the modal still reads "1 of 2" even when
   the section only requires 1. Click **Yes, continue** to proceed.
   The decision records normally.

**Once all five are in place**, sync writes the Notion `Special Issue`
relation onto every submission that lands in the paired section.

**If the pairing is missing** when an editor moves an article into the new
section, sync CLEARS the Notion `Special Issue` cell (so it can't lie about
which SI the article belongs to) AND posts a one-shot message to the sync
Slack channel: "Submission #NNN moved to *[section]* (id N) — no Notion
Special Issue pairing." That message includes the exact JSON snippet to
add to the pairings setting, so the fix is one paste-and-save away. It
fires once per section move; a routine re-sync of an article that has
stayed put is silent. The same message is used for legitimate moves to
*Articles* (or any non-SI section) — the wording spells both branches out
so a JM can tell at a glance which case they're looking at.

**Optional check before saving:** `php tools/dev/listNotionSpecialIssues.php`
prints every Notion SI referenced by non-archived articles alongside its
paired OJS section (or the note that a pairing is missing). Useful sanity
check when adding a new SI or when the board looks off.

**If a CFP is involved**, set it up via the post45Cfp plugin (Journal Manager
→ *Calls for Papers*) and pick this section from the *Associated section*
dropdown. That is what makes the CFP page's *Submit to this call* button
route submissions into the SI section automatically.

---

## 📝 To write up

Topics we know are worth an entry. Add detail when someone next walks the path.
🚨 marks entries editors need at launch; the rest can wait.

- 🚨 **Publication agreement.** Nothing happens automatically on Accept —
  requesting the agreement is always an explicit action. The "N days ago"
  note on the Editorial State panel is the *only* reminder in the system;
  there is no email or digest. Also covers backdating a request or signature
  you didn't record on the day.
- 🚨 **Editorial State panel.** Owner (editable), stage status (read-only
  sentence on both Copyediting and Production), publication agreement
  (editable on Copyediting). Which sections appear depends on the stage
  you're on.
- 🚨 **Reassign `Assigned to` when you take a decision.** When you record a
  decision that puts the ball in someone else's court (Request Revisions →
  the author, Send Copyedits to Author → the author, Send to Production →
  a copyeditor, and so on), also reassign the submission's owner in OJS to
  whoever needs to act next. That's the value the Notion board's `Assigned to`
  column reflects. Write up which decisions imply which handoffs.
- 🚨 **What reviewers actually see** at each step, for answering their
  questions without having to log in as one.
- 🚨 **Silent accounts for completed pre-cutover reviewers — what an
  editor sees.** Walk the UI so the entry says exactly which panel the
  completed review appears in, what the "Date Completed" column reads,
  and where the report file lives (should be Review Attachments on the
  assignment). Cross-reference the launch-section note above.
- **Returning reviewer flow.** A pre-cutover reviewer whose silent
  account exists — what happens if they password-reset, get invited for
  a new review, or submit an article? Walk each path in the browser
  once we have a live case, and document that no admin intervention is
  needed (the silent account is a normal account, just never touched).
- **The Notion board is written by OJS now** (once sync is switched on).
  Some columns are OJS's to fill and get rewritten on the next sync, so
  editing them on the board doesn't stick — status, decision, dates, the
  agreement fields, the links. Others stay yours and OJS never touches them:
  `Assigned to`, `Keywords`, the "needs fixing?" flags, `Archived`,
  attachments, `Current Task Due`. Write up which is which, and where to
  make a change so it survives (in OJS, for the first group). Not live yet —
  nothing on the board changes until sync is enabled.
- **When an author emails you a revision instead of using OJS.** Some will,
  and you can put it into OJS on their behalf: on the review round, the
  **Upload** button beside *Revisions Uploaded* is available to editors, not
  just authors. Worth doing rather than leaving it in your inbox — a file
  uploaded there is what the rest of the workflow (and the Notion board,
  once sync is on) can actually see.
- **The two legitimate ways an author sends a revision.** They can use the
  **Upload** button beside *Revisions Uploaded*, or attach the file to a
  **Review Discussion** message — the latter is normal when they want to
  explain what they changed, not a mistake to correct. Both are fine; note
  what each looks like from the editor's side.
- **Production statuses in depth.** What each value means for downstream
  tasks (typesetting, scheduling, announcement), when a status "sticks" vs
  when it moves forward, and how to read the panel when a submission has
  been in Production for a while.
- **Mark Published on WordPress.** Terminal action — what it does, and why
  the WordPress URL has to be saved before you run it.
- **Double-anonymous downloads.** Reviewers see a renamed file rather than
  the author's original filename. Worth knowing so it isn't reported as a
  bug.

---

## Adding to this file

When you hit something in the workflow that took more figuring out than it
should have, add a note — even a rough one.
