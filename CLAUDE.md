# OJS Submissions-Focused Theme Development

## Project Goal
Transform OJS 3.5 into a submissions-only management system by creating a child theme of Pragma that hides publishing functionality and provides a submissions-focused homepage.

## Deployment & Upgrade Workflow (post-June 2026 restructuring)

**Repo architecture:**
- Source-of-truth: `Post45-Journal/ojs` on GitHub (fork of `pkp/ojs`). Default branch `main` = stable-3_5_0 + Post45 customizations.
- Local: `~/dev/submissions-ojs` tracks `origin/main` (origin = fork). `upstream` remote points at `pkp/ojs` for pulling future stable-branch updates.
- Prod (`submissions.post45.org`, Ubuntu 24.04, 1GB DO droplet): `/var/www/html` is a real git checkout tracking `origin/main`. Files dir is `/var/www/ojs-files` (outside web root). Plugin monorepo cloned at `/var/www/ojs-plugins-monorepo`.

**Day-to-day edits (local → prod):**
```bash
# Local
cd ~/dev/submissions-ojs
# ...make changes...
git push origin main

# Prod
ssh submissions.post45.org
cd /var/www/html
git pull origin main
# If front-end assets changed (rare for theme/doc-only edits):
NODE_OPTIONS=--max-old-space-size=1536 npm run build
```

**Major OJS upgrade (merge upstream stable-3_5_0 → main):**
```bash
# Local
cd ~/dev/submissions-ojs
git fetch upstream
git merge upstream/stable-3_5_0
# Resolve any conflicts in Post45 customizations
git push origin main

# Prod
cd /var/www/html
git pull origin main
git submodule update --init --recursive
cd lib/pkp && composer install --no-dev --optimize-autoloader && cd ../..
cd plugins/paymethod/paypal && composer install --no-dev --optimize-autoloader && cd ../../..
npm install
NODE_OPTIONS=--max-old-space-size=1536 npm run build
./scripts/post-update-assets.sh   # restores TinyMCE asset symlinks
sudo systemctl restart apache2
```

**Prod-specific quirks:**
- The 1GB droplet OOMs during Vite builds without help. Mitigations: 1GB swap at `/swapfile` (persistent via `/etc/fstab`) + `NODE_OPTIONS=--max-old-space-size=1536` on every build invocation. Consider upgrading to 2GB droplet long-term.
- `config.inc.php` must be `chown ojsadmin:www-data` + `chmod 640` so apache (www-data) can read it but it's not world-readable.
- Plugin symlinks (colorPalettes, submissionsOnly, mailgun, pragmaSubmissions) and TinyMCE asset symlinks (js/plugins/, js/skins/) are gitignored — recreated per environment.
- `cache/`, `public/`, and `/var/www/ojs-files` must be `chown www-data:www-data`.

**Migration record (one-time, June 2026):** `temp/prod-upgrade-checklist.md` documents the move from tarball install → proper git checkout. Keep for reference but don't re-execute.

## Current Status: ✅ THREE PLUGIN APPROACH + DATABASE CLEANUP

Successfully implemented a three-plugin solution plus database-level role cleanup that transforms OJS into a submissions-only system with enhanced color customization:

### 1. ✅ Frontend Theme: Pragma Submissions Child Theme  
**Location**: `/plugins/themes/pragmaSubmissions/`
- **Base Theme**: Inherits from Pragma (gets all styling/functionality automatically)
- **Method**: Clean template override without conditionals - dedicated submissions-only experience
- **Features**: 
  - Submissions-focused homepage with integrated author guidelines, checklist, and section policies
  - Replaces current issue display with submission opportunities
  - Author dashboard integration for logged-in users
  - Mobile responsive design matching Pragma aesthetic

