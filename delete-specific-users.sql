-- Delete specific test users from OJS production database
-- Users to delete: alexis_reviewer, river-reviewer, alexis-the-author, echo-the-editor
--
-- IMPORTANT: Review this script carefully before running!
-- Run each section separately and verify results before proceeding.

-- ============================================
-- STEP 1: Find the user IDs and store them
-- ============================================

-- First, view the users to confirm they're the right ones
SELECT user_id, username, email, date_registered, date_last_login
FROM users
WHERE username IN ('alexis_reviewer', 'river-reviewer', 'alexis-the-author', 'echo-the-editor')
ORDER BY user_id;

-- Store the user IDs in a temporary table for easy reuse
DROP TEMPORARY TABLE IF EXISTS users_to_delete;
CREATE TEMPORARY TABLE users_to_delete AS
SELECT user_id
FROM users
WHERE username IN ('alexis_reviewer', 'river-reviewer', 'alexis-the-author', 'echo-the-editor');

-- Verify what's in the temp table
SELECT * FROM users_to_delete;


-- ============================================
-- STEP 2: Verify these users have no activity
-- ============================================
-- Check submissions, reviews, etc. for these users

SELECT
    u.user_id,
    u.username,
    (SELECT COUNT(*) FROM review_assignments WHERE reviewer_id = u.user_id) as reviews,
    (SELECT COUNT(*) FROM stage_assignments WHERE user_id = u.user_id) as stage_assignments,
    (SELECT COUNT(*) FROM user_user_groups WHERE user_id = u.user_id) as role_assignments,
    (SELECT COUNT(*) FROM email_log_users WHERE user_id = u.user_id) as emails_sent
FROM users u
JOIN users_to_delete utd ON u.user_id = utd.user_id;


-- ============================================
-- STEP 3: Delete the users
-- ============================================
-- Uses the temporary table created in Step 1
-- No manual modification needed!

START TRANSACTION;

DELETE FROM user_settings WHERE user_id IN (SELECT user_id FROM users_to_delete);
DELETE FROM user_user_groups WHERE user_id IN (SELECT user_id FROM users_to_delete);
DELETE FROM user_interests WHERE user_id IN (SELECT user_id FROM users_to_delete);
DELETE FROM stage_assignments WHERE user_id IN (SELECT user_id FROM users_to_delete);
DELETE FROM review_assignments WHERE reviewer_id IN (SELECT user_id FROM users_to_delete);
DELETE FROM notification_subscription_settings WHERE user_id IN (SELECT user_id FROM users_to_delete);
DELETE FROM notification_subscriptions WHERE user_id IN (SELECT user_id FROM users_to_delete);
DELETE FROM notifications WHERE user_id IN (SELECT user_id FROM users_to_delete);
DELETE FROM email_log_users WHERE user_id IN (SELECT user_id FROM users_to_delete);
DELETE FROM access_keys WHERE user_id IN (SELECT user_id FROM users_to_delete);
DELETE FROM sessions WHERE user_id IN (SELECT user_id FROM users_to_delete);
DELETE FROM temporary_files WHERE user_id IN (SELECT user_id FROM users_to_delete);
DELETE FROM users WHERE user_id IN (SELECT user_id FROM users_to_delete);

-- ============================================
-- STEP 4: Verify deletion
-- ============================================

SELECT 'Remaining users with these usernames:' as status;
SELECT user_id, username, email
FROM users
WHERE username IN ('alexis_reviewer', 'river-reviewer', 'alexis-the-author', 'echo-the-editor');

-- Should return 0 rows if deletion was successful


-- ============================================
-- STEP 5: Commit or rollback
-- ============================================

-- If everything looks correct, commit:
COMMIT;

-- If something looks wrong, rollback instead:
-- ROLLBACK;

-- Clean up temporary table
DROP TEMPORARY TABLE IF EXISTS users_to_delete;


-- ============================================
-- QUICK REFERENCE: Template for future use
-- ============================================
-- To delete different users, just modify the usernames in Step 1:
-- WHERE username IN ('user1', 'user2', 'user3')
--
-- Then run all steps sequentially - no other changes needed!
