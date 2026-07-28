# Seekmodo for Zen Cart v1.3.35

## 2026-07-28 - Push catalog open_basedir (NS-26042)

- **Trust NUMINIX_SEEKMODO_PHP_BINARY under open_basedir** - shared hosts
  often block `is_file('/opt/cpanel/...')` while `shell_exec` can still
  run that CLI. The override is now trusted without a filesystem check,
  and EasyApache / CloudLinux candidates are shell-probed (`php -r`)
  when `is_file` fails. Fixes Push catalog still failing on PHP 8.3
  after v1.3.34 (Cannapot / links-c3ca80).

## 2026-07-28 - Push catalog PHP CLI discovery (from v1.3.34)

- **Push catalog now on PHP 8.3 / EasyApache** - `Pairing::resolve_php_binary()`
  finds CLI binaries on hosts where `$PATH` is empty under FPM.
  Adds version-matched ea-php{MM} / alt-php paths, derives CLI from
  `PHP_BINARY`, and passes `--ack-quota` on admin-forked pushes.
