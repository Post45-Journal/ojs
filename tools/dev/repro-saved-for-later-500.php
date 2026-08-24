<?php

// Reproduce the "500 on Edit for Submission Saved for Later" from the CLI so we
// can see the actual exception instead of an opaque HTTP 500.
require dirname(dirname(__DIR__)) . '/tools/bootstrap.php';

use APP\core\Application;
use APP\facades\Repo;
use PKP\mail\mailables\SubmissionSavedForLater;

$contextId = 1;

// Give the request a context so dispatcher URL generation works.
$request = Application::get()->getRequest();
$context = Application::getContextDAO()->getById($contextId);
$router = new \PKP\core\PKPPageRouter();
$router->setApplication(Application::get());
$reflection = new ReflectionClass($router);
$prop = $reflection->getProperty('_context');
$prop->setAccessible(true);
$prop->setValue($router, $context);
$request->setRouter($router);
$request->getDispatcher(); // ensure dispatcher initialised

// Generic plugins register against the CURRENT context — CLI has none, so
// re-register post45Editorial so its Mailer::Mailables hook is active.
\PKP\plugins\PluginRegistry::loadCategory('generic', true, $contextId);
foreach (['post45editorialplugin', 'post45editorial', 'Post45EditorialPlugin'] as $name) {
    $p = \PKP\plugins\PluginRegistry::getPlugin('generic', $name);
    if ($p) {
        echo "Registering plugin: {$name}\n";
        $p->register('generic', $p->getPluginPath(), $contextId);
        break;
    }
}

echo "=== Repo::mailable()->getMany(includeConfigurableOnly=true) — what the manageEmails page load calls ===\n";
try {
    $classes = Repo::mailable()->getMany($context, null, false, true);
    echo 'OK: count=' . $classes->count() . "\n";
} catch (\Throwable $e) {
    echo 'FAIL: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
echo "\n";

echo "=== Repo::mailable()->get() (what PKPMailableController::get uses first) ===\n";
try {
    $class = Repo::mailable()->get('SUBMISSION_SAVED_FOR_LATER', $context);
    echo 'OK: class=' . ($class ?? 'NULL') . "\n";
} catch (\Throwable $e) {
    echo 'FAIL: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
echo "\n";

foreach ([
    'summarizeMailable' => fn () => Repo::mailable()->summarizeMailable(SubmissionSavedForLater::class),
    'describeMailable' => fn () => Repo::mailable()->describeMailable(SubmissionSavedForLater::class, $contextId),
] as $label => $call) {
    echo "=== {$label} ===\n";
    try {
        $data = $call();
        echo 'OK: keys=' . implode(',', array_keys($data)) . "\n";
        $json = json_encode($data);
        if ($json === false) {
            echo '  JSON ENCODE FAIL: ' . json_last_error_msg() . "\n";
            echo "  raw dump:\n";
            var_export($data);
            echo "\n";
        } else {
            echo '  json length=' . strlen($json) . "\n";
            if ($label === 'describeMailable') {
                echo '  emailTemplates count=' . count($data['emailTemplates'] ?? []) . "\n";
                foreach ($data['emailTemplates'] ?? [] as $i => $tpl) {
                    echo "  template[{$i}]: keys=" . implode(',', array_keys((array) $tpl)) . "\n";
                }
            }
        }
    } catch (\Throwable $e) {
        echo 'FAIL: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
    }
    echo "\n";
}

echo "=== Repo::emailTemplate()->getByKey (what PKPEmailTemplateController::get uses) ===\n";
try {
    $tpl = Repo::emailTemplate()->getByKey($contextId, 'SUBMISSION_SAVED_FOR_LATER');
    if (!$tpl) {
        echo "NULL — would produce 404, not 500\n";
    } else {
        $mapped = Repo::emailTemplate()->getSchemaMap()->map($tpl);
        $json = json_encode($mapped);
        echo 'OK, json length=' . strlen((string) $json) . ($json === false ? ' ENCODE FAIL: ' . json_last_error_msg() : '') . "\n";
    }
} catch (\Throwable $e) {
    echo 'FAIL: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
