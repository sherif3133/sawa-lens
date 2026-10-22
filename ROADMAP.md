# Sawa Lens – planned features

## Stories for you (built 22 Oct 2026, 0.18.0–0.19.0)

Built: a written library in `data/stories/*.json` (104 stories: every interest-quiz topic at A2 and B1, a story and an explainer, plus the 6-chapter football series "The Bulaq Boys"), imported on each update, free and open to every student; nearest-level matching (A1 students get A2, B2+ get B1); "Video quizzes → Stories" admin list (reads, finishes, quick-check %, hide/show, re-import); reading time counts as Active Immersion minutes; optional natural voice (OpenAI TTS, recorded once per paragraph and shared); optional AI writing when a student has read everything, and AI personal stories for testers, both off by default within a monthly budget; Rawi the storyteller in the Silk Road (sawa-tracker 2.10.8). Check the library with `node tools/stories-check.js`; test with `tools/readers-test.js`.

Next:
- **More stories**: B2 versions for the most popular topics (football, rap, business, gaming, comedy), and more series (one per popular topic). Add them as new JSON files; the check and import handle the rest.
- **Arabic summary after reading** (one line, for A1–A2) and a "too easy / too hard" button that nudges the level of the next story.
