## PHP / Symfony specifics
- Verify bundle and API Platform behavior against `vendor/` source or
  `bin/console debug:*` (`debug:router`, `debug:container`,
  `debug:messenger`, `debug:config`), not memory — Symfony 8 and API
  Platform 4 removed and renamed things across majors
- Run PHP through the container: `make composer ARGS=…`,
  `make console ARGS=…`, `make test`; never call a host `php` (there is
  none)
- Doctrine is a DataMapper here: no active-record helpers on entities,
  repositories behind interfaces, every schema change is a reviewed
  migration (`make migration`, then read the generated class)
- Before proposing a bundle, check `composer.json` and the Symfony
  component list — the framework usually already covers the role
- `make check` before claiming a task done
