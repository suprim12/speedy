<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\events;

use craft\base\ElementInterface;
use craft\events\CancelableEvent;

/**
 * When Craft says some content changed. Change `tags` to clear more or fewer pages, or set `isValid` to false to
 * clear nothing for this change.
 */
class InvalidateEvent extends CancelableEvent
{
    /** The element that changed, when Craft says which. */
    public ?ElementInterface $element = null;

    /** @var string[] The cache tags whose pages will be cleared, such as `element::123`. */
    public array $tags = [];
}
