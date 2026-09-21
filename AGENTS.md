# Project rules

These rules apply to the whole repository.

## Visual language

Keep the visual language established on the homepage consistent across the project:

- poster-inspired geometric composition;
- warm cream background, near-black text, deep red accent;
- large condensed display typography for headings;
- strong borders, offset shadows and simple angular decorative elements;
- high contrast and restrained use of accent color;
- no extremist, hate-group or prohibited propaganda symbols, slogans or emblems.

Shared design tokens and reusable layout rules belong in `app/styles.css`. Prefer reusing existing classes instead of duplicating page-specific CSS.

## Navigation

Every user-facing page must include the shared page navigation.

Desktop:
- navigation is fixed on the left;
- current page is visually marked with `aria-current="page"`.

Mobile:
- navigation must adapt to the viewport and must not cover page content;
- horizontal or compact navigation is acceptable when space is limited.

## Responsive design — mandatory

Every new page and every substantial UI change must include a mobile adaptation in the same change.

Minimum checks:
- usable at 320px viewport width;
- no unintended horizontal scrolling;
- readable typography without zooming;
- touch targets remain usable;
- fixed/sticky elements do not cover content;
- desktop and mobile layouts preserve the same information and navigation.

Do not postpone mobile work to a later task.

## Frontend structure

- Shared CSS: `app/styles.css`
- Shared browser JavaScript: `app/main.js`
- Homepage: `app/index.html`
- Additional static pages: `app/*.html`

Avoid inline CSS and duplicated JavaScript unless a page has a genuinely isolated need.

## Deployment

Changes merged/pushed to `main` are deployed by GitHub Actions to the VPS.

Do not commit secrets, passwords, private SSH keys or production `.env` files.
