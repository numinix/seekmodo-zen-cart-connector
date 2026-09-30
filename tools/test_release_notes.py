"""changelog_release_notes keeps ### subsections and refuses placeholders."""
from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from publish_numinix_release import (  # noqa: E402
    _reject_placeholder,
    changelog_release_notes,
)


SAMPLE = """
## Unreleased

## v1.3.92 - 2026-09-29 (Suggest proxy-first default)

### Changed
- **Suggest is same-origin proxy-first by default.** Storefront metas
  emit stub token.

### Added
- **Publish shopper Store Context** — when snapshot includes it.

## v1.3.91 - 2026-09-29

### Fixed
- Older note.
"""


class ReleaseNotesTest(unittest.TestCase):
    def test_keeps_every_bullet_under_h3(self) -> None:
        notes = changelog_release_notes(SAMPLE, "1.3.92")
        self.assertIn("Changed", notes)
        self.assertIn("Suggest is same-origin proxy-first by default. Storefront metas emit stub token.", notes)
        self.assertIn("Added", notes)
        self.assertIn("Publish shopper Store Context", notes)
        self.assertNotIn("Older note", notes)
        self.assertNotIn("**", notes)

    def test_bracket_heading(self) -> None:
        text = "## [1.2.3] - 2026-01-01\n\n### Fixed\n- One fix.\n"
        self.assertEqual(changelog_release_notes(text, "1.2.3"), "Fixed\nOne fix.")

    def test_missing_heading_refuses_placeholder(self) -> None:
        with self.assertRaises(SystemExit):
            changelog_release_notes(SAMPLE, "9.9.9")

    def test_explicit_placeholder_refused(self) -> None:
        with self.assertRaises(SystemExit):
            _reject_placeholder("Release 1.3.92", "1.3.92")


if __name__ == "__main__":
    unittest.main()