### 2. ✅ Database-Level Role Cleanup
**Method**: Direct database deletion of unwanted default user groups
- **Approach**: Removed publishing-related roles directly from MySQL database
- **Deleted Roles** (originally 12; Copyeditor re-added June 2026):
  - Production Editor, Designer
  - Funding Coordinator, Indexer, Layout Editor
  - Marketing and Sales Coordinator, Proofreader, Translator
  - Reader, Subscription Manager, Editorial Board Member
- **Scope expansion (June 2026):** Copy editing is now in scope (handled in OJS). The Copyeditor role must be re-added via Users & Roles → Roles → "Add Role" in the admin UI. Do NOT re-run the install migration — it would re-add all 12 deleted roles. This is a per-environment manual step (apply on local and prod separately).
- **Remaining Roles** (submissions + copyediting, 7 total):
  - Journal Manager, Journal Editor
  - Section Editor, Guest Editor
  - Author, Reviewer
  - Copyeditor (re-added June 2026)
- **Benefits**:
  - Clean, permanent solution with no JavaScript/CSS hiding needed
  - Unwanted roles completely removed from system
  - No maintenance overhead or compatibility issues
  - Simplifies user management interface

**SQL Commands Used**:
```sql
-- Delete user assignments, stage assignments, settings, then user groups
DELETE FROM user_user_groups WHERE user_group_id IN (4, 7, 8, 9, 10, 11, 12, 13, 15, 17, 18, 19);
DELETE FROM user_group_stage WHERE user_group_id IN (4, 7, 8, 9, 10, 11, 12, 13, 15, 17, 18, 19);
DELETE FROM user_group_settings WHERE user_group_id IN (4, 7, 8, 9, 10, 11, 12, 13, 15, 17, 18, 19);
DELETE FROM user_groups WHERE user_group_id IN (4, 7, 8, 9, 10, 11, 12, 13, 15, 17, 18, 19);
```

### 3. ✅ Enhanced Color Selection: Color Palettes Plugin
**Location**: `https://github.com/Post45-Journal/ojs-color-palettes` (standalone repository)
- **Purpose**: Provide curated color palette selection for any OJS theme
- **Method**: Vue.js modal interface with organized color swatches + CSS custom properties
- **Status**: **COMPLETED & PUBLISHED** - Ready for deployment
- **Features**:
  - Flexoki design system colors (8 families with lightness-sorted 50-950 scale)
  - Albers color study palettes (15 families with artistic variations, lightness-sorted)
  - Vue.js modal with proper backdrop, ROYGBIV organization, and intuitive UX
  - Auto-detects Vue Chrome color picker components and adds palette selection buttons
  - Works with any OJS theme that uses color customization
  - 300+ carefully curated colors with smooth light-to-dark progressions

### Current Theme Structure
```
/plugins/themes/pragmaSubmissions/
├── PragmaSubmissionsThemePlugin.inc.php    # Main theme class (extends Pragma)
├── version.xml                              # Plugin metadata
├── index.php                               # Plugin entry point
├── settings.xml                            # Default settings
├── templates/frontend/pages/
│   ├── indexJournal.tpl                    # Streamlined submissions homepage
│   └── submissionGuidelines.tpl            # Comprehensive submission guidelines page
├── styles/
│   └── custom.less                         # Frontend styling with LESS variables
└── locale/en/
    └── locale.po                           # Theme translations
```

### 3. ✅ Enhanced Color Selection: Color Palettes Plugin
**Repository**: `https://github.com/Post45-Journal/ojs-color-palettes`
**Status**: Fully functional standalone plugin, ready for deployment to any OJS site

**Final Structure:**
```
ColorPalettesPlugin.php                     # Main plugin class
version.xml                                 # Plugin metadata
settings.xml                               # Plugin settings
styles/
├── flexoki-colors.css                     # Flexoki colors (8 families, 50-950 scale)
├── albers-colors.css                      # Albers colors (15 families, artistic variations)
├── palette-modal.css                      # Modal interface styles
├── vue-color-palette-modal.js             # Vue 3 modal (fully functional)
└── admin-palette-buttons.js               # Admin interface integration
locale/en/locale.po                        # Translations
```

