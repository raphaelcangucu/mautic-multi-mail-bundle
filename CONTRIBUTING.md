# Development

Use PHP 8.2+ and Composer. Install isolated test dependencies:

```
composer install --working-dir=build/dependencies
php Tests/connections.php
php Tests/transports.php
php Tests/native-compatibility.php
node --check Assets/js/connections.js
python3 build/package.py
```

These are standalone CLI scripts with temporary filesystem state, mocked HTTP, a simulated SMTP stream and a dependency injection container. They do not boot Mautic, connect to a database or send real email. Do not substitute production Mautic databases, PHPUnit fixtures, resets or destructive schema tests for these checks.

Native compatibility tests compare the installed factory registry with and without Multi Mail and verify that native connections return the original transport object. Preserve original transport interfaces and do not replace native service definitions. New provider adapters must use official bridges and document their send outcome/fallback boundary.

Do not commit credentials, private connection registries, environment files or customer data. Release ZIPs contain code and public dependencies only. Production deployment must preserve the shared private registry and the current global mail configuration unless the operator explicitly selects a new transport. Back up code, rebuild both container and configured Twig cache, and verify deployed package hashes.
