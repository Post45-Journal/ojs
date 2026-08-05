#!/bin/bash
# Deploy a plugin update — runs installPluginVersion.php, sweeps OJS's
# file caches, and restarts apache. Bundles the three "did I forget the
# cache-clear step again?" operations for a plugin bump.
#
# Usage:
#   deploy-plugin.sh                       # auto-detect: install every
#                                          # monorepo-linked plugin
#   deploy-plugin.sh <plugin-path>         # install just this plugin
#
# Examples:
#   deploy-plugin.sh
#   deploy-plugin.sh plugins/generic/post45Editorial
#   deploy-plugin.sh plugins/themes/pragmaSubmissions
#
# Plugin paths are relative to the OJS root. Run this from the OJS root
# (usually /var/www/html on prod, or ~/dev/post45-ojs locally).
#
# Auto-detect walks plugins/ and finds symlinks whose target lives under
# an `ojs-plugins-monorepo` directory — those are the only plugins that
# change on a Post45 deploy. Stock OJS plugins are left alone (their
# versions table row already matches whatever the OJS tree shipped).
#
# installPluginVersion.php is a no-op when the version.xml release
# matches the versions-table row, so re-running against unchanged
# plugins is safe.
#
# On prod, cache/ is owned by www-data, so cache clears + apache restart
# use sudo; you'll be prompted once. On local dev the cache is owned by
# you and sudo is skipped for the clears. Apache restart is skipped
# entirely when systemctl / apache2.service isn't available.

set -e

if [ ! -f lib/pkp/tools/installPluginVersion.php ]; then
    echo "✗ Run this from the OJS root (couldn't find lib/pkp/tools/installPluginVersion.php)."
    exit 1
fi

# Collect the list of plugin paths to install.
PLUGIN_PATHS=()

if [ $# -eq 1 ]; then
    PLUGIN_PATH="${1%/}"
    if [ ! -f "${PLUGIN_PATH}/version.xml" ]; then
        echo "✗ No version.xml at ${PLUGIN_PATH}/version.xml"
        echo "  Check the plugin path (relative to OJS root)."
        exit 1
    fi
    PLUGIN_PATHS+=("$PLUGIN_PATH")
elif [ $# -eq 0 ]; then
    echo "▸ Auto-detecting monorepo-linked plugins..."
    # Find symlinked plugin dirs under plugins/ whose target contains
    # `ojs-plugins-monorepo`. version.xml lookup follows the symlink.
    while IFS= read -r link; do
        target=$(readlink -f "$link")
        case "$target" in
            *ojs-plugins-monorepo*)
                if [ -f "${link}/version.xml" ]; then
                    PLUGIN_PATHS+=("$link")
                fi
                ;;
        esac
    done < <(find plugins -maxdepth 3 -type l 2>/dev/null)

    if [ ${#PLUGIN_PATHS[@]} -eq 0 ]; then
        echo "✗ No monorepo-linked plugins found under plugins/."
        echo "  If your monorepo lives elsewhere, invoke with an explicit path."
        exit 1
    fi
    echo "  Found ${#PLUGIN_PATHS[@]}: ${PLUGIN_PATHS[*]}"
else
    echo "Usage: $0 [<plugin-path>]"
    echo "  no arg  — install every monorepo-linked plugin"
    echo "  path    — install just that plugin (e.g. plugins/generic/post45Editorial)"
    exit 1
fi

# On prod, cache/ is owned by www-data and CLI PHP running as your login
# user can't write into it — Laravel's cache store then silently fails to
# mkdir the two-level sub-dirs (cache/opcache/XX/YY/) and file_put_contents
# floods stderr with "No such file or directory" warnings. Run PHP as
# www-data when we can't write cache/ ourselves. Local dev (cache/ owned
# by you) skips the sudo prefix entirely.
PHP_SUDO=""
CACHE_SUDO=""
if [ -d cache ] && [ ! -w cache ]; then
    PHP_SUDO="sudo -u www-data"
    CACHE_SUDO="sudo"
fi

# 1. Register the plugin versions. Runs install steps (email templates,
#    schema migrations) when the version.xml release is newer than the
#    row in the `versions` table; no-ops when they match.
#    Individual failures don't abort the batch — plugins other than
#    the failing one still get installed, and cache clear + apache
#    restart still run so the successes take effect.
INSTALLED=()
FAILED=()
for path in "${PLUGIN_PATHS[@]}"; do
    echo "▸ Installing $path/version.xml..."
    if $PHP_SUDO php lib/pkp/tools/installPluginVersion.php "${path}/version.xml"; then
        INSTALLED+=("$path")
    else
        echo "  ⚠ Install failed for $path — continuing"
        FAILED+=("$path")
    fi
done

# 2. Sweep the file caches OJS keeps under cache/. Two matter for a
#    plugin bump:
#      - opcache/    — Laravel file cache (nav menus, permissions, etc.)
#      - t_compile/  — Smarty compiled templates (may include plugin
#                      template overrides)
#    CSS/JS caches don't need clearing for a PHP-only bump.
#    Done AFTER installPluginVersion because the installer's own
#    Laravel file-cache writes need the sub-directory scaffolding
#    (e.g. cache/opcache/7b/fa/) to exist when it runs — clearing
#    first would surface a wave of "mkdir failed" warnings from
#    file_put_contents.
echo "▸ Clearing OJS file caches..."
for dir in cache/opcache cache/t_compile; do
    if [ -d "$dir" ]; then
        $CACHE_SUDO find "$dir" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    fi
done

# 3. Restart apache — flushes PHP's opcache too. Only when systemctl +
#    apache2 are around (i.e. Linux prod), skipped on local dev where
#    OJS typically runs behind a short-TTL / disabled opcache anyway.
if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files apache2.service --no-legend 2>/dev/null | grep -q apache2; then
    echo "▸ Restarting apache..."
    sudo systemctl restart apache2
else
    echo "▸ Skipping apache restart (systemctl / apache2.service not available)"
fi

if [ ${#FAILED[@]} -gt 0 ]; then
    echo "✓ Installed: ${INSTALLED[*]:-<none>}"
    echo "✗ Failed:    ${FAILED[*]}"
    exit 1
fi
echo "✓ Done. Installed: ${INSTALLED[*]}"
