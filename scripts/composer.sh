#!/usr/bin/env bash
# Runs Composer, installing it first where it's missing (the php:*-cli Docker
# images ship without it). The installer's signature is checked before it
# runs. Composer lands in ~/.local/bin, which the SDK scripts keep in a
# cached volume between runs.
set -euo pipefail

if command -v composer >/dev/null 2>&1; then
  exec composer "$@"
fi

bin="${HOME:-/root}/.local/bin"
if [[ ! -x "$bin/composer" ]]; then
  # Composer unpacks dists with unzip (or the zip extension); Debian images have neither.
  if ! command -v unzip >/dev/null 2>&1 && ! php -m | grep -qi '^zip$' && command -v apt-get >/dev/null 2>&1; then
    apt-get update -qq >/dev/null && apt-get install -y -qq --no-install-recommends unzip git >/dev/null
  fi
  mkdir -p "$bin"
  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' EXIT
  php -r "copy('https://getcomposer.org/installer', '$tmp/installer');"
  expected="$(php -r "echo trim(file_get_contents('https://composer.github.io/installer.sig'));")"
  actual="$(php -r "echo hash_file('sha384', '$tmp/installer');")"
  if [[ "$expected" != "$actual" ]]; then
    echo "composer.sh: the Composer installer's signature doesn't match; not running it" >&2
    exit 1
  fi
  php "$tmp/installer" --quiet --install-dir="$bin" --filename=composer
fi
exec "$bin/composer" "$@"
