## Phase 1 Complete: API Configuration & Settings

Admin settings page successfully created for storing OpenAI and ElevenLabs API keys with language preference options (auto-detect, Malay, English). Settings are securely saved with proper validation, nonce verification, and dedicated error logging to module-specific log file.

**Files created/changed:**
- modules/news.utm.my.php

**Functions created/changed:**
- utm_news_log_error()
- utm_news_register_settings_menu()
- utm_news_settings_page()
- utm_news_save_settings()

**Tests created/changed:**
- Test settings menu registered under Settings
- Test settings page renders with API key fields
- Test language preference dropdown (auto/ms/en)
- Test API keys saved to wp_options
- Test admin-only access
- Test error logging with timestamps
- Test success feedback display
- Test error handling in log function

**Review Status:** APPROVED

**Git Commit Message:**
feat: Add API settings page for AI summary and TTS features

- Create admin settings page under Settings > News Settings
- Add secure storage for OpenAI and ElevenLabs API keys
- Implement language preference dropdown (auto-detect/Malay/English)
- Add dedicated error logging to modules/news.utm.my-errors.log
- Implement nonce verification and capability checks
- Add success feedback messages for users
- Include error handling with LOCK_EX for safe concurrent writes