**Completed Features:**
- ✅ **Perfect Vue.js modal** with backdrop, proper sizing, border, and responsive design
- ✅ **Lightness-sorted color progressions** in all color families (browser-calculated)
- ✅ **ROYGBIV organization** with improved naming (Lavender, Violet instead of Violet, Magenta)
- ✅ **Auto-detection** of Vue Chrome color picker components
- ✅ **Seamless color selection** updates hex input fields correctly
- ✅ **Reduced vertical spacing** for minimal scrolling
- ✅ **300+ curated colors** from Flexoki and Albers design systems

**Deployment:**
```bash
cd /path/to/live/ojs/plugins/generic/
git clone https://github.com/Post45-Journal/ojs-color-palettes.git colorPalettes
```

### ~~4. Section Metadata Plugin~~ (❌ REMOVED - Oct 5, 2025)
**Previous Location**: `/plugins/generic/sectionMetadata/` (deleted)
**Status**: Removed due to technical issues

**Reason for Removal:**
- JavaScript field injection wasn't working reliably
- Plugin was causing 500 errors
- Functionality was not essential for core workflow

**What Was Lost:**
- Custom CFP deadline field in section form
- Custom CFP editor field in section form

**Alternatives:**
- ✅ Section editors are still displayed on CFP cards (fetched from native OJS SubEditorsDAO)
- ✅ Deadline info can be included in section policy text if needed
- ✅ No core functionality impacted - CFP system still works perfectly

## OJS 3.5 Architecture Insights

### Frontend vs Admin Interface
- **Frontend**: Controlled by themes (Smarty templates), easily customizable
- **Admin Interface**: Built with Vue.js components, more complex to modify
- **Separation**: CSS hiding works well for admin; template overrides better for frontend

### OJS 3.5 UI Guidelines & Best Practices
**Source**: `/lib/ui-library/src/docs/guide/` - Official OJS 3.5 UI development guidelines

#### Design System & Styling (`/lib/ui-library/src/docs/guide/DesignSystem/`)
- **TailwindCSS Adoption**: OJS 3.5 uses TailwindCSS for admin interface styling
- **Plugin Styling Recommendation**: Use **scoped CSS with CSS variables** (not TailwindCSS classes) to avoid conflicts
- **CSS Variables Available**: Access OJS color palette, typography, spacing via CSS variables
  - Colors: `var(--color-stage-in-review)`, `var(--text-color-heading)`
  - Typography: `var(--font-3xl-bold)`
  - Spacing: `var(--spacing-8)`, `var(--spacing-12)`

#### Plugin Development (`/lib/ui-library/src/docs/guide/Plugins/`)
**Modern Vue.js Plugin Architecture (3.5+)**:
- **Build Step**: Recommended to use Vite for JS/Vue components
- **Component Style**: Single File Components (SFC) with Composition API
- **Component Prefix**: Use prefixes to avoid naming conflicts
- **Global Components**: ui-library components available globally with `pkp` prefix (`<PkpButton>`)
- **Composables**: Access via `pkp.modules.useLocalize`, `pkp.modules.useFetch`, etc.

**Component Registration**:
```javascript
// Register via JS
pkp.registry.registerComponent('MyComponent', MyComponent);

// Extend existing stores/pages via JS hooks
pkp.registry.storeExtend('fileManager_SUBMISSION_FILES', (piniaContext) => {
  // Custom logic here
});
```

**Translation Integration**: Automatic locale string detection in JS/Vue files (requires Vite plugin)

#### Extensibility Patterns
- **PHP Hooks**: For Smarty template pages (legacy approach)
- **JS Hooks**: For Vue.js pages/managers (modern approach via Pinia stores)
- **Store Extension**: Extend existing functionality via `store.extender`
- **Available Hooks**: Dashboard, Workflow, FileManager, ReviewerManager, etc.

