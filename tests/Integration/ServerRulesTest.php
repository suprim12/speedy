<?php

namespace suprim\speedy\tests\Integration;

use suprim\speedy\tests\TestCase;

class ServerRulesTest extends TestCase
{
    public function testNginxRulesSkipPersonalRequests(): void
    {
        $this->settings()->skipCookies = ['cookie_consent'];
        $rules = $this->plugin()->serverRules->nginx();

        foreach (['_identity', 'speedy_state', '_commerce_cart', 'cookie_consent'] as $cookie) {
            $this->assertStringContainsString($cookie, $rules);
        }
        foreach (['$http_x_requested_with', '$http_hx_request', '$http_x_up_target', '$http_x_speedy_warm', '$args'] as $check) {
            $this->assertStringContainsString($check, $rules);
        }
        $this->assertStringContainsString('internal;', $rules);
        $this->assertStringContainsString('gzip_static on;', $rules);
    }

    public function testCartCookieOnlyWhenSkippingCarts(): void
    {
        $this->settings()->skipWhenCart = false;
        $this->assertStringNotContainsString('_commerce_cart', $this->plugin()->serverRules->apache());
    }
}
