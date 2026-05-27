#!/usr/bin/env bash
#
# check-boot-invariants.sh — Thin wrapper around the PHP tokenizer-based
# detector at bin/check-boot-invariants.php. See that file for rationale.

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1
exec php bin/check-boot-invariants.php
