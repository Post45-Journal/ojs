# Admin Setup Guide for Pragma Submissions Theme

This guide documents all the admin configuration steps needed to fully set up the submissions-focused OJS site for DOAJ/OASPA compliance.

## Theme Configuration

### 1. Activate Theme
**Path**: Settings → Website → Appearance → Theme

1. Select "Pragma Submissions" theme
2. Configure theme options:
   - **Primary Section ID**: Set this to the ID of your "Articles" section (the general submission section you want to hide from special issue lists)
   - **Color Options**: Configure 6 semantic color variables for your brand

### 2. Enable Submissions Only Plugin
**Path**: Settings → Website → Plugins → Generic Plugins

1. Find "Submissions Only" plugin
2. Check the box to enable it
3. This hides publishing workflow elements from the admin interface

## Content Configuration for DOAJ/OASPA Compliance

### 3. Author Guidelines
**Path**: Settings → Workflow → Submission → Author Guidelines

Add structured content with headings (h3/h4) for auto-generated anchor links:

```html
<h3>Peer Review Process</h3>
<p>All submissions undergo double-blind peer review by at least two independent experts...</p>
<ul>
  <li>Timeline: 4-8 weeks for initial review</li>
  <li>Authors can respond to reviewer comments</li>
  <li>Final decision made by Editor-in-Chief</li>
</ul>

<h3>Publication Timeline</h3>
<p>Expected timeline from submission to publication decision...</p>

<h3>Submission Requirements</h3>
<p>Before submitting, ensure your manuscript meets these requirements...</p>
```

**Required DOAJ/OASPA elements to include:**
- Peer review process details (type: double-blind, number of reviewers: 2+)
- Timeline information
- Editorial decision-making process
- How to respond to reviewer feedback

### 4. About the Journal
**Path**: Settings → Journal → Masthead

Add structured content with headings:

```html
<h3>Publisher Information</h3>
<p><strong>Publisher:</strong> Post45<br>
<strong>Type:</strong> 501(c)3 nonprofit organization<br>
<strong>EIN:</strong> 93-2364881<br>
<strong>Contact Person:</strong> [Name]<br>
<strong>Email:</strong> [journal email]<br>
<strong>Address:</strong> [Business address]</p>

<h3>Open Access Policy</h3>
<p>Post45 Journal is diamond open access, meaning all content is freely available to readers without subscription fees or author processing charges (APCs). We are committed to the principles of open access as defined by DOAJ.</p>

<h3>Archiving & Preservation</h3>
<p>All published content is automatically archived via the Wayback Machine (Internet Archive). We are planning to join Crossref and begin minting DOIs for long-term persistence.</p>

<h3>Editorial Ethics</h3>
<p>Post45 Journal follows the Committee on Publication Ethics (COPE) guidelines for handling research misconduct allegations, conflicts of interest, and ethical publishing practices.</p>

<h4>Conflict of Interest Policy</h4>
<p>Authors, editors, and reviewers must declare any conflicts of interest...</p>

<h4>Research Misconduct</h4>
<p>We follow COPE guidelines for investigating allegations of plagiarism, data fabrication, or other forms of misconduct...</p>

<h3>No Publication Fees</h3>
<p>Post45 Journal is diamond open access. There are NO author fees (APCs), submission fees, or page charges. Publication is entirely free for authors.</p>
```

**IMPORTANT:** Copyright and licensing information should be added as sections within "About the Journal" above using h3/h4 headings. Include:
- Open Access Statement with CC BY-NC 4.0 license link
- License terms (what users can/cannot do)
- Copyright policy (authors retain rights)
- Self-archiving/repository policy

### 5. Submission Checklist
**Path**: Settings → Workflow → Submission → Submission Preparation Checklist

Ensure the checklist includes items that verify compliance with submission requirements.

### 6. Special Issue CFPs
**Path**: Settings → Journal → Sections

For each special issue section:
1. Add a descriptive policy that explains the CFP
2. Include deadline, scope, and guest editor information
3. This content will appear on both the homepage and in the submission guidelines

## Navigation & Footer

### 8. Add Privacy Statement Link to Footer
**Path**: Settings → Website → Appearance → Setup → Footer

Add to the footer content:

```html
<p><a href="[URL to privacy page]">Privacy Statement</a></p>
```

Or add via navigation menu:
**Path**: Settings → Website → Navigation Menus → Primary Navigation

Create a "Privacy" menu item linking to your privacy statement page.

### 9. Rename "About" Navigation Link
**Path**: Settings → Website → Navigation Menus

Edit the "About" link to display as "Submission Guidelines" or similar text that better describes the page content.

## Optional Enhancements

### 10. Enable Color Palettes Plugin (Optional)
If you want enhanced color selection with curated palettes:

```bash
cd /path/to/ojs/plugins/generic/
git clone https://github.com/Post45-Journal/ojs-color-palettes.git colorPalettes
```

Then enable via Settings → Website → Plugins → Generic Plugins

## DOAJ/OASPA Compliance Checklist

After completing the above steps, verify you have:

- [ ] Open access statement clearly displayed
- [ ] CC BY-NC license information with links
- [ ] Copyright policy stating authors retain rights
- [ ] Detailed peer review process description
- [ ] Publisher information with contact details
- [ ] Statement that no fees are charged (diamond OA)
- [ ] Editorial ethics and COPE compliance statement
- [ ] Conflict of interest policy
- [ ] Self-archiving/repository policy
- [ ] Archiving preservation information
- [ ] Editorial board with affiliations (in Editorial Team settings)
- [ ] ISSN displayed on site
- [ ] Submission/acceptance/publication dates on articles (configure in article metadata)

## Notes

- All content fields support HTML with headings (h3, h4, h5)
- Headings automatically generate anchor links for direct linking (e.g., `submissions.post45.org/about#peer-review-process`)
- The theme automatically filters out the primary "Articles" section from special issue lists on both homepage and submission guidelines
- Privacy statement is NOT required for DOAJ/OASPA but recommended for GDPR compliance
