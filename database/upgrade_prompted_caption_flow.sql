ALTER TABLE instagram_comment_events
    ADD COLUMN IF NOT EXISTS caption_text TEXT NULL AFTER normalized_comment_text,
    ADD COLUMN IF NOT EXISTS prompt_keyword VARCHAR(191) NULL AFTER caption_text,
    ADD COLUMN IF NOT EXISTS skip_reason VARCHAR(100) NULL AFTER matched_keyword;
