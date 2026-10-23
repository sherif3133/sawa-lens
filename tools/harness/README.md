# /app phone harness (no WordPress, no sign-in)

The real `assets/app.js` (or `app-ar.js`) and `assets/app.css`, with a fake API (`fake-api.js`), so /app screens can be checked at phone sizes in the built-in browser.

1. Start it: `preview_start` with name `app-harness` (from `../.claude/launch.json`), or `node sawa-lens/tools/harness/serve.js`.
2. Open `http://localhost:9420/tools/harness/?s=mixed#review`, then set the size with `resize_window` (375×667 = iPhone SE/8, 390×844 = iPhone 12–15).
3. Run `await checkReview()` in the page (browser `javascript_tool`): it goes through every card (front → Show answer → Good) and lists any card that runs under the bottom bar or makes the page scroll.

Options: `?s=mixed|video|long|empty|catchup` (card sets), `&lang=ar` (Arabic, right to left), `&plan=free` (free account with 🔒 parts). Any `#screen` works (`#today`, `#learn`, `#library`…); unknown API calls get `{}`, so some screens show empty.

Not covered: the tracker parts of Home/Chat/Play (no sawa-tracker here), real YouTube timing, iPhone Safari quirks (safe areas, the toolbar). Add fixtures to `fake-api.js` when you test a new screen.
