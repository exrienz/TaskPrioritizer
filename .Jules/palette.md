# Palette's Journal

## 2024-05-22 - Missing Delete Confirmation
**Learning:** Destructive actions like "Delete" should always have a confirmation step to prevent accidental data loss. This is a critical safety feature.
**Action:** Add `onsubmit="return confirm('...')" ` to delete forms.

## 2024-05-22 - Form Accessibility
**Learning:** Relying on placeholders or default option text for form labels is bad for accessibility. Screen readers may not read them correctly, and placeholders disappear when typing.
**Action:** Use `<label>` elements or `aria-label` attributes for all form inputs.
