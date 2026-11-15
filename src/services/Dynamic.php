<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\services;

use Craft;
use craft\base\Component;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;
use suprim\speedy\Plugin;
use Twig\Markup;

/**
 * Parts of a cached page that are built for each visitor, such as a cart count or a form, loaded after the page.
 */
class Dynamic extends Component
{
    /** The most parts one request may ask for. */
    public const MAX_PARTS = 20;

    /**
     * A part of the page that’s rendered for each visitor. On pages that won’t be cached, it’s rendered in place.
     *
     * @param array<string, mixed> $variables Scalars and arrays only. They’re visible in the page source, so never secrets.
     * @param array{placeholder?: string} $options
     */
    public function include(string $template, array $variables = [], array $options = []): Markup
    {
        $view = Craft::$app->getView();
        if (!Plugin::getInstance()->cacher->willCache()) {
            return new Markup($view->renderTemplate($template, $variables, View::TEMPLATE_MODE_SITE), 'UTF-8');
        }

        $token = Craft::$app->getSecurity()->hashData(Json::encode([
            't' => $template,
            'v' => $variables,
            's' => Craft::$app->getSites()->getCurrentSite()->id,
        ]));
        $view->registerJs($this->loaderScript(), View::POS_END, 'speedy-dynamic');

        return new Markup(Html::tag('div', $options['placeholder'] ?? '', ['data-speedy-dynamic' => $token]), 'UTF-8');
    }

    /**
     * Renders a part from its token, with any CSS and JavaScript it registered. Null when the token isn’t valid.
     */
    public function render(string $token): ?string
    {
        $data = Craft::$app->getSecurity()->validateData($token);
        $data = $data !== false ? Json::decodeIfJson($data) : null;
        if (!is_array($data) || !is_string($data['t'] ?? null)) {
            return null;
        }
        $site = Craft::$app->getSites()->getSiteById((int)($data['s'] ?? 0));
        if ($site) {
            Craft::$app->getSites()->setCurrentSite($site);
        }
        $view = Craft::$app->getView();
        if (!$view->doesTemplateExist($data['t'], View::TEMPLATE_MODE_SITE)) {
            return null;
        }
        $html = $view->renderTemplate($data['t'], (array)($data['v'] ?? []), View::TEMPLATE_MODE_SITE);

        return $view->getHeadHtml() . $html . $view->getBodyHtml();
    }

    /**
     * Fetches every part on the page in one request, puts each in place and runs its scripts in order.
     */
    private function loaderScript(): string
    {
        $url = Json::encode(UrlHelper::actionUrl('speedy/dynamic/render'));

        return <<<JS
(function(){var els=[].slice.call(document.querySelectorAll('[data-speedy-dynamic]'));if(!els.length)return;
fetch($url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({tokens:els.map(function(e){return e.getAttribute('data-speedy-dynamic')})})})
.then(function(r){return r.json()}).then(function(d){var queue=[];
els.forEach(function(el,i){var html=(d.parts||[])[i];if(typeof html!=='string')return;var box=document.createElement('div');box.innerHTML=html;
[].slice.call(box.querySelectorAll('script')).forEach(function(old){queue.push(old)});var nodes=[].slice.call(box.childNodes);nodes.forEach(function(n){el.parentNode.insertBefore(n,el)});el.remove()});
(function next(){var old=queue.shift();if(!old){document.dispatchEvent(new CustomEvent('speedy:dynamic'));return}
if(old.src&&[].some.call(document.querySelectorAll('script[src]'),function(x){return x!==old&&x.src===old.src})){old.remove();return next()}
var s=document.createElement('script');[].slice.call(old.attributes).forEach(function(a){s.setAttribute(a.name,a.value)});s.setAttribute('data-speedy-run','');
if(old.src){s.onload=s.onerror=next}s.text=old.text;old.parentNode.replaceChild(s,old);if(!old.src)next()})()})})();
JS;
    }
}
