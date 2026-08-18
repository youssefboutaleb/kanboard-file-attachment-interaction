## What does this change?

<!-- A short description, and the issue it closes if there is one. -->

## Why?

<!-- The problem being solved. For a bug, what the incorrect behaviour was. -->

## Checklist

- [ ] `composer test` passes
- [ ] `composer phpstan` passes (level 8, no new baseline entries)
- [ ] New behaviour has tests; bug fixes have a test that fails without the fix
- [ ] All template output is escaped (`htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`)
- [ ] No inline `<script>` — client-side behaviour ships as a file registered on `template:layout:js` with delegated listeners
- [ ] No Kanboard core files modified
- [ ] `CHANGELOG.md` updated under the unreleased/next version heading
- [ ] Any new bundled third-party asset is recorded in `NOTICE`

## Security impact

<!-- State "none" if there is none. If this touches permissions, attachment
     resolution, streaming, or template output, say what changed and why it is safe. -->
