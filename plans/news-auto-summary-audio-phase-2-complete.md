## Phase 2 Complete: Language Detection Utility

Language detection system successfully implemented to analyze post content for Malay/English keywords. Detects language automatically or respects admin preference. Provides robust detection with word-boundary matching, content sanitization, and appropriate fallbacks.

**Files created/changed:**
- modules/news.utm.my.php

**Functions created/changed:**
- utm_news_detect_language()
- utm_news_get_summary_language()

**Tests created/changed:**
- Test Malay keyword detection (adalah, untuk, dengan, etc.)
- Test English keyword detection (the, is, and, etc.)
- Test returns 'unknown' with insufficient keywords
- Test HTML tag and shortcode stripping
- Test case-insensitive matching
- Test auto-detect mode
- Test respects 'ms' preference
- Test respects 'en' preference
- Test fallback to 'en' when unknown
- Test keyword threshold (minimum 3)
- Test language comparison logic

**Review Status:** APPROVED

**Git Commit Message:**
feat: Add language detection utility for auto-summary

- Implement keyword-based language detection for Malay and English
- Add 20 common keywords per language for accurate detection
- Use word-boundary regex to avoid substring false positives
- Strip HTML tags and shortcodes before analysis
- Respect admin language preference or auto-detect
- Fallback to English when detection uncertain
- Include case-insensitive matching with minimum threshold of 3 keywords
