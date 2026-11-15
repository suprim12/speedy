<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\events;

use yii\base\ModelEvent;

/**
 * Before a page is cached. Set `isValid` to false to skip it, or change the HTML or tags.
 */
class CachePageEvent extends ModelEvent
{
    /** The page’s key, such as `example.com/news`. */
    public string $key = '';

    public string $url = '';

    /** The HTML that will be saved. */
    public string $html = '';

    /** @var string[] Craft cache tags the page depends on. Add your own to clear it with Clearer::clearTags(). */
    public array $tags = [];

    /** When the page expires, as a Unix time, or null. */
    public ?int $expires = null;
}
