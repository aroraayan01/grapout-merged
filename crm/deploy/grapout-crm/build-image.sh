#!/usr/bin/env bash
# Build the image ON THIS SERVER, with grapout.com's URLs baked in.
#
# Why not the GitHub image: NEXT_PUBLIC_API_URL and NEXT_PUBLIC_BASE_PATH are
# compiled into the browser bundle, so an image built for grapme.com can never
# serve grapout.com/crm. Building here also means shipping does not depend on
# anyone else's CI.
#
#   bash deploy/grapout-crm/build-image.sh
#
# Then point the stack at it:  APP_IMAGE=grapme:grapout-crm  in /opt/aeo/.env
# Nothing is restarted by this script. Data is untouched — this only builds.
set -euo pipefail
cd "$(dirname "$0")/../.."

TAG="${TAG:-grapme:grapout-crm}"
SITE="${SITE:-https://www.grapout.com}"
BASE_PATH="${BASE_PATH:-/crm}"
# Where the PUBLIC marketing site actually is — only used by the "View" link on
# a published blog post, which has to open the real page. It has not moved, so
# this stays grapme.com; change it the day those pages move to grapout.com.
# Signing out no longer uses this: clients land on their own sign-in screen.
MARKETING_URL="${MARKETING_URL:-https://www.grapme.com}"

echo "==> Building $TAG"
echo "    app at    $SITE$BASE_PATH"
echo "    api at    $SITE$BASE_PATH/api/v1"
echo "    blog at   $MARKETING_URL"
docker build \
  --build-arg "NEXT_PUBLIC_API_URL=$SITE$BASE_PATH/api/v1" \
  --build-arg "NEXT_PUBLIC_BASE_PATH=$BASE_PATH" \
  --build-arg "NEXT_PUBLIC_MARKETING_URL=$MARKETING_URL" \
  -t "$TAG" .

echo
echo "Built $TAG. Nothing is live yet — see docs/DEPLOY_GRAPOUT_CRM.md for the"
echo "two .env lines and the restart, and for how to roll back to the old image."
