<?php

/**
 * @file classes/core/AppServiceProvider.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2003-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AppServiceProvider
 *
 * @brief Resolves requests for application classes such as the request handler
 *   to support dependency injection
 */

namespace APP\core;

use APP\services\ContextService;
use APP\services\NavigationMenuService;
use APP\services\StatsEditorialService;
use APP\services\StatsIssueService;
use APP\services\StatsPublicationService;
use PKP\core\PKPRequest;

class AppServiceProvider extends \PKP\core\AppServiceProvider
{
    /**
     * @copydoc \PKP\core\AppServiceProvider::register()
     */
    public function register()
    {
        parent::register();

        $this->app->bind(Request::class, PKPRequest::class);

        // Bind the log ContextLogProcessor contract to its concrete implementation.
        // Laravel's LogManager::get() resolves this contract when building ANY log
        // channel's logger, but the binding is normally registered by Laravel's
        // Illuminate\Log\Context\ContextServiceProvider — which OJS's hand-built
        // container does not load. Without it, resolving a log channel throws a
        // BindingResolutionException, the emergency-logger fallback then dies on the
        // undefined PKPContainer::storagePath(), and the request 500s. This surfaces
        // most visibly with the `log` mailer ([email] default = log), which resolves
        // a log channel when rendering an editorial decision's email step.
        // See: pkp-lib LogManager.php / ContextServiceProvider.php.
        $this->app->bind(
            \Illuminate\Contracts\Log\ContextLogProcessor::class,
            \Illuminate\Log\Context\ContextLogProcessor::class
        );

        // Navigation Menu service
        $this->app->singleton('navigationMenu', fn ($app) => new NavigationMenuService());

        // Context service
        $this->app->singleton('context', fn ($app) => new ContextService());

        // Publication statistics service
        $this->app->singleton('publicationStats', fn ($app) => new StatsPublicationService());

        // Issue statistics service
        $this->app->singleton('issueStats', fn ($app) => new StatsIssueService());

        // Editorial statistics service
        $this->app->singleton('editorialStats', fn ($app) => new StatsEditorialService());
    }
}
