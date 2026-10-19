# Offline test harness

Runs the module outside WHMCS against an in-memory SQLite database, stub WHMCS functions
and a fake control-panel driver (no real servers are contacted).

```bash
cd tests
composer install
php tests.php                          # business logic, lifecycle, API (expects "FAIL 0")
php ui.php vendor/autoload.php         # admin pages, hooks, client template (Smarty 4)
```

Not part of the distributed zip.
