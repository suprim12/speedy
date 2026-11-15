# Cart Count in a Cached Header

Every page has the same header, but the cart count is different for each visitor. Include it with `craft.speedy.dynamic()`, and every page stays cached.

![A cached page: the page was built at 23:02:19, while the parts in it were built for this visitor at 23:05:41](/images/examples/dynamic.jpg)

## The layout

```twig
{# templates/_layout.twig #}
<header class="site-header">
  <a href="/" class="logo">Fresh Grocer</a>
  <nav>…</nav>
  {{ craft.speedy.dynamic('_parts/cart-count', {}, {
    placeholder: '<a href="/cart" class="cart">Cart</a>',
  }) }}
</header>
```

## The part

```twig
{# templates/_parts/cart-count.twig #}
{% set cart = craft.commerce.carts.cart(false) %}
<a href="/cart" class="cart">
  Cart
  {% if cart and cart.totalQty %}
    <span class="cart__count">{{ cart.totalQty }}</span>
  {% endif %}
</a>
```

`cart(false)` reads the visitor’s cart without creating one, so visitors who haven’t added anything don’t get a cart cookie.

## How it works

1. The page is cached with the placeholder: `<a href="/cart" class="cart">Cart</a>`.
2. A small script, added once per page, fetches every dynamic part in one request.
3. The part replaces the placeholder. Visitors see “Cart”, then the count a moment later.

Visitors who add something to their cart get fresh pages anyway (`skipWhenCart`), and the part renders in place on those, with no extra request.

## A greeting for members

The same works for anything personal:

```twig
{# In the layout #}
{{ craft.speedy.dynamic('_parts/account-link') }}
```

```twig
{# templates/_parts/account-link.twig #}
{% if currentUser %}
  <a href="/account">Hi, {{ currentUser.friendlyName }}</a>
{% else %}
  <a href="/login">Log in</a>
{% endif %}
```
