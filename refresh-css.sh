#!/bin/bash
# Quick script to refresh CSS during development
# Usage: ./refresh-css.sh

echo "🔄 Refreshing CSS cache..."

# Clear compiled CSS cache (with checksum in filename)
rm -f cache/*.css 2>/dev/null

# Clear template cache
rm -rf cache/t_compile/* 2>/dev/null

echo "✓ Done - hard refresh browser (Ctrl+Shift+R)"
