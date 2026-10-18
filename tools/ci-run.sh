#!/usr/bin/env bash
# Runs a command and, when it fails, repeats the end of its output as a GitHub Actions annotation so that
# the reason is visible without opening the raw log.
set +e
"$@" > ci-out.txt 2>&1
code=$?
cat ci-out.txt

if [ "$code" -ne 0 ]; then
  summary=$(sed 's/\x1b\[[0-9;]*m//g' ci-out.txt | grep -vE '^\s*$' | tail -n 45 | cut -c1-200)
  summary="${summary//'%'/'%25'}"
  summary="${summary//$'\n'/'%0A'}"
  echo "::error title=Command failed: $*::${summary}"
fi

exit "$code"
