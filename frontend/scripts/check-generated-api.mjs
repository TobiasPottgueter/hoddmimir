import { execFileSync } from "node:child_process";
import { mkdtempSync, readdirSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, relative, resolve } from "node:path";

const projectRoot = resolve(import.meta.dirname, "..");
const generatedRoot = resolve(projectRoot, "src/api/generated");
const temporaryRoot = mkdtempSync(join(tmpdir(), "hoddmimir-openapi-"));

function files(root, directory = root) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    return entry.isDirectory() ? files(root, path) : [relative(root, path)];
  });
}

try {
  execFileSync(
    process.execPath,
    [resolve(projectRoot, "scripts/generate-api.mjs"), temporaryRoot],
    { cwd: projectRoot },
  );

  const expected = files(temporaryRoot).sort();
  const actual = files(generatedRoot).sort();
  if (
    JSON.stringify(expected) !== JSON.stringify(actual) ||
    expected.some(
      (path) =>
        readFileSync(join(temporaryRoot, path), "utf8") !==
        readFileSync(join(generatedRoot, path), "utf8"),
    )
  ) {
    throw new Error(
      "Generated API client is stale. Run `npm run api:generate` and commit the result.",
    );
  }
} finally {
  rmSync(temporaryRoot, { recursive: true, force: true });
}
