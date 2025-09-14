#!/usr/bin/env bash
set -euo pipefail
PROJECT_DIR="$PWD"

# Export the project to get rid of .git/, etc
rm -Rf /tmp/python-mcp
mkdir /tmp/python-mcp
cp -a "$PROJECT_DIR"/. /tmp/python-mcp/
cd /tmp/python-mcp
rm -Rf ./dist

box compile

docker build -t static-app -f static-build.Dockerfile .

docker create --name static-app-tmp static-app
docker cp static-app-tmp:/work/app/dist/. dist/
docker rm static-app-tmp

cp -r ./dist "${PROJECT_DIR}"

rm -Rf /tmp/python-mcp


