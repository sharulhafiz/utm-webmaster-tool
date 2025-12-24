## Phase 4 Complete: Text-to-Speech Audio Generation

Text-to-speech audio generation successfully implemented using ElevenLabs API. Automatically generates MP3 audio files from AI summaries with language-appropriate voices. Audio saved to WordPress media library and attached to posts with comprehensive error handling.

**Files created/changed:**
- modules/news.utm.my.php

**Functions created/changed:**
- utm_news_generate_tts_audio()
- utm_news_call_elevenlabs_api()
- utm_news_save_audio_to_media()

**Tests created/changed:**
- Test TTS triggered after summary generation
- Test respects existing audio attachments
- Test force regeneration
- Test ElevenLabs API endpoint and authentication
- Test language-specific voice selection (Malay/English)
- Test MP3 audio with proper MIME type
- Test media library attachment creation
- Test _audio_attachment_id meta saved
- Test temporary file cleanup
- Test error logging for TTS failures
- Test empty API key handling
- Test invalid audio data handling

**Review Status:** APPROVED

**Git Commit Message:**
feat: Add TTS audio generation with ElevenLabs API

- Auto-generate MP3 audio from AI summaries using ElevenLabs
- Language-aware voice selection (Malay/English voices)
- Use eleven_multilingual_v2 model for quality
- Save audio to WordPress media library
- Attach audio to posts with _audio_attachment_id meta
- Proper temp file cleanup with error handling
- Comprehensive API error logging
- Integration with Phase 3 summary generation
