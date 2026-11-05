<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Behat\Config\Config;
use Behat\Config\Extension;
use Behat\Config\GherkinOptions;
use Behat\Config\Filter\TagFilter;
use Behat\Config\Profile;
use PhpBuiltin\Server;

return (new Config())
    ->withProfile((new Profile('default'))
        ->withExtension(new Extension(Server::class))
        ->withGherkinOptions((new GherkinOptions())->withFilter(new TagFilter('~@diagnostic-crash&&~@xdebug-persistent'))))
    ->withProfile((new Profile('diagnostic'))
        ->withGherkinOptions((new GherkinOptions())->withFilter(new TagFilter('@diagnostic-crash'))))
    ->withProfile((new Profile('xdebug-persistent'))
        ->withGherkinOptions((new GherkinOptions())->withFilter(new TagFilter('@xdebug-persistent'))))
;
