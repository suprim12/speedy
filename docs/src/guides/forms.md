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
