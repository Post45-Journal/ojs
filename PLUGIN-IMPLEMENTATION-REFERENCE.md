# Submissions Only Plugin - Implementation Reference

This document contains detailed implementation information for the Submissions Only plugin, including data flow, hook architecture, and working code examples.

## Architecture Overview

### The Challenge
Transform OJS workflow from: Submission → Review → Copyediting → Production → Published
To simplified: Submit → Review → Accept → Done

**Key Problem**: OJS doesn't have an "Accepted" stage. Submissions that are accepted go to Copyediting (stage 4) or Production (stage 5), but we want to show them as "Accepted" without publishing.

### The Solution: Three-Part Data Transformation

The plugin uses a three-layer approach to transform how submissions appear in the dashboard:

1. **PHP Layer**: Query data from database (real stages 4 & 5)
2. **Fetch Interception**: Modify API responses for badge counts
3. **Pinia Store Extension**: Transform display data (stages 4 & 5 → pseudo-stage 99)

This creates a "virtual" stage 99 that only exists in the UI, while the database still tracks real stages.

---

## Data Flow Architecture

### 1. PHP Dashboard Hook - Backend Query Layer
**File**: `DashboardViewsHook.php`

**Purpose**: Add "Accepted" view to dashboard that queries real stages 4 & 5 from database

**Key Method**: `addAcceptedView()`

```php
public static function addAcceptedView(string $hookName, array $args): bool
{
    $viewsData = &$args[0];
    $userRoles = $args[1];

    $request = Application::get()->getRequest();
    $context = $request->getContext();

    // Only add view if user has appropriate roles
    $hasEditorialRole = !empty(array_intersect(
        $userRoles,
        [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER,
         Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT, Role::ROLE_ID_AUTHOR]
    ));

    if (!$hasEditorialRole) {
        return false;
    }

    // Create collector for accepted submissions
    // CRITICAL: Query REAL stages 4 & 5 from database
    $collector = Repo::submission()->getCollector()
        ->filterByContextIds([$context->getId()])
        ->filterByStageIds([
            WORKFLOW_STAGE_ID_EDITING,     // 4 - Copyediting
            WORKFLOW_STAGE_ID_PRODUCTION   // 5 - Production
        ])
        ->filterByStatus([PKPSubmission::STATUS_QUEUED]);

    // For authors, filter to only their submissions
    if (in_array(Role::ROLE_ID_AUTHOR, $userRoles) &&
        !in_array(Role::ROLE_ID_MANAGER, $userRoles)) {
        $user = $request->getUser();
        $collector->filterByAuthorIds([$user->getId()]);
    }

    // Determine if user can access unassigned submissions
    $canAccessUnassignedSubmission = !empty(array_intersect(
        $userRoles,
        [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER]
    ));

    // Create the "Accepted" view
    $acceptedView = new DashboardView(
        'accepted',  // View ID
        __('plugins.generic.submissionsOnly.dashboard.view.accepted'),  // "Accepted"
        [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER,
         Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT, Role::ROLE_ID_AUTHOR],
        $collector,
        $canAccessUnassignedSubmission ? null : 'assigned',
        [
            'stageIds' => [
                WORKFLOW_STAGE_ID_EDITING,     // Still real stages for querying
                WORKFLOW_STAGE_ID_PRODUCTION
            ],
            'status' => [PKPSubmission::STATUS_QUEUED],
        ]
    );

    $viewsData[] = $acceptedView->getData();
    return false;
}
```

**Key Points:**
- Queries database for **real** stages 4 & 5 (not pseudo-stage 99)
- JavaScript layer will transform these to stage 99 for display
- Handles both editorial and author access permissions
- Active/Assigned views naturally exclude stages 4 & 5 (they filter for stages 1-3)

---

### 2. Fetch Interception - API Response Modification
**File**: `js/dashboard-stage-transform.js` (lines 1-55 approx)

**Purpose**: Fix badge counts in sidebar by intercepting `/viewsCount` API calls

**Why Needed**: The SideNav component fetches badge counts independently from a separate API endpoint, not from the Pinia store. Without interception, badges show wrong counts.

