# Live Data, Refreshed Every Minute

Speedy clears a page when Craft content on it changes. Data from outside Craft, such as exchange rates, stock from another system or the weather, changes without Craft knowing. Rebuild those pages on a timer.

## The template

```twig
{# templates/rates.twig #}
{% extends '_layout' %}

{# Rebuild this page every minute #}
{% do craft.speedy.ttl(60) %}

{% set rates = craft.app.cache.getOrSet('fx-rates', () => fetchRates(), 60) %}

{% block content %}
  <h1>Today’s rates</h1>
  <table>
    {% for code, rate in rates %}
      <tr><th>{{ code }}</th><td>{{ rate|number_format(4) }}</td></tr>
    {% endfor %}
  </table>
  <p class="small">Updated {{ now|date('H:i') }}</p>
{% endblock %}
```

## What happens

- The page is cached, then rebuilt in the background a minute later. Visitors never wait for it.
- Saving entries shown on the page still clears it straight away.
- If the template calls `ttl()` more than once, such as from an include, the shortest time wins.

## Only one part is live

If most of the page is ordinary content and only a small box is live, keep the page cached and include the box dynamically instead:

```twig
{{ craft.speedy.dynamic('_parts/rates-box') }}
```
