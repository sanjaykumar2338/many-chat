# Instagram Comment to DM MVP

Simple PHP/MySQL MVP for Randy's Instagram account:

1. Meta sends an Instagram comment webhook
2. The app normalizes the comment text
3. It fetches the parent media caption and extracts a `Comment KEYWORD` prompt
4. It only continues when the comment matches the prompted keyword
5. It matches an active keyword rule and sends one official Meta private reply
6. It logs the attempt and blocks duplicates by `comment_id`

This build uses Meta's official Instagram webhook + private reply flow only.

## What is included

- `webhooks/instagram.php` for Meta verification + comment webhook intake
- caption prompt parsing so replies only fire on intentionally prompted posts
- keyword rule matching with exact and contains modes
- parent media caption fetch before reply decisions
- private reply sender using `/{IG_ID}/messages` and `recipient.comment_id`
- duplicate protection with a unique `comment_id`
- admin login, settings, rules, and event logs
- optional default no-match reply and test mode

## Setup

1. Create a MySQL database.
2. Import the schema:

```bash
mysql -u root -p YOUR_DATABASE_NAME < database/schema.sql
```

If you are upgrading an existing copy of this MVP, run:

```bash
mysql -u root -p YOUR_DATABASE_NAME < database/upgrade_prompted_caption_flow.sql
```

3. Copy `.env.example` to `.env` and fill in the real values.
4. Generate an admin password hash if you want to avoid plain text:

```bash
php -r "echo password_hash('change-me', PASSWORD_DEFAULT), PHP_EOL;"
```

5. Point Apache/XAMPP or a PHP server at this directory.

## Local URLs

- Admin: `/admin/login.php`
- Webhook: `/webhooks/instagram.php`

## Meta app configuration

Use Meta's App Dashboard to:

1. connect the Instagram professional account correctly
2. subscribe the app to `comments` and `live_comments`
3. set the callback URL to `webhooks/instagram.php`
4. set the Verify Token to match `META_VERIFY_TOKEN`
5. make sure the app has the needed permissions for the chosen login flow

For the Instagram Login flow, this MVP expects:

- `META_GRAPH_HOST=https://graph.instagram.com`
- an Instagram user access token
- `META_IG_BUSINESS_ACCOUNT_ID`

If you are using the Facebook Login for Business flow instead, change `META_GRAPH_HOST` to `https://graph.facebook.com` and supply the correct page/account token for that setup.

## Notes

- Rules store `response_type`, but this MVP always sends a plain text private reply. For links or PDFs, put the URL in `response_body`.
- A reply is only allowed when the parent media caption contains a supported prompt such as `Comment PLACE`, `Comment "PLACE"`, or `comment: PLACE`.
- If the caption has no prompt, or prompts a different keyword than the actual comment, the event is logged as `skipped`.
- `test_mode` lets you validate webhooks, matching, and logs without making a live Meta send.
- Duplicate webhook deliveries increment the delivery count and skip a second send.

## Official Meta references

- Private Replies: https://developers.facebook.com/docs/instagram-platform/private-replies/
- Instagram Messaging Webhooks: https://developers.facebook.com/docs/messenger-platform/instagram/features/webhook/
- Messaging API: https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/messaging-api/
- Webhook verification + signatures: https://developers.facebook.com/docs/messenger-platform/webhooks/
