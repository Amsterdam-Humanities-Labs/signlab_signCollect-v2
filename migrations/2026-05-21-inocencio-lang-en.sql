-- Inocencio prefers the English UI by default. Everyone else keeps their
-- current `users.lang` value (which defaults to 'nl' historically).
UPDATE users SET lang = 'en' WHERE user = 'inocencio';

SELECT user, lang FROM users WHERE user IN ('inocencio', 'gomer');
