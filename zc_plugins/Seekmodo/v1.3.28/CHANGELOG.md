# Seekmodo Zen Cart connector v1.3.28

## Fixed

- **Delta indexer CLI parse error** — the file-header cron example used
  `*/15`, which prematurely closes the PHP `/**` docblock and causes
  `Parse error: syntax error, unexpected token "*"` on line 10 when
  `numinix_seekmodo_index_delta.php` is run (or linted). The example
  now uses an equivalent `0,15,30,45` minute list so the script parses
  cleanly on all PHP versions.
