# Seekmodo Zen Cart connector v1.3.27

## Added

- **Multi-language packs (EN / DE / ES / FR)** — ships Zen Cart
  language files under `admin/includes/languages/{english,german,deutsch,spanish,french}/extra_definitions/`
  and the matching catalog paths. Covers the Tools menu labels
  (`Connect to Seekmodo`, `Seekmodo Updates`) and the suggest
  dropdown chrome (Recently searched, Trending, Suggestions,
  Products, Categories, Did you mean, View all N results, empty
  state, CORS notice, Powered by).
- **Runtime language loader** — catalog + admin init include the
  active language pack from the plugin tree so file-only / rsync
  installs (Zen Cart 1.5.7 German packs included) pick up translations
  without a Plugin Manager language merge.
- **Suggest UI i18n wiring** — `<seekmodo-suggest>` receives `lang`
  + a `labels` JSON attribute from the shopper's Zen Cart language;
  the vendored bundle prefers those labels over the English defaults.

Merchants who want custom wording can edit the `define()` / `$define`
values in those language files and leave the constant names unchanged.
Deutsch mirrors German for Austrian / German Zen Cart Pro packs that
use that directory name.
