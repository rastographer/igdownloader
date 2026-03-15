# Changelog

## 0.1.1 - 2026-03-16

- Added URL parsing support for Instagram story, story highlight, and profile URLs.
- Added `StoryStrategy` to resolve story and highlight media from public Instagram pages.
- Added `ProfileStrategy` to resolve profile-picture media from public Instagram profile pages.
- Added `InstagramHtmlMediaExtractor` to share HTML and JSON payload extraction for new URL types.
- Updated strategy registration so older published configs can still pick up new built-in strategies safely.
- Expanded package tests to cover story, highlight, and profile fetch flows.
- Updated the README to document the new URL support and test harness usage.
