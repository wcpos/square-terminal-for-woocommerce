# Require PHP 8.1 for the current Square SDK

The plugin will require PHP 8.1 or newer because the current official Square PHP SDK requires PHP 8.1 and uses PHP 8.1 language features. The plugin bootstrap must include both an activation guard and an early runtime guard before loading Composer so older PHP sites receive a clear admin error instead of a fatal error from SDK code.
