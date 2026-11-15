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
 * Before the CDN is cleared (change `urls`, such as to add another domain, or set `isValid` to false), and after
 * (`results`, by CDN).
 */
class PurgeEvent extends CancelableEvent
{
    /** @var string[] Empty when everything is being cleared. */
    public array $urls = [];

    /** Whether everything is being cleared. */
    public bool $all = false;

    /** @var array<string, string> After clearing: each CDN’s answer, such as `['cloudflare' => 'cleared 3 URLs']`. */
    public array $results = [];
}
