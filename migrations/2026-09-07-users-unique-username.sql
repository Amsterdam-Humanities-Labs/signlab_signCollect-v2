-- 2026-09-07 - one row per username, enforced by the schema

-- `users` had only a PRIMARY KEY on userId, so nothing stopped the same
-- username existing many times. db/demo-user.sql relies on
-- `ON DUPLICATE KEY UPDATE` to be idempotent, and with no unique key on `user`
-- that clause could never fire: every seed run inserted another row. A demo
-- host had accumulated 17 accounts called "gomer", all admin.
--
-- login_sc.php authenticates on `user` + `pass` and takes the first match, so
-- the duplicates were not a login failure - they were invisible until someone
-- opened the user-management page.

-- 1. Keep the lowest userId per username; it is the one other tables reference.
DELETE u FROM users u
JOIN (
  SELECT user, MIN(userId) AS keep_id
  FROM users
  GROUP BY user
  HAVING COUNT(*) > 1
) d ON u.user = d.user AND u.userId > d.keep_id;

-- 2. Make it impossible to reintroduce. Also makes ON DUPLICATE KEY UPDATE in
--    demo-user.sql behave as its author intended.
ALTER TABLE users ADD UNIQUE KEY uniq_user (user);
