#!/usr/bin/env python3

import argparse
import concurrent.futures
import ipaddress
import time
import urllib.error
import urllib.parse
import urllib.request


PATHS = ("/up", "/api/v1/health", "/api/v1/info")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


def validate_target(url: str, allow_remote: bool) -> str:
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme not in ("http", "https") or not parsed.hostname:
        raise ValueError("Target must be an HTTP(S) origin.")
    if parsed.username or parsed.password or parsed.path not in ("", "/") or parsed.query or parsed.fragment:
        raise ValueError("Target must not contain credentials, a path, query, or fragment.")
    hostname = parsed.hostname.lower()
    try:
        loopback = ipaddress.ip_address(hostname).is_loopback
    except ValueError:
        loopback = hostname == "localhost"
    if not loopback and not allow_remote:
        raise ValueError("Remote load tests require the explicit --allow-remote flag.")

    return f"{parsed.scheme}://{parsed.netloc}"


def request_once(base_url: str, index: int, timeout: float) -> tuple[float, int, str | None]:
    url = base_url + PATHS[index % len(PATHS)]
    opener = urllib.request.build_opener(NoRedirect)
    started = time.perf_counter()
    try:
        with opener.open(url, timeout=timeout) as response:
            status = response.status
            response.read(64 * 1024)
            error = None if 200 <= status < 300 else f"HTTP {status}"
    except (OSError, urllib.error.URLError, TimeoutError) as exception:
        status = 0
        error = type(exception).__name__

    return time.perf_counter() - started, status, error


def percentile(values: list[float], value: float) -> float:
    ordered = sorted(values)
    index = min(len(ordered) - 1, max(0, round((len(ordered) - 1) * value)))
    return ordered[index] * 1000


def main() -> None:
    parser = argparse.ArgumentParser(description="Bounded GET-only load test for owned health/info endpoints.")
    parser.add_argument("--url", default="http://127.0.0.1:8080")
    parser.add_argument("--requests", type=int, default=120)
    parser.add_argument("--workers", type=int, default=8)
    parser.add_argument("--timeout", type=float, default=5.0)
    parser.add_argument("--allow-remote", action="store_true")
    args = parser.parse_args()
    if not 1 <= args.requests <= 5000 or not 1 <= args.workers <= 50:
        raise SystemExit("Use 1-5000 requests and 1-50 workers.")
    if not 0.1 <= args.timeout <= 60:
        raise SystemExit("Timeout must be between 0.1 and 60 seconds.")

    base_url = validate_target(args.url, args.allow_remote)
    started = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as executor:
        results = list(executor.map(
            lambda index: request_once(base_url, index, args.timeout),
            range(args.requests),
        ))
    duration = time.perf_counter() - started
    latencies = [result[0] for result in results]
    failures = [result[2] for result in results if result[2] is not None]
    print(f"Requests: {args.requests}; workers: {args.workers}; elapsed: {duration:.2f}s")
    print(f"Throughput: {args.requests / duration:.2f} requests/s")
    print(f"Latency: p50={percentile(latencies, 0.50):.1f}ms p95={percentile(latencies, 0.95):.1f}ms max={max(latencies) * 1000:.1f}ms")
    print(f"Failures: {len(failures)}")
    if failures:
        print(f"Failure categories: {', '.join(sorted(set(failures)))}")
        raise SystemExit(1)


if __name__ == "__main__":
    main()
