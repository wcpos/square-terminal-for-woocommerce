# Use and scope the official Square PHP SDK

The plugin will use the official Square PHP SDK for Square API calls and ship release builds with SDK dependencies scoped under the plugin namespace. Normal Composer autoloading may be used during early development, but distributable builds must isolate Square SDK classes to avoid conflicts with other WordPress plugins that may bundle incompatible Square SDK versions for online payments.
