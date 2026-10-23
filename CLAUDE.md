# Sawa Lens

Server side of the Language Lens extension and the web app at `/lens/` and `/app`. The shared notes for all Sawa apps are in `../CLAUDE.md`.

- Endpoints here are used by `../language-lens` and by the Silk Road game in `../sawa-tracker`. Changing one can break the other.
- Bump `Version:` and `SAWA_LENS_VER` in `sawa-lens.php` for every release (and `SAWA_LENS_DB` when the database changes).
- The app is written in English (`assets/app.js`, `assets/placement.js`). Students who pick العربية get `assets/app-ar.js` / `assets/placement-ar.js`, generated: never edit them by hand. After changing text in app.js or placement.js, run `node tools/i18n-ar.js extract`, add the Arabic for new strings to `tools/i18n/ar-*.json` (simple Egyptian Arabic, like the tracker), then `node tools/i18n-ar.js build` and commit the generated files too. Strings without Arabic stay English.
- One language setting for the site and the app: `includes/class-lang.php` (`Sawa_Lang`, same file in every Sawa plugin). English by default; `?lang=ar` works.
