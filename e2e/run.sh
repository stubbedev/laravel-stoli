#!/usr/bin/env bash
# Every e2e scenario, in the Laravel application e2e/Dockerfile builds. Each one sets
# the writer, split, client, mode, formatter and urls options through the environment,
# then holds what Stoli generates to:
#
#   1. generation: typescript:transform (and stoli:generate when it is not hooked in)
#   2. stoli:generate --check agreeing with what was written
#   3. a second run rewriting nothing
#   4. tsc, under the strictest options, against the type contract in e2e/ts
#   5. the generated code making real requests to `php artisan serve`
#
# Then watch mode: a route added while `typescript:transform --watch` runs ends up in
# the route file.
set -euo pipefail

cd /app

GENERATED=resources/js/generated
SERVER_LOG=/tmp/serve.log

# writer split client mode formatter urls
SCENARIOS=(
    "global true fetch transform none true"
    "global false axios generate none false"
    "global true none generate prettier true"
    "module true axios transform prettier true"
    "module false fetch generate none true"
    "module true none transform none false"
    "flat true fetch generate prettier false"
    "flat false axios transform none true"
    "flat true none transform prettier true"
)

if [[ -n "${E2E_ONLY:-}" ]]; then
    SCENARIOS=("${SCENARIOS[@]:$E2E_ONLY:1}")
fi

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

generate() {
    php artisan typescript:transform --no-interaction >/dev/null

    if [[ "$E2E_MODE" == "generate" ]]; then
        php artisan stoli:generate --no-interaction >/dev/null
    fi
}

# A tsconfig taking in what the scenario generates. The ModuleWriter's own output
# imports types without `import type`, so verbatimModuleSyntax is left to the other
# writers, which hold Stoli's files to it.
tsconfig() {
    local base="$1" output="$2"
    shift 2
    local files verbatim=true
    files=$(printf '"%s",' "$@")
    [[ "$E2E_WRITER" == "module" ]] && verbatim=false

    cat > "e2e-ts/${output}" <<JSON
{
    "extends": "./${base}",
    "compilerOptions": { "verbatimModuleSyntax": ${verbatim} },
    "include": ["../${GENERATED}/**/*.ts", ${files%,}]
}
JSON
}

php artisan serve --host 127.0.0.1 --port 8000 >"$SERVER_LOG" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT

for _ in $(seq 1 50); do
    php -r 'exit(@file_get_contents("http://127.0.0.1:8000/api/route-check?name=users.index") === false ? 1 : 0);' && break
    sleep 0.2
done

for scenario in "${SCENARIOS[@]}"; do
    read -r E2E_WRITER E2E_SPLIT E2E_CLIENT E2E_MODE E2E_FORMATTER E2E_URLS <<<"$scenario"
    export E2E_WRITER E2E_SPLIT E2E_CLIENT E2E_MODE E2E_FORMATTER E2E_URLS
    echo "== writer=$E2E_WRITER split=$E2E_SPLIT client=$E2E_CLIENT mode=$E2E_MODE formatter=$E2E_FORMATTER urls=$E2E_URLS"

    rm -rf "$GENERATED" e2e-ts/dist vendor/stubbedev/laravel-stoli/.cache
    mkdir -p "$GENERATED"

    # 1. generation
    generate

    for file in stoli.ts api.ts pages.ts constants.ts; do
        [[ -f "$GENERATED/$file" ]] || fail "$file was not generated"
    done

    if [[ "$E2E_CLIENT" == "none" ]]; then
        [[ ! -e "$GENERATED/router.ts" ]] || fail "a router was generated without a client"
    else
        [[ -f "$GENERATED/router.ts" && -f "$GENERATED/stoli-$E2E_CLIENT.ts" ]] || fail "the $E2E_CLIENT router was not generated"
        [[ ! -e "$GENERATED/pages.router.ts" ]] || fail "a standalone module got a router"
    fi

    if [[ "$E2E_URLS" == "true" ]]; then
        [[ -f "$GENERATED/api.urls.ts" && -f "$GENERATED/pages.urls.ts" ]] || fail "the url functions were not generated"
    else
        [[ ! -e "$GENERATED/api.urls.ts" ]] || fail "url functions were generated without the option"
    fi

    # 2. the check agrees
    php artisan stoli:generate --check --no-interaction || fail "stoli:generate --check disagrees with what was generated"

    # 3. nothing is rewritten
    touch /tmp/marker
    sleep 1
    generate
    rewritten=$(find "$GENERATED" -type f -newer /tmp/marker ! -name 'typescript-transformer-manifest.json')
    [[ -z "$rewritten" ]] || fail "a second run rewrote: $rewritten"

    # 4. the types
    types=("types.ts")
    http=("http.ts")
    [[ "$E2E_CLIENT" != "none" ]] && types+=("router-types.ts") && http+=("http-router.ts")
    [[ "$E2E_URLS" == "true" ]] && types+=("urls-types.ts") && http+=("http-urls.ts")

    tsconfig tsconfig.json tsconfig.scenario.json "${types[@]}"
    (cd e2e-ts && npx tsc -p tsconfig.scenario.json) || fail "the generated TypeScript does not hold to the type contract"

    # 5. real requests
    # Type-checked by tsc, then bundled the way an application bundles the generated files.
    tsconfig tsconfig.http.json tsconfig.scenario-http.json "${http[@]}"
    (cd e2e-ts && npx tsc -p tsconfig.scenario-http.json) || fail "the HTTP tests do not compile"

    for file in "${http[@]}"; do
        (cd e2e-ts && npx esbuild "$file" --bundle --platform=node --format=cjs --log-level=warning --outfile="dist/${file%.ts}.cjs") || fail "${file} does not bundle"
        node "e2e-ts/dist/${file%.ts}.cjs" || fail "${file} failed; server log: $(tail -20 "$SERVER_LOG")"
    done
done

if [[ -z "${E2E_ONLY:-}" ]]; then
    echo "== watch mode"
    export E2E_WRITER=global E2E_SPLIT=true E2E_CLIENT=fetch E2E_MODE=transform E2E_FORMATTER=none E2E_URLS=false
    rm -rf "$GENERATED" && mkdir -p "$GENERATED"
    cp routes/api.php /tmp/api.php

    php artisan typescript:transform --watch >/tmp/watch.log 2>&1 &
    WATCHER=$!
    trap 'kill $SERVER $WATCHER 2>/dev/null || true; cp /tmp/api.php routes/api.php' EXIT

    for _ in $(seq 1 150); do
        [[ -f "$GENERATED/api.ts" ]] && break
        sleep 0.2
    done
    [[ -f "$GENERATED/api.ts" ]] || fail "watch mode generated nothing: $(cat /tmp/watch.log)"

    sleep 2
    echo "Route::get('watched', [App\\Http\\Controllers\\E2eController::class, 'wrapped'])->name('watched.added');" >> routes/api.php

    for _ in $(seq 1 300); do
        grep -q "watched.added" "$GENERATED/api.ts" && break
        sleep 0.2
    done
    grep -q "watched.added" "$GENERATED/api.ts" || fail "a route added in watch mode did not reach api.ts: $(cat /tmp/watch.log)"
fi

echo "e2e: all scenarios passed"
