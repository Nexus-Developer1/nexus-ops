#!/bin/sh
set -e

# Garantir que o Laravel pode escrever em storage e cache.
# (Necessário porque o código é um volume bind do host.)
chmod -R 777 storage bootstrap/cache 2>/dev/null || true

exec "$@"
