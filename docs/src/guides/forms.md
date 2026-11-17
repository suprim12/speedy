# Forms and CSRF

Craft protects forms with a CSRF token, unique to each visitor. A cached page can’t contain one visitor’s token, or everyone would share it and forms would fail.

Speedy handles this for you:

1. When a page with a CSRF token is cached, the token is replaced with a placeholder.
2. A tiny script (under 1 KB, no dependencies) is added to the end of the page.
3. When the page loads, the script fetches a fresh token from `actions/speedy/csrf`, which is never cached, and puts it into every CSRF field and meta tag.

Your templates don’t change:

```twig
<form method="post">
  {{ csrfInput() }}
  {{ actionInput('contact-form/send') }}
  …
</form>
```

Pages without forms get no script.

## Scripts that read the token

The script also sets `window.csrfTokenValue` once the token arrives. If your own JavaScript reads the token, read it when the form is submitted, not as the page loads.

Craft’s <code v-pre>{{ csrfInput({ async: true }) }}</code> works too: Craft fetches its own token, and Speedy leaves it alone.

## Turning it off

Set `refreshCsrf` to `false` in `config/speedy.php`. Pages with CSRF tokens are then not cached, as Craft marks them `no-store`.

## Form plugins

Some form plugins keep each visitor’s progress through a form, and its “Thanks” message, on the server, linked to the visitor’s session. So:

- **Pages that show such a form aren’t cached** (`X-Speedy: SKIP session`). A cached copy could show one visitor’s step or message to someone else.
- **Seeing a form doesn’t affect other pages.** The visitor still gets cached copies of every other page.
- **After submitting,** the visitor gets fresh pages until the confirmation has been shown.

To cache a page that has a form on it, such as a newsletter sign-up in the footer, include the form with `craft.speedy.dynamic()`. The page is cached, and the form, its steps and its messages are built for each visitor:

```twig
{# In the footer #}
{{ craft.speedy.dynamic('_partials/newsletter') }}
```

```twig
{# templates/_partials/newsletter.twig #}
{# Render the form here the way your form plugin does. #}
```

The form’s scripts and styles come with it, and its validation and multi-page steps work as usual. See [Personal content on a cached page](/guides/templates#personal-content-on-a-cached-page).

### Captchas and other tokens

Speedy refreshes Craft’s CSRF token on cached pages. Some forms put other one-off values in the page too, such as a JavaScript or duplicate-submission captcha, or a token for each time the form is shown. On a cached page those are the same for everyone and go stale, so submissions can fail.

Some form plugins can fetch fresh values when the page loads. Speedy turns that on for the ones it knows, and pages with their forms are cached as usual. For other forms, either:

- turn on your form plugin’s own refresh for cached pages, or
- include the form with `craft.speedy.dynamic()`, as above.

Forms with only the CSRF token, and captchas checked by a third party such as reCAPTCHA or Turnstile, need nothing extra.
