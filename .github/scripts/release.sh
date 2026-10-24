#!/usr/bin/env bash
set -euo pipefail
assets=(dist/nebula.plg "dist/nebula-${RELEASE_VERSION}.tar.gz"
        "dist/nebula-unraid-${RELEASE_VERSION}-noarch-1.txz")
for asset in "${assets[@]}"; do
  test -s "$asset" || { echo "Missing or empty release download: $asset" >&2; exit 1; }
done

gh api --paginate --slurp "repos/$GH_REPO/releases?per_page=100" > "$RUNNER_TEMP/releases.json"
if jq -e --arg version "$RELEASE_VERSION" \
  'any(.[][]; .tag_name == $version)' "$RUNNER_TEMP/releases.json" >/dev/null; then
  echo 'This release version already exists. Run the workflow again after the current UTC minute.' >&2
  exit 1
fi

commit=$(git rev-parse HEAD)
if tagged_commit=$(git rev-parse --verify "refs/tags/$RELEASE_VERSION^{commit}" 2>/dev/null); then
  if [[ "$tagged_commit" != "$commit" ]]; then
    echo 'The release tag already points to a different commit.' >&2
    exit 1
  fi
fi

previous=$(jq -r '[.[][] | select(.draft == false and .prerelease == false)]
  | sort_by(.published_at) | last | .tag_name // empty' "$RUNNER_TEMP/releases.json")
revision=HEAD
if [[ -n "$previous" ]]; then
  previous_commit=$(git rev-parse --verify "refs/tags/$previous^{commit}")
  if ! git merge-base --is-ancestor "$previous_commit" "$commit"; then
    echo 'The last published release is not an ancestor of this commit.' >&2
    exit 1
  fi
  revision="$previous_commit..HEAD"
fi
notes="$RUNNER_TEMP/release-notes.md"
git log --reverse --format='- %s (%h)' "$revision" > "$notes"
if [[ ! -s "$notes" ]]; then
  echo 'There are no new commits since the last published release.' >&2
  exit 1
fi

(cd dist && sha256sum nebula.plg "nebula-${RELEASE_VERSION}.tar.gz" \
  "nebula-unraid-${RELEASE_VERSION}-noarch-1.txz" > SHA256SUMS)
assets+=(dist/SHA256SUMS)

draft_url=$(gh release create "$RELEASE_VERSION" --draft --target "$commit" \
  --title "Nebula for Unraid $RELEASE_VERSION" --notes-file "$notes" "${assets[@]}")
printf '[Draft release](%s)\n' "$draft_url" >> "$GITHUB_STEP_SUMMARY"
