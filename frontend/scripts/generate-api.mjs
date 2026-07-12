import { execFileSync } from "node:child_process";
import { readdirSync, readFileSync, writeFileSync } from "node:fs";
import { join, resolve } from "node:path";

const projectRoot = resolve(import.meta.dirname, "..");
const output = resolve(
  process.argv[2] ?? join(projectRoot, "src/api/generated"),
);

execFileSync(
  resolve(projectRoot, "node_modules/.bin/openapi-ts"),
  [
    "--input",
    resolve(projectRoot, "../docs/openapi-v1.json"),
    "--output",
    output,
    "--client",
    "@hey-api/client-fetch",
    "--plugins",
    "@hey-api/typescript",
    "@hey-api/sdk",
    "--no-log-file",
    "--silent",
  ],
  { cwd: projectRoot },
);

function generatedFiles(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    return entry.isDirectory() ? generatedFiles(path) : [path];
  });
}

const files = generatedFiles(output);
if (files.length === 0) {
  throw new Error("OpenAPI generation produced no files.");
}
for (const path of files) {
  if (!path.endsWith(".ts")) continue;
  let source = readFileSync(path, "utf8");
  if (path === join(output, "types.gen.ts")) {
    const corrected = source.replace(
      /export type InventoryResource =[\s\S]*?\n\nexport type InventoryResourcePage =/,
      `export type InventoryResource =
  | PveClusterResource
  | PveNodeResource
  | PveGuestResource
  | PveStorageResource
  | PbsServerResource
  | PbsDatastoreResource
  | PbsNamespaceResource
  | PbsBackupGroupResource
  | PbsSnapshotResource;

export type InventoryResourcePage =`,
    );
    if (corrected === source) {
      throw new Error(
        "The pinned generator's InventoryResource discriminator output changed; review the normalization.",
      );
    }
    source = corrected;
  }
  writeFileSync(
    path,
    `// @ts-nocheck -- generated runtime is validated by its pinned generator\n${source}`,
  );
}

execFileSync(
  resolve(projectRoot, "node_modules/.bin/prettier"),
  ["--write", ...files],
  { cwd: projectRoot, stdio: "ignore" },
);
