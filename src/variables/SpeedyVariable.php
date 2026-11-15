<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\variables;

use suprim\speedy\Plugin;
use Twig\Markup;

/**
 * `craft.speedy` in templates.
 */
class SpeedyVariable
{
    /**
     * Stops this page being cached: `{% do craft.speedy.skip() %}`.
     */
    public function skip(): void
    {
        Plugin::getInstance()->cacher->skip();
    }

    /**
     * A part of the page rendered for each visitor, such as a cart count, loaded after the cached page:
     * `{{ craft.speedy.dynamic('_partials/cart') }}`.
     *
     * @param array<string, mixed> $variables
     * @param array{placeholder?: string} $options
     */
    public function dynamic(string $template, array $variables = [], array $options = []): Markup
    {
        return Plugin::getInstance()->dynamic->include($template, $variables, $options);
    }

    /**
     * Names this page so it can be cleared by name, such as when data from outside Craft changes:
     * `{% do craft.speedy.tag('weather') %}`, then `php craft speedy/clear --tag=weather`.
     */
    public function tag(string ...$names): void
    {
        Plugin::getInstance()->cacher->tag(...$names);
    }

    /**
     * Clears this page after a number of seconds, for content from outside Craft: `{% do craft.speedy.ttl(600) %}`.
     */
    public function ttl(int $seconds): void
    {
        Plugin::getInstance()->cacher->ttl($seconds);
    }
}
