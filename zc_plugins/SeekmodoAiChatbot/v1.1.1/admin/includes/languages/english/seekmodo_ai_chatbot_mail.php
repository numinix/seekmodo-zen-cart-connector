<?php
/**
 * Admin language for Support Ticket Mail settings.
 */
if (!defined('BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL')) {
    define('BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL', 'Support Ticket Mail');
}
if (!defined('BOX_TOOLS_SEEKMODO_AI_CHATBOT_MAIL')) {
    define('BOX_TOOLS_SEEKMODO_AI_CHATBOT_MAIL', BOX_CONFIGURATION_SEEKMODO_AI_CHATBOT_MAIL);
}
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_HEADING', 'Support Ticket Mail');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_SUBTITLE', 'IMAP inbox for inbound replies and SMTP for sending ticket emails from the same mailbox.');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_SAVED', 'Mail settings saved.');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_TOKEN_ERROR', 'Security token mismatch. Please try again.');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_ENABLED', 'Enable mailbox');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED', 'Poll new emails and open a ticket');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_CREATE_UNMATCHED_HELP', 'Off by default. Applies only to email that has no ticket number. Replies that already include the ticket number stay on that ticket.');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_ADDRESS', 'Mailbox email address');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_IMAP', 'Incoming mail (IMAP)');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_SMTP', 'Outgoing mail (SMTP)');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_HOST', 'Host');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_PORT', 'Port');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_ENCRYPTION', 'Encryption');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_FOLDER', 'Folder');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_USER', 'Username');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_PASSWORD', 'Password');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_PASSWORD_KEEP', 'Leave blank to keep the current password.');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_SAVE', 'Save');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_TEST_IMAP', 'Test IMAP');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_TEST_SMTP', 'Test SMTP');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_POLL_NOW', 'Poll inbox now');
define('TEXT_SEEKMODO_AI_CHATBOT_MAIL_CRON_HELP', 'Add a cron job every 1–5 minutes: php zc_plugins/SeekmodoAiChatbot/v1.1.0/tools/poll_inbound_mail.php --catalog=/path/to/catalog --host=www.example.com');
