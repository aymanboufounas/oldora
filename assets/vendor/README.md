# Browser dependencies

These pinned distribution files are served locally so the dashboard charts and
the Home/Plans forms work when third-party CDNs are unavailable.

| Package | Version | License |
| --- | --- | --- |
| Chart.js | 4.4.1 | `chartjs/LICENSE.md` |
| jQuery | 3.5.1 | `jquery/LICENSE.txt` |
| Bootstrap | 4.5.2 | `bootstrap/LICENSE` |

Files come from the corresponding npm package's `dist` directory. Fetch updates
with npm's normal integrity verification and test the forms, charts, and mobile
navigation before replacing the pinned files.

Font Awesome Free 6.7.2 is stored separately in `assets/icons`, with its license.
