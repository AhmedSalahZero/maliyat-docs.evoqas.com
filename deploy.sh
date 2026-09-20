#!/bin/bash
set -e

echo ">>> Maintenance mode ON"
/usr/local/bin/ea-php84 artisan down

echo ">>> Pulling latest code"
git status
git stash
git pull origin master

# ── Are the compiled assets actually here? ────────────────────────
#
# This server has no Node, so public/build travels in the repository
# (see .gitignore). That worked until the folder was gitignored while
# a handful of force-added files stayed tracked: the repo shipped a
# manifest.json naming 85 assets of which 7 were present, and every
# page loaded with no JS and no CSS. Nothing failed loudly — the
# deploy said "finished successfully" and the site was broken.
#
# So: read the manifest, check every file it names is on disk, and
# refuse to leave maintenance mode if any are missing. A deploy that
# stops with the old site still up beats one that finishes and serves
# a blank page.
echo ">>> Verifying compiled assets"
/usr/local/bin/ea-php84 -r '
    $manifest = "public/build/manifest.json";
    if (! is_file($manifest)) {
        fwrite(STDERR, "ERROR: public/build/manifest.json is missing. Run `npm run build` locally and commit public/build.\n");
        exit(1);
    }
    $missing = [];
    $checked = 0;
    foreach (json_decode(file_get_contents($manifest), true) ?: [] as $entry) {
        foreach (array_merge([$entry["file"] ?? null], $entry["css"] ?? [], $entry["assets"] ?? []) as $file) {
            if (! $file) { continue; }
            $checked++;
            if (! is_file("public/build/" . $file)) { $missing[] = $file; }
        }
    }
    if ($missing) {
        fwrite(STDERR, sprintf("ERROR: %d asset(s) named by the manifest are not on disk, e.g. %s\n", count($missing), implode(", ", array_slice($missing, 0, 3))));
        fwrite(STDERR, "The frontend would load blank. Run `npm run build` locally, commit public/build, and deploy again.\n");
        exit(1);
    }
    printf("    %d assets present.\n", $checked);
'

echo ">>> Installing dependencies"
/usr/local/bin/ea-php84 $(which composer) install --no-interaction --prefer-dist --optimize-autoloader

echo ">>> Clearing cache"
/usr/local/bin/ea-php84 artisan optimize:clear

echo ">>> Fixing permissions"
chmod -R 775 storage
chmod -R 775 bootstrap/cache

echo ">>> Running migrations"
/usr/local/bin/ea-php84 artisan migrate --force

echo ">>> Linking storage"
/usr/local/bin/ea-php84 artisan storage:link

echo ">>> Restarting queue workers"
sudo supervisorctl restart queue-worker:*

echo ">>> Maintenance mode OFF"
/usr/local/bin/ea-php84 artisan up

echo ">>> Deployment finished successfully!"
