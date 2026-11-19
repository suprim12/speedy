<?php

namespace suprim\speedy\tests\Integration;

use Craft;
use suprim\speedy\tests\TestCase;

/**
 * The settings page shows what’s saved, so saving it again never changes a setting by itself.
 */
class SettingsPageTest extends TestCase
{
    private function page(): string
    {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode($view::TEMPLATE_MODE_CP);
        // The page’s fields publish their scripts, which needs a folder for them: Craft 5.6 has none in the console.
        $assets = $view->getAssetManager();
        [$basePath, $baseUrl] = [$assets->basePath, $assets->baseUrl];
        if (!is_dir((string)$basePath)) {
            $assets->basePath = $this->tmp . '/cpresources';
            $assets->baseUrl = '/cpresources';
            mkdir($assets->basePath, 0775, true);
        }
        try {
            return (string)(fn() => $this->settingsHtml())->call($this->plugin());
        } finally {
            $view->setTemplateMode($mode);
            [$assets->basePath, $assets->baseUrl] = [$basePath, $baseUrl];
        }
    }

    /**
     * The option chosen in a row of the Lifetimes by section table, by the row’s position.
     */
    private function chosen(string $html, string $column): array
    {
        preg_match_all('/<select name="sectionLifetimes\[[^\]]+\]\[' . $column . '\]"[^>]*>(.*?)<\/select>/s', $html, $selects);
        $chosen = [];
        foreach ($selects[1] as $options) {
            $chosen[] = preg_match('/<option value="([^"]*)"[^>]*selected/', $options, $m) ? $m[1] : null;
        }

        return $chosen;
    }

    public function testASectionThatIsGoneKeepsItsRow(): void
    {
        $this->settings()->sectionLifetimes = ['speedyTestGone' => 3600, 'speedyTestOdd' => 123];
        $html = $this->page();

        $this->assertStringContainsString('speedyTestGone (not found)', $html, 'Named as it’s saved.');
        $this->assertSame(['speedyTestGone', 'speedyTestOdd'], $this->chosen($html, 'section'), 'Chosen in its row, so saving keeps it, not another section.');
        $this->assertSame(['3600', '123'], $this->chosen($html, 'lifetime'), 'A lifetime that isn’t one of the choices too.');
    }
}
