from __future__ import annotations

import json
import os
import pathlib
import shutil
import subprocess
import tempfile
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
BACKEND = ROOT / "backend"
TOOL = BACKEND / "tools/mutation-shards.php"
SHARDS = [
    *(f"critical-{index}" for index in range(3)),
    *(f"global-rest-{index}" for index in range(9)),
]


class MutationShardingContractTest(unittest.TestCase):
    def run_tool(self, *arguments: pathlib.Path | str, succeeds: bool = True) -> subprocess.CompletedProcess[str]:
        result = subprocess.run(
            ["php", str(TOOL), *(str(argument) for argument in arguments)],
            cwd=BACKEND,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        if succeeds and result.returncode != 0:
            self.fail(result.stderr)
        if not succeeds and result.returncode == 0:
            self.fail("command unexpectedly succeeded")
        return result

    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.temporary.name)
        self.plan = self.root / "plan"
        self.reports = self.root / "reports"
        self.run_tool("plan", self.plan)

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def test_plan_is_deterministic_disjoint_and_exact(self) -> None:
        first = {name: (self.plan / f"{name}.txt").read_text() for name in SHARDS}
        all_paths = [path for contents in first.values() for path in contents.splitlines()]

        self.assertEqual(len(all_paths), len(set(all_paths)))
        self.assertTrue(all(first[name].strip() for name in SHARDS))
        metadata = json.loads((self.plan / "plan.json").read_text())
        self.assertEqual(metadata["sourceCount"], len(all_paths))

        self.run_tool("plan", self.plan)
        second = {name: (self.plan / f"{name}.txt").read_text() for name in SHARDS}
        self.assertEqual(first, second)

    def test_critical_plan_matches_the_infection_source_contract(self) -> None:
        configuration = json.loads(
            (BACKEND / "infection-critical.json5.dist").read_text(encoding="utf-8")
        )
        directories = configuration["source"]["directories"]
        expected = {
            path.relative_to(BACKEND).as_posix()
            for directory in directories
            for path in (BACKEND / directory).rglob("*.php")
            if path.is_file() and not path.is_symlink()
        }
        planned = {
            path
            for name in SHARDS
            if name.startswith("critical-")
            for path in (self.plan / f"{name}.txt").read_text(encoding="utf-8").splitlines()
        }

        self.assertEqual(expected, planned)

    def write_summaries(self, *, critical_killed: int = 9, rest_killed: int = 8, timeout_shard: str | None = None) -> None:
        for name in SHARDS:
            killed = critical_killed if name.startswith("critical-") else rest_killed
            timeout = 1 if name == timeout_shard else 0
            escaped = 10 - killed - timeout
            directory = self.reports / name
            directory.mkdir(parents=True)
            shutil.copyfile(self.plan / f"{name}.txt", directory / "manifest.txt")
            (directory / "summary.json").write_text(
                json.dumps(
                    {
                        "stats": {
                            "totalMutantsCount": 10,
                            "killedCount": killed,
                            "notCoveredCount": 0,
                            "escapedCount": escaped,
                            "errorCount": 0,
                            "syntaxErrorCount": 0,
                            "skippedCount": 0,
                            "ignoredCount": 0,
                            "timeOutCount": timeout,
                        }
                    }
                ),
                encoding="utf-8",
            )

    def test_aggregate_uses_weighted_raw_counters_without_critical_duplication(self) -> None:
        self.write_summaries()
        output = self.root / "aggregate.json"
        self.run_tool("aggregate", self.plan, self.reports, output)
        result = json.loads(output.read_text())

        self.assertEqual(27, result["critical"]["detectedMutantsCount"])
        self.assertEqual(30, result["critical"]["effectiveMutantsCount"])
        self.assertEqual(99, result["global"]["detectedMutantsCount"])
        self.assertEqual(120, result["global"]["effectiveMutantsCount"])
        self.assertEqual(82.5, result["global"]["msi"])

    def test_skipped_mutants_are_excluded_but_process_timeouts_still_fail(self) -> None:
        self.write_summaries()
        summary_path = self.reports / "critical-0/summary.json"
        summary = json.loads(summary_path.read_text())
        summary["stats"]["skippedCount"] = 1
        summary["stats"]["escapedCount"] = 0
        summary_path.write_text(json.dumps(summary), encoding="utf-8")

        output = self.root / "skipped.json"
        self.run_tool("aggregate", self.plan, self.reports, output)
        result = json.loads(output.read_text())
        self.assertEqual(29, result["critical"]["effectiveMutantsCount"])
        self.assertEqual(119, result["global"]["effectiveMutantsCount"])
        self.assertEqual(0, result["critical"]["timeOutCount"])

    def test_thresholds_and_timeouts_fail_closed(self) -> None:
        self.write_summaries(critical_killed=8)
        failed_threshold = self.run_tool(
            "aggregate", self.plan, self.reports, self.root / "failed.json", succeeds=False
        )
        self.assertIn("below 90%", failed_threshold.stderr)

        shutil.rmtree(self.reports)
        self.write_summaries(timeout_shard="global-rest-0")
        failed_timeout = self.run_tool(
            "aggregate", self.plan, self.reports, self.root / "timeout.json", succeeds=False
        )
        self.assertIn("timeouts remain a hard failure", failed_timeout.stderr)

    def test_duplicate_or_missing_plan_entries_are_rejected(self) -> None:
        first = self.plan / "critical-0.txt"
        duplicate = first.read_text().splitlines()[0]
        second = self.plan / "critical-1.txt"
        second.write_text("\n".join(sorted([*second.read_text().splitlines(), duplicate])) + "\n")

        failure = self.run_tool("verify-plan", self.plan, succeeds=False)
        self.assertIn("more than one shard", failure.stderr)

    def test_report_manifest_must_match_the_shard_plan(self) -> None:
        self.write_summaries()
        report_manifest = self.reports / "global-rest-0/manifest.txt"
        report_manifest.write_text(report_manifest.read_text().splitlines()[0] + "\n")

        failure = self.run_tool(
            "aggregate", self.plan, self.reports, self.root / "mismatch.json", succeeds=False
        )
        self.assertIn("does not match its planned manifest", failure.stderr)

    def test_shard_and_aggregate_never_generate_coverage(self) -> None:
        sandbox = self.root / "runner"
        (sandbox / "tools").mkdir(parents=True)
        for directory in ("src", "tests", "config", "migrations"):
            (sandbox / directory).mkdir()
        shutil.copyfile(BACKEND / "tools/run-mutation.sh", sandbox / "tools/run-mutation.sh")
        for name in (
            "composer.lock",
            "phpunit.xml.dist",
            "infection-critical.json5.dist",
            "infection.json5.dist",
        ):
            shutil.copyfile(BACKEND / name, sandbox / name)
        (sandbox / "tools/mutation-shards.php").write_text("<?php\n", encoding="utf-8")

        fake_bin = sandbox / "fake-bin"
        fake_bin.mkdir()
        invocation_log = sandbox / "php.log"
        fake_php = fake_bin / "php"
        fake_php.write_text(
            "#!/bin/sh\nprintf '%s\\n' \"$*\" >> \"$PHP_INVOCATION_LOG\"\nexit 0\n",
            encoding="utf-8",
        )
        fake_php.chmod(0o755)
        environment = {
            **os.environ,
            "PATH": f"{fake_bin}:{os.environ['PATH']}",
            "PHP_INVOCATION_LOG": str(invocation_log),
            "MUTATION_SHARD_NAME": "critical-0",
        }

        shard = subprocess.run(
            ["sh", "tools/run-mutation.sh", "shard"],
            cwd=sandbox,
            env=environment,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        self.assertNotEqual(0, shard.returncode)
        self.assertIn("must never regenerate it", shard.stderr)
        self.assertNotIn("vendor/bin/phpunit", invocation_log.read_text())

        invocation_log.write_text("", encoding="utf-8")
        aggregate = subprocess.run(
            ["sh", "tools/run-mutation.sh", "aggregate"],
            cwd=sandbox,
            env=environment,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        self.assertEqual(0, aggregate.returncode, aggregate.stderr)
        self.assertNotIn("vendor/bin/phpunit", invocation_log.read_text())

    def test_timeout_contract_and_positional_shard_cli_are_explicit(self) -> None:
        for configuration in ("infection-critical.json5.dist", "infection.json5.dist"):
            decoded = json.loads((BACKEND / configuration).read_text(encoding="utf-8"))
            self.assertEqual(180, decoded["timeout"])
            self.assertTrue(decoded["timeoutsAsEscaped"])
            self.assertEqual(0, decoded["maxTimeouts"])

        runner = (BACKEND / "tools/run-mutation.sh").read_text(encoding="utf-8")
        self.assertNotIn("--filter", runner)
        self.assertIn('set -- "$@" "$source_path"', runner)
        self.assertIn('-- "$@"', runner)


if __name__ == "__main__":
    unittest.main()
