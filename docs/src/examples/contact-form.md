# Contact Form on a Cached Page

Craft protects forms with a CSRF token unique to each visitor. Speedy refreshes it on cached pages, so a plain form needs no changes.

![A form on a cached page, sent successfully: the token was accepted](/images/examples/form.jpg)

## The template

```twig
{# templates/contact.twig #}
{% extends '_layout' %}

{% block content %}
  <h1>Contact us</h1>

  {% if craft.app.session.hasFlash('notice') %}
    <p class="notice">{{ craft.app.session.getFlash('notice') }}</p>
  {% endif %}

  <form method="post">
    {{ csrfInput() }}
    {{ actionInput('contact-form/send') }}
    {{ redirectInput('contact') }}

    <label>Your email <input type="email" name="fromEmail" required></label>
    <label>Message <textarea name="message[body]" required></textarea></label>
    <button type="submit">Send</button>
  </form>
{% endblock %}
```

## What happens

1. The page is cached with a placeholder in place of the token, and a script of under 1 KB.
2. When the page loads, the script fetches a fresh token and puts it in the form.
3. The visitor sends the form. After the redirect, the “Thanks” flash message is in their session, so Speedy builds `/contact` fresh for them, and they see it.
4. Everyone else keeps getting the cached page.

See [Forms and CSRF](/guides/forms) for scripts that read the token.
