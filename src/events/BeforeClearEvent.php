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

/**
 * Before pages are cleared. Change `keys` to clear other pages, or set `isValid` to false to keep them.
 */
class BeforeClearEvent extends CancelableEvent
{
    public string $reason = '';

    /** @var string[] The pages’ keys, such as `example.com/news`. Empty when everything is being cleared. */
    public array $keys = [];

    /** Whether every page is being cleared. */
    public bool $all = false;
}
