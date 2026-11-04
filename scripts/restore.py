#!/usr/bin/env python3

import argparse
import shutil
import subprocess
import tarfile
import tempfile
from pathlib import Path, PurePosixPath


ROOT = Path(__file__).resolve().parents[1]
DATABASE_RESTORE = r"""
set -eu
case "$MARIADB_DATABASE" in
    ''|*[!A-Za-z0-9_]*)
        echo "Unsafe database name." >&2
        exit 1
        ;;
esac
umask 077
config_file="$(mktemp)"
trap 'rm -f "$config_file"' EXIT
printf '[client]\\nuser=root\\npassword=%s\\n' "$(cat /run/secrets/db_root_password)" > "$config_file"
mariadb --defaults-extra-file="$config_file" -e "DROP DATABASE IF EXISTS \`$MARIADB_DATABASE\`; CREATE DATABASE \`$MARIADB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mariadb --defaults-extra-file="$config_file" "$MARIADB_DATABASE"
"""
STORAGE_RESTORE = """
set -eu
destination=/var/www/html/storage/app/private
mkdir -p "$destination"
find "$destination" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
tar -xzf - --no-same-owner -C "$destination"
"""


def run_compose(*arguments: str, stdin=None) -> None:
    subprocess.run(
        ["docker", "compose", *arguments],
        cwd=ROOT,
        check=True,
        stdin=stdin,
    )


def copy_member(archive: tarfile.TarFile, name: str, destination: Path) -> None:
    member = archive.getmember(name)
    if not member.isfile():
        raise ValueError(f"Backup member {name} is not a regular file.")
    source = archive.extractfile(member)
    if source is None:
        raise ValueError(f"Backup member {name} could not be read.")
    with source, destination.open("wb") as output:
        shutil.copyfileobj(source, output)


def validate_storage_archive(path: Path) -> None:
    with tarfile.open(path, "r:gz") as archive:
        for member in archive.getmembers():
            member_path = PurePosixPath(member.name)
            normalized_parts = [part for part in member_path.parts if part not in ("", ".")]
            if member_path.is_absolute() or ".." in normalized_parts:
                raise ValueError("The private-files archive contains an unsafe path.")
            if not (member.isdir() or member.isfile()):
                raise ValueError("The private-files archive contains a link or unsupported file type.")


def main() -> None:
    parser = argparse.ArgumentParser(description="Restore an age-encrypted database and private-file backup.")
    parser.add_argument("backup", type=Path)
    args = parser.parse_args()
    backup_path = args.backup.resolve()
    if not backup_path.is_file():
        raise SystemExit("Backup file does not exist.")
    if shutil.which("age") is None:
        raise SystemExit("Install age before restoring encrypted backups.")
    if input("This replaces the current database and private files. Type RESTORE to continue: ") != "RESTORE":
        raise SystemExit("Restore cancelled.")

    with tempfile.TemporaryDirectory(prefix="multi-ai-agent-restore-") as temp_name:
        temp_directory = Path(temp_name)
        archive_path = temp_directory / "backup.tar.gz"
        database_path = temp_directory / "database.sql"
        storage_path = temp_directory / "storage.tar.gz"
        with archive_path.open("wb") as decrypted:
            subprocess.run(
                ["age", "--decrypt", str(backup_path)],
                cwd=ROOT,
                check=True,
                stdout=decrypted,
            )

        with tarfile.open(archive_path, "r:gz") as archive:
            names = archive.getnames()
            if len(names) != 2 or set(names) != {"database.sql", "storage.tar.gz"}:
                raise ValueError("Backup archive must contain only database.sql and storage.tar.gz.")
            copy_member(archive, "database.sql", database_path)
            copy_member(archive, "storage.tar.gz", storage_path)
        if database_path.stat().st_size == 0:
            raise ValueError("The database backup is empty.")
        validate_storage_archive(storage_path)

        run_compose("stop", "web", "app", "worker", "scheduler")
        with database_path.open("rb") as database_input:
            run_compose("exec", "-T", "db", "sh", "-ec", DATABASE_RESTORE, stdin=database_input)
        with storage_path.open("rb") as storage_input:
            run_compose(
                "run",
                "--rm",
                "--no-deps",
                "-T",
                "app",
                "sh",
                "-ec",
                STORAGE_RESTORE,
                stdin=storage_input,
            )
        run_compose("start", "app", "worker", "scheduler", "web")

    print("Database and private files restored. Confirm the deployment retained the original APP_KEY secret.")


if __name__ == "__main__":
    main()
