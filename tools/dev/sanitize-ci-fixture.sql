--
-- Rewrite identity-carrying + free-text fields in a scratch copy of ojs_test
-- before dumping it as the CI fixture. Preserves every id/FK; strips names,
-- emails, urls, biographies, titles, abstracts, journal-config free text.
--
-- Applied by tools/dev/dump-ci-fixture.sh against an ojs_test_ci scratch
-- schema. NEVER run against ojs_test itself or the dev DB.
--
-- Scope note: the integration tests never assert on any pre-seeded identity
-- or free-text values — they either read structural fields (status, sectionId,
-- publicationUrl) or create their own entities (users, CFPs, sections) inside
-- setUp. So blanket-rewriting titles/abstracts/journal names to synthetic
-- placeholders is safe.
--

-- Users: username + email keyed off the numeric id, wipe url + free text.
UPDATE users
   SET username = CONCAT('ci_user_', user_id),
       email    = CONCAT('ci_user_', user_id, '@example.test'),
       url      = '';

-- User settings: identity-carrying entries → synthetic per-id values;
-- free-text bios/notes/gossip → empty. Everything else (locale, timezone,
-- role prefs) stays intact.
UPDATE user_settings
   SET setting_value = CASE setting_name
       WHEN 'givenName'           THEN CONCAT('Test', user_id)
       WHEN 'familyName'          THEN CONCAT('User', user_id)
       WHEN 'preferredPublicName' THEN CONCAT('Test User ', user_id)
       WHEN 'affiliation'         THEN 'Example University'
       WHEN 'biography'           THEN ''
       WHEN 'signature'           THEN ''
       WHEN 'orcid'               THEN ''
       WHEN 'phone'               THEN ''
       WHEN 'mailingAddress'      THEN ''
       WHEN 'billingAddress'      THEN ''
       WHEN 'gossip'              THEN ''
       WHEN 'interests'           THEN ''
       ELSE setting_value
   END
 WHERE setting_name IN (
       'givenName', 'familyName', 'preferredPublicName',
       'affiliation', 'biography', 'signature', 'orcid', 'phone',
       'mailingAddress', 'billingAddress', 'gossip', 'interests'
 );

-- Authors on publications carry their own copies of name + email.
UPDATE authors
   SET email = CONCAT('ci_author_', author_id, '@example.test');

UPDATE author_settings
   SET setting_value = CASE setting_name
       WHEN 'givenName'           THEN CONCAT('Test', author_id)
       WHEN 'familyName'          THEN CONCAT('Author', author_id)
       WHEN 'preferredPublicName' THEN CONCAT('Test Author ', author_id)
       WHEN 'affiliation'         THEN 'Example University'
       WHEN 'biography'           THEN ''
       WHEN 'orcid'               THEN ''
       ELSE setting_value
   END
 WHERE setting_name IN (
       'givenName', 'familyName', 'preferredPublicName',
       'affiliation', 'biography', 'orcid'
 );

-- Publication free text: titles, abstracts, subtitles, prefix, coverage,
-- disciplines, keywords, subjects — all could quote real research. Replace
-- with a per-publication placeholder.
UPDATE publication_settings
   SET setting_value = CASE setting_name
       WHEN 'title'      THEN CONCAT('Test Publication ', publication_id)
       WHEN 'abstract'   THEN CONCAT('<p>Placeholder abstract for publication ', publication_id, '.</p>')
       WHEN 'subtitle'   THEN ''
       WHEN 'prefix'     THEN ''
       WHEN 'coverage'   THEN ''
       WHEN 'dataAvailability' THEN ''
       WHEN 'disciplines' THEN ''
       WHEN 'keywords'   THEN ''
       WHEN 'subjects'   THEN ''
       WHEN 'agencies'   THEN ''
       WHEN 'copyrightHolder' THEN ''
       WHEN 'copyrightNotice' THEN ''
       WHEN 'coverImage' THEN ''
       WHEN 'publicationUrl' THEN CONCAT('https://example.test/publication/', publication_id)
       WHEN 'rights'     THEN ''
       WHEN 'source'     THEN ''
       WHEN 'type'       THEN ''
       ELSE setting_value
   END
 WHERE setting_name IN (
       'title', 'abstract', 'subtitle', 'prefix', 'coverage',
       'dataAvailability', 'disciplines', 'keywords', 'subjects',
       'agencies', 'copyrightHolder', 'copyrightNotice', 'coverImage',
       'publicationUrl', 'rights', 'source', 'type'
 );

-- Section labels: strip real journal section policy/title text.
UPDATE section_settings
   SET setting_value = CASE setting_name
       WHEN 'title'        THEN CONCAT('Test Section ', section_id)
       WHEN 'abbrev'       THEN CONCAT('S', section_id)
       WHEN 'identifyType' THEN ''
       WHEN 'policy'       THEN ''
       WHEN 'description'  THEN ''
       ELSE setting_value
   END
 WHERE setting_name IN ('title', 'abbrev', 'identifyType', 'policy', 'description');

