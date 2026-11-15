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
 * Before pages are queued to be built (change `urls`, or set `isValid` to false), and after they’re built (`built`
 * and `failed`).
 */
class WarmEvent extends CancelableEvent
{
    /** @var string[] */
    public array $urls = [];

    /** Whether the pages are replacing cached copies, rather than being built for the first time. */
    public bool $rebuild = false;

    /** @var string[] After building: the pages that were built. */
    public array $built = [];

    /** @var string[] After building: the pages that couldn’t be. */
    public array $failed = [];
}
