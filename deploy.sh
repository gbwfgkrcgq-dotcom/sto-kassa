#!/bin/sh
# Обновляет приложение на хостинге до последнего коммита в GitHub.
# Ключ лежит рядом с папкой репозитория в sto-kassa-deploy.key и в GitHub не попадает.
set -e
cd "$(dirname "$0")"
KEY=$(tr -d '\n' < ../sto-kassa-deploy.key)
git fetch -q origin main
REF=$(git rev-parse origin/main)
[ "$REF" = "$(git rev-parse HEAD)" ] || { echo "Сначала отправьте изменения в GitHub (git push)"; exit 1; }
curl -sS -X POST -H "X-Deploy-Key: $KEY" --data "ref=$REF" https://ch602150.tw1.ru/deploy.php
echo
