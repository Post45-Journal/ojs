-- OJS Test User Deletion Script
-- For users who have not created submissions, reviews, or other content

-- ============================================
-- STEP 1: Find your test user IDs
-- ============================================
-- Run this first to identify which users to delete:

SELECT user_id, username, email, CONCAT(first_name, ' ', last_name) as full_name, date_registered
FROM users
ORDER BY date_registered DESC
LIMIT 20;

-- Or search by pattern (e.g., all test users):
-- SELECT user_id, username, email
-- FROM users
-- WHERE username LIKE 'test%' OR email LIKE 'test%';


-- ============================================
-- STEP 2: Verify user has no activity
-- ============================================
-- Replace USER_ID with the actual ID from Step 1

SET @check_user = 999;  -- CHANGE THIS

SELECT
    'User Details' as check_type,
    user_id, username, email
FROM users WHERE user_id = @check_user
UNION ALL
SELECT 'Submissions', COUNT(*), NULL, NULL FROM submissions WHERE author_id = @check_user
UNION ALL
SELECT 'Reviews', COUNT(*), NULL, NULL FROM review_assignments WHERE reviewer_id = @check_user
UNION ALL
SELECT 'Stage Assignments', COUNT(*), NULL, NULL FROM stage_assignments WHERE user_id = @check_user
UNION ALL
SELECT 'User Groups', COUNT(*), NULL, NULL FROM user_user_groups WHERE user_id = @check_user;


-- ============================================
-- STEP 3: Delete a single test user
-- ============================================
-- Only run this after verifying the user in Step 2

START TRANSACTION;

SET @uid = 999;  -- CHANGE THIS to your test user ID

-- Delete all related records
DELETE FROM user_settings WHERE user_id = @uid;
DELETE FROM user_user_groups WHERE user_id = @uid;
DELETE FROM user_interests WHERE user_id = @uid;
DELETE FROM stage_assignments WHERE user_id = @uid;
DELETE FROM review_assignments WHERE reviewer_id = @uid;
DELETE FROM notification_subscription_settings WHERE user_id = @uid;
DELETE FROM notification_subscriptions WHERE user_id = @uid;
DELETE FROM notifications WHERE user_id = @uid;
DELETE FROM email_log_users WHERE user_id = @uid;
DELETE FROM access_keys WHERE user_id = @uid;
DELETE FROM sessions WHERE user_id = @uid;
DELETE FROM temporary_files WHERE user_id = @uid;
DELETE FROM users WHERE user_id = @uid;

-- Verify deletion
SELECT 'User deleted?' as status,
       CASE WHEN COUNT(*) = 0 THEN 'YES - User successfully deleted'
            ELSE 'NO - User still exists'
       END as result
FROM users WHERE user_id = @uid;

-- If everything looks good:
COMMIT;

-- If something went wrong:
-- ROLLBACK;


-- ============================================
-- STEP 4: Delete multiple test users at once
-- ============================================
-- Use this if you have several test users to delete
-- Only run after verifying each user individually!

START TRANSACTION;

-- Set your user IDs here (comma-separated)
DELETE FROM user_settings WHERE user_id IN (101, 102, 103);
DELETE FROM user_user_groups WHERE user_id IN (101, 102, 103);
DELETE FROM user_interests WHERE user_id IN (101, 102, 103);
DELETE FROM stage_assignments WHERE user_id IN (101, 102, 103);
DELETE FROM review_assignments WHERE reviewer_id IN (101, 102, 103);
DELETE FROM notification_subscription_settings WHERE user_id IN (101, 102, 103);
DELETE FROM notification_subscriptions WHERE user_id IN (101, 102, 103);
DELETE FROM notifications WHERE user_id IN (101, 102, 103);
DELETE FROM email_log_users WHERE user_id IN (101, 102, 103);
DELETE FROM access_keys WHERE user_id IN (101, 102, 103);
DELETE FROM sessions WHERE user_id IN (101, 102, 103);
DELETE FROM temporary_files WHERE user_id IN (101, 102, 103);
DELETE FROM users WHERE user_id IN (101, 102, 103);

-- Verify all deleted
SELECT COUNT(*) as remaining_users
FROM users
WHERE user_id IN (101, 102, 103);

COMMIT;
-- Or ROLLBACK if needed


-- ============================================
-- OPTIONAL: Create backup before deletion
-- ============================================
-- Run this in bash, not SQL:
-- mysqldump -u root -p ojs users user_settings user_user_groups > user_backup_$(date +%Y%m%d).sql
