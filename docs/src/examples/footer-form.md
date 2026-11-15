# Newsletter Form in a Cached Footer

Pages that show a form from a plugin that keeps each visitor’s progress and messages in their session aren’t cached. With a sign-up form in the footer, that would mean no page is cached. Include the form dynamically instead.

## The footer

```twig
{# templates/_layout.twig #}
<footer class="site-footer">
  <h2>Get our weekly specials</h2>
  {{ craft.speedy.dynamic('_parts/newsletter', {}, {
    placeholder: '<p>Loading the sign-up form…</p>',
  }) }}
</footer>
```

## The part

```twig
{# templates/_parts/newsletter.twig #}
{# Render the form here the way your form plugin does. #}
```

## What happens

- Every page is cached, footer and all.
- The form is rendered for each visitor, with its CSS and JavaScript, so validation, multi-page steps and Ajax submission work as usual.
- After submitting, the visitor sees their own “Thanks” message, never someone else’s.

The same works for a search box that shows the visitor’s last search.
