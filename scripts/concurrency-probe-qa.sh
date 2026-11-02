#!/usr/bin/env bash
# Alias histórico: el runner principal ya tiene los guardrails de MySQL QA.
set -euo pipefail
exec bash "$(dirname "$0")/concurrency-probe.sh" "$@"
