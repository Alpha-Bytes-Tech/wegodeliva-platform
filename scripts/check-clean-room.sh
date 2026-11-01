#!/usr/bin/env bash
# Clean-room check (CLAUDE.md §1).
# Fails if any tracked or staged file mentions the old template or its author.
# Allowed only in docs/spec/legacy-notes.md and CLAUDE.md.
#
# The forbidden words are assembled at runtime so this script does not trip itself.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

words=("glo""ver" "fu""odz" "eden""tech" "ambrose""thebuild")
pattern="$(IFS='|'; echo "${words[*]}")"

allowed=(
  "CLAUDE.md"
  "docs/spec/legacy-notes.md"
)

# Tracked files plus anything staged for the next commit.
mapfile -t files < <(git ls-files --cached --others --exclude-standard | sort -u)

hits=()
for f in "${files[@]}"; do
  [[ -f "$f" ]] || continue
  skip=false
  for a in "${allowed[@]}"; do
    [[ "$f" == "$a" ]] && skip=true && break
  done
  $skip && continue

  # File names count too (e.g. a package folder named after the template).
  if echo "$f" | grep -qiE "$pattern"; then
    hits+=("$f (file name)")
    continue
  fi
  # -I skips binary files.
  if grep -qIiE "$pattern" -- "$f"; then
    hits+=("$f")
  fi
done

if ((${#hits[@]} > 0)); then
  echo "Clean-room check FAILED. Old template names found in:" >&2
  printf '  - %s\n' "${hits[@]}" >&2
  echo "Remove them (see CLAUDE.md §1)." >&2
  exit 1
fi

echo "Clean-room check passed (${#files[@]} files scanned)."
