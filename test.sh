#!/bin/bash
set -e
set -o pipefail

files=(*.php class/*.php template/*.php);
for file in "${files[@]}"
do
  php -l "$file";
  php vendor/phpfmt/fmt/fmt.phar --psr1 --psr2 -o=- "$file" | diff -U3 \
  --label "$file (correct formatting)" --label "$file (original)" - "$file";
done
