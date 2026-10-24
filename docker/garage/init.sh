#!/bin/sh
# Idempotent Garage provisioning: single-node layout, S3 key, bucket.
# Requires: GARAGE_RPC_SECRET, S3_ACCESS_KEY_ID, S3_SECRET_ACCESS_KEY.
# Optional: S3_BUCKET (default "photos"), GARAGE_CAPACITY (default "10G").
set -eu

BUCKET="${S3_BUCKET:-photos}"
CAPACITY="${GARAGE_CAPACITY:-10G}"
KEY_ID="${S3_ACCESS_KEY_ID:?S3_ACCESS_KEY_ID is required}"
SECRET="${S3_SECRET_ACCESS_KEY:?S3_SECRET_ACCESS_KEY is required}"

# 1. Layout (only if no role has been assigned yet)
status_out="$(garage status)"
if printf '%s\n' "${status_out}" | grep -q "NO ROLE ASSIGNED"; then
    NODE_ID="$(printf '%s\n' "${status_out}" | awk '/NO ROLE ASSIGNED/ {print $1; exit}')"
    echo "Assigning layout to node ${NODE_ID}"
    garage layout assign -z dc1 -c "${CAPACITY}" "${NODE_ID}"
    garage layout apply --version 1
else
    echo "Layout already applied"
fi

# 2. Access key (deterministic, imported from env)
if garage key info "${KEY_ID}" >/dev/null 2>&1; then
    echo "Key already imported"
else
    garage key import --yes -n avapeek-backend "${KEY_ID}" "${SECRET}"
fi

# 3. Bucket
if garage bucket info "${BUCKET}" >/dev/null 2>&1; then
    echo "Bucket already exists"
else
    garage bucket create "${BUCKET}"
fi

# 4. Permissions (idempotent)
garage bucket allow --read --write --owner "${BUCKET}" --key "${KEY_ID}"

echo "Garage provisioned"
