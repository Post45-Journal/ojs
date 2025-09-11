<?php

/**
 * @file plugins/generic/submissionsOnly/SubmissionsOnlyPlugin.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SubmissionsOnlyPlugin
 *
 * @brief Plugin to hide publishing workflow elements for submissions-only management.
 */

namespace APP\plugins\generic\submissionsOnly;

use APP\core\Application;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class SubmissionsOnlyPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {
                // Hook into template manager to add CSS for admin pages
                Hook::add('TemplateManager::display', $this->handleTemplateDisplay(...));
            }
            return true;
        }
        return false;
    }

    /**
     * Install default settings on context creation.
     *
     * @return string
     */
    public function getContextSpecificPluginSettingsFile()
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * @copydoc Plugin::getDisplayName
     */
    public function getDisplayName()
    {
        return __('plugins.generic.submissionsOnly.name');
    }

    /**
     * @copydoc Plugin::getDescription
     */
    public function getDescription()
    {
        return __('plugins.generic.submissionsOnly.description');
    }

    /**
     * Handle template display to add CSS for admin pages
     *
     * @param string $hookName
     * @param array $args
     */
    public function handleTemplateDisplay($hookName, $args)
    {
        $templateMgr = $args[0];
        $template = $args[1];

        // Check if this is an admin page
        $request = Application::get()->getRequest();
        $requestedPage = $request->getRequestedPage();

        // Add CSS for admin/editorial pages
        if (in_array($requestedPage, ['dashboard', 'workflow', 'management', 'admin', 'manageIssues', 'stats', 'dois', 'issues', 'user', 'submission'])) {
            $templateMgr->addStyleSheet(
                'submissionsOnlyAdmin',
                $request->getBaseUrl() . '/' . $this->getPluginPath() . '/css/admin.css',
                [
                    'contexts' => ['backend']
                ]
            );
        }

        return false;
    }
}
