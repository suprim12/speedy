<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use suprim\speedy\Plugin;

/**
 * Clears the cached pages that show the selected elements, from an element index.
 */
class ClearPages extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('speedy', 'Clear cached pages');
    }

    public function getTriggerLabel(): string
    {
        return self::displayName();
    }

    /**
     * Whether the current user may clear cached pages: with Speedy’s utility, or the permission to clear pages alone.
     */
    public static function allowed(): bool
    {
        $user = Craft::$app->getUser();

        return $user->checkPermission('utility:speedy') || $user->checkPermission(Plugin::PERMISSION_CLEAR);
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        if (!self::allowed()) {
            $this->setMessage(Craft::t('speedy', 'You can’t clear cached pages.'));
            return false;
        }
        $ids = $query->status(null)->ids();
        $count = Plugin::getInstance()->clearer->clearTags(
            array_map(fn($id) => "element::$id", $ids),
            Craft::t('speedy', 'Cleared from the element index by {user}', ['user' => (string)Craft::$app->getUser()->getIdentity()]),
        );
        $this->setMessage(Craft::t('speedy', '{count, plural, =0{No cached pages show these.} =1{# page cleared.} other{# pages cleared.}}', ['count' => $count]));

        return true;
    }
}