-- Journal-level config: real journal name, contact info, editorial masthead,
-- author guidelines, policies. Blank the free-text keys and rewrite contact
-- fields to synthetic values.
UPDATE journal_settings
   SET setting_value = CASE
       WHEN setting_name = 'name'                 THEN CONCAT('Test Journal ', journal_id)
       WHEN setting_name = 'acronym'              THEN CONCAT('TJ', journal_id)
       WHEN setting_name = 'description'          THEN '<p>Test journal fixture.</p>'
       WHEN setting_name = 'about'                THEN ''
       WHEN setting_name = 'authorGuidelines'     THEN ''
       WHEN setting_name = 'authorInformation'    THEN ''
       WHEN setting_name = 'beginSubmissionHelp'  THEN ''
       WHEN setting_name = 'contributorsHelp'     THEN ''
       WHEN setting_name = 'copyrightNotice'      THEN ''
       WHEN setting_name = 'detailsHelp'          THEN ''
       WHEN setting_name = 'forTheEditorsHelp'    THEN ''
       WHEN setting_name = 'librarianInformation' THEN ''
       WHEN setting_name = 'openAccessPolicy'     THEN ''
       WHEN setting_name = 'privacyStatement'     THEN ''
       WHEN setting_name = 'readerInformation'    THEN ''
       WHEN setting_name = 'reviewGuidelines'     THEN ''
       WHEN setting_name = 'reviewHelp'           THEN ''
       WHEN setting_name = 'submissionChecklist'  THEN ''
       WHEN setting_name = 'uploadFilesHelp'      THEN ''
       WHEN setting_name = 'contactEmail'         THEN 'contact@example.test'
       WHEN setting_name = 'contactName'          THEN 'Test Contact'
       WHEN setting_name = 'supportEmail'         THEN 'support@example.test'
       WHEN setting_name = 'supportName'          THEN 'Test Support'
       WHEN setting_name = 'envelopeSender'       THEN 'noreply@example.test'
       WHEN setting_name = 'emailSignature'       THEN ''
       WHEN setting_name = 'publisherInstitution' THEN 'Example Publisher'
       WHEN setting_name = 'publisherUrl'         THEN 'https://example.test'
       WHEN setting_name = 'onlineIssn'           THEN ''
       WHEN setting_name = 'printIssn'            THEN ''
       WHEN setting_name = 'mailingAddress'       THEN ''
       ELSE setting_value
   END
 WHERE setting_name IN (
       'name', 'acronym', 'description', 'about',
       'authorGuidelines', 'authorInformation', 'beginSubmissionHelp',
       'contributorsHelp', 'copyrightNotice', 'detailsHelp',
       'forTheEditorsHelp', 'librarianInformation', 'openAccessPolicy',
       'privacyStatement', 'readerInformation', 'reviewGuidelines',
       'reviewHelp', 'submissionChecklist', 'uploadFilesHelp',
       'contactEmail', 'contactName', 'supportEmail', 'supportName',
       'envelopeSender', 'emailSignature', 'publisherInstitution',
       'publisherUrl', 'onlineIssn', 'printIssn', 'mailingAddress'
 );

-- Navigation menu items may contain journal-specific link text/urls.
UPDATE navigation_menu_item_settings
   SET setting_value = CASE
       WHEN setting_name = 'title' THEN CONCAT('Test Menu ', navigation_menu_item_id)
       WHEN setting_name IN ('content', 'remoteUrl') THEN ''
       ELSE setting_value
   END
 WHERE setting_name IN ('title', 'content', 'remoteUrl');

-- Site-level settings can carry publisher / support contact info.
UPDATE site_settings
   SET setting_value = CASE setting_name
       WHEN 'contactEmail' THEN 'contact@example.test'
       WHEN 'contactName'  THEN 'Test Contact'
       WHEN 'title'        THEN 'Test Site'
       WHEN 'about'        THEN ''
       ELSE setting_value
   END
 WHERE setting_name IN ('contactEmail', 'contactName', 'title', 'about');

-- Announcements: title + description text often journal-specific.
UPDATE announcement_settings
   SET setting_value = CASE setting_name
       WHEN 'title'            THEN CONCAT('Test Announcement ', announcement_id)
       WHEN 'descriptionShort' THEN ''
       WHEN 'description'      THEN ''
       ELSE setting_value
   END
 WHERE setting_name IN ('title', 'descriptionShort', 'description');

-- Issue metadata: title, description, cover text.
UPDATE issue_settings
   SET setting_value = CASE setting_name
       WHEN 'title'       THEN CONCAT('Test Issue ', issue_id)
       WHEN 'description' THEN ''
       WHEN 'coverImage'  THEN ''
       ELSE setting_value
   END
 WHERE setting_name IN ('title', 'description', 'coverImage');

-- Submission files can carry uploader-supplied filenames.
UPDATE submission_file_settings
   SET setting_value = CASE setting_name
       WHEN 'name' THEN CONCAT('test-file-', submission_file_id, '.docx')
       ELSE setting_value
   END
 WHERE setting_name = 'name';

-- Tables whose data carries PII in body text but whose schema is needed
-- because tests INSERT into them (or the framework does on their behalf).
-- Truncate rather than drop so the schema survives. FK checks disabled to
-- avoid parent/child ordering headaches.
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE notes;
TRUNCATE TABLE notification_settings;
TRUNCATE TABLE notification_subscription_settings;
TRUNCATE TABLE notifications;
TRUNCATE TABLE email_log_users;
TRUNCATE TABLE email_log;
TRUNCATE TABLE event_log_settings;
TRUNCATE TABLE event_log;
TRUNCATE TABLE sessions;
TRUNCATE TABLE failed_jobs;
TRUNCATE TABLE jobs;
SET FOREIGN_KEY_CHECKS = 1;

-- Plugin settings can store deployment-specific values (mailgun archive
-- bcc, provider API keys). Wipe anything that looks like an email address
-- or credential-shaped string.
UPDATE plugin_settings
   SET setting_value = ''
 WHERE setting_name IN ('archiveBcc', 'apiKey', 'apiSecret', 'accessKey',
       'accessSecret', 'clientId', 'clientSecret', 'password', 'token',
       'senderEmail', 'fromEmail', 'replyToEmail');

-- The `notes` and `email_log` tables (editorial discussion + logged outbound
-- mail bodies) may quote real emails; both are excluded from the fixture
-- dump entirely (see dump-ci-fixture.sh EXCLUDES). No sanitization needed.
