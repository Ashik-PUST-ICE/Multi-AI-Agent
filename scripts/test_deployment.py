import io
import runpy
import tarfile
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
LOAD_TEST = runpy.run_path(ROOT / "scripts" / "load-test.py")
RESTORE = runpy.run_path(ROOT / "scripts" / "restore.py")


class LoadTestTargetTest(unittest.TestCase):
    def test_loopback_targets_are_allowed_by_default(self):
        self.assertEqual(
            "http://127.0.0.1:8080",
            LOAD_TEST["validate_target"]("http://127.0.0.1:8080", False),
        )
        self.assertEqual(
            "https://localhost",
            LOAD_TEST["validate_target"]("https://localhost", False),
        )

    def test_remote_targets_require_explicit_permission(self):
        with self.assertRaises(ValueError):
            LOAD_TEST["validate_target"]("https://example.com", False)
        self.assertEqual(
            "https://example.com",
            LOAD_TEST["validate_target"]("https://example.com", True),
        )

    def test_target_must_be_an_origin_without_credentials_or_path(self):
        for target in (
            "ftp://localhost",
            "http://user:password@localhost",
            "http://localhost/private",
            "http://localhost?query=1",
        ):
            with self.subTest(target=target), self.assertRaises(ValueError):
                LOAD_TEST["validate_target"](target, True)


class RestoreArchiveTest(unittest.TestCase):
    def validate_archive(self, member: tarfile.TarInfo) -> None:
        with tempfile.TemporaryDirectory() as temporary_directory:
            archive_path = Path(temporary_directory) / "storage.tar.gz"
            with tarfile.open(archive_path, "w:gz") as archive:
                archive.addfile(member, io.BytesIO(b"x") if member.isfile() else None)
            RESTORE["validate_storage_archive"](archive_path)

    def test_regular_files_are_allowed(self):
        member = tarfile.TarInfo("documents/one.txt")
        member.size = 1
        self.validate_archive(member)

    def test_path_traversal_is_rejected(self):
        for name in ("../outside.txt", "/absolute.txt"):
            member = tarfile.TarInfo(name)
            member.size = 1
            with self.subTest(name=name), self.assertRaises(ValueError):
                self.validate_archive(member)

    def test_links_are_rejected(self):
        member = tarfile.TarInfo("outside-link")
        member.type = tarfile.SYMTYPE
        member.linkname = "../outside"
        with self.assertRaises(ValueError):
            self.validate_archive(member)


if __name__ == "__main__":
    unittest.main()
