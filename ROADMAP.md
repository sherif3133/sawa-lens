# Sawa Lens – planned features

## From the learner research (planned 22 Oct 2026)

Picked by Sherif from the learner pain map (report: https://claude.ai/artifact/6vQJfLv3n6qyWtuJy2pwKB, data in `../audience-research/`). The research found that habit problems (laziness, boredom, quitting: 38% of the struggles mentioned in Skool) are bigger than all skill problems together (28%). It also found that learners keep asking strangers to "remind me" (رجعوني).

- **Start pledge with real follow-up.** On day 1 the student makes a pledge card in `/app`: today's date, their goal (job, interview, travel, films), and an optional 30-second voice clip. It can be shared to Skool. Check-ins come on day 3, 7, 30 and 90 as notifications in the app, by email, or by WhatsApp later. Each check-in shows how far they have come since the pledge. After 3 days without studying, Sherif or a guide gets a list of names to nudge personally, because a real person checking in is what YouTube "remind me" comments never get. The guides already matched for 2 weeks (`sawa-tracker` coop) can take this over.
- **Comeback mode.** A missed day never resets anything to zero. Total study days and words stay; only the current run starts again, and it is shown next to the best run, never alone. Coming back after 2+ days opens a short "continue from where you stopped" session: 10 minutes, today's cards capped, no pile-up. It gives a small welcome-back reward, not a penalty. The Today plan and the Home stats in `assets/app.js` need the wording; streaks are in `class-activity.php`, `class-review.php` and `class-readers.php`. The Silk Road Wounds need a matching change in `sawa-tracker` (see its ROADMAP).
- **Monthly before/after.** Once a month the student records the same 60-second answer ("tell me about your day / your work") and takes a short version of the placement test (`/placement/`). The app shows them side by side with last month's and the first month's: both recordings, the level, vocabulary size, words known, and minutes of English met. It ends in a share card for Skool. It answers "when will I feel a difference?" and gives Sherif real before/after proof (with permission).
- **Word-to-speech bridge.** One daily speaking (or writing) prompt built from this week's Learning words: "Talk for 60 seconds about your weekend using 3 of these: …". The free speaking checker scores it as usual and also marks which target words were used correctly. Used words count toward the card (right uses → `record_uses`), and unused ones come back in tomorrow's prompt. It is a step in the Today plan. It targets "I learned the words but can't use them when I speak."

## Stories for you (built 22 Oct 2026, 0.18.0–0.19.0)

Built: a written library in `data/stories/*.json` (104 stories: every interest-quiz topic at A2 and B1, a story and an explainer, plus the 6-chapter football series "The Bulaq Boys"), imported on each update, free and open to every student; nearest-level matching (A1 students get A2, B2+ get B1); "Video quizzes → Stories" admin list (reads, finishes, quick-check %, hide/show, re-import); reading time counts as Active Immersion minutes; optional natural voice (OpenAI TTS, recorded once per paragraph and shared); optional AI writing when a student has read everything, and AI personal stories for testers, both off by default within a monthly budget; Rawi the storyteller in the Silk Road (sawa-tracker 2.10.8). Check the library with `node tools/stories-check.js`; test with `tools/readers-test.js`.

Next:
- **More stories**: B2 versions for the most popular topics (football, rap, business, gaming, comedy), and more series (one per popular topic). Add them as new JSON files; the check and import handle the rest.
- **Arabic summary after reading** (one line, for A1–A2) and a "too easy / too hard" button that nudges the level of the next story.

## Simplifying the app (review 23 Oct 2026, small fixes built in 0.23.5)

A new student went through `/app` on a phone and on a computer. Built in 0.23.5 (no decision needed): the stages screen has one name, "Your stages", everywhere. A locked tool now says which stage opens it instead of jumping silently to Learn. Open tools come first in Learn. Learn and Your things show an Arabic name when العربية is on. Scan is no longer inside Write's switch. "My mistakes" (Home) and "Progress" (its page) are one name, "Writing progress". The Level test tile always shows and opens in the same tab. Step buttons say what they do. The word "tracker" is gone from student text. A new student's Home shows one line instead of empty charts. The review streak is labelled "review days in a row", so it doesn't clash with the day streak.

Bigger changes that need Sherif (my pick first):

1. **Home is about 5 phone screens long, and today's plan starts at screen 2.** A new student first sees Log your day, + Activity, a welcome ring, Today's new thing, 4 stat tiles, the leaderboard, a locked Silk Road hero, the quest, and only then "Your plan for today". On a computer the plan is already first (left column).
   - **Pick:** on phones, show the plan first, then a short tracker strip (streak, minutes, XP). Leaderboard and hero move to Play. The full stats move to a "Me" page under the avatar.
   - Or: plan first, everything else stays where it is.
   - Or: leave it as it is.
2. **Three ways to log study, and they contradict each other.** The tracker has "Log your day" and "+ Activity", the plan has "Count today as a study day → Log", and the plan also says "min counted automatically — no need to log it".
   - **Pick:** count automatically only, and keep "+ Activity" for study outside the app (a class, a book) under the avatar menu.
   - Or: keep one Log button in the plan.
3. **Three guides compete:** the tracker's "Today's new thing", the stage line under the plan, and the Your stages page (plus the beta notes).
   - **Pick:** one daily card, "Today's new thing", that follows the stage the student is at. The stages page stays for students who want the full list.
   - Note: "Today's new thing" offers Review at stage 1 but Review opens at stage 2. The app now explains this; the tracker list (`NEW_THINGS` in `sawa-tracker/assets/app-en.js` and `app.js`) should say stage 2.
4. **Read a text and Scan a page are two Learn tiles that do the same thing** (get English text, then tap words), and two plan steps ("Save 3 new words" → Scan, "Read something and save new words" → Read) are finished by the same action.
   - **Pick:** one "Read" tile with three inputs (paste, PDF, photo), and one plan step "Read and save 3 words".
   - Or: keep both tiles but put one plan step.
5. **Features students can't reach or do twice:**
   - **Video quizzes (Watch)** are built, but no stage or tile opens them in `/app` and the plan no longer adds them (`today_plan` loads `$lesson` but never uses it). Pick: a "Watch" tile in Learn at stage 1. Or remove it from the app and keep the quizzes in Language Lens only.
   - **Two level tests:** the quick check (8 min) and the full test, both at `/placement/`. Pick: keep the quick check in stage 1 and offer the full test once a month (it matches "Monthly before/after").
   - **Two speaking checks:** Write → Speak, and a voice note in Chat → "رأي في كلامي". Both are fine, but the stage-4 step should name only one. Pick: Speak.
   - **Two feedback entries:** the Beta button and the Feedback & Dev tile. Pick: keep both while in beta, and merge them after launch.
   - **Language Lens (computer) vs the app:** review cards, the proofreader vs Write, and the Arabic/English setting are in both. They share one account and sync, so the student doesn't do anything twice; they just see two doors. No change, but say so once in Your stages.
6. **The app is in English and the language switch hides in Learn.** Arabic names now show when العربية is on, but a new student doesn't know the switch exists. Pick: ask once on the first day ("Explanations in English or العربية?") and put the switch under the avatar.
