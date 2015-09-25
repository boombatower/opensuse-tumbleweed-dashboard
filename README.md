# opensuse-tumbleweed-dashboard
openSUSE Tumbleweed dashboard for tracking package progression from devel through factory to tumbleweed.

This is a very simple project which does not use any [micro-]frameworks or what not and is intended to get the job done with no frills.

## config

Supports a basic [ini file](http://php.net/parse-ini-file) format for listing rpms of interest. If the rpm name differs from the package that provides it use the format `{package}/{rpm}` otherwise the package name is assume to be the same as rpm (ie. `{rpm}/{rpm}`).

All devel packages are assumed to be built against the `openSUSE_Factory` repository, if a different repository is to be checked use the suffix `@{repository}`. See `kernel-source` example below.

Example:

```ini
[Base]
package[] = _product:openSUSE-release/openSUSE-release
package[] = kernel-source@standard

[Graphics]
package[] = Mesa
package[] = llvm/libLLVM
package[] = xorg-x11-server

[Desktop]
package[] = libqt5-qtbase/libQt5Core5
package[] = plasma-framework
package[] = plasma5-workspace
```

## api.opensuse.org credentials

The API requires credentials which should be placed in a file aptly named `credentials` using the format `{username}:{password}`. Not secure storage so keep that in mind and either create a different mechanism or perhaps a dummy account used for API access.

A `.htaccess` file is included to restrict public access when using Apache web server. Alternatively the file could be place outside of webroot and code tweaked.

## cache

Results are cached for one hour, but that can be controlled using `CACHE_LIFE`. Additionally, all API/repository requests are cached when in development mode (not accessed as tumbleweed.boombatower.com) to allow for quicker development. In production mode the final generated table is cached instead.

Two GET parameters are available to control the cache.

- `rebuild`: rebuilds cached table (from cached api calls if available)
- `refresh`: refreshes api calls and then rebuilds table
