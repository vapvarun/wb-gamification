# Wbcom Admin Design System

> Shared design guidelines for all Wbcom Designs WordPress plugins.
> Every plugin must follow this pattern so admins see one consistent UX.

## Reference Implementation

**WPMediaVerse Settings** (`/wp-admin/admin.php?page=mvs-settings`) is the reference.

## Layout Pattern

```
┌─────────────────────────────────────────────────────┐
│ [Plugin Icon] Plugin Name                           │
│              SETTINGS                               │
├──────────────┬──────────────────────────────────────┤
│              │                                      │
│  CATEGORY 1  │  SECTION HEADER (uppercase)          │
│  ○ Tab 1     │  Description text in muted color     │
│  ○ Tab 2     │                                      │
│  ○ Tab 3     │  Label          [Input/Select/Check] │
│              │                 Helper text below     │
│  CATEGORY 2  │                                      │
│  ○ Tab 4     │  Label          [Input/Select/Check] │
│  ○ Tab 5     │                 Helper text below     │
│              │                                      │
│  CATEGORY 3  │  ──────────────────────────────────  │
│  ○ Tab 6 PRO │                                      │
│              │  SECTION 2 HEADER                     │
│              │  Description                          │
│              │                                      │
│              │         [Save Changes]                │
└──────────────┴──────────────────────────────────────┘
```

## Components

### 1. Settings Wrapper
```html
<div class="wbcom-settings-wrap">
    <div class="wbcom-settings-header">
        <div class="wbcom-settings-icon">[plugin icon]</div>
        <div class="wbcom-settings-title">
            <strong>Plugin Name</strong>
            <span>SETTINGS</span>
        </div>
    </div>
    <div class="wbcom-settings-body">
        <nav class="wbcom-settings-nav">...</nav>
        <div class="wbcom-settings-content">...</div>
    </div>
</div>
```

### 2. Sidebar Navigation
```html
<nav class="wbcom-settings-nav">
    <div class="wbcom-nav-group">
        <div class="wbcom-nav-group-label">CATEGORY NAME</div>
        <a href="#section" class="wbcom-nav-item active">
            <span class="dashicons dashicons-admin-generic"></span>
            Section Name
        </a>
        <a href="#section2" class="wbcom-nav-item">
            <span class="dashicons dashicons-video-alt3"></span>
            Video
            <span class="wbcom-pro-badge">Pro</span>
        </a>
    </div>
</nav>
```

### 3. Content Sections
```html
<div class="wbcom-settings-section" id="general">
    <div class="wbcom-section-header">
        <h2>GENERAL</h2>
        <p>Upload limits, file types, privacy defaults.</p>
    </div>
    <div class="wbcom-section-body">
        <div class="wbcom-field-row">
            <label>Max Upload Size</label>
            <div class="wbcom-field-input">
                <input type="number" value="100"> <strong>MB</strong>
                <p class="wbcom-field-help">Maximum file size per upload.</p>
            </div>
        </div>
    </div>
</div>
```

### 4. Field Types

**Text input:**
```html
<div class="wbcom-field-row">
    <label>Field Label</label>
    <div class="wbcom-field-input">
        <input type="text" class="wbcom-input">
        <p class="wbcom-field-help">Helper text.</p>
    </div>
</div>
```

**Select dropdown:**
```html
<div class="wbcom-field-row">
    <label>Privacy Level</label>
    <div class="wbcom-field-input">
        <select class="wbcom-select">
            <option>Public</option>
            <option>Members Only</option>
        </select>
    </div>
</div>
```

**Checkbox group:**
```html
<div class="wbcom-field-row">
    <label>Allowed Types</label>
    <div class="wbcom-field-input">
        <div class="wbcom-checkbox-grid">
            <div class="wbcom-checkbox-group">
                <strong>Images</strong>
                <label><input type="checkbox" checked> JPEG</label>
                <label><input type="checkbox" checked> PNG</label>
            </div>
        </div>
    </div>
</div>
```

**Toggle switch:**
```html
<div class="wbcom-field-row">
    <label>Enable Feature</label>
    <div class="wbcom-field-input">
        <label class="wbcom-toggle">
            <input type="checkbox" checked>
            <span class="wbcom-toggle-slider"></span>
        </label>
        <p class="wbcom-field-help">Description of what this does.</p>
    </div>
</div>
```

## CSS Variables (Design Tokens)

```css
:root {
    /* Layout */
    --wbcom-nav-width: 280px;
    --wbcom-content-max: 900px;

    /* Colors */
    --wbcom-bg: #f0f0f1;
    --wbcom-card-bg: #fff;
    --wbcom-border: #e0e0e0;
    --wbcom-text: #1e1e1e;
    --wbcom-text-muted: #757575;
    --wbcom-primary: #2271b1;
    --wbcom-primary-hover: #135e96;
    --wbcom-pro-badge: #7c3aed;

    /* Typography */
    --wbcom-font: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --wbcom-heading-size: 13px;
    --wbcom-heading-weight: 600;
    --wbcom-heading-transform: uppercase;
    --wbcom-heading-spacing: 0.05em;
    --wbcom-heading-color: #1e1e1e;
    --wbcom-body-size: 14px;
    --wbcom-help-size: 13px;
    --wbcom-help-color: #757575;

    /* Spacing */
    --wbcom-section-gap: 32px;
    --wbcom-field-gap: 24px;
    --wbcom-row-padding: 16px 0;

    /* Borders */
    --wbcom-radius: 4px;
    --wbcom-field-border: 1px solid #8c8f94;
    --wbcom-section-border: 1px solid #e0e0e0;
}
```

## Rules

1. **Left sidebar navigation** — always. Never top tabs for settings pages.
2. **Section headers** — uppercase, 13px, letter-spacing 0.05em, with description below.
3. **Field rows** — label left (200px width), input right, helper text below input in muted color.
4. **Pro badges** — purple pill next to nav items that require Pro.
5. **Save button** — at bottom of each section, standard WordPress blue button.
6. **No inline styles** — all CSS in external files.
7. **No admin notices** — suppress third-party notices on plugin settings pages.
8. **Plugin icon** — 40x40px dashicon or SVG in the header.
9. **Responsive** — sidebar collapses to top nav at 782px.
10. **RTL** — use logical CSS properties everywhere.

## Which Plugins Use This

| Plugin | Status |
|--------|--------|
| WPMediaVerse | Reference implementation |
| wb-gamification | Needs rewrite to match |
| Jetonomy | Needs rewrite to match |
| BuddyNext | Future |

## Implementation

The shared CSS should be in a separate file that any Wbcom plugin can enqueue:
- Option A: Each plugin copies the CSS (current — works but duplicates)
- Option B: A shared `wbcom-admin` npm package (future — DRY)
- Option C: A shared mu-plugin that provides the CSS (simplest for multi-plugin sites)

For 1.0.0, each plugin includes its own copy. The CSS class prefix is `wbcom-` (shared) not `wbgam-` (plugin-specific).
