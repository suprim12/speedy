<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use craft\helpers\Json;
use suprim\speedy\tests\TestCase;

class DynamicTest extends TestCase
{
    private string $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->template = '_speedy-tests/part-' . bin2hex(random_bytes(3));
        $file = Craft::getAlias('@templates/' . $this->template . '.twig');
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, 'Hello {{ name }}');
    }

    protected function tearDown(): void
    {
        $dir = Craft::getAlias('@templates/_speedy-tests');
        foreach (glob("$dir/*") ?: [] as $f) {
            unlink($f);
        }
        @rmdir($dir);
        parent::tearDown();
    }

    private function token(string $template, array $variables): string
    {
        return Craft::$app->getSecurity()->hashData(Json::encode(['t' => $template, 'v' => $variables, 's' => Craft::$app->getSites()->getPrimarySite()->id]));
    }

    public function testRendersASignedPart(): void
    {
        $html = $this->plugin()->dynamic->render($this->token($this->template, ['name' => 'Alex']));
        $this->assertStringContainsString('Hello Alex', (string)$html);
    }

    /**
     * Visitors can’t ask for templates the site didn’t place, or change the variables.
     */
    public function testRefusesTamperedOrUnknownTokens(): void
    {
        $dynamic = $this->plugin()->dynamic;
        $token = $this->token($this->template, ['name' => 'Alex']);

        $this->assertNull($dynamic->render(str_replace('Alex', 'Eve', $token)));
        $this->assertNull($dynamic->render('not-a-token'));
        $this->assertNull($dynamic->render(Json::encode(['t' => $this->template, 'v' => []])));
        $this->assertNull($dynamic->render($this->token('_speedy-tests/does-not-exist', [])));
    }
}
