<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use suprim\speedy\elements\actions\ClearPages;
use suprim\speedy\Plugin;
use suprim\speedy\tests\TestCase;

/**
 * Who may clear cached pages from element indexes: anyone with Speedy’s utility, or the permission on its own.
 */
class PermissionsTest extends TestCase
{
    private const USER_ID = 987654321;

    private ?\yii\web\IdentityInterface $identity = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identity = Craft::$app->getUser()->getIdentity();
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity($this->identity);
        $this->permissions(null);
        parent::tearDown();
    }

    /**
     * Gives the made-up user these permissions, without the database.
     *
     * @param string[]|null $permissions
     */
    private function permissions(?array $permissions): void
    {
        $service = Craft::$app->getUserPermissions();
        $cache = new \ReflectionProperty($service, '_permissionsByUserId');
        $all = $cache->getValue($service);
        if ($permissions === null) {
            unset($all[self::USER_ID]);
        } else {
            $all[self::USER_ID] = $permissions;
        }
        $cache->setValue($service, $all);
    }

    /**
     * Craft Solo has one account, which may do everything.
     */
    private function solo(): bool
    {
        return Craft::$app->edition === \craft\enums\CmsEdition::Solo;
    }

    private function signIn(bool $admin = false): void
    {
        Craft::$app->getUser()->setIdentity(new User(['id' => self::USER_ID, 'admin' => $admin]));
    }

    public function testThePermissionIsListed(): void
    {
        $names = [];
        foreach (Craft::$app->getUserPermissions()->getAllPermissions() as $group) {
            $names = array_merge($names, array_keys($group['permissions']));
        }
        $this->assertContains(Plugin::PERMISSION_CLEAR, $names);
    }

    public function testClearingNeedsTheUtilityOrThePermission(): void
    {
        $this->signIn();
        $this->permissions([]);
        if ($this->solo()) {
            $this->assertTrue(ClearPages::allowed(), 'Craft Solo: the one account may do everything.');
            return;
        }
        $this->assertFalse(ClearPages::allowed(), 'An editor without either.');

        $this->permissions([Plugin::PERMISSION_CLEAR]);
        $this->assertTrue(ClearPages::allowed(), 'The permission on its own.');

        $this->permissions(['utility:speedy']);
        $this->assertTrue(ClearPages::allowed(), 'People with the utility keep clearing, as before.');

        $this->signIn(true);
        $this->assertTrue(ClearPages::allowed(), 'Admins.');
    }

    public function testTheActionRefusesWithoutIt(): void
    {
        $this->signIn();
        $this->permissions([]);
        if ($this->solo()) {
            $this->assertTrue(ClearPages::allowed(), 'Craft Solo: the one account may do everything.');
            return;
        }
        $action = new ClearPages();
        $this->assertFalse($action->performAction(Entry::find()->id(0)));
        $this->assertSame('You can’t clear cached pages.', $action->getMessage());
    }
}
