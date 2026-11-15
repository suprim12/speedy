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
     * Clears this page after a number of seconds, for content from outside Craft: `{% do craft.speedy.ttl(600) %}`.
     */
    public function ttl(int $seconds): void
    {
        Plugin::getInstance()->cacher->ttl($seconds);
    }
}
