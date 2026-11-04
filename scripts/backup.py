#!/usr/bin/env python3

import argparse
import os
import shutil
import subprocess
import tarfile
import tempfile
from datetime import datetime, timezone
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
DATABASE_DUMP = """
set -eu
umask 077
config_file="$(mktemp)"
trap 'rm -f "$config_file"' EXIT
printf '[client]\\nuser=root\\npassword=%s\\n' "$(cat /run/secrets/db_root_password)" > "$config_file"
mariadb-dump --defaults-extra-file="$config_file" --single-transaction --routines --triggers "$MARIADB_DATABASE"
"""


def run_compose(*arguments: str, stdout=None) -> None:
    subprocess.run(
        ["docker", "compose", *arguments],
        cwd=ROOT,
        check=True,
        stdout=stdout,
    )


def main() -> None:
    parser = argparse.ArgumentParser(description="Create an age-encrypted database and private-file backup.")
    parser.add_argument("--output", type=Path, default=ROOT / "backups")
    args = parser.parse_args()
    if shutil.which("age") is None:
        raise SystemExit("Install age before creating encrypted backups.")

    output_directory = args.output.resolve()
    output_directory.mkdir(parents=True, exist_ok=True)
    try:
        os.chmod(output_directory, 0o700)
    except OSError:
        pass

    timestamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup_path = output_directory / f"multi-ai-agent-{timestamp}.tar.gz.age"

    run_compose("stop", "web", "app", "worker", "scheduler")
    try:
        with tempfile.TemporaryDirectory(prefix="multi-ai-agent-backup-") as temp_name:
            temp_directory = Path(temp_name)
            database_path = temp_directory / "database.sql"
            storage_path = temp_directory / "storage.tar.gz"
            archive_path = temp_directory / "backup.tar.gz"

            with database_path.open("wb") as database_file:
                run_compose("exec", "-T", "db", "sh", "-ec", DATABASE_DUMP, stdout=database_file)

            with storage_path.open("wb") as storage_file:
                run_compose(
                    "run",
                    "--rm",
                    "--no-deps",
                    "-T",
                    "app",
                    "tar",
                    "-czf",
                    "-",
                    "-C",
                    "/var/www/html/storage/app/private",
                    ".",
                    stdout=storage_file,
                )

            with tarfile.open(archive_path, "w:gz") as archive:
                archive.add(database_path, arcname="database.sql", recursive=False)
                archive.add(storage_path, arcname="storage.tar.gz", recursive=False)

            subprocess.run(
                ["age", "--passphrase", "--output", str(backup_path), str(archive_path)],
                cwd=ROOT,
                check=True,
            )
        try:
            os.chmod(backup_path, 0o600)
        except OSError:
            pass
        print(f"Encrypted backup created: {backup_path}")
    finally:
        run_compose("start", "app", "worker", "scheduler", "web")


if __name__ == "__main__":
    main()
