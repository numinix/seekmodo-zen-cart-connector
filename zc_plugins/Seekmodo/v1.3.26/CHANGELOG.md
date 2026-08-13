# Seekmodo Zen Cart connector v1.3.26

## Fixed

- **Exact suggest category redirects** — vendored
  `@seekmodo/web-components` v0.3.14 restores auto-navigation when the
  gateway returns a top-level `redirect` (exact + unambiguous term
  matches only). Typing `beer glasses` again settles into the category
  landing page. Prefix-only Go-to rows (`photo`) stay non-navigating so
  multi-word queries like `photo mugs` still work. Includes the
  v1.3.25 / 0.3.13 eager thumb paint fix.

