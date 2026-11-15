<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\events;

use craft\events\CancelableEvent;
use craft\web\Request;

/**
 * Before a request is served from the cache or built for it. Set `isValid` to false to build this request without
 * caching it, such as for an A/B test or a visitor’s region.
 */
class CacheableRequestEvent extends CancelableEvent
{
    public Request $request;

    /** The page’s cache key, such as `example.com/news`. */
    public string $key = '';
}