#### Reference Implementation
- **Example Plugin**: [backend-ui-example-plugin](https://github.com/jardakotesovec/backend-ui-example-plugin)
- **Best Practices**: Follow Vue 3 + Composition API patterns from official examples

### Plugin Architecture Patterns
**Themes** (for frontend modifications):
- Extend `ThemePlugin` class
- Use `setParent('parenttheme')` for inheritance  
- Template overrides in `templates/` directory
- CSS/JS loaded via `addStyle()` and `addScript()`

**Generic Plugins** (for admin modifications):
- Extend `GenericPlugin` class
- Hook into `TemplateManager::display` for CSS/JS injection
- Page detection via `$request->getRequestedPage()`
- CSS targeting for hiding elements

**⚠️ CSS Selector Best Practice**: 
- **NEVER guess at CSS selectors** - always ask for HTML examples
- **Request exact markup** before writing targeting CSS
- **Use specific selectors** based on actual `name`, `id`, or structure provided

**⚠️ Documentation and External Resources**:
- **NEVER claim to have read or reviewed documentation without actually using WebFetch or Read tools**
- **If user asks "did you look at [resource]?", answer honestly** - use "No, let me check that now" if you haven't
- **Always use tools to fetch external documentation** before making claims about their contents
- **Be transparent about what information you have vs. what you're inferring**

### Workflow UI Structure (Vue.js Based)
**Key Components**: `/lib/ui-library/src/pages/workflow/`
- `WorkflowPage.vue` - Main workflow interface
- `useWorkflowNavigationConfigOJS.js` - Menu structure and labels
- `workflowConfigAuthorOJS.js` / `workflowConfigEditorialOJS.js` - Stage configurations

**Translation System**:
- Locale keys in `/locale/en/submission.po`
- Key terms: `submission.publication`, `publication.status.unscheduled`
- Function: `getPublicationTitle()` generates "Publication: Title & Abstract" headers

### Advanced Modifications (Future Possibilities)

**For comprehensive workflow renaming** (Publication → Article Metadata, etc.):
1. **Override Vue Components**: Replace workflow navigation config functions
2. **Custom Locale Files**: Override translation keys in plugin
3. **Template Modifications**: Override form templates for header text
4. **Status Logic**: Modify workflow status terminology

**Complexity**: Moderate to High - requires Vue.js component knowledge and OJS internal APIs

## Current Working State

✅ **Functional submissions-only system** with clean separation of concerns
✅ **Frontend theme** provides submissions-focused user experience (6 semantic color variables)
✅ **Admin plugin** hides publishing workflow clutter
✅ **Enhanced color system** with curated palettes - **COMPLETED & PUBLISHED**
✅ **Reusable palette plugin** works with any OJS theme - **STANDALONE REPOSITORY**
✅ **2-page structure implemented** - Homepage + Submission Guidelines
✅ **External navigation styling** with right-alignment and special visual treatment
✅ **Easy to maintain** using standard OJS plugin patterns
✅ **Future extensible** for additional workflow modifications

## 🎯 NEXT PHASE: Complete Submissions-Only System

### Immediate Priorities

#### 1. 🔄 Enhance Submissions Only Plugin
**Goal**: Hide ALL non-submission elements throughout OJS workflow
**Current Status**: Basic hiding implemented, needs comprehensive expansion

**Active scope (revised June 2026):** Submit → Peer Review → Accept → Copy Edit → Proof Coordination → Mark Published on WordPress. All four OJS workflow stages (1, 3, 4, 5) are in scope. Stage 5 (Production) is repurposed as proof coordination — actual typesetting and publication happen on WordPress, but OJS provides the discussion thread + decision tracking surface for proof review. The terminal action is "Mark Published on WordPress" (custom decision added by post45Editorial), which sets STATUS_PUBLISHED, stores the WordPress URL on the publication, sends the author notification email, and blocks the public OJS article view.

**Plugin restructure (June 2026):** `submissionsOnly` plugin was forked into `post45Editorial`. The original `submissionsOnly` (kept on disk as disabled backup) assumed OJS stopped at acceptance. The new `post45Editorial` plugin assumes OJS runs the full pipeline through "marked published on WordPress." Don't enable both plugins simultaneously.

**Required Enhancements (still in progress)**:
- Hide email templates related to OJS-native publication mechanics — versioning, issue publish notify, OA flip, broadcast announcements — but keep production/copyediting templates visible (they're in scope as proof coordination + copy editing)
- Build the "Mark Published on WordPress" decision + custom mailable + public route blocker (post45Editorial Stage 5 action)
- Remove DOI and citation management features (already hidden via existing plugin disables)
- Hide issue management UI (already done via admin.css)

#### 2. 🔄 Streamline Pragma Submissions Theme
**Goal**: Simplified, enhanced user-facing submission experience
**Current Status**: Basic homepage override, needs UX improvements

**Required Enhancements**:
- Simplify navigation and remove publishing-related menu items
- Enhance submission guidelines presentation and discoverability
- Improve author onboarding and submission process flow
- Add better visual hierarchy and information architecture
- Consider progressive disclosure for complex submission requirements
- Optimize mobile experience for author workflows
- Integrate submission status tracking for authors

### Success Criteria
- **Zero publishing workflow visibility** for authors and editors
- **Streamlined submission process** with clear, intuitive steps
- **Clean admin interface** focused solely on submission management
- **Enhanced author experience** with better guidance and feedback

## Color System Architecture

### Theme-Level Color Variables (6 semantic variables):
- `@theme-primary`: Links, button backgrounds, key interactive elements (#006798)
- `@theme-secondary`: Header, footers, important sections, structural elements (#01354F)
- `@theme-text`: Main body text (#222222)
- `@theme-text-accent`: Headings, secondary text for visual hierarchy (#01354F)
- `@theme-background`: Main page and content section backgrounds (#FFFFFF)
- `@theme-background-accent`: Content cards, contrast sections (#EAEDEE)

### Palette System (300+ curated colors) - ✅ COMPLETED:
- **Flexoki**: 8 color families following 50-950 scale (Base, Red, Orange, Yellow, Green, Cyan, Blue, Purple)
- **Albers**: 15 artistic color studies with harmonious variations (Gray through Violet, ROYGBIV organized)
- **All colors lightness-sorted** for intuitive light-to-dark progressions within each family

### Integration:
1. **Any theme** can define color customization options
2. **Color Palettes plugin** auto-detects color fields and adds palette selection buttons  
3. **Users click palette buttons** → modal shows organized color swatches
4. **Selecting a swatch** populates the color field with chosen value
5. **Theme applies the color** via CSS custom properties

## OJS 3.5 Compatibility & Breaking Changes

### Critical PHP/Hook Syntax Updates
**⚠️ BREAKING CHANGE**: OJS 3.5 requires modern PHP hook registration syntax:

**❌ Old Syntax** (causes plugin registration failures):
```php
Hook::add('TemplateManager::display', [$this, 'methodName']);
```

**✅ New Syntax** (required for OJS 3.5):
```php
Hook::add('TemplateManager::display', $this->methodName(...));
```

### Plugin Registration Requirements for OJS 3.5
**Essential files for plugin discovery:**
1. ✅ `PluginNamePlugin.php` - Main class (exact case-sensitive naming)
2. ✅ `version.xml` - Must include `<class>PluginNamePlugin</class>`
3. ✅ `settings.xml` - Required for admin interface visibility
4. ✅ `locale/en/locale.po` - Translation strings
5. ❌ `index.php` - **NOT REQUIRED** (OJS 3.5 uses autoloading)

### Naming Conventions (Strict)
- **Directory**: `camelCase` (e.g., `colorPalettes`)
- **Class Name**: `PascalCase` + `Plugin` (e.g., `ColorpalettesPlugin`)
- **File Name**: Must match class name exactly (e.g., `ColorpalettesPlugin.php`)
- **Namespace**: `\APP\plugins\{category}\{directoryName}\`

### Version Format
- Use 4-part semantic versioning: `1.0.0.0` (not `1.0.0`)
- Include `<lazy-load>1</lazy-load>` in version.xml

### PHP Version Compatibility
OJS 3.5 likely requires **PHP 8.1+** for:
- First-class callable syntax (`$this->method(...)`)
- Modern autoloading mechanisms
- Enhanced type checking

### Debugging Plugin Issues
1. **Check PHP syntax**: `php -l PluginFile.php`
2. **Verify file permissions**: All plugin files should be readable
3. **Clear OJS cache**: Plugin changes may require cache clearing
4. **Check error logs**: Look in `/files/scheduledTaskLogs/` for PHP errors
5. **Test minimal plugin**: Create bare-bones plugin to verify registration works

### ⚠️ Critical: Never Assume HTML Structure or CSS Selectors

**ALWAYS verify actual DOM structure before writing selectors.**

#### Common Selector Assumption Failures:
1. **❌ Assumed**: `input[type="color"]` for color inputs
   **✅ Reality**: OJS uses Vue Chrome color picker (`.vc-chrome`)

2. **❌ Assumed**: Standard form inputs
   **✅ Reality**: Vue.js components with complex nested structure

3. **❌ Assumed**: Simple CSS class names
   **✅ Reality**: Framework-generated classes with dynamic attributes

#### Required Verification Process:
1. **Inspect Element**: Always check actual HTML structure in browser dev tools
2. **Test Selectors**: Use browser console to test `document.querySelectorAll()` first
3. **Ask for HTML**: Request user to provide actual element markup when debugging
4. **Console Debug**: Add `console.log()` statements to verify element detection

#### Example Debug Commands:
```javascript
// Test if elements exist
console.log('Target elements:', document.querySelectorAll('.expected-selector'));

// Check element properties
console.log('Element details:', element.classList, element.attributes);

// Verify function availability
console.log('Function exists:', typeof expectedFunction);
```

**Never guess at selectors - always verify with the actual DOM structure first!**

## Development Workflow & Notes

### Recently Completed (Latest Session - Oct 3, 2025)
1. ✅ **Header Navigation Fixes**:
   - **External Link Right-Alignment**: Successfully positioned "Return to Journal" link on right side using proper CSS selectors
   - **Header Vertical Alignment**: Improved home link alignment with admin navigation
   - **Home Link Styling**: Increased font size to 1.5rem and removed awkward top padding
   - **Admin Dropdown Fixes**: Resolved white rectangle/invisible links issues with proper dropdown styling

2. ✅ **CFP (Call for Papers) System Implementation**:
   - **Individual CFP Pages**: Created dedicated section-specific CFP pages at `/about?cfp=1&sectionId=X`
   - **Template Override Approach**: Used OJS 3.5-compatible template display hooks instead of deprecated HANDLER_CLASS
   - **Infinite Loop Prevention**: Added flag system to prevent recursive template display calls
   - **Simplified CFP Content**: Focused pages with section info + link to general guidelines + submit buttons

3. ✅ **Homepage Streamlining**:
   - **Simplified Submission Flow**: Single "Begin a Submission" button for general submissions
   - **Special Issue CFPs**: Clean grid layout showing non-primary sections with "View Full CFP" links
   - **Removed Section Pre-selection**: Simplified to let users choose sections in submission wizard
   - **Navigation Text Clarity**: Identified that "About" nav text should be customized via admin interface (not theme)

4. ✅ **CSS Architecture Understanding**:
   - **Confirmed Bootstrap 5.2.3**: OJS themes inherit Bootstrap from parent themes (not TailwindCSS)
   - **TailwindCSS Scope**: Only used in admin interface, themes use Bootstrap + custom LESS
   - **Semantic Color System**: Maintained LESS variable approach as OJS best practice

5. ✅ **Technical Debugging**:
   - **OJS 3.5 Compatibility**: Learned HANDLER_CLASS is deprecated, template display hooks are preferred
   - **DOM Structure**: Investigated actual header structure for proper CSS targeting
   - **Hook Conflicts**: Resolved infinite loop issues with proper flag management

### Recently Completed (Latest Session - Oct 5, 2025)
1. ✅ **Database-Level Role Cleanup**:
   - **Problem**: Submissions Only plugin using JavaScript/CSS hiding was brittle and not working properly in wizard
   - **Solution**: Deleted 12 unwanted publishing roles directly from MySQL database
   - **Roles Removed**: Production Editor, Copyeditor, Designer, Funding Coordinator, Indexer, Layout Editor, Marketing and Sales Coordinator, Proofreader, Translator, Reader, Subscription Manager, Editorial Board Member
   - **Roles Kept**: Journal Manager, Journal Editor, Section Editor, Guest Editor, Author, Reviewer
   - **Plugin Deleted**: Removed `/plugins/generic/submissionsOnly/` entirely - no longer needed

2. ✅ **OJS Documentation Review**:
   - **Reviewed**: `/lib/ui-library/src/docs/guide/Plugins/Plugins.mdx`
   - **Key Learnings**:
     - Vue.js 3 + Composition API for modern plugins
     - Pinia stores for state management and extensibility
     - JS hooks via `pkp.registry.storeExtend()` for Vue pages
     - PHP hooks for Smarty template pages
   - **Decision**: Database deletion was simpler and more robust than complex hook-based filtering

3. ✅ **Plugin Cleanup & 500 Error Fix**:
   - **Problem**: 500 errors caused by references to deleted Section Metadata plugin
   - **Root Cause**: Theme templates trying to access `cfpDueDate` data that no longer existed
   - **Solution**:
     - Deleted `/plugins/generic/sectionMetadata/` plugin completely
     - Removed all `cfpDueDate` references from indexJournal.tpl and submissionGuidelines.tpl
     - Cleared OJS cache
   - **Result**: Clean working state with only essential plugins remaining

### Current System Status: 🎯 **NEAR COMPLETION**

**✅ Fully Functional:**
- Submissions-focused homepage with general submission + special issue CFPs
- Individual CFP pages for each section with section-specific information
- Clean submission guidelines page (accessible via customizable "About" nav)
- Header styling with proper navigation alignment and admin dropdown functionality
- Database-level removal of publishing roles (12 unwanted roles deleted permanently)
- 6 semantic color options with working color palette system

**🔧 Minor Cleanup Needed:**
- Remove "Submit to [section]" buttons from submission guidelines page
- Consider other minor UX refinements on guidelines page

### Next Session Goals
1. **Cleanup Guidelines Page**: Remove section-specific submit buttons from the main guidelines page
2. **Final UX Polish**: Any remaining minor improvements to submission flow
3. **Documentation**: Update any remaining documentation for deployment

### Known Technical Insights
- **External Link Ordering**: Can be controlled via admin navigation interface (not CSS)
- **Navigation Text**: "About" → "Submission Guidelines" easily changed in admin Settings → Website → Navigation
- **CFP System**: Works well with template override approach, avoiding deprecated handler patterns
- **Header Structure**: Admin nav and main nav are in separate containers, limited alignment possible

### Long-term Possibilities
1. **Locale Overrides**: Custom translation keys for submission-focused terminology
2. **Advanced Workflow**: Vue component overrides for comprehensive workflow renaming
3. **Analytics**: Submissions-focused statistics and reporting dashboard
4. **Author Experience**: Enhanced submission tracking and status communication