```javascript
// Store original fetch function
const originalFetch = window.fetch;

// Intercept all fetch calls
window.fetch = async function(...args) {
    const [url, options] = args;

    // Call original fetch
    const response = await originalFetch(...args);

    // Only intercept viewsCount API calls
    if (url && url.includes('/viewsCount')) {
        // Clone response so we can read it
        const clonedResponse = response.clone();
        const data = await clonedResponse.json();

        // Calculate accepted count from copyediting + production
        const copyeditingCount = data.find(v => v.id === 'copyediting')?.count || 0;
        const productionCount = data.find(v => v.id === 'production')?.count || 0;
        const acceptedCount = copyeditingCount + productionCount;

        // Remove copyediting and production views
        const filteredData = data.filter(v =>
            v.id !== 'copyediting' &&
            v.id !== 'production' &&
            v.id !== 'scheduled' &&
            v.id !== 'published'
        );

        // Add accepted view with calculated count
        filteredData.push({
            id: 'accepted',
            count: acceptedCount
        });

        // Adjust active and assigned-to-me counts
        const activeView = filteredData.find(v => v.id === 'active');
        const assignedView = filteredData.find(v => v.id === 'assigned-to-me');

        if (activeView) {
            activeView.count = Math.max(0, activeView.count - acceptedCount);
        }
        if (assignedView) {
            assignedView.count = Math.max(0, assignedView.count - acceptedCount);
        }

        // Return modified response
        return new Response(
            JSON.stringify(filteredData),
            {
                status: response.status,
                statusText: response.statusText,
                headers: response.headers
            }
        );
    }

    // Return original response for non-viewsCount calls
    return response;
};
```

