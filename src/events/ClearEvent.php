<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\events;

use yii\base\Event;

/**
 * After pages are cleared.
 */
class ClearEvent extends Event
{
    /** Why, as shown in Recent clears. */
    public string $reason = '';

    /** How many pages. */
    public int $count = 0;

    /** @var string[] The pages’ URLs. Empty when everything was cleared. */
    public array $urls = [];

    /** Whether everything was cleared. */
    public bool $all = false;
}
