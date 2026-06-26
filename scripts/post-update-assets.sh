#!/bin/bash
# Post-OJS Update Asset Linking Script
# Run this after any OJS update to restore TinyMCE asset paths

echo "Creating TinyMCE asset symlinks..."

# Remove any existing symlinks/directories that might conflict
rm -rf js/plugins/fullscreen 2>/dev/null
rm -rf js/skins 2>/dev/null

# Create directory structure
mkdir -p js/plugins js/skins/ui

# Create symlinks for TinyMCE assets
ln -sf ../../lib/pkp/lib/vendor/tinymce/tinymce/plugins/fullscreen js/plugins/fullscreen
ln -sf ../../../lib/pkp/lib/vendor/tinymce/tinymce/skins/ui/tinymce-5 js/skins/ui/tinymce-5

# Verify symlinks were created
if [ -e "js/plugins/fullscreen/plugin.js" ] && [ -e "js/skins/ui/tinymce-5/content.css" ]; then
    echo "✓ TinyMCE asset symlinks created successfully"
else
    echo "✗ Error: Symlinks not created properly"
    exit 1
fi

echo "Done! TinyMCE assets should now load correctly."