**Key Points:**
- Must intercept fetch, not modify store (SideNav doesn't use store for counts)
- Calculates accepted count = copyediting + production
- Subtracts accepted count from active/assigned-to-me to prevent double-counting
- Removes publishing-related views (scheduled, published)

---

### 3. Pinia Store Extension - Display Transformation
**File**: `js/dashboard-stage-transform.js` (lines 56-128 approx)

**Purpose**: Transform submission stage IDs from 4/5 → 99 when viewing "Accepted" tab

**Why Needed**: Submissions come from API with real stage IDs (4 or 5), but we want dashboard table to show "Accepted" (stage 99) so they display correctly.

```javascript
pkp.registry.storeExtend('dashboard', (piniaContext) => {
    const dashboardStore = piniaContext.store;

    // Polling-based view detection
    let lastViewId = null;
    let lastSubmissionCount = 0;

    setInterval(() => {
        const currentViewId = dashboardStore.currentViewId;
        const submissions = dashboardStore.items?.submissions;

        // Detect view change or new submissions loaded
        const viewChanged = currentViewId !== lastViewId;
        const submissionsChanged = submissions?.length !== lastSubmissionCount;

        if (viewChanged || submissionsChanged) {
            lastViewId = currentViewId;
            lastSubmissionCount = submissions?.length || 0;

            // Only transform when viewing "Accepted" tab
            if (currentViewId === 'accepted' && submissions?.length > 0) {
                submissions.forEach(submission => {
                    // Transform stages 4 & 5 → 99
                    if (submission.stageId === 4 || submission.stageId === 5) {
                        submission.stageId = 99;
                        if (submission.stageName) {
                            submission.stageName = 'Accepted';
                        }
                    }
                });
            }
        }
    }, 1000); // Poll every second
});
```

**Key Points:**
- **View-specific transformation**: Only transforms when `currentViewId === 'accepted'`
- **Polling approach**: Watches for view changes and submission loading
- **Mutates submission objects directly**: Vue's reactivity system updates UI automatically
- **Pseudo-stage 99**: Not a real OJS stage, only exists in frontend display

**Why polling instead of watchers?**
- `dashboardStore.currentViewId` is not a reactive ref, it's a plain property
- Vue watchers don't detect changes to plain properties
- Polling is simple and works reliably

---

### 4. Workflow Panel Extension - Custom "Accepted" Stage
**File**: `js/dashboard-stage-transform.js` (lines 129-287 approx)

**Purpose**: Show custom "Accepted" panel when viewing accepted submission workflow

**Implementation using OJS Extender API:**

#### Custom Vue Component
```javascript
const AcceptedPanelComponent = {
    name: 'AcceptedPanel',
    props: {submission: {type: Object, required: true}},
    template: `
        <div class="border border-light p-3">
            <h3 class="text-lg-bold text-heading">Submission Accepted</h3>
            <p class="pt-2 text-base-normal">
                This submission has been accepted for publication.
                Further processing (copyediting, production, scheduling) will be handled externally.
            </p>
            <p class="pt-2 text-base-normal">
                You can review submission files and reviewer reports by clicking on the
                <strong>Submission</strong> or <strong>Review</strong> stages in the left sidebar.
            </p>
        </div>
    `,
};
pkp.registry.registerComponent('AcceptedPanel', AcceptedPanelComponent);
```

#### Workflow Store Extensions
```javascript
pkp.registry.storeExtend('workflow', (piniaContext) => {
    const workflowStore = piniaContext.store;

    // 1. Add "Accepted" menu item to sidebar
    workflowStore.extender.extendFn('getMenuItems', (originalResult, args) => {
        const submission = args.submission;

        // Only add if submission is in stage 4 or 5
        if (submission && (submission.stageId === 4 || submission.stageId === 5)) {
            const workflowMenu = originalResult.find(item => item.key === 'workflow');

            if (workflowMenu && workflowMenu.items) {
                workflowMenu.items.push({
                    key: 'workflow_99',
                    label: 'Accepted',
                    state: {stageId: 99, primaryMenuItem: 'workflow', title: 'Accepted'},
                });
            }
        }

        return originalResult;
    });

    // 2. Default to "Accepted" stage on initial load
    workflowStore.extender.extendFn('getInitialSelectionItemKey', (originalResult, args) => {
        const submission = args.submission;

        if (submission && (submission.stageId === 4 || submission.stageId === 5)) {
            return 'workflow_99';
        }

        return originalResult;
    });

    // 3. Show AcceptedPanel component for stage 99
    workflowStore.extender.extendFn('getPrimaryItems', (originalResult, args) => {
        const selectedStageId = args.selectedMenuState?.stageId;
        const submission = args.submission;

        if (selectedStageId === 99) {
            return [{component: 'AcceptedPanel', props: {submission: submission}}];
        }

        return originalResult;
    });

    // 4. Hide Participants panel for stage 99
    workflowStore.extender.extendFn('getSecondaryItems', (originalResult, args) => {
        const selectedStageId = args.selectedMenuState?.stageId;

        if (selectedStageId === 99) {
            return []; // No sidebar for pseudo-stage 99
        }

        return originalResult;
    });

    // 5. Add "Cancel Acceptance" button (editorial users only)
    workflowStore.extender.extendFn('getActionItems', (originalResult, args) => {
        const selectedStageId = args.selectedMenuState?.stageId;
        const submission = args.submission;

        if (selectedStageId === 99) {
            // Check user permissions
            const currentUser = pkp.currentUser;
            const hasEditorialRole = currentUser?.roles?.some(role =>
                [1, 16, 17, 4097].includes(role) // Manager, Editor, Section Editor, Assistant
            );

            if (hasEditorialRole) {
                return [{
                    component: 'WorkflowActionButton',
                    props: {
                        label: 'Cancel Acceptance and Revert to Review Stage',
                        isWarnable: true,  // Red warning style
                        action: () => {
                            // Redirect to OJS decision page
                            const submissionId = submission.id;
                            const returnUrl = window.location.href;
                            window.location.href =
                                `/decision/record/${submissionId}?decision=30&ret=${encodeURIComponent(returnUrl)}`;
                        },
                    },
                }];
            }
        }

        return originalResult;
    });
});
```

**Key Technical Insights:**
- **OJS Extender API**: `workflowStore.extender.extendFn(fnName, callback)` is the proper way to extend workflow functions
- **Function Signature**: `callback(originalResult, args)` - receives original result and can modify it
- **Stage 99 Components**: ParticipantManager requires role assignments that don't exist for pseudo-stage 99, so return empty array
- **Action Buttons**: Go in `getActionItems`, not `getPrimaryItems` to place in right column
- **Cancel Acceptance**: Uses OJS's built-in decision page (`decision=30` = BACK_FROM_COPYEDITING)

---

## Testing Checklist

Use this checklist for regression testing after OJS updates or plugin modifications:

### Dashboard Tests
- [ ] **Initial Load**: Dashboard loads with no flash, accepted submissions show as stage 99
- [ ] **Badge Counts**: "Accepted" view count matches copyediting + production submissions
- [ ] **View Switching**: Switching between Active → Accepted → Assigned works smoothly
- [ ] **No Duplicates**: Submissions don't appear in both Active and Accepted views
- [ ] **Search/Filter**: Searching and filtering still work correctly
- [ ] **Active Count**: Active badge = total active submissions - accepted submissions

### Workflow Panel Tests
- [ ] **Accepted Menu Item**: Shows in sidebar for stages 4/5 submissions
- [ ] **Custom Panel**: AcceptedPanel component displays with correct messaging
- [ ] **Cancel Button**: Appears for editorial users, hidden for authors
- [ ] **Cancel Functionality**: Clicking button opens decision page, properly reverts submission
- [ ] **Preview Button**: Hidden from workflow header for accepted submissions
- [ ] **No Errors**: Console shows no JavaScript errors

### Author Dashboard Tests
- [ ] **Accepted View Visible**: Authors see "Accepted" link in sidebar
- [ ] **Badge Count Correct**: Shows count of author's accepted submissions
- [ ] **No Cancel Button**: "Cancel Acceptance" button not visible to authors
- [ ] **Access Control**: Authors only see their own accepted submissions

### Plugin Disable Test
- [ ] **Clean Reversion**: Disabling plugin restores normal Copyediting/Production stages
- [ ] **No Orphaned Data**: No stage 99 references remain in database
- [ ] **No JavaScript Errors**: OJS works normally with plugin disabled

### Performance Tests
- [ ] **No Lag**: No noticeable lag from transformation on large submission lists (100+ submissions)
- [ ] **Polling Overhead**: CPU usage remains normal with multiple browser tabs open

---

## Common Issues & Debugging

### Issue: Badge counts are wrong after plugin update

**Diagnosis:**
- Check browser console for fetch interception errors
- Verify `/viewsCount` API response structure hasn't changed

**Debug command:**
```javascript
// Test viewsCount API directly
fetch('/index.php/testjournal/$$$call$$$/stats/editorial/OJS3EditorialStatsHandler/viewsCount')
  .then(r => r.json())
  .then(data => console.log('Raw API response:', data));
```

### Issue: Submissions appear in multiple views

**Diagnosis:**
- Check if view-specific transformation is working
- Verify `currentViewId === 'accepted'` condition

**Debug command:**
```javascript
const store = pkp.registry.getPiniaStore('dashboard');
console.log('Current view:', store.currentViewId);
console.log('Submissions:', store.items?.submissions);
```

### Issue: Workflow panel doesn't show "Accepted" stage

**Diagnosis:**
- Check if submission.stageId is 4 or 5 (real stages)
- Verify workflow store extension is loaded

**Debug command:**
```javascript
const workflowStore = pkp.registry.getPiniaStore('workflow');
console.log('Store:', workflowStore);
console.log('Available hooks:', workflowStore.extender.listExtendableFns());
console.log('Submission:', workflowStore.submission);
```

### Issue: "Cancel Acceptance" button not working

**Diagnosis:**
- Check if decision URL is correct
- Verify user has editorial permissions

**Debug command:**
```javascript
const currentUser = pkp.currentUser;
console.log('User roles:', currentUser.roles);
console.log('Has editorial role?', currentUser.roles.some(r => [1, 16, 17, 4097].includes(r)));
```

---

## Key Architecture Decisions

### Why pseudo-stage 99 instead of modifying database?

**Reasons:**
1. **Clean uninstall**: Disable plugin → everything returns to normal, no database migration needed
2. **No data corruption**: Database remains in valid OJS state
3. **OJS compatibility**: Works with standard OJS upgrade procedures
4. **Reversible**: Can easily switch back to normal workflow

### Why fetch interception instead of modifying store?

**Reasons:**
1. **SideNav independence**: SideNav component fetches counts separately from dashboard store
2. **API source of truth**: Counts come from `/viewsCount` API, not Pinia store
3. **Single modification point**: One interception fixes counts everywhere

### Why polling instead of Vue watchers?

**Reasons:**
1. **Plain property**: `currentViewId` is not a reactive ref
2. **Simplicity**: Polling is straightforward and reliable
3. **Performance**: 1-second polling has negligible overhead
4. **Robustness**: Works regardless of OJS internal state management changes

---

## Future Considerations

### If OJS adds native "Accepted" stage

**Migration path:**
1. Database migration to move submissions from stages 4/5 to new accepted stage
2. Remove fetch interception (no longer needed)
3. Simplify Pinia store extension (no transformation needed)
4. Update DashboardViewsHook to query native accepted stage

### If OJS changes Pinia store structure

**Risks:**
- `store.currentViewId` property name could change
- `store.items.submissions` path could change
- Store extension API could change

**Mitigation:**
- Monitor OJS release notes for breaking changes
- Test plugin with beta releases
- Add defensive checks for property existence

### If performance becomes an issue

**Optimizations:**
- Replace polling with MutationObserver on DOM
- Debounce transformation function
- Add caching layer for stage transformations
- Use Vue computed properties instead of direct mutation
