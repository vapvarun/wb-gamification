#!/usr/bin/env bash
# Thin wrapper around bin/check-badge-condition-contract.php
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1
exec php bin/check-badge-condition-contract.php
