#!/usr/bin/env python3
"""
Derive each app's release file allowlist from its own Makefile.

Two Makefile generations exist across the oc11 apps:

  "modern"  — declares `<prefix>src_dirs` / `<prefix>src_files` / `<prefix>doc_files`
              and the dist rule does `cp -R $(<prefix>all_src) $@`.
  "legacy"  — has a `dist: source appstore` target. `source` tars the whole
              working tree (this is what leaked .git into 14 shipped app dirs in
              11.0.0); `appstore` copies an explicit `cp --parents -r` list,
              which is the correct release set.

Both cases yield an explicit list of top-level paths. This script extracts it so
the monorepo can carry ONE reviewed manifest instead of 43 divergent Makefiles.

Output: TSV of `app_id<TAB>space separated paths`, written to stdout.
"""

import re
import subprocess
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
MANIFEST = REPO_ROOT / "tools" / "monorepo" / "apps-11.0.0.tsv"


def public_apps():
    """Yield (app_id, repo) for public apps, skipping example-files.

    example-files is not an app: it replaces core/skeleton wholesale and has no
    appinfo/ or Makefile allowlist.
    """
    for line in MANIFEST.read_text().splitlines():
        if line.startswith("#") or not line.strip():
            continue
        parts = line.split("\t")
        if len(parts) >= 5 and parts[3] == "public" and parts[0] != "example-files":
            yield parts[0], parts[1]


def fetch_makefile(repo):
    out = subprocess.run(
        ["gh", "api", f"repos/{repo}/contents/Makefile", "--jq", ".content"],
        capture_output=True, text=True,
    )
    if out.returncode != 0:
        return None
    import base64
    return base64.b64decode(out.stdout).decode("utf-8", "replace")


def expand(value, variables):
    """Resolve $(foo) / ${foo} references against already-parsed variables."""
    for _ in range(10):
        new = re.sub(
            r"\$[({]([A-Za-z0-9_]+)[)}]",
            lambda m: variables.get(m.group(1), ""),
            value,
        )
        if new == value:
            break
        value = new
    return value.split()


def parse_modern(text):
    """Return the allowlist the dist rule copies: `cp -R $(<prefix>all_src) $@`.

    Prefer `<prefix>all_src` over its inputs. It is usually just
    `$(src_dirs) $(src_files) $(doc_files)`, but apps append literal paths there
    too -- external's is `$(src_dirs) $(doc_files) settings.php index.php`, and
    those two files really do ship. Reading only the src_*/doc_files variables
    would silently drop them.
    """
    variables = {}
    # Join backslash continuations first so multi-line assignments parse.
    joined = re.sub(r"\\\n\s*", " ", text)
    for m in re.finditer(r"^([A-Za-z0-9_]+)\s*[:?]?=\s*(.*)$", joined, re.M):
        variables[m.group(1)] = m.group(2).strip()

    keys = [k for k in variables if k.endswith("all_src")]
    if not keys:
        keys = [k for k in variables if k.endswith(("src_dirs", "src_files", "doc_files"))]
    if not keys:
        return None
    paths = []
    for k in sorted(keys):
        paths.extend(expand(variables[k], variables))
    return paths


def parse_legacy(text):
    """Return the allowlist from the appstore target's `--parents -r` copy list.

    Deliberately keyed on `--parents -r` anywhere in the file rather than on the
    `appstore:` target header, because the headers vary:
      - files_primary_s3 declares `appstore:` twice (once for the ## help text,
        once for the real prerequisite), which defeats header-anchored matching;
      - notes invokes `$(copy_command)` rather than a literal `cp`.
    The copy list runs across backslash continuations and ends at the
    destination, which is always a $(...) variable. Paths may be quoted and may
    be nested (notes ships only js/vendor and js/public, not all of js/).
    """
    m = re.search(r"--parents\s+-r\s+(.*?)\$\(", text, re.S)
    if not m:
        return None
    paths = []
    for tok in m.group(1).replace("\\", " ").split():
        tok = tok.strip("\"'")
        if not tok or tok.startswith("-"):
            continue
        paths.append(tok)
    return paths or None


def main():
    rows = []
    problems = []
    for app, repo in public_apps():
        text = fetch_makefile(repo)
        if text is None:
            problems.append((app, "no Makefile"))
            continue
        kind = "modern"
        paths = parse_modern(text)
        if not paths:
            kind = "legacy"
            paths = parse_legacy(text)
        if not paths:
            problems.append((app, "no allowlist found"))
            continue
        # Drop duplicates while preserving order, and drop anything that is
        # obviously not a release path.
        seen, clean = set(), []
        for p in paths:
            if p in seen or p.startswith("-"):
                continue
            seen.add(p)
            clean.append(p)
        rows.append((app, kind, clean))

    for app, kind, paths in sorted(rows):
        print(f"{app}\t{kind}\t{' '.join(sorted(paths))}")

    for app, why in problems:
        print(f"# PROBLEM {app}: {why}", file=sys.stderr)
    print(f"# {len(rows)} apps parsed, {len(problems)} problems", file=sys.stderr)


if __name__ == "__main__":
    main()